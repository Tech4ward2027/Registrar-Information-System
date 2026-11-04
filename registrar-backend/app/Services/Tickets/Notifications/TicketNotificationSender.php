<?php

namespace App\Services\Tickets\Notifications;

use App\Contracts\NotificationServiceInterface;
use App\Enums\TicketNotificationKindEnum as Kind;
use App\Mail\TicketNotificationMail;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\PublicSubmission\TicketAccessTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use App\Services\Mail\MailDispatcher;

/**
 * Support Ticketing — Phase 6. Works out WHO gets a ticket notification and
 * delivers it; runs inside SendTicketNotificationJob.
 *
 * Requester kinds
 *   signed-in requester   one bell entry (through NotificationService, which also
 *                         pushes it over Reverb and, for urgent types, Web Push)
 *                         AND one email.
 *   public submitter      one email only. They have no account, so no bell. The
 *                         email carries a fresh magic link: only the hash of a
 *                         link is stored, so an old one cannot be re-sent.
 *   Nobody is notified when the requester's account is gone or not Activated,
 *   or a public ticket has been anonymized (no address left).
 *
 * Staff kinds (bell only, no email)
 *   requester reply / claim expired / returned   the one person it concerns
 *                         (the claimer, the previous claimer, the resolver)
 *   SLA at risk / breached   the claimer, or when nobody holds the ticket, staff
 *                         who can Manage tickets
 *   Every staff recipient is re-checked against TicketPolicy at send time: a
 *   notification is never sent about a ticket the person can no longer open.
 *
 * What is NOT in an email: the text of any reply. Email is not a private
 * channel; the message waits behind the sign-in (or the magic link).
 */
