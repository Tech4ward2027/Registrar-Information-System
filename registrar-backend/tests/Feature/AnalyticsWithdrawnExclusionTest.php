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

/*
|--------------------------------------------------------------------------
| Deficiency Notice & Withdrawn Status — Phase 5
| ("Analytics/report exclusion")
|--------------------------------------------------------------------------
| Mirrors AnalyticsArchivedExclusionTest.php's structure, but for the
| Withdrawn exclusion (see AnalyticsService::excludeFromProcessingTimeMetrics()
| and AnalyticsProcessingMetrics) rather than the archive/soft-delete
| exclusion — "why processing-time averages exclude Withdrawn but plain
| counts don't".
|
| Analytics alignment: processing-time panels derive their figure from the
| history TIMESTAMPS (ProcessingTimeService), not the stored business_minutes
| column. The Withdrawn fixtures therefore get a genuine stop row (Pending
| Signature) BEFORE the request is withdrawn, with a deliberately far-apart
| duration — so a test that excludes Withdrawn can only pass because the
| exclusion works, never because the request happened to be unmeasurable.
|
| Helper names carry an "awx" prefix: Pest test files share one global
| function namespace.
|--------------------------------------------------------------------------
*/

function seedWithdrawnAnalyticsFixtures(): array
{
    $completedStatus = RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::Completed->value],
        ['status_name' => 'Completed']
    );

    RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::Withdrawn->value],
        ['status_name' => 'Withdrawn']
    );

    RequestStatus::firstOrCreate(
        ['status_id' => RequestStatusEnum::PendingSignature->value],
        ['status_name' => 'Pending Signature']
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

    return compact('completedStatus', 'purpose', 'docType', 'student', 'admin');
}

function awxRange(): array
{
    return [
        Carbon::parse('2026-03-09 00:00:00', 'Asia/Manila')->utc(),
        Carbon::parse('2026-03-13 23:59:59', 'Asia/Manila')->utc(),
    ];
}

/**
 * A request filed Tue 2026-03-10 10:00 Manila that reaches Pending Signature
 * after exactly $registrarMinutes office minutes (the figure under test), then
 * continues through $later transitions: [old, new, minutesAfterStop, storedJunk].
 * The stored minute columns carry the junk value and must never be read.
 */
function awxRequest(array $fixtures, int $statusId, int $registrarMinutes, array $later = []): DocumentRequest
{
    $filed = Carbon::parse('2026-03-10 10:00:00', 'Asia/Manila')->utc();
    $stop  = $filed->copy()->addMinutes($registrarMinutes);

    $request = DocumentRequest::factory()->create([
        'user_id'            => $fixtures['student']->user_id,
        'status_id'          => $statusId,
        'request_purpose_id' => $fixtures['purpose']->request_purpose_id,
        'requested_at'       => $filed,
    ]);

    DB::table('request_document')->insert([
        'request_id'       => $request->request_id,
        'document_type_id' => $fixtures['docType']->document_type_id,
        'number_of_copies' => 1,
    ]);

    $rows = [[RequestStatusEnum::Processing->value, RequestStatusEnum::PendingSignature->value, $stop, 9999]];

    foreach ($later as [$old, $new, $minutesAfterStop, $junk]) {
        $rows[] = [$old->value, $new->value, $stop->copy()->addMinutes($minutesAfterStop), $junk];
    }

    foreach ($rows as [$old, $new, $at, $junk]) {
        DB::table('request_history')->insert([
            'request_id'         => $request->request_id,
            'old_status_id'      => $old,
            'new_status_id'      => $new,
            'changed_at'         => $at->toDateTimeString(),
            'changed_by'         => $fixtures['admin']->user_id,
            'processed_by_email' => $fixtures['admin']->email,
            'minutes_processed'  => $junk,
            'business_minutes'   => $junk,
        ]);
    }

    return $request;
}

const AWX_PS = RequestStatusEnum::PendingSignature;
const AWX_DONE = RequestStatusEnum::Completed;
const AWX_WITHDRAWN = RequestStatusEnum::Withdrawn;

// ═════════════════════════════════════════════════════════════════════════════
// Processing-time metrics EXCLUDE Withdrawn
// ═════════════════════════════════════════════════════════════════════════════

test('overview avg_processing_minutes excludes Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    awxRequest($fixtures, RequestStatusEnum::Completed->value, 25, [[AWX_PS, AWX_DONE, 60, 1]]);
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 500, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);

    $result = app(AnalyticsService::class)->overview(awxRange());

    expect((float) $result['avg_processing_minutes'])->toBe(25.0);
});

