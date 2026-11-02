<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use Illuminate\Console\Command;

/**
 * Support Ticketing — Phase 5. Releases claims whose claimer has been silent for
 * the configured working hours (config 'tickets.claim_timeout_working_hours',
 * default 24), so an abandoned claim cannot hide a ticket from the rest of the
 * team. Each release is logged as a claim_expired event by the system.
 *
 * Idempotent and safe against a human acting at the same moment: every ticket is
 * re-checked under its row lock inside TicketService::expireClaim(). A Pending
 * Client ticket is never released (the wait is on the requester).
 */
class ReleaseStaleTicketClaims extends Command
{
    use LogsJobRun;

    protected $signature   = 'tickets:release-stale-claims';
    protected $description = 'Release support ticket claims that have gone stale';

    public function handle(TicketService $tickets): int
    {
        $this->startJobRun($this->getName());

        try {
            $now      = now();
            $released = 0;

            Ticket::query()
                ->active()
                ->where('status', '!=', \App\Enums\TicketStatusEnum::PendingClient->value)
                ->whereNotNull('claimed_by_user_id')
                ->whereNotNull('claim_expires_at')
                ->where('claim_expires_at', '<=', $now)
                ->chunkById(max(1, (int) config('tickets.sweep_chunk_size')), function ($stale) use ($tickets, $now, &$released) {
                    foreach ($stale as $ticket) {
                        if ($tickets->expireClaim($ticket, $now)) {
                            $released++;
                        }
                    }
                });

            $this->info("Released {$released} stale ticket claim(s).");
            $this->finishJobRun(self::SUCCESS, $released);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