class TicketNotificationSender
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly TicketAccessTokenService $accessTokens,
        private readonly MailDispatcher $mailer,
    ) {
    }

    /**
     * @param  array<string,scalar|null>  $context
     */
    public function deliver(int|string $ticketId, Kind $kind, string $ref, array $context = []): void
    {
        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null) {
            return;
        }

        $data = $this->templateData($ticket, $kind, $context);

        if ($kind->isForRequester()) {
            $this->toRequester($ticket, $kind, $ref, $data);

            return;
        }

        foreach ($this->staffRecipients($ticket, $kind, $context) as $user) {
            $this->inApp($user, $ticket, $kind, $ref, $data);
        }
    }

    // ── Requester ────────────────────────────────────────────────────────

    private function toRequester(Ticket $ticket, Kind $kind, string $ref, array $data): void
    {
        $emailOn = (bool) config('tickets.notifications.email_enabled') && $kind->sendsEmail();

        if ($ticket->requester_user_id !== null) {
            $user = SystemUser::query()->find($ticket->requester_user_id);

            if ($user === null || $user->status !== 'Activated') {
                return;
            }

            $this->inApp($user, $ticket, $kind, $ref, $data);

            if ($emailOn && filled($user->email)) {
                $this->once($ref, 'mail:' . $user->user_id, fn () => $this->queueMail(
                    (string) $user->email, $kind, $ticket, $this->accountUrl($ticket), false, $data,
                ));
            }

            return;
        }

        // A public submitter: email only, with a fresh magic link.
        if ($emailOn && $ticket->anonymized_at === null && filled($ticket->requester_email)
            && $this->accessTokens->linkStillValid($ticket)) {
            $this->once($ref, 'mail:public', fn () => $this->queueMail(
                (string) $ticket->requester_email, $kind, $ticket,
                $this->accessTokens->trackUrl($this->accessTokens->issue($ticket)), true, $data,
            ));
        }
    }

    // ── Staff ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,scalar|null>  $context
     * @return iterable<SystemUser>
     */
    private function staffRecipients(Ticket $ticket, Kind $kind, array $context): iterable
    {
        $ids = match ($kind) {
            Kind::RequesterReply => [$ticket->claimed_by_user_id],
            Kind::Returned       => [$context['resolver'] ?? null],
            Kind::ClaimExpired   => [$context['previous_claimer'] ?? null],
            Kind::SlaAtRisk, Kind::SlaBreached => $ticket->claimed_by_user_id !== null ? [$ticket->claimed_by_user_id] : null,
            default              => [],
        };

        // null = "the claimer if there is one, otherwise the managers".
        if ($ids === null) {
            yield from $this->managers();

            return;
        }

        foreach (array_filter($ids) as $id) {
            $user = SystemUser::query()->find($id);

            if ($user !== null && $this->canSeeQueue($user)) {
                yield $user;
            }
        }
    }

    /** Active staff who can see the queue and manage tickets (Super Admins included, via the policy). */
    private function managers(): iterable
    {
        $staff = SystemUser::query()
            ->where('status', 'Activated')
            ->whereIn('role_id', [SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN])
            ->cursor();

        foreach ($staff as $user) {
            if ($this->canSeeQueue($user) && Gate::forUser($user)->allows('manage', Ticket::class)) {
                yield $user;
            }
        }
    }

    private function canSeeQueue(SystemUser $user): bool
    {
        return $user->status === 'Activated'
            && ($user->isAdmin() || $user->isSuperAdmin())
            && Gate::forUser($user)->allows('viewQueue', Ticket::class);
    }

    // ── Delivery ─────────────────────────────────────────────────────────

    private function inApp(SystemUser $user, Ticket $ticket, Kind $kind, string $ref, array $data): void
    {
        $this->once($ref, 'bell:' . $user->user_id, function () use ($user, $ticket, $kind, $data) {
            // No request_id: tickets are not document requests. The ticket's
            // public id rides in `data`, and NotificationResource exposes it so
            // the bell can open the ticket.
            $this->notifications->send(
                recipient:    $user,
                triggerEvent: $kind->triggerEvent(),
                data:         $data + ['ticket_public_id' => $ticket->public_id],
            );
        });
    }

    private function queueMail(string $to, Kind $kind, Ticket $ticket, string $url, bool $isPublic, array $data): void
    {
        $this->mailer->sendApproved('ticket_notification', $to, new TicketNotificationMail(
            kind:         $kind->value,
            ticketNumber: $ticket->ticket_number,
            url:          $url,
            isPublic:     $isPublic,
            closeDate:    $data['close_date'] ?? null,
        ));
    }

    /**
     * Run $send unless this (ref, channel) was already delivered; record it once
     * it has. A job retried after a partial failure therefore resends only what
     * had not gone out.
     */
    private function once(string $ref, string $channel, callable $send): void
    {
        $key = "tickets:notified:{$ref}:{$channel}";

        if (Cache::has($key)) {
            return;
        }

        $send();

        Cache::put($key, 1, now()->addHours(max(1, (int) config('tickets.notifications.dedupe_ttl_hours'))));
    }

    // ── Wording and links ────────────────────────────────────────────────

    /**
     * Values for the :placeholders in the notification_types template.
     *
     * @param  array<string,scalar|null>  $context
     * @return array<string,scalar|null>
     */
    private function templateData(Ticket $ticket, Kind $kind, array $context): array
    {
        $data = ['ticket_number' => $ticket->ticket_number];

        $closeAt = $context['auto_close_at'] ?? $ticket->auto_close_at?->toIso8601String();
        if ($closeAt !== null) {
            $data['close_date'] = Carbon::parse($closeAt)
                ->setTimezone(config('app.display_timezone', 'Asia/Manila'))
                ->format('F j, Y');
        }

        if ($kind === Kind::SlaAtRisk || $kind === Kind::SlaBreached) {
            $data['deadline'] = ($context['target'] ?? '') === Ticket::SLA_FIRST_RESPONSE ? 'first response' : 'resolution';
        }

        if ($kind === Kind::ClaimExpired) {
            $data['timeout_hours'] = (int) config('tickets.claim_timeout_working_hours');
        }

        return $data;
    }

    /** Where a signed-in requester's email links. */
    private function accountUrl(Ticket $ticket): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . str_replace('{public_id}', $ticket->public_id, (string) config('tickets.notifications.requester_path'));
    }
}