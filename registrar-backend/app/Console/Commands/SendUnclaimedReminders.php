<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\SystemUser;
use App\Contracts\NotificationServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| SendUnclaimedReminders
|--------------------------------------------------------------------------
| Runs daily via the Laravel scheduler (see routes/console.php).
|
| Policy
|   When a document request has been in "ReadyToClaim" status for exactly
|   7 days and has not been claimed, the student receives a reminder that
|   their documents will be shredded 83 days from now (90-day policy).
|
| Window approach
|   "Exactly 7 days" is implemented as a 24-hour catch window:
|     changed_at BETWEEN (now - 8 days) AND (now - 7 days)
|   This ensures a once-daily run never misses nor double-fires regardless
|   of the exact time the cron runs within the day.
|
| Idempotency
|   Before sending, we check whether a reminder_claim notification already
|   exists for this request_id. Re-running after a crash is therefore safe
|   and will not double-notify the student.
|--------------------------------------------------------------------------
*/

class SendUnclaimedReminders extends Command
{
    use LogsJobRun;

    protected $signature   = 'notifications:send-unclaimed-reminders';
    protected $description = 'Remind students whose ReadyToClaim request has been unclaimed for 7 days';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. Logic below is
     * unchanged; only the outer try/catch and the two logging calls
     * around it are new.
     */
    public function handle(NotificationServiceInterface $notificationService): int
    {
        $this->startJobRun($this->getName());

        try {
            $sent = $this->sendReminders($notificationService);
            $this->finishJobRun(self::SUCCESS, $sent);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function sendReminders(NotificationServiceInterface $notificationService): int
    {
        $windowEnd   = Carbon::now()->subDays(7);
        $windowStart = Carbon::now()->subDays(8);

        $requests = DocumentRequest::query()
            ->where('status_id', RequestStatusEnum::ReadyToClaim->value)
            ->whereNull('deleted_at')
            ->whereHas('history', function ($q) use ($windowStart, $windowEnd) {
                $q->where('new_status_id', RequestStatusEnum::ReadyToClaim->value)
                  ->whereBetween('changed_at', [$windowStart, $windowEnd]);
            })
            ->whereDoesntHave('notifications', function ($q) {
                $q->whereHas('type', fn ($t) => $t->where('trigger_event', 'reminder_claim'));
            })
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($requests as $request) {
            /** @var SystemUser $owner */
            $owner = $request->user;
            if (!$owner instanceof SystemUser) {
                continue;
            }

            $notificationService->send(
                recipient:    $owner,
                triggerEvent: 'reminder_claim',
                data:         ['request_id' => $request->request_id],
                requestId:    $request->request_id,
            );

            $sent++;
            Log::info('[SendUnclaimedReminders] reminder sent', [
                'request_id' => $request->request_id,
                'user_id'    => $owner->user_id,
            ]);
        }

        $this->info("[SendUnclaimedReminders] {$sent} reminder(s) sent.");
        return $sent;
    }
}