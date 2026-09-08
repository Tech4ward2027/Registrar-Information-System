<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Contracts\NotificationServiceInterface;
use App\Models\RequestRemark;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| EscalateStaleDeficiencyNotices
|--------------------------------------------------------------------------
| Runs daily via the Laravel scheduler (see routes/console.php).
|
| Policy — Data Retention & Disposal Policy §3.3 ("Deficiency Notice
| compliance window (NEW) — 30 Days"):
|   "A Deficiency Notice that remains unresolved 30 days after issuance
|   is escalated for review by the Registrar Admin, who determines
|   whether to extend the window, close the request as abandoned, or
|   close the request as permanently unresolvable."
|
| This command performs ONLY the escalation half of that sentence —
| flagging the notice and notifying admins that a decision is needed.
| It never auto-decides extend/abandon/close-unresolvable; that always
| stays a manual staff decision (extend = simply leave the notice open
| and take no system action; abandon = an ordinary Withdrawn via
| DocumentRequestService::withdraw(); permanently unresolvable = a
| ClosedUnableToProcess via DocumentRequestService::closeUnableToProcess(),
| both invoked separately by staff after reviewing the escalation).
|
| Distinct from Phase 4's 14-day dashboard staleness badge
| (RequestRemark::STALE_AFTER_DAYS, RequestRemark::getIsStaleAttribute())
| — see this command's and RequestRemark::ESCALATE_AFTER_DAYS's
| docblocks for the full reasoning on why 14 days is a pure UI read-time
| computation while 30 days needs an actual job. A notice that has
| reached 30 days has necessarily already been showing the 14-day
| staleness badge for two weeks; escalation does not replace that badge,
| it adds a second, more consequential signal on top of it once the
| policy's real compliance window elapses.
|
| Idempotency
|   escalated_at IS NULL is part of the WHERE clause, so a notice is
|   only ever escalated (and only ever triggers the admin notification)
|   once, no matter how many times this command runs. Clearing or
|   voiding a notice after escalation does not un-escalate it — the
|   escalated_at timestamp is a historical record of "this crossed the
|   30-day line," not a live flag that needs to track the notice's
|   current status.
|
| Audience
|   Sent to admins (sendToAdmins — 'admin' audience notification type),
|   NOT to the requestor. The requestor already has the original
|   deficiency_notice_issued notification; a 30-day escalation is an
|   internal Registrar Admin workflow signal, not new information the
|   requestor needs.
|--------------------------------------------------------------------------
*/

class EscalateStaleDeficiencyNotices extends Command
{
    use LogsJobRun;

    protected $signature   = 'notifications:escalate-stale-deficiency-notices';
    protected $description = 'Escalate open Deficiency Notices past the 30-day compliance window for Registrar Admin review';

    public function handle(NotificationServiceInterface $notificationService): int
    {
        $this->startJobRun($this->getName());

        try {
            $escalated = $this->escalateStaleNotices($notificationService);
            $this->finishJobRun(self::SUCCESS, $escalated);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function escalateStaleNotices(NotificationServiceInterface $notificationService): int
    {
        $cutoff = Carbon::now()->subDays(RequestRemark::ESCALATE_AFTER_DAYS);

        $notices = RequestRemark::query()
            ->where('status', RequestRemark::STATUS_OPEN)
            ->whereNull('escalated_at')
            ->where('issued_at', '<=', $cutoff)
            ->get();

        $escalated = 0;

        foreach ($notices as $notice) {
            DB::transaction(function () use ($notice, $notificationService, &$escalated) {
                // Re-fetch and lock inside the transaction — a concurrent
                // clear()/void() could have resolved this notice between
                // the SELECT above and this lock.
                $locked = RequestRemark::lockForUpdate()->find($notice->remark_id);

                if (!$locked || $locked->status !== RequestRemark::STATUS_OPEN || $locked->escalated_at !== null) {
                    return;
                }

                $locked->update(['escalated_at' => now()]);

                $notificationService->sendToAdmins(
                    triggerEvent: 'deficiency_notice_escalated',
                    data:         [
                        'request_id' => $locked->request_id,
                        'item_label' => $locked->item_label,
                    ],
                    requestId:    $locked->request_id,
                );

                $escalated++;
                Log::info('[EscalateStaleDeficiencyNotices] notice escalated', [
                    'remark_id'  => $locked->remark_id,
                    'request_id' => $locked->request_id,
                    'issued_at'  => $locked->issued_at?->toDateTimeString(),
                ]);
            });
        }

        $this->info("[EscalateStaleDeficiencyNotices] {$escalated} notice(s) escalated.");

        return $escalated;
    }
}
