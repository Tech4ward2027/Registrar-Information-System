<?php

namespace App\Console\Commands;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestReleaseGroup;
use App\Models\SystemUser;
use App\Console\Commands\Concerns\LogsJobRun;
use App\Contracts\NotificationServiceInterface;
use App\Services\BusinessCalendarService;
use App\Services\Concerns\FlushesAnalyticsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| ShredExpiredRequests
|--------------------------------------------------------------------------
| Runs daily via the Laravel scheduler (see routes/console.php).
| Scheduled BEFORE SendUnclaimedReminders so a request expiring on day 90
| is forfeited rather than receiving a 7-day reminder on the same day.
|
| Policy
|   If a document request has been in "ReadyToClaim" status for 90 or
|   more days, it is automatically transitioned to Forfeited and the
|   student is notified that their documents have been shredded.
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
    protected $description = 'Auto-forfeit ReadyToClaim requests unclaimed for 90+ days and notify the student';

    public function __construct(private BusinessCalendarService $businessCalendarService)
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
        $cutoff = Carbon::now()->subDays(90);

        // Use the MOST RECENT ReadyToClaim history row, not the oldest.
        // If a request was ever cycled back through Processing and then
        // set ReadyToClaim again, the 90-day clock should restart from the
        // latest transition — not from the original one.
        //
        // NOTE: this is deliberately a raw whereExists() rather than
        // whereHas('history', ...). whereHas()'s automatic "select 1"
        // existence-subquery optimization does not apply once the
        // closure adds its own groupBy()/havingRaw(), so Eloquent falls
        // back to selecting request_history's full column list. Combined
        // with GROUP BY request_id, that trips MySQL's ONLY_FULL_GROUP_BY
        // mode (every unaggregated column in the SELECT list must be in
        // the GROUP BY). Building the subquery explicitly with
        // select(DB::raw(1)) sidesteps that entirely and doesn't depend
        // on Eloquent's relation-existence internals staying the same
        // across versions.
        $requests = DocumentRequest::query()
            ->where('status_id', RequestStatusEnum::ReadyToClaim->value)
            ->whereExists(function ($query) use ($cutoff) {
                $query->select(DB::raw(1))
                    ->from('request_history')
                    ->whereColumn('request_history.request_id', 'document_request.request_id')
                    ->where('request_history.new_status_id', RequestStatusEnum::ReadyToClaim->value)
                    ->groupBy('request_history.request_id')
                    ->havingRaw('MAX(request_history.changed_at) <= ?', [$cutoff]);
            })
            ->with('user')
            ->get();

        $shredded = 0;

        foreach ($requests as $request) {
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

                $forfeitedDocuments = RequestDocument::where('request_id', $documentRequest->request_id)
                    ->where('status_id', RequestStatusEnum::ReadyToClaim->value)
                    ->lockForUpdate()
                    ->get();

                $forfeitedCertificates = RequestCertificate::where('request_id', $documentRequest->request_id)
                    ->where('status_id', RequestStatusEnum::ReadyToClaim->value)
                    ->lockForUpdate()
                    ->get();

                foreach ($forfeitedDocuments as $item) {
                    $item->update(['status_id' => RequestStatusEnum::Forfeited->value]);
                    $this->recordItemHistory($documentRequest, RequestStatusEnum::ReadyToClaim->value, requestDocumentId: $item->request_document_id);
                }

                foreach ($forfeitedCertificates as $item) {
                    $item->update(['status_id' => RequestStatusEnum::Forfeited->value]);
                    $this->recordItemHistory($documentRequest, RequestStatusEnum::ReadyToClaim->value, requestCertificateId: $item->request_certificate_id);
                }

                // Phase 3 release-group tickets (only present on requests
                // whose items span more than one fulfillment_track — see
                // RequestReleaseGroupService::assignReleaseGroups()). A
                // group already Completed was legitimately claimed early
                // and is left alone; only a still-outstanding group
                // ticket is forfeited alongside its items above.
                RequestReleaseGroup::where('request_id', $documentRequest->request_id)
                    ->where('status_id', RequestStatusEnum::ReadyToClaim->value)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (RequestReleaseGroup $group) => $group->update(['status_id' => RequestStatusEnum::Forfeited->value]));

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
                    // Use 'request_forfeited' — documents have already been
                    // shredded at this point, so the message must be past-tense.
                    // 'reminder_final_warning' (future-tense) was incorrect here.
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
                    'documents_forfeited'    => $forfeitedDocuments->count(),
                    'certificates_forfeited' => $forfeitedCertificates->count(),
                ]);
            });
        }

        $this->info("[ShredExpiredRequests] {$shredded} request(s) forfeited.");

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

    /**
     * Writes a per-item RequestHistory row for a forfeited
     * request_document/request_certificate row. Same shape and same
     * business_minutes segment-timing calculation as
     * RequestItemStatusService::recordItemHistory() / RequestReleaseGroupService::
     * recordHistory() — this is now the THIRD call site for this exact
     * logic (flagged as the threshold worth extracting in both of those
     * classes' docblocks). Left as a third duplicate here rather than
     * extracting a shared trait in this pass, to avoid touching two
     * already-shipped, already-tested service files as a side effect of
     * an unrelated bugfix — but a future change to any of these three
     * should pull this into a shared
     * App\Services\Concerns\RecordsRequestItemHistory trait instead of
     * copying it a fourth time.
     *
     * changed_by = null / processed_by_email = 'system', matching this
     * command's own parent-level history row — every row this command
     * writes should be identifiable as an automated transition, not just
     * the parent one.
     */
    private function recordItemHistory(
        DocumentRequest $documentRequest,
        int $oldStatusId,
        ?int $requestDocumentId = null,
        ?int $requestCertificateId = null,
    ): void {
        $minutesProcessed = (int) $documentRequest->requested_at->diffInMinutes(now());

        $segmentStart = RequestHistory::where('request_id', $documentRequest->request_id)
            ->when($requestDocumentId, fn ($q) => $q->where('request_document_id', $requestDocumentId))
            ->when($requestCertificateId, fn ($q) => $q->where('request_certificate_id', $requestCertificateId))
            ->orderByDesc('changed_at')
            ->orderByDesc('request_history_id')
            ->value('changed_at');

        $segmentStart = $segmentStart
            ? Carbon::parse($segmentStart)
            : $documentRequest->requested_at;

        $businessMinutes = $this->businessCalendarService->minutesBetween($segmentStart, now());

        RequestHistory::create([
            'request_id'             => $documentRequest->request_id,
            'request_document_id'    => $requestDocumentId,
            'request_certificate_id' => $requestCertificateId,
            'old_status_id'          => $oldStatusId,
            'new_status_id'          => RequestStatusEnum::Forfeited->value,
            'changed_at'             => now(),
            'changed_by'             => null,
            'processed_by_email'     => 'system',
            'minutes_processed'      => $minutesProcessed,
            'business_minutes'       => $businessMinutes,
        ]);
    }
}