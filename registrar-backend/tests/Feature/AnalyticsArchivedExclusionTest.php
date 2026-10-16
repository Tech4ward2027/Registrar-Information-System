<?php

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Regression coverage for Step 1.0 of the Analytics & Audit Log Revamp plan:
 *
 *   1.0.1 — archived/deleted requests were included in byDocumentType(),
 *           byStatus(), byPurpose(), processingTime(), and
 *           signatureTurnaroundTime() (raw DB::table() queries bypass
 *           DocumentRequest's ExcludeArchivedScope / SoftDeletes global
 *           scopes), while overview()/volumeTrend()/peakHours() (Eloquent
 *           queries) correctly excluded them — a guaranteed source of
 *           "the numbers don't match between panels."
 *
 *   1.0.2 — several panels averaged rh.minutes_processed (cumulative
 *           wall-clock time) instead of calendar-aware processing time.
 *
 * Analytics alignment: the processing-time panels (overview average,
 * byDocumentType, processingTime, signatureTurnaroundTime's registrar_time)
 * no longer read the stored request_history.business_minutes column at all —
 * they derive the figure from the history TIMESTAMPS through
 * ProcessingTimeService. So these fixtures build real timelines on a fixed
 * Tuesday inside office hours (the default calendar is Mon-Fri 08:00-20:00
 * Asia/Manila) and deliberately write JUNK into the stored minute columns:
 * a test that passes therefore also proves the stored values are ignored.
 *
 * Each test sets up one active request and one archived (or soft-deleted)
 * request with deliberately different processing durations, then asserts the
 * archived one is invisible to the panel under test.
 *
 * Helper names carry an "aex" prefix: Pest test files share one global
 * function namespace.
 */
function seedAnalyticsFixtures(): array
{
    $status = RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::Completed->value],
        ['status_name' => 'Completed']
    );

    $purpose = RequestPurpose::firstOrCreate(
        ['request_purpose_id' => 1],
        ['purpose_name' => 'DFA']
    );

    $docType = DocumentType::firstOrCreate(
        ['document_type_id' => 1],
        [
            'document_name'           => 'Transcript of Records',
            'document_description'    => 'Regression fixture document type.',
            'document_process_period' => '3-5 business days',
        ]
    );

    $student = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
    ]);

    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Activated',
    ]);

    return compact('status', 'purpose', 'docType', 'student', 'admin');
}

/** Tuesday 2026-03-10 10:00 Asia/Manila (inside office hours), as UTC, plus $dayOffset days. */
function aexFiledAt(int $dayOffset = 0): Carbon
{
    return Carbon::parse('2026-03-10 10:00:00', 'Asia/Manila')->addDays($dayOffset)->utc();
}

/** A range that covers the fixture days, in UTC like the controller's. */
function aexRange(): array
{
    return [
        Carbon::parse('2026-03-09 00:00:00', 'Asia/Manila')->utc(),
        Carbon::parse('2026-03-13 23:59:59', 'Asia/Manila')->utc(),
    ];
}

/** One history row. Stored minute columns carry junk on purpose — they must be ignored. */
function aexHistory(array $fixtures, int $requestId, int $old, int $new, Carbon $at, int $junk = 9999): void
{
    DB::table('request_history')->insert([
        'request_id'         => $requestId,
        'old_status_id'      => $old,
        'new_status_id'      => $new,
        'changed_at'         => $at->toDateTimeString(),
        'changed_by'         => $fixtures['admin']->user_id,
        'processed_by_email' => $fixtures['admin']->email,
        'minutes_processed'  => $junk,
        'business_minutes'   => $junk,
    ]);
}

/**
 * A request with one document line, filed 10:00 on the fixture day, that
 * reaches Ready to Claim after exactly $officeMinutes office minutes and is
 * then claimed (Completed). $storedJunk is what the stored minute columns say.
 */
