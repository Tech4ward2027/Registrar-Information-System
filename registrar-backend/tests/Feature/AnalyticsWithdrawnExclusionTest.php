<?php

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Deficiency Notice & Withdrawn Status — Phase 5
| ("Analytics/report exclusion")
|--------------------------------------------------------------------------
| Mirrors AnalyticsArchivedExclusionTest.php's structure exactly, but for
| AnalyticsService::excludeFromProcessingTimeMetrics() (Withdrawn
| exclusion) rather than excludeArchived() (archive/soft-delete
| exclusion) — see that method's docblock for the full "why processing-
| time averages exclude Withdrawn but plain counts don't" reasoning.
|
| Every panel below is exercised with BOTH an active (Completed) request
| AND a Withdrawn one with a deliberately far-apart business_minutes
| value, so a test that should exclude the Withdrawn row can never
| accidentally pass because the two rows happened to average out to the
| same number.
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

/**
 * Creates a request + its request_document + request_history rows at the
 * given status (Completed, by default, or Withdrawn), with
 * $businessMinutes set deliberately far from the "active" fixture's value
 * so a test asserting exclusion can never accidentally pass for the wrong
 * reason (e.g. the two values happening to average to something that
 * still looks plausible).
 */
function createRequestForWithdrawnAnalytics(array $fixtures, int $statusId, int $oldStatusId, int $businessMinutes): DocumentRequest
{
    $request = DocumentRequest::factory()->create([
        'user_id'            => $fixtures['student']->user_id,
        'status_id'          => $statusId,
        'request_purpose_id' => $fixtures['purpose']->request_purpose_id,
        'requested_at'       => now(),
    ]);

    DB::table('request_document')->insert([
        'request_id'       => $request->request_id,
        'document_type_id' => $fixtures['docType']->document_type_id,
        'number_of_copies' => 1,
    ]);

    DB::table('request_history')->insert([
        'request_id'         => $request->request_id,
        'old_status_id'      => $oldStatusId,
        'new_status_id'      => $statusId,
        'changed_at'         => now(),
        'changed_by'         => $fixtures['admin']->user_id,
        'processed_by_email' => $fixtures['admin']->email,
        'minutes_processed'  => $businessMinutes,
        'business_minutes'   => $businessMinutes,
    ]);

    return $request;
}

// ═════════════════════════════════════════════════════════════════════════════
// Processing-time metrics EXCLUDE Withdrawn
// ═════════════════════════════════════════════════════════════════════════════

test('overview avg_processing_minutes excludes Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Completed->value, RequestStatusEnum::Processing->value, 25);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 999);

    $range  = [now()->subDay(), now()->addDay()];
    $result = (new AnalyticsService())->overview($range);

    expect((float) $result['avg_processing_minutes'])->toBe(25.0);
});

test('byDocumentType processing-time average excludes Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Completed->value, RequestStatusEnum::Processing->value, 30);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 800);

    $range = [now()->subDay(), now()->addDay()];
    $rows  = (new AnalyticsService())->byDocumentType($range);

    // Both requests still count toward line-item volume (byDocumentType's
    // outer counts are NOT processing-time metrics — see this method's
    // own excludeArchived() call, deliberately untouched by Phase 5), but
    // the averaged processing time must only reflect the Completed one.
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['avg_processing_min'])->toBe(30.0);
});

test('processingTime by_document_type and by_admin exclude Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Completed->value, RequestStatusEnum::Processing->value, 20);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 700);

    $range  = [now()->subDay(), now()->addDay()];
    $result = (new AnalyticsService())->processingTime($range);

    expect($result['by_document_type'])->toHaveCount(1);
    expect((float) $result['by_document_type'][0]->avg_minutes)->toBe(20.0);
    expect((int) $result['by_document_type'][0]->sample_count)->toBe(1);

    expect($result['by_admin'])->toHaveCount(1);
    expect((float) $result['by_admin'][0]->avg_minutes)->toBe(20.0);
    expect((int) $result['by_admin'][0]->requests_handled)->toBe(1);
});

test('signatureTurnaroundTime excludes Withdrawn requests from both SLA clocks', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    // registrar_time segment: old_status_id = Processing
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::PendingSignature->value, RequestStatusEnum::Processing->value, 12);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 900);

    // signature_time segment: old_status_id = PendingSignature
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Completed->value, RequestStatusEnum::PendingSignature->value, 18);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::PendingSignature->value, 950);

    $range  = [now()->subDay(), now()->addDay()];
    $result = (new AnalyticsService())->signatureTurnaroundTime($range);

    expect($result['registrar_time'])->toHaveCount(1);
    expect((float) $result['registrar_time'][0]->avg_minutes)->toBe(12.0);

    expect($result['signature_time'])->toHaveCount(1);
    expect((float) $result['signature_time'][0]->avg_minutes)->toBe(18.0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Plain counts DO NOT exclude Withdrawn — it's a real submission that
// consumed intake, just one that never completed
// ═════════════════════════════════════════════════════════════════════════════

test('overview total count includes Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Completed->value, RequestStatusEnum::Processing->value, 10);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 10);

    $range  = [now()->subDay(), now()->addDay()];
    $result = (new AnalyticsService())->overview($range);

    expect($result['total'])->toBe(2);
});

test('byStatus includes a row for Withdrawn requests', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Completed->value, RequestStatusEnum::Processing->value, 10);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 10);
    createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 10);

    $range = [now()->subDay(), now()->addDay()];
    $rows  = (new AnalyticsService())->byStatus($range);

    $withdrawnTotal = collect($rows)->firstWhere('status_id', RequestStatusEnum::Withdrawn->value)['total'] ?? 0;

    expect($withdrawnTotal)->toBe(2);
});

// ═════════════════════════════════════════════════════════════════════════════
// Withdrawn is still subject to archive exclusion, same as every other status
// ═════════════════════════════════════════════════════════════════════════════

test('a Withdrawn request that is also archived is excluded from byStatus like any other archived request', function () {
    $fixtures = seedWithdrawnAnalyticsFixtures();

    $withdrawn = createRequestForWithdrawnAnalytics($fixtures, RequestStatusEnum::Withdrawn->value, RequestStatusEnum::Processing->value, 10);

    DB::table('document_request')
        ->where('request_id', $withdrawn->request_id)
        ->update(['is_archived' => true, 'archived_on' => now()]);

    $range = [now()->subDay(), now()->addDay()];
    $rows  = (new AnalyticsService())->byStatus($range);

    $withdrawnTotal = collect($rows)->firstWhere('status_id', RequestStatusEnum::Withdrawn->value)['total'] ?? 0;

    expect($withdrawnTotal)->toBe(0);
});
