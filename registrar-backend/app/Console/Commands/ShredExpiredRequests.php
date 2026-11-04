<?php

namespace App\Console\Commands;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestHistory;
use App\Models\SystemUser;
use App\Console\Commands\Concerns\LogsJobRun;
use App\Contracts\NotificationServiceInterface;
use App\Services\Concerns\FlushesAnalyticsCache;
use App\Services\RequestStatusCascade;
use App\Services\UnclaimedPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| ShredExpiredRequests
|--------------------------------------------------------------------------
| Runs once a day (Manila time) via the Laravel scheduler, after
| SendForfeitureNotices (see routes/console.php).
|
| Policy (§3.5, v1.2.0)
|   Day 89: SendForfeitureNotices sends the final notice.
|   Day 90: this command moves the request to Forfeited, forfeits its
|   still-unclaimed items, and writes the in-app disposition entry.
|   Days are calendar days in Manila time from the day the request most
|   recently became Ready to Claim (see UnclaimedPolicy).
|
| Notice gate
|   A request is forfeited only if its final notice was created on an
|   EARLIER calendar day than today. A request that reaches Day 90 without
|   one (missed run, or already overdue at deploy) is skipped here; the
|   notice command sends it, and forfeiture follows the next day. The
|   requestor therefore always gets at least one day's notice.
|
| Item-level consistency (added alongside the 2026_08_29 Phase 2/3 work)
|   document_request.status_id is a DERIVED "earliest-stage-wins"
|   aggregate over its own request_document/request_certificate rows
|   (see RequestItemStatusService::recomputeAggregateStatus()) and, when
|   release groups exist, over request_release_group rows too (see
|   RequestReleaseGroupService::recomputeParentAggregate()). Writing
|   Forfeited to document_request alone — as this command used to do —
|   left every still-ReadyToClaim item/group underneath it unchanged. The
|   very next per-item transition anywhere on that request would call
|   recomputeAggregateStatus(), see those ReadyToClaim rows again, and
|   silently resurrect the parent back out of Forfeited. This command now
|   forfeits every item/group that is still ReadyToClaim in the SAME
|   transaction as the parent, so the aggregate the two services compute
|   stays consistent with what this command wrote. Items/groups that are
|   already Completed (a partial claim happened before expiry) are left
|   untouched — they were legitimately claimed and are not being shredded.
|
| Audit trail
|   A RequestHistory row is written for every automated transition —
|   the parent-level one (unchanged shape) plus one per forfeited item —
|   so admin reports and per-item history stay consistent with manual
|   status changes. changed_by = null / processed_by_email = 'system'
|   distinguishes automated transitions from manual admin updates (see
|   migration 2026_07_08_000001_consolidate_request_history_actor —
|   processed_by was dropped; changed_by is now the single actor column).
|
| Idempotency
|   Every status write (parent, items, groups) happens inside the same
|   DB transaction, so re-running the command finds no qualifying rows —
|   including no lingering ReadyToClaim items that would otherwise make a
|   re-run look like a partial forfeiture.
|--------------------------------------------------------------------------
*/

class ShredExpiredRequests extends Command
{
    use FlushesAnalyticsCache;
    use LogsJobRun;

    protected $signature   = 'notifications:shred-expired-requests';
    protected $description = 'Forfeit ReadyToClaim requests on Day 90 (after the Day 89 notice) and log the disposition';

    public function __construct(private RequestStatusCascade $statusCascade)
    {
        parent::__construct();
    }

