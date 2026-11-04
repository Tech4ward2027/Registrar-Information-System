<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Contracts\NotificationServiceInterface;
use App\Models\Notification;
use App\Models\SystemUser;
use App\Services\RequestDocumentNames;
use App\Services\UnclaimedPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| SendForfeitureNotices
|--------------------------------------------------------------------------
| Runs daily (Manila time) via the scheduler, BEFORE ShredExpiredRequests.
|
| Policy (§3.5, v1.2.0)
|   Day 89 after a request becomes Ready to Claim, the requestor receives
|   the Final Notice by email, web push and in-app: the documents will be
|   forfeited and shredded tomorrow. Day 90 is the forfeiture itself.
|
| Day counting
|   See UnclaimedPolicy: calendar days in Manila time from the day the
|   request most recently entered Ready to Claim.
|
| Catch-up
|   The query is "Day 89 or later with no notice yet", not "exactly Day 89",
|   so a missed scheduler run or a request that was already past Day 89 when
|   this shipped still gets its notice. ShredExpiredRequests will not forfeit
|   a request until its notice went out on an EARLIER calendar day, so a
|   catch-up notice always leaves the requestor at least one full day.
|
| Idempotency
|   A request already holding a reminder_final_warning notification created
|   since its latest Ready to Claim transition is skipped, including when the
|   requestor deleted that notification from their inbox. If the request is
|   cycled back through Processing and becomes Ready to Claim again, the
|   clock and the notice both restart.
|--------------------------------------------------------------------------
*/

class SendForfeitureNotices extends Command
{
    use LogsJobRun;

    public const TRIGGER = 'reminder_final_warning';

    protected $signature   = 'notifications:send-forfeiture-notices';
    protected $description = 'Send the Day 89 final forfeiture notice for unclaimed Ready to Claim requests';

    public function handle(NotificationServiceInterface $notificationService): int
    {
        $this->startJobRun($this->getName());

        try {
            $sent = $this->sendNotices($notificationService);
            $this->finishJobRun(self::SUCCESS, $sent);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function sendNotices(NotificationServiceInterface $notificationService): int
    {
        $requests = UnclaimedPolicy::readyRequestsBefore(
            UnclaimedPolicy::cutoff(UnclaimedPolicy::FINAL_NOTICE_DAY)
        )->with('user')->get();

        $sent = 0;

        foreach ($requests as $request) {
            $owner = $request->user;
            if (! $owner instanceof SystemUser) {
                continue;
            }

            if (self::noticeExistsSince($request->request_id, UnclaimedPolicy::readyAt($request))) {
                continue;
            }

            [$names, $plural] = RequestDocumentNames::forRequest($request->request_id);

            $notification = $notificationService->send(
                recipient:    $owner,
                triggerEvent: self::TRIGGER,
                data:         [
                    'request_id'    => $request->request_id,
                    'document_name' => $names,
                    'document_noun' => $plural ? 'documents' : 'document',
                ],
                requestId:    $request->request_id,
            );

            if ($notification === null) {
                continue; // send() already logged why; next run retries
            }

            $sent++;
            Log::info('[SendForfeitureNotices] final notice sent', [
                'request_id' => $request->request_id,
                'user_id'    => $owner->user_id,
            ]);
        }

        $this->info("[SendForfeitureNotices] {$sent} final notice(s) sent.");

        return $sent;
    }

    /**
     * Has a final notice been created for this request since it last became
     * Ready to Claim? Soft-deleted rows count: deleting the inbox entry must
     * not cause a second notice.
     */
    public static function noticeExistsSince(int $requestId, \DateTimeInterface $readyAt, ?\DateTimeInterface $before = null): bool
    {
        return Notification::withTrashed()
            ->where('request_id', $requestId)
            ->whereHas('type', fn ($t) => $t->where('trigger_event', self::TRIGGER))
            ->where('created_at', '>=', $readyAt->format('Y-m-d H:i:s'))
            ->when($before, fn ($q) => $q->where('created_at', '<', $before->format('Y-m-d H:i:s')))
            ->exists();
    }
}
