<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\Tickets\TicketChainVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| VerifyAuditChain  (php artisan audit:verify)
|--------------------------------------------------------------------------
| Walks every audit_logs row in id order and recomputes each row's hash
| from (prev_hash + that row's own action/user_id/target_user_id/
| target_email/created_at), using the exact same algorithm AuditLogger::log()
| used to write it (AuditLogger::computeHash() is the single shared
| implementation — see its docblock).
|
| A row fails verification if either:
|   - its stored prev_hash doesn't match the previous row's stored hash
|     (a row was inserted, deleted, or reordered out of sequence), or
|   - its stored hash doesn't match what recomputing from its own fields
|     produces (the row itself was altered after being written).
|
| Support Ticketing (Phase 7): the same run also verifies every ticket's own
| hash-chained event log (ticket_events), through TicketChainVerifier. Each
| ticket has its own chain, so a break is reported with the ticket id and the
| event sequence number. Either kind of break makes the command exit non-zero.
|
| Intended for CI and a scheduled cron/monitoring check, not interactive
| use only — exits non-zero on any break so it can gate a pipeline or fire
| an alert.
|--------------------------------------------------------------------------
*/
class VerifyAuditChain extends Command
{
    use LogsJobRun;

    protected $signature   = 'audit:verify {--chunk=1000 : Rows to load per DB round-trip}';
    protected $description = 'Verify the audit_logs tamper-evident hash chain is unbroken';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. This command has
     * two intentional (non-exception) exit points, so each one calls
     * finishJobRun() directly with its own exit code — same pattern as
     * TestBreakGlassAccess. The outer try/catch still exists to catch
     * genuine uncaught exceptions (e.g. a DB error mid-chunk).
     */
    public function handle(AuditLogger $auditLogger, TicketChainVerifier $ticketVerifier): int
    {
        $this->startJobRun($this->getName());

        try {
            return $this->verifyChain($auditLogger, $ticketVerifier);
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function verifyChain(AuditLogger $auditLogger, TicketChainVerifier $ticketVerifier): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        $previousHash  = '0';
        $rowsChecked   = 0;
        $breaks        = [];

        AuditLog::orderBy('id')
            ->select(['id', 'user_id', 'target_user_id', 'target_email', 'action', 'created_at', 'prev_hash', 'hash'])
            ->chunkById($chunkSize, function ($rows) use (&$previousHash, &$rowsChecked, &$breaks, $auditLogger) {
                foreach ($rows as $row) {
                    $rowsChecked++;

                    $storedPrevHash = $row->prev_hash ?? '0';
                    $storedHash     = $row->hash;

                    $expectedHash = $auditLogger->computeHash($storedPrevHash, [
                        'action'         => $row->action,
                        'user_id'        => $row->user_id,
                        'target_user_id' => $row->target_user_id,
                        'target_email'   => $row->target_email,
                        'created_at'     => (string) $row->created_at,
                    ]);

                    if ($storedPrevHash !== $previousHash) {
                        $breaks[] = [
                            'id'     => $row->id,
                            'reason' => "prev_hash mismatch: stored='{$storedPrevHash}' expected='{$previousHash}' (chain link to the prior row is broken)",
                        ];
                    }

                    if ($storedHash !== $expectedHash) {
                        $breaks[] = [
                            'id'     => $row->id,
                            'reason' => "hash mismatch: stored='{$storedHash}' recomputed='{$expectedHash}' (this row's own fields were altered after being written)",
                        ];
                    }

                    // Continue the walk from what's actually stored, not
                    // the expected value — so a single broken row is
                    // reported once, not cascaded into a false positive
                    // for every row after it.
                    $previousHash = $storedHash;
                }
            });

        // Support Ticketing (Phase 7). Skipped only on an install that has not
        // run the ticket migrations yet, so audit:verify never fails for that.
        $ticketResult = Schema::hasTable('ticket_events')
            ? $ticketVerifier->verifyAll($chunkSize)
            : ['tickets' => 0, 'events' => 0, 'breaks' => []];

        $ticketBreaks = $ticketResult['breaks'];

        if (empty($breaks) && empty($ticketBreaks)) {
            $this->info("[audit:verify] OK — {$rowsChecked} row(s) verified, chain intact.");
            $this->info("[audit:verify] OK — {$ticketResult['events']} ticket event(s) across {$ticketResult['tickets']} ticket(s) verified, chains intact.");
            $this->finishJobRun(self::SUCCESS, $rowsChecked + $ticketResult['events']);
            return self::SUCCESS;
        }

        $totalBreaks = count($breaks) + count($ticketBreaks);

        $this->error('[audit:verify] FAILED — ' . $totalBreaks . ' issue(s) found across ' . $rowsChecked . ' audit row(s) and ' . $ticketResult['events'] . ' ticket event(s) checked:');

        $breakSummaries = [];

        foreach ($breaks as $break) {
            $this->line("  - audit_logs.id={$break['id']}: {$break['reason']}");

            Log::critical('[audit:verify] audit log chain integrity failure', [
                'audit_log_id' => $break['id'],
                'reason'       => $break['reason'],
            ]);

            $breakSummaries[] = "id={$break['id']}: {$break['reason']}";
        }

        foreach ($ticketBreaks as $break) {
            $where = $break['seq'] === null ? "ticket_id={$break['ticket_id']}" : "ticket_id={$break['ticket_id']} seq={$break['seq']}";

            $this->line("  - ticket_events {$where}: {$break['reason']}");

            Log::critical('[audit:verify] ticket event chain integrity failure', [
                'ticket_id' => $break['ticket_id'],
                'seq'       => $break['seq'],
                'reason'    => $break['reason'],
            ]);

            $breakSummaries[] = "ticket {$where}: {$break['reason']}";
        }

        $this->finishJobRun(
            self::FAILURE,
            $totalBreaks,
            $totalBreaks . ' break(s) found across ' . $rowsChecked . ' audit row(s) and ' . $ticketResult['events'] . ' ticket event(s) — ' . implode(' | ', $breakSummaries),
        );

        return self::FAILURE;
    }
}