    /**
     * Job-Health Monitoring: every run of this command is recorded via
     * LogsJobRun (see that trait's docblock) so the SuperAdmin dashboard
     * can show whether the 90-day forfeiture sweep actually ran, without
     * requiring an SSH session and a scheduler.log grep. The command's
     * own logic below is unchanged — only the outer try/catch and the
     * two logging calls around it are new.
     */
    public function handle(NotificationServiceInterface $notificationService): int
    {
        $this->startJobRun($this->getName());

        try {
            $shredded = $this->shredExpiredRequests($notificationService);
            $this->finishJobRun(self::SUCCESS, $shredded);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    private function shredExpiredRequests(NotificationServiceInterface $notificationService): int
    {
        $requests = UnclaimedPolicy::readyRequestsBefore(
            UnclaimedPolicy::cutoff(UnclaimedPolicy::FORFEIT_DAY)
        )->with('user')->get();

        $startOfToday   = UnclaimedPolicy::startOfToday();
        $awaitingNotice = 0;

        $shredded = 0;

        foreach ($requests as $request) {
            if (! SendForfeitureNotices::noticeExistsSince(
                $request->request_id,
                UnclaimedPolicy::readyAt($request),
                $startOfToday,
            )) {
                $awaitingNotice++;
                continue;
            }

            DB::transaction(function () use ($request, $notificationService, &$shredded) {
                // Re-fetch and lock the parent + its still-ReadyToClaim
                // items/groups inside the transaction, same convention
                // RequestItemStatusService/RequestReleaseGroupService use
                // for every other status write on this schema.
                $documentRequest = DocumentRequest::lockForUpdate()->findOrFail($request->request_id);

                // A concurrent claim could have moved this out of
                // ReadyToClaim between the SELECT above and this lock —
                // re-check under the lock rather than trusting the
                // earlier snapshot.
                if ($documentRequest->status_id !== RequestStatusEnum::ReadyToClaim->value) {
                    return;
                }

                $oldStatusId = $documentRequest->status_id;

                // Items and release-group tickets still at ReadyToClaim
                // are forfeited alongside the parent, in this same
                // transaction, through the same helper the QR claim uses
                // (RequestStatusCascade). Rows already Completed (a
                // partial claim before expiry, e.g. via a release-group
                // ticket) are left alone — they were legitimately
                // claimed and are not being shredded. History rows are
                // written as an automated actor (changed_by null,
                // processed_by_email 'system').
                $forfeited = $this->statusCascade->cascade(
                    $documentRequest,
                    RequestStatusEnum::ReadyToClaim,
                    RequestStatusEnum::Forfeited,
                    actorId:   null,
                    automated: true,
                );

                $documentRequest->update(['status_id' => RequestStatusEnum::Forfeited->value]);

                RequestHistory::create([
                    'request_id'         => $documentRequest->request_id,
                    'old_status_id'      => $oldStatusId,
                    'new_status_id'      => RequestStatusEnum::Forfeited->value,
                    'changed_at'         => now(),
                    'changed_by'         => null,
                    'processed_by_email' => 'system',
                    'minutes_processed'  => (int) $documentRequest->requested_at->diffInMinutes(now()),
                ]);

                /** @var SystemUser $owner */
                $owner = $request->user;
                if ($owner instanceof SystemUser) {
                    // Disposition entry (past tense). The forward-looking
                    // warning is reminder_final_warning, sent on Day 89 by
                    // SendForfeitureNotices.
                    $notificationService->send(
                        recipient:    $owner,
                        triggerEvent: 'request_forfeited',
                        data:         ['request_id' => $documentRequest->request_id],
                        requestId:    $documentRequest->request_id,
                    );
                }

                $shredded++;
                Log::info('[ShredExpiredRequests] request forfeited', [
                    'request_id'             => $documentRequest->request_id,
                    'user_id'                => $owner?->user_id,
                    'documents_forfeited'    => $forfeited['documents'],
                    'certificates_forfeited' => $forfeited['certificates'],
                    'groups_forfeited'       => $forfeited['groups'],
                ]);
            });
        }

        $this->info("[ShredExpiredRequests] {$shredded} request(s) forfeited, {$awaitingNotice} waiting for their final notice.");

        // QA bugs #4/#9 ("Forfeited Count Mismatch" / "Forfeited Missing
        // from Admin Analytics") — this command writes status_id
        // directly to the DB via bare update() calls, bypassing
        // DocumentRequestService::updateRequest() / RequestItemStatusService
        // entirely, which are the only other places that used to
        // invalidate the "analytics" cache. That meant an auto-forfeiture
        // from this cron could leave the Forfeited summary card and
        // detail table disagreeing for up to 10 minutes — exactly the
        // symptom QA reported. Flushed ONCE after the loop (not per-row):
        // a tag flush clears everything regardless of row count, so
        // per-row flushing would just be N-1 wasted Redis round trips for
        // the same result. Skipped entirely when nothing was forfeited,
        // to avoid a pointless cache round trip on the common no-op run.
        if ($shredded > 0) {
            $this->flushAnalyticsCache();
        }

        return $shredded;
    }
}