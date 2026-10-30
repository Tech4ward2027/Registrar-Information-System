<?php

namespace App\Services\Tickets;

use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use App\Exceptions\Tickets\TicketActionNotAllowedException;
use App\Exceptions\Tickets\TicketClaimException;
use App\Exceptions\Tickets\TicketReturnLimitException;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Every write to a ticket goes through this class: create, claim, release,
 * reply (staff and requester), resolve, confirm and "this isn't resolved".
 *
 * Division of labour
 *   - TicketPolicy decides WHO may do something. Controllers (Phase 3) and the
 *     public magic-link flow (Phase 4) authorise before calling in here. This
 *     class trusts its caller on identity, with one exception: release(), where
 *     "only the claimer or a Manager" is enforced here as well because a
 *     wrong release silently strips someone's work.
 *   - TicketStateMachine decides WHICH status changes are legal.
 *   - This class makes each action atomic and keeps the data consistent: one
 *     transaction per action, the ticket row locked while its state is checked
 *     and changed, and every action leaving exactly the events it should.
 *
 * Event conventions (TicketEvent)
 *   Each status change is recorded in exactly ONE event, with from_status and
 *   to_status filled in, using the most specific type that exists (claimed,
 *   resolved, returned, ...). A reply that also moves the status (a Pending
 *   Client ticket answered by its requester) is a `replied` event carrying
 *   from/to. An action that changes nothing about status leaves them null.
 *   One action can therefore write more than one event (a staff reply that
 *   auto-claims writes `claimed` then `replied`), and each is chained.
 *
 * SLA (Phase 5): due dates, the Pending Client pause and the claim timeout are
 * COMPUTED by SlaService and merged into the same save as the status change they
 * belong to, so they share this class's transaction and row lock. The periodic
 * sweeps live elsewhere (TicketSlaMonitor, and expireClaim() below, called by
 * tickets:release-stale-claims).
 *
 * Not in this class (later phases): notifications (6), the auto-close sweep (7).
 */