function createRequestWithHistory(array $fixtures, bool $archived, int $officeMinutes, int $storedJunk, ?string $deletedAt = null, int $dayOffset = 0): DocumentRequest
{
    $filed = aexFiledAt($dayOffset);

    $request = DocumentRequest::factory()->create([
        'user_id'            => $fixtures['student']->user_id,
        'status_id'          => $fixtures['status']->status_id,
        'request_purpose_id' => $fixtures['purpose']->request_purpose_id,
        'requested_at'       => $filed,
    ]);

    DB::table('request_document')->insert([
        'request_id'       => $request->request_id,
        'document_type_id' => $fixtures['docType']->document_type_id,
        'number_of_copies' => 1,
    ]);

    $ready = $filed->copy()->addMinutes($officeMinutes);

    aexHistory($fixtures, $request->request_id, RequestStatusEnum::Processing->value, RequestStatusEnum::ReadyToClaim->value, $ready, $storedJunk);
    aexHistory($fixtures, $request->request_id, RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::Completed->value, $ready->copy()->addMinutes(30), $storedJunk);

    if ($archived) {
        DB::table('document_request')
            ->where('request_id', $request->request_id)
            ->update(['is_archived' => true, 'archived_on' => now()]);
    }

    if ($deletedAt) {
        DB::table('document_request')
            ->where('request_id', $request->request_id)
            ->update(['deleted_at' => $deletedAt]);
    }

    return $request;
}

test('byDocumentType excludes archived requests and uses the shared processing rule, not stored minutes', function () {
    $fixtures = seedAnalyticsFixtures();

    // Active: 30 office minutes (should be the ONLY request counted/averaged).
    createRequestWithHistory($fixtures, archived: false, officeMinutes: 30, storedJunk: 9999);

    // Archived: would skew both the count and the average if leaked in.
    createRequestWithHistory($fixtures, archived: true, officeMinutes: 500, storedJunk: 500);

    $rows = app(AnalyticsService::class)->byDocumentType(aexRange());

    expect($rows)->toHaveCount(1);
    expect($rows[0]['total_documents'])->toBe(1);
    expect((float) $rows[0]['avg_processing_min'])->toBe(30.0);
});

test('byStatus excludes archived and soft-deleted requests', function () {
    $fixtures = seedAnalyticsFixtures();

    createRequestWithHistory($fixtures, archived: false, officeMinutes: 10, storedJunk: 10);
    createRequestWithHistory($fixtures, archived: true, officeMinutes: 10, storedJunk: 10);
    createRequestWithHistory($fixtures, archived: false, officeMinutes: 10, storedJunk: 10, deletedAt: now()->toDateTimeString());

    $rows = app(AnalyticsService::class)->byStatus(aexRange());

    $completedTotal = collect($rows)->firstWhere('status_id', $fixtures['status']->status_id)['total'] ?? 0;

    expect($completedTotal)->toBe(1);
});

test('byPurpose excludes archived requests', function () {
    $fixtures = seedAnalyticsFixtures();

    createRequestWithHistory($fixtures, archived: false, officeMinutes: 10, storedJunk: 10);
    createRequestWithHistory($fixtures, archived: true, officeMinutes: 10, storedJunk: 10);

    $rows = app(AnalyticsService::class)->byPurpose(aexRange());

    $purposeTotal = collect($rows)->firstWhere('purpose_id', $fixtures['purpose']->request_purpose_id)['total'] ?? 0;

    expect($purposeTotal)->toBe(1);
});

test('processingTime by_document_type and by_admin exclude archived and soft-deleted requests and ignore stored minutes', function () {
    $fixtures = seedAnalyticsFixtures();

    createRequestWithHistory($fixtures, archived: false, officeMinutes: 20, storedJunk: 8888);
    createRequestWithHistory($fixtures, archived: true, officeMinutes: 500, storedJunk: 900);
    createRequestWithHistory($fixtures, archived: false, officeMinutes: 400, storedJunk: 900, deletedAt: now()->toDateTimeString());

    $result = app(AnalyticsService::class)->processingTime(aexRange());

    expect($result['by_document_type'])->toHaveCount(1);
    expect($result['by_document_type'][0]['avg_minutes'])->toBe(20.0);
    expect($result['by_document_type'][0]['sample_count'])->toBe(1);

    expect($result['by_admin'])->toHaveCount(1);
    expect($result['by_admin'][0]['requests_handled'])->toBe(1);
    expect($result['by_admin'][0]['avg_minutes'])->toBe(20.0);
});