test('byDocumentType processing-time average excludes Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    awxRequest($fixtures, RequestStatusEnum::Completed->value, 30, [[AWX_PS, AWX_DONE, 60, 1]]);
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 400, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);

    $rows = app(AnalyticsService::class)->byDocumentType(awxRange());

    // Both requests still count toward line-item volume (byDocumentType's
    // outer counts are NOT processing-time metrics), but the averaged
    // processing time must only reflect the Completed one.
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['avg_processing_min'])->toBe(30.0);
});

test('processingTime by_document_type and by_admin exclude Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    awxRequest($fixtures, RequestStatusEnum::Completed->value, 20, [[AWX_PS, AWX_DONE, 60, 1]]);
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 500, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);

    $result = app(AnalyticsService::class)->processingTime(awxRange());

    expect($result['by_document_type'])->toHaveCount(1);
    expect($result['by_document_type'][0]['avg_minutes'])->toBe(20.0);
    expect($result['by_document_type'][0]['sample_count'])->toBe(1);

    expect($result['by_admin'])->toHaveCount(1);
    expect($result['by_admin'][0]['avg_minutes'])->toBe(20.0);
    expect($result['by_admin'][0]['requests_handled'])->toBe(1);
});

test('signatureTurnaroundTime excludes Withdrawn requests from both SLA clocks', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    // Kept: still waiting on a signature (12 registrar minutes, no signature
    // wait yet) and one that was signed and claimed (12 registrar minutes,
    // 18 stored signature-wait minutes).
    awxRequest($fixtures, RequestStatusEnum::PendingSignature->value, 12);
    awxRequest($fixtures, RequestStatusEnum::Completed->value, 12, [[AWX_PS, AWX_DONE, 40, 18]]);

    // Excluded: withdrawn while waiting for a signature, with a large
    // registrar figure and a large stored signature-wait segment.
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 500, [[AWX_PS, AWX_WITHDRAWN, 40, 950]]);

    $result = app(AnalyticsService::class)->signatureTurnaroundTime(awxRange());

    expect($result['registrar_time'])->toHaveCount(1);
    expect($result['registrar_time'][0]['avg_minutes'])->toBe(12.0);
    expect($result['registrar_time'][0]['sample_count'])->toBe(2);

    // signature_time still averages the stored segments leaving Pending Signature.
    expect($result['signature_time'])->toHaveCount(1);
    expect((float) $result['signature_time'][0]->avg_minutes)->toBe(18.0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Plain counts DO NOT exclude Withdrawn — it's a real submission that
// consumed intake, just one that never completed
// ═════════════════════════════════════════════════════════════════════════════

test('overview total count includes Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    awxRequest($fixtures, RequestStatusEnum::Completed->value, 10, [[AWX_PS, AWX_DONE, 60, 1]]);
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 10, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);

    $result = app(AnalyticsService::class)->overview(awxRange());

    expect($result['total'])->toBe(2);
});

test('byStatus includes a row for Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    awxRequest($fixtures, RequestStatusEnum::Completed->value, 10, [[AWX_PS, AWX_DONE, 60, 1]]);
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 10, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);
    awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 10, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);

    $rows = app(AnalyticsService::class)->byStatus(awxRange());

    $withdrawnTotal = collect($rows)->firstWhere('status_id', RequestStatusEnum::Withdrawn->value)['total'] ?? 0;

    expect($withdrawnTotal)->toBe(2);
});

// ═════════════════════════════════════════════════════════════════════════════
// Withdrawn is still subject to archive exclusion, same as every other status
// ═════════════════════════════════════════════════════════════════════════════

test('a Withdrawn request that is also archived is excluded from byStatus like any other archived request', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    $withdrawn = awxRequest($fixtures, RequestStatusEnum::Withdrawn->value, 10, [[AWX_PS, AWX_WITHDRAWN, 30, 1]]);

    DB::table('document_request')
        ->where('request_id', $withdrawn->request_id)
        ->update(['is_archived' => true, 'archived_on' => now()]);

    $rows = app(AnalyticsService::class)->byStatus(awxRange());

    $withdrawnTotal = collect($rows)->firstWhere('status_id', RequestStatusEnum::Withdrawn->value)['total'] ?? 0;

    expect($withdrawnTotal)->toBe(0);
});