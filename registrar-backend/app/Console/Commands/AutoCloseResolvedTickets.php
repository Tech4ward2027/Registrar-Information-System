<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Support Ticketing — Phase 7. Closes Resolved tickets whose window has run out
 * and sends the day-2 reminder to the ones that are still waiting.
 *
 *   1. Close:    Resolved and auto_close_at has passed  -> Closed (reason "auto"),
 *                the requester is told it closed.
 *   2. Remind:   Resolved, reminder_after_days (2) old, window still open,
 *                reminder not sent yet                  -> one reminder.
 *
 * Closing runs first so a ticket the scheduler missed for a day is closed, not
 * sent a reminder about something already over.
 *
 * Idempotent: both steps re-check the ticket under its row lock inside
 * TicketService, so a second run (or an overlapping run, or a requester
 * confirming at the same moment) changes nothing. A failure on one ticket is
 * logged and skipped, so it cannot stop the rest; it still makes the run fail,
 * so the job-health panel shows it. rows_affected = tickets closed + reminders.
 */
class AutoCloseResolvedTickets extends Command
{
    use LogsJobRun;

    protected $signature   = 'tickets:auto-close';
    protected $description = 'Close support tickets whose Resolved window has ended, and send the day-2 reminder';

    public function handle(TicketService $tickets): int
    {
        $this->startJobRun($this->getName());

        try {
            $now      = now();
            $chunk    = max(1, (int) config('tickets.sweep_chunk_size'));
            $closed   = 0;
            $reminded = 0;
            $failures = [];

            Ticket::query()
                ->where('status', TicketStatusEnum::Resolved->value)
                ->whereNotNull('auto_close_at')
                ->where('auto_close_at', '<=', $now)
                ->chunkById($chunk, function ($due) use ($tickets, $now, &$closed, &$failures) {
                    foreach ($due as $ticket) {
                        try {
                            $closed += $tickets->autoClose($ticket, $now) ? 1 : 0;
                        } catch (\Throwable $e) {
                            $failures[] = $this->report('auto-close', $ticket, $e);
                        }
                    }
                });

            // Resolved at or before this moment means the reminder age is reached.
            // The exact test (resolved_at + days <= now) is repeated under the lock.
            $remindBefore = $now->copy()->subDays((int) config('tickets.reminder_after_days'));

            Ticket::query()
                ->where('status', TicketStatusEnum::Resolved->value)
                ->whereNull('reminder_sent_at')
                ->where('resolved_at', '<=', $remindBefore)
                ->where('auto_close_at', '>', $now)
                ->chunkById($chunk, function ($waiting) use ($tickets, $now, &$reminded, &$failures) {
                    foreach ($waiting as $ticket) {
                        try {
                            $reminded += $tickets->sendResolutionReminder($ticket, $now) ? 1 : 0;
                        } catch (\Throwable $e) {
                            $failures[] = $this->report('reminder', $ticket, $e);
                        }
                    }
                });

            $this->info("Closed {$closed} ticket(s), sent {$reminded} reminder(s).");

            if ($failures !== []) {
                $this->error(count($failures) . ' ticket(s) could not be processed: ' . implode(' | ', $failures));
                $this->finishJobRun(self::FAILURE, $closed + $reminded, count($failures) . ' ticket(s) failed: ' . implode(' | ', $failures));

                return self::FAILURE;
            }

            $this->finishJobRun(self::SUCCESS, $closed + $reminded);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function report(string $step, Ticket $ticket, \Throwable $e): string
    {
        Log::error("tickets:auto-close — {$step} failed.", [
            'ticket_id' => $ticket->getKey(),
            'error'     => $e->getMessage(),
        ]);

        return "{$step} ticket #{$ticket->getKey()}: " . class_basename($e);
    }
}