/**
 * Step 1a/1b/1c regression coverage — the Staff Performance metrics
 * (min/max spread, active-day rate, forfeit-rate quality signal).
 */
test('processingTime by_admin reports min/max spread, active-day rate, and forfeit rate', function () {
    $fixtures = seedAnalyticsFixtures();

    // Same admin finishes two requests on two different working days: one
    // Completed (10 office minutes) and one that later Forfeited (60 office
    // minutes). Exercises min/max, active_days = 2 (=> 1.0/day), 50% forfeit.
    createRequestWithHistory($fixtures, archived: false, officeMinutes: 10, storedJunk: 1, dayOffset: 0);

    $forfeitedStatus = RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::Forfeited->value],
        ['status_name' => 'Forfeited']
    );

    $day2      = aexFiledAt(1); // Wednesday
    $forfeited = DocumentRequest::factory()->create([
        'user_id'            => $fixtures['student']->user_id,
        'status_id'          => $forfeitedStatus->status_id,
        'request_purpose_id' => $fixtures['purpose']->request_purpose_id,
        'requested_at'       => $day2,
    ]);
    DB::table('request_document')->insert([
        'request_id'       => $forfeited->request_id,
        'document_type_id' => $fixtures['docType']->document_type_id,
        'number_of_copies' => 1,
    ]);
    aexHistory($fixtures, $forfeited->request_id, RequestStatusEnum::Processing->value, RequestStatusEnum::ReadyToClaim->value, $day2->copy()->addMinutes(60), 1);
    aexHistory($fixtures, $forfeited->request_id, RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::Forfeited->value, $day2->copy()->addDays(10), 1);

    $result = app(AnalyticsService::class)->processingTime([aexRange()[0], aexFiledAt(0)->addDays(20)]);

    expect($result['by_admin'])->toHaveCount(1);
    $row = $result['by_admin'][0];

    expect($row['min_minutes'])->toBe(10.0);
    expect($row['max_minutes'])->toBe(60.0);
    expect($row['sample_count'])->toBe(2);
    expect($row['requests_handled'])->toBe(2);
    expect($row['active_days'])->toBe(2);
    expect($row['requests_per_active_day'])->toBe(1.0);
    expect($row['forfeited_count'])->toBe(1);
    expect($row['forfeit_rate'])->toBe(50.0);
});

test('processingTime by_admin excludes forfeited requests from the count when archived', function () {
    $fixtures = seedAnalyticsFixtures();

    $forfeitedStatus = RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::Forfeited->value],
        ['status_name' => 'Forfeited']
    );

    // A forfeited request that was later archived should not count toward
    // anything — same archive-exclusion rule as every other panel here.
    $filed   = aexFiledAt(0);
    $request = DocumentRequest::factory()->create([
        'user_id'            => $fixtures['student']->user_id,
        'status_id'          => $forfeitedStatus->status_id,
        'request_purpose_id' => $fixtures['purpose']->request_purpose_id,
        'requested_at'       => $filed,
    ]);
    DB::table('request_document')->insert([
        'request_id'       => $request->request_id,
        'document_type_id' => $fixtures['docType']->document_type_id,
        'number_of_copies' => 1,
    ]);
    aexHistory($fixtures, $request->request_id, RequestStatusEnum::Processing->value, RequestStatusEnum::ReadyToClaim->value, $filed->copy()->addMinutes(60), 60);
    aexHistory($fixtures, $request->request_id, RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::Forfeited->value, $filed->copy()->addDays(10), 60);
    DB::table('document_request')
        ->where('request_id', $request->request_id)
        ->update(['is_archived' => true, 'archived_on' => now()]);

    $result = app(AnalyticsService::class)->processingTime([aexRange()[0], $filed->copy()->addDays(20)]);

    // No non-archived work in range at all, so no phantom 0-request row.
    expect($result['by_admin'])->toHaveCount(0);
});

