<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Services\Tickets\TicketSlaMonitor;
use Illuminate\Console\Command;

/**
 * Support Ticketing — Phase 5. Flags tickets that are at risk of, or have
 * breached, their first-response or resolution target.
 *
 * Idempotent: a ticket is flagged once, so running it twice in a row flags
 * nothing the second time (see TicketSlaMonitor). Recorded in job_run_logs via
 * LogsJobRun; rows_affected is the number of NEW flags set.
 */
class TicketSlaSweep extends Command
{
    use LogsJobRun;

    protected $signature   = 'tickets:sla-sweep';
    protected $description = 'Flag support tickets that are at risk of, or have breached, their SLA';

    public function handle(TicketSlaMonitor $monitor): int
    {
        $this->startJobRun($this->getName());

        try {
            $flagged = $monitor->run();
            $total   = $flagged['at_risk'] + $flagged['breached'];

            $this->info("Flagged {$flagged['at_risk']} at-risk and {$flagged['breached']} breached ticket target(s).");
            $this->finishJobRun(self::SUCCESS, $total);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
