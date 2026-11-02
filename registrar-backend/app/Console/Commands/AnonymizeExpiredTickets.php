<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Tickets\TicketEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| AnonymizeExpiredTickets  (php artisan tickets:anonymize-expired)
|--------------------------------------------------------------------------
| Support Ticketing — Phase 7. The 3-year retention step (RA 10173: keep
| personal data only as long as it is needed).
|
| A ticket becomes eligible when it has been Closed for
| config('tickets.retention.years') years (default 3). For each one, in a single
| transaction under the ticket's row lock:
|
|   - Public submitter: requester_email, requester_name and requester_ip are
|     set to NULL. [DEFAULT] — public submitters' email and IP are anonymized at
|     the 3-year mark.
|   - Signed-in requester: the link to their account (requester_user_id) is
|     cleared, and so is the link on their own messages.
|   - Every magic-link token for the ticket is deleted, and so are the
|     confirmed submission rows that still hold the submitter's email and IP.
|     A link can no longer open an anonymized ticket in any case (see
|     TicketAccessTokenService::linkStillValid()); deleting the rows removes the
|     hashes too.
|   - anonymized_at is stamped, and an "anonymized" event is appended to the
|     ticket's hash chain, so the retention action is itself tamper-evident.
|
| What is deliberately KEPT: the ticket, its messages and its event log. The
| event log is hash-chained, so deleting or editing events would break
| audit:verify. It holds no email, name or IP (events record ids, statuses and
| the system's own notes); the one personal pointer in it, actor_user_id on a
| signed-in requester's events, is an id only and cannot be rewritten without
| invalidating the hashes.
|
| Idempotent: only tickets with anonymized_at IS NULL are selected, and each is
| re-checked under its lock, so a second or overlapping run changes nothing.
| A failure on one ticket is logged and skipped, and fails the run.
|
| --dry-run reports how many tickets are due and changes nothing.
|--------------------------------------------------------------------------
*/
class AnonymizeExpiredTickets extends Command
{
    use LogsJobRun;

    protected $signature   = 'tickets:anonymize-expired {--dry-run : Report how many tickets are due without changing anything}';
    protected $description = 'Anonymize requester personal data on support tickets past the retention period';

    public function handle(TicketEventRecorder $events): int
    {
        $this->startJobRun($this->getName());

        try {
            $years  = max(1, (int) config('tickets.retention.years'));
            $cutoff = now()->subYears($years);
            $chunk  = max(1, (int) config('tickets.retention.chunk_size'));
            $dryRun = (bool) $this->option('dry-run');

            $due        = 0;
            $anonymized = 0;
            $failures   = [];

            Ticket::query()
                ->where('status', TicketStatusEnum::Closed->value)
                ->whereNull('anonymized_at')
                ->whereNotNull('closed_at')
                ->where('closed_at', '<=', $cutoff)
                ->chunkById($chunk, function ($tickets) use ($events, $cutoff, $years, $dryRun, &$due, &$anonymized, &$failures) {
                    foreach ($tickets as $ticket) {
                        $due++;

                        if ($dryRun) {
                            continue;
                        }

                        try {
                            $anonymized += $this->anonymize($ticket, $events, $cutoff, $years) ? 1 : 0;
                        } catch (\Throwable $e) {
                            Log::error('tickets:anonymize-expired — could not anonymize a ticket.', [
                                'ticket_id' => $ticket->getKey(),
                                'error'     => $e->getMessage(),
                            ]);
                            $failures[] = "ticket #{$ticket->getKey()}: " . class_basename($e);
                        }
                    }
                });

            if ($dryRun) {
                $this->info("tickets:anonymize-expired — dry run: {$due} ticket(s) closed {$years}+ year(s) ago are due. Nothing changed.");
                $this->finishJobRun(self::SUCCESS, 0);

                return self::SUCCESS;
            }

            $this->info("tickets:anonymize-expired — anonymized {$anonymized} ticket(s) closed {$years}+ year(s) ago.");

            if ($failures !== []) {
                $this->error(count($failures) . ' ticket(s) could not be anonymized: ' . implode(' | ', $failures));
                $this->finishJobRun(self::FAILURE, $anonymized, count($failures) . ' ticket(s) failed: ' . implode(' | ', $failures));

                return self::FAILURE;
            }

            $this->finishJobRun(self::SUCCESS, $anonymized);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    /**
     * Returns true only when THIS call anonymized the ticket.
     */
    private function anonymize(Ticket $ticket, TicketEventRecorder $events, \Carbon\CarbonInterface $cutoff, int $years): bool
    {
        return DB::transaction(function () use ($ticket, $events, $cutoff, $years) {
            // Same lock the event recorder takes, so this cannot interleave with
            // anything else appending to this ticket's chain.
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== TicketStatusEnum::Closed
                || $locked->anonymized_at !== null
                || $locked->closed_at === null
                || $locked->closed_at->greaterThan($cutoff)) {
                return false;
            }

            $id           = $locked->getKey();
            $hadAccount   = $locked->requester_user_id !== null;
            $now          = now();

            // Query-builder writes on purpose: these are retention writes, not
            // business edits, and they must not fire the model's save hooks or
            // touch last_activity_at (which would make the ticket look active).
            DB::table('tickets')->where('id', $id)->update([
                'requester_user_id' => null,
                'requester_name'    => null,
                'requester_email'   => null,
                'requester_ip'      => null,
                'anonymized_at'     => $now,
                'updated_at'        => $now,
            ]);

            if ($hadAccount) {
                DB::table('ticket_messages')
                    ->where('ticket_id', $id)
                    ->where('sender_type', TicketSenderTypeEnum::Requester->value)
                    ->update(['sender_user_id' => null]);
            }

            DB::table('ticket_access_tokens')->where('ticket_id', $id)->delete();

            if (Schema::hasTable('ticket_public_submissions')) {
                DB::table('ticket_public_submissions')->where('ticket_id', $id)->delete();
            }

            $events->record(
                $locked, TicketEvent::TYPE_ANONYMIZED, TicketSenderTypeEnum::System, null,
                null, null,
                ['had_account' => $hadAccount, 'retention_years' => $years],
            );

            return true;
        });
    }
}