class TicketService
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly TicketStateMachine $machine,
        private readonly TicketEventRecorder $events,
        private readonly SlaService $sla,
    ) {
    }

    // =====================================================================
    // CREATE
    // =====================================================================

    /**
     * A signed-in requester files a ticket.
     */
    public function createForUser(
        SystemUser $user,
        TicketCategory $category,
        string $subject,
        string $description,
        ?string $ip = null,
    ): Ticket {
        return $this->create($category, $subject, $description, [
            'requester_user_id' => $user->user_id,
        ], $user->user_id, 'authenticated', $ip);
    }

    /**
     * A public submitter whose email has ALREADY been verified (Phase 4) files
     * a ticket. The caller is responsible for that verification; this method
     * only stores the verified identity.
     */
    public function createForPublic(
        string $name,
        string $email,
        TicketCategory $category,
        string $subject,
        string $description,
        ?string $ip = null,
    ): Ticket {
        return $this->create($category, $subject, $description, [
            'requester_name'  => trim($name),
            // Lowercased so per-email limits cannot be dodged with letter case.
            'requester_email' => mb_strtolower(trim($email)),
        ], null, 'public', $ip);
    }

    /**
     * @param  array<string,mixed>  $requester
     */
    private function create(
        TicketCategory $category,
        string $subject,
        string $description,
        array $requester,
        ?int $actorUserId,
        string $channel,
        ?string $ip,
    ): Ticket {
        if (! $category->is_active) {
            throw new InvalidArgumentException('That ticket category is no longer available.');
        }

        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > (int) config('tickets.content.subject_max_length')) {
            throw new InvalidArgumentException('The ticket subject is empty or too long.');
        }
        $description = $this->normaliseBody($description);

        return DB::transaction(function () use ($category, $subject, $description, $requester, $actorUserId, $channel, $ip) {
            $now = now();

            $ticket = new Ticket();
            $ticket->fill(['category_id' => $category->getKey(), 'subject' => $subject] + $requester);
            $ticket->forceFill([
                // Inside this transaction on purpose: a failed insert rolls the
                // counter back too, so numbers stay gap-free. See the generator.
                'ticket_number'    => $this->numbers->next($now),
                // Copied, not referenced: re-prioritising a category later must
                // not rewrite tickets already in flight.
                'priority'         => $category->priority,
                'status'           => TicketStatusEnum::Open,
                'requester_ip'     => $ip,
                'last_activity_at' => $now,
            ] + $this->sla->startAttributes($category->priority, $now))->save();

            // The description is the first message of the thread.
            $message = $this->addMessage($ticket, TicketSenderTypeEnum::Requester, $actorUserId, $description);

            $this->events->record(
                $ticket, TicketEvent::TYPE_CREATED, TicketSenderTypeEnum::Requester, $actorUserId,
                null, TicketStatusEnum::Open,
                ['category' => $category->code, 'channel' => $channel, 'message_id' => $message->getKey()],
            );

            return $ticket->refresh();
        });
    }

    // =====================================================================
    // CLAIM / RELEASE
    // =====================================================================

    /**
     * Claim a ticket from the shared queue.
     *
     * The claim is one conditional UPDATE: it succeeds only if nobody holds the
     * ticket at that instant. Two staff clicking Claim together cannot both win,
     * because the database lets exactly one UPDATE match the "unclaimed" row
     * (on MySQL the second waits for the first to commit, then matches nothing).
     * No SELECT precedes it, so there is no check-then-act window.
     *
     * Claiming an Open ticket also starts work on it (Open -> In Progress).
     * A claim is a temporary marker, not an assignment: release() gives it up.
     *
     * @throws TicketClaimException            someone else holds it (lost race)
     * @throws TicketActionNotAllowedException the ticket is Resolved or Closed
     */
    public function claim(Ticket $ticket, SystemUser $staff): Ticket
    {
        return DB::transaction(function () use ($ticket, $staff) {
            $from = $this->claimAtomically($ticket->getKey(), $staff);

            if ($from === null) {
                $this->explainFailedClaim($ticket->getKey());
            }

            $fresh = Ticket::query()->findOrFail($ticket->getKey());

            $this->events->record(
                $fresh, TicketEvent::TYPE_CLAIMED, TicketSenderTypeEnum::Staff, $staff->user_id,
                $from, $fresh->status,
                ['claim_expires_at' => $fresh->claim_expires_at?->toIso8601String()],
            );

            return $fresh;
        });
    }

    /**
     * Give a claim up, returning the ticket to the shared queue. Status is left
     * as it is (an In Progress ticket stays In Progress, just unclaimed).
     *
     * Allowed for the claimer, or for staff who may manage tickets (to free a
     * ticket whose claimer is away). See TicketPolicy::release.
     *
     * @throws TicketClaimException
     */
    public function release(Ticket $ticket, SystemUser $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor) {
            $locked = $this->lock($ticket);

            if ($locked->claimed_by_user_id === null) {
                throw TicketClaimException::notClaimed();
            }

            if (! Gate::forUser($actor)->allows('release', $locked)) {
                throw TicketClaimException::notPermitted($locked->claimed_by_user_id);
            }

            $previous = (int) $locked->claimed_by_user_id;

            $locked->forceFill([
                'claimed_by_user_id' => null,
                'claimed_at'         => null,
                'claim_expires_at'   => null,
                'last_activity_at'   => now(),
            ])->save();

            $this->events->record(
                $locked, TicketEvent::TYPE_RELEASED, TicketSenderTypeEnum::Staff, $actor->user_id,
                null, null,
                ['previous_claimer' => $previous, 'released_by_other' => $previous !== (int) $actor->user_id],
            );

            return $locked;
        });
    }

    /**
     * Release a claim that has gone stale (called by tickets:release-stale-claims).
     *
     * Re-checks everything under the row lock, so it is safe to run twice, to
     * overlap with a human release, or to race with the claimer replying (a
     * reply renews the expiry, and this then sees an unexpired claim and does
     * nothing). Returns true only when it actually released the claim.
     *
     * Not released: a Pending Client ticket (the wait is on the requester, not
     * the claimer), or one that is no longer being worked.
     */
    public function expireClaim(Ticket $ticket, CarbonInterface $now): bool
    {
        // Stored as given, so put it in the application zone first.
        $now = Carbon::instance($now)->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($ticket, $now) {
            $locked = $this->lock($ticket);

            if ($locked->claimed_by_user_id === null
                || $locked->claim_expires_at === null
                || $locked->claim_expires_at->greaterThan($now)
                || ! $locked->status->isActive()
                || $locked->status === TicketStatusEnum::PendingClient) {
                return false;
            }

            $previous  = (int) $locked->claimed_by_user_id;
            $expiredAt = $locked->claim_expires_at;

            $locked->forceFill([
                'claimed_by_user_id' => null,
                'claimed_at'         => null,
                'claim_expires_at'   => null,
                'last_activity_at'   => $now,
            ])->save();

            $this->events->record(
                $locked, TicketEvent::TYPE_CLAIM_EXPIRED, TicketSenderTypeEnum::System, null,
                null, null,
                ['previous_claimer' => $previous, 'expired_at' => $expiredAt->toIso8601String()],
            );

            return true;
        });
    }

    // =====================================================================
    // REPLIES
    // =====================================================================

    /**
     * A staff reply.
     *
     * Replying to an unclaimed ticket claims it for the replier first: answering
     * is working on it, and this keeps two people from answering the same
     * unclaimed ticket without noticing. Replying to a ticket someone ELSE holds
     * is allowed (cover for an absent colleague); warning about it is the UI's
     * job, and the event log shows who said what.
     *
     * $awaitRequester sets Pending Client (staff asked a question and the clock
     * is waiting on the requester). Leave it false when no answer is needed.
     *
     * The first staff reply stamps first_responded_at, which the SLA measures.
     *
     * @throws TicketActionNotAllowedException the ticket is Resolved or Closed
     */
    public function replyAsStaff(Ticket $ticket, SystemUser $staff, string $body, bool $awaitRequester = false): TicketMessage
    {
        $body = $this->normaliseBody($body);

        return DB::transaction(function () use ($ticket, $staff, $body, $awaitRequester) {
            $locked = $this->lock($ticket);

            if (! $locked->status->isActive()) {
                throw new TicketActionNotAllowedException('reply to', $locked->status);
            }

            if ($locked->claimed_by_user_id === null) {
                $from = $this->claimAtomically($locked->getKey(), $staff);

                if ($from !== null) {
                    $locked->refresh();
                    $this->events->record(
                        $locked, TicketEvent::TYPE_CLAIMED, TicketSenderTypeEnum::Staff, $staff->user_id,
                        $from, $locked->status, ['claim_expires_at' => $locked->claim_expires_at?->toIso8601String(), 'via' => 'reply'],
                    );
                }
            }

            $message = $this->addMessage($locked, TicketSenderTypeEnum::Staff, $staff->user_id, $body);

            $from      = $locked->status;
            $now       = now();
            $attrs     = ['last_activity_at' => $now];
            if ($locked->first_responded_at === null) {
                $attrs['first_responded_at'] = $now;
            }

            // The claimer replying is staff activity: it restarts the claim
            // timeout. A colleague covering for them does not.
            if ((int) $locked->claimed_by_user_id === (int) $staff->user_id) {
                $expiry = $this->sla->claimExpiresAt($now);
                if ($expiry !== null) {
                    $attrs['claim_expires_at'] = $expiry;
                }
            }

            if ($awaitRequester && $locked->status !== TicketStatusEnum::PendingClient) {
                // The wait is now on the requester: stop the resolution clock.
                $this->machine->transition($locked, TicketStatusEnum::PendingClient, $attrs + $this->sla->pauseAttributes($now));
            } else {
                $locked->forceFill($attrs)->save();
            }

            $this->events->record(
                $locked, TicketEvent::TYPE_REPLIED, TicketSenderTypeEnum::Staff, $staff->user_id,
                $from !== $locked->status ? $from : null,
                $from !== $locked->status ? $locked->status : null,
                ['message_id' => $message->getKey(), 'awaiting_requester' => $awaitRequester],
            );

            return $message;
        });
    }

    /**
     * A requester's reply. $requester is null for a public (magic-link)
     * submitter.
     *
     *   Open / In Progress  the message is added, status unchanged.
     *   Pending Client      the requester has answered, so the ticket goes back
     *                       to In Progress.
     *   Resolved            a reply during the Resolved window counts as "this
     *                       isn't resolved": the ticket returns to In Progress
     *                       and one of its limited returns is used. Past the
     *                       limit the reply is REFUSED, not stored: a message on
     *                       a Resolved ticket would sit unseen (Resolved tickets
     *                       are out of the staff queue).
     *   Closed              refused. Closed is final.
     *
     * @throws TicketActionNotAllowedException the ticket is Closed
     * @throws TicketReturnLimitException      the ticket was returned too often
     */
    public function replyAsRequester(Ticket $ticket, ?SystemUser $requester, string $body): TicketMessage
    {
        $body = $this->normaliseBody($body);

        return DB::transaction(function () use ($ticket, $requester, $body) {
            $locked = $this->lock($ticket);
            $actorId = $requester?->user_id;

            if ($locked->status === TicketStatusEnum::Closed) {
                throw new TicketActionNotAllowedException('reply to', $locked->status);
            }

            if ($locked->status === TicketStatusEnum::Resolved) {
                $this->applyReturn($locked, $actorId, 'reply');
            }

            $message = $this->addMessage($locked, TicketSenderTypeEnum::Requester, $actorId, $body);

            $from  = $locked->status;
            $now   = now();
            $attrs = ['last_activity_at' => $now];

            if ($from === TicketStatusEnum::PendingClient) {
                // The requester answered: restart the resolution clock, pushed
                // back by the working time spent waiting.
                $this->machine->transition($locked, TicketStatusEnum::InProgress, $attrs + $this->sla->resumeAttributes($locked, $now));
            } else {
                $locked->forceFill($attrs)->save();
            }

            $this->events->record(
                $locked, TicketEvent::TYPE_REPLIED, TicketSenderTypeEnum::Requester, $actorId,
                $from !== $locked->status ? $from : null,
                $from !== $locked->status ? $locked->status : null,
                ['message_id' => $message->getKey()],
            );

            return $message;
        });
    }

    // =====================================================================
    // RESOLVE / CONFIRM / RETURN
    // =====================================================================

    /**
     * Mark a ticket Resolved. A resolution note is required: it is what the
     * requester reads, and it is preserved in the event log even if the ticket
     * is later sent back and resolved again.
     *
     * Allowed straight from Open, In Progress or Pending Client (the policy
     * lets staff resolve at once when nothing more is needed). Resolving
     * releases any claim (a Resolved ticket is not being worked), starts the
     * calendar-day auto-close window, and counts as a first response if none
     * has been made.
     *
     * @throws \App\Exceptions\Tickets\TicketTransitionException already Resolved or Closed
     */
    public function resolve(Ticket $ticket, SystemUser $staff, string $resolutionNote): Ticket
    {
        $note = $this->normaliseBody($resolutionNote);

        return DB::transaction(function () use ($ticket, $staff, $note) {
            $locked = $this->lock($ticket);
            $now    = now();
            $from   = $locked->status;
            $previousClaimer = $locked->claimed_by_user_id;

            $this->machine->transition($locked, TicketStatusEnum::Resolved, [
                'resolution_note'     => $note,
                'resolved_at'         => $now,
                'resolved_by_user_id' => $staff->user_id,
                'auto_close_at'       => $this->autoCloseAt($now),
                'reminder_sent_at'    => null,
                'claimed_by_user_id'  => null,
                'claimed_at'          => null,
                'claim_expires_at'    => null,
                'sla_paused_at'       => null, // resolving straight from Pending Client ends the pause
                'first_responded_at'  => $locked->first_responded_at ?? $now,
                'last_activity_at'    => $now,
            ]);

            $this->events->record(
                $locked, TicketEvent::TYPE_RESOLVED, TicketSenderTypeEnum::Staff, $staff->user_id,
                $from, TicketStatusEnum::Resolved,
                ['resolution_note' => $note, 'previous_claimer' => $previousClaimer],
            );

            return $locked;
        });
    }

    /**
     * The requester confirms the resolution: Resolved -> Closed. Final.
     *
     * @throws \App\Exceptions\Tickets\TicketTransitionException not Resolved
     */
    public function confirm(Ticket $ticket, ?SystemUser $requester): Ticket
    {
        return DB::transaction(function () use ($ticket, $requester) {
            $locked = $this->lock($ticket);
            $now    = now();

            $this->machine->transition($locked, TicketStatusEnum::Closed, [
                'closed_at'        => $now,
                'close_reason'     => Ticket::CLOSE_REASON_CONFIRMED,
                'auto_close_at'    => null,
                'reminder_sent_at' => null,
                'last_activity_at' => $now,
            ]);

            $this->events->record(
                $locked, TicketEvent::TYPE_RESOLUTION_CONFIRMED, TicketSenderTypeEnum::Requester, $requester?->user_id,
                TicketStatusEnum::Resolved, TicketStatusEnum::Closed,
            );

            return $locked;
        });
    }

    /**
     * "This isn't resolved": the requester sends a Resolved ticket back to
     * In Progress. The only backward move, limited per ticket. An optional
     * reason is added to the thread.
     *
     * @throws TicketActionNotAllowedException the ticket is not Resolved
     * @throws TicketReturnLimitException      too many returns already
     */
    public function returnToProgress(Ticket $ticket, ?SystemUser $requester, ?string $reason = null): Ticket
    {
        $reason = $reason === null || trim($reason) === '' ? null : $this->normaliseBody($reason);

        return DB::transaction(function () use ($ticket, $requester, $reason) {
            $locked  = $this->lock($ticket);
            $actorId = $requester?->user_id;

            $this->applyReturn($locked, $actorId, 'button');

            if ($reason !== null) {
                $this->addMessage($locked, TicketSenderTypeEnum::Requester, $actorId, $reason);
            }

            return $locked;
        });
    }

    // =====================================================================
    // INTERNALS
    // =====================================================================

    /**
     * Resolved -> In Progress, shared by the button and a reply. Caller holds
     * the transaction and the row lock.
     *
     * The previous resolution note is cleared from the ticket so an In Progress
     * ticket does not show a stale "resolved" text, but it is not lost: it is in
     * the `resolved` event, and is repeated in this event for convenience.
     * The ticket comes back UNCLAIMED, so it reappears at the top of the queue
     * for whoever is free.
     */
    private function applyReturn(Ticket $locked, ?int $actorId, string $via): void
    {
        if ($locked->status !== TicketStatusEnum::Resolved) {
            throw new TicketActionNotAllowedException('send back', $locked->status);
        }

        $limit = (int) config('tickets.max_requester_returns');
        if ($locked->requester_returns >= $limit) {
            throw new TicketReturnLimitException($limit);
        }

        $previousNote = $locked->resolution_note;
        $returns      = $locked->requester_returns + 1;

        $now = now();

        $this->machine->transition($locked, TicketStatusEnum::InProgress, [
            'requester_returns' => $returns,
            'resolution_note'   => null,
            'resolved_at'       => null,
            'resolved_by_user_id' => null,
            'auto_close_at'     => null,
            'reminder_sent_at'  => null,
            'last_activity_at'  => $now,
        ] + $this->sla->restartAttributes($locked->priority, $now));

        $this->events->record(
            $locked, TicketEvent::TYPE_RETURNED, TicketSenderTypeEnum::Requester, $actorId,
            TicketStatusEnum::Resolved, TicketStatusEnum::InProgress,
            ['return_number' => $returns, 'via' => $via, 'previous_resolution_note' => $previousNote],
        );
    }

    /**
     * The atomic claim. Returns the status the ticket was in before the claim,
     * or null if nothing was claimed. Never reads first.
     *
     * Two statements, each a single conditional UPDATE: one for an Open ticket
     * (which also becomes In Progress) and one for the other claimable statuses
     * (status unchanged). Splitting them is what lets us know the previous
     * status without a SELECT, so the race stays closed on every database.
     * The target status comes from the state machine, not a literal.
     */
    private function claimAtomically(int|string $ticketId, SystemUser $staff): ?TicketStatusEnum
    {
        $now    = now();
        $expiry = $this->claimExpiresAt($now);

        $claim = [
            'claimed_by_user_id' => $staff->user_id,
            'claimed_at'         => $now,
            'claim_expires_at'   => $expiry,
            'last_activity_at'   => $now,
            'updated_at'         => $now,
        ];

        $base = fn () => DB::table('tickets')->where('id', $ticketId)->whereNull('claimed_by_user_id');

        $open = TicketStatusEnum::Open;
        if ($base()->where('status', $open->value)
            ->update($claim + ['status' => $this->machine->claimTarget($open)->value]) === 1) {
            return $open;
        }

        $others = array_values(array_filter(
            $this->machine->claimableStatuses(),
            static fn (TicketStatusEnum $s) => $s !== $open
        ));

        // Which of the remaining statuses it was is not needed by the caller
        // except for the event; one more tiny read after a WIN is race-free,
        // because the row is ours. Done only on success.
        if ($base()->whereIn('status', array_map(static fn ($s) => $s->value, $others))->update($claim) === 1) {
            return Ticket::query()->whereKey($ticketId)->firstOrFail()->status;
        }

        return null;
    }

    /**
     * Turn a failed claim into the right exception. Runs only after the
     * conditional UPDATE matched nothing, so a read here cannot reopen the race.
     *
     * @throws TicketClaimException
     * @throws TicketActionNotAllowedException
     */
    private function explainFailedClaim(int|string $ticketId): never
    {
        $ticket = Ticket::query()->findOrFail($ticketId);

        if ($ticket->claimed_by_user_id !== null) {
            throw TicketClaimException::alreadyClaimed((int) $ticket->claimed_by_user_id);
        }

        throw new TicketActionNotAllowedException('claim', $ticket->status);
    }

    /**
     * When a claim lapses if the claimer does nothing: the configured number of
     * WORKING hours on the business calendar (see SlaService). Null if the
     * calendar cannot say, in which case the claim just does not expire by
     * itself and staff release it by hand.
     */
    private function claimExpiresAt(CarbonInterface $from): ?CarbonInterface
    {
        return $this->sla->claimExpiresAt($from);
    }

    /**
     * Auto-close is counted in CALENDAR days (policy), not working days.
     */
    private function autoCloseAt(CarbonInterface $resolvedAt): CarbonInterface
    {
        return $resolvedAt->copy()->addDays((int) config('tickets.auto_close_days'));
    }

    private function lock(Ticket $ticket): Ticket
    {
        // A fresh, locked copy: the caller's instance may be stale, and the
        // state checks that follow must see the row as it is right now.
        return Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
    }

    private function addMessage(Ticket $ticket, TicketSenderTypeEnum $sender, ?int $senderUserId, string $body): TicketMessage
    {
        return TicketMessage::create([
            'ticket_id'      => $ticket->getKey(),
            'sender_type'    => $sender,
            'sender_user_id' => $senderUserId,
            'body'           => $body,
        ]);
    }

    /**
     * Service-level backstop for the Form Requests' rules: never store an empty
     * or oversized message, whatever called us.
     */
    private function normaliseBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A message cannot be empty.');
        }
        if (mb_strlen($body) > (int) config('tickets.content.message_max_length')) {
            throw new InvalidArgumentException('That message is too long.');
        }

        return $body;
    }
}