test('signatureTurnaroundTime excludes archived requests', function () {
    $fixtures = seedAnalyticsFixtures();

    // document_request.status_id has a FK to request_status — PendingSignature
    // (status_id 6) needs its own row here if the migrations did not seed it.
    RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::PendingSignature->value],
        ['status_name' => 'Pending Signature']
    );

    foreach ([[false, 15], [true, 300]] as [$archived, $officeMinutes]) {
        $filed   = aexFiledAt(0);
        $request = DocumentRequest::factory()->create([
            'user_id'            => $fixtures['student']->user_id,
            'status_id'          => RequestStatusEnum::PendingSignature->value,
            'request_purpose_id' => $fixtures['purpose']->request_purpose_id,
            'requested_at'       => $filed,
        ]);
        DB::table('request_document')->insert([
            'request_id'       => $request->request_id,
            'document_type_id' => $fixtures['docType']->document_type_id,
            'number_of_copies' => 1,
        ]);
        aexHistory($fixtures, $request->request_id, RequestStatusEnum::Processing->value, RequestStatusEnum::PendingSignature->value, $filed->copy()->addMinutes($officeMinutes), 9999);
        // Signed 25 office minutes later for the active request, 200 for the archived one.
        aexHistory($fixtures, $request->request_id, RequestStatusEnum::PendingSignature->value, RequestStatusEnum::ReadyToClaim->value, $filed->copy()->addMinutes($officeMinutes + ($archived ? 200 : 25)), 9999);

        if ($archived) {
            DB::table('document_request')
                ->where('request_id', $request->request_id)
                ->update(['is_archived' => true, 'archived_on' => now()]);
        }
    }

    $result = app(AnalyticsService::class)->signatureTurnaroundTime(aexRange());

    expect($result['registrar_time'])->toHaveCount(1);
    expect($result['registrar_time'][0]['avg_minutes'])->toBe(15.0);
    expect($result['registrar_time'][0]['sample_count'])->toBe(1);

    expect($result['signature_time'])->toHaveCount(1);
    expect($result['signature_time'][0]['avg_minutes'])->toBe(25.0);
    expect($result['signature_time'][0]['sample_count'])->toBe(1);
});

test('overview avg_processing_minutes excludes archived requests and ignores stored minutes', function () {
    $fixtures = seedAnalyticsFixtures();

    createRequestWithHistory($fixtures, archived: false, officeMinutes: 40, storedJunk: 7777);
    createRequestWithHistory($fixtures, archived: true, officeMinutes: 500, storedJunk: 999);

    $result = app(AnalyticsService::class)->overview(aexRange());

    expect((float) $result['avg_processing_minutes'])->toBe(40.0);
});

/**
 * Lightweight cross-panel consistency check, per the plan's Step 1.0.1
 * recommendation: converts "QA notices numbers look wrong three weeks
 * later" into "CI fails immediately when this regresses." Sums of
 * per-bucket panels must never exceed the unconditional overview() total
 * for the same range.
 */
test('cross-panel invariant: byStatus and byPurpose totals never exceed overview total', function () {
    $fixtures = seedAnalyticsFixtures();

    createRequestWithHistory($fixtures, archived: false, officeMinutes: 10, storedJunk: 10);
    createRequestWithHistory($fixtures, archived: false, officeMinutes: 15, storedJunk: 15);
    createRequestWithHistory($fixtures, archived: true, officeMinutes: 500, storedJunk: 999);

    $range   = aexRange();
    $service = app(AnalyticsService::class);

    $overviewTotal = $service->overview($range)['total'];
    $statusSum     = collect($service->byStatus($range))->sum('total');
    $purposeSum    = collect($service->byPurpose($range))->sum('total');

    expect($statusSum)->toBeLessThanOrEqual($overviewTotal);
    expect($purposeSum)->toBeLessThanOrEqual($overviewTotal);
    expect($overviewTotal)->toBe(2);
});