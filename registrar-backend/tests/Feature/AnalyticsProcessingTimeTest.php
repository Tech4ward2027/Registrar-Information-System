<?php

use App\Enums\RequestStatusEnum as S;
use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\SystemUser;
use App\Services\AnalyticsProcessingMetrics;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Dashboard processing-time figures read the shared per-transaction rule
// (ProcessingTimeService), the same one the logbook and OPCR report use.
// The default business calendar (Mon-Fri 08:00-20:00 Asia/Manila) comes from
// the migrations. Fixture days: Tue 2026-03-10 and Wed 2026-03-11.
//
// Helper names carry an "apt" prefix: Pest test files share one global
// function namespace.
// ═════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    config(['app.display_timezone' => 'Asia/Manila']);
});

function aptAdmin(): SystemUser
{
    return SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
}

function aptType(string $name): DocumentType
{
    return DocumentType::create([
        'document_name'           => $name,
        'document_description'    => 'Test',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '2 hours',
        'access_id'               => AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id,
    ]);
}

/** A Manila wall-clock time on a fixture day, as UTC. */
function aptAt(string $dateTime): Carbon
{
    return Carbon::parse($dateTime, 'Asia/Manila')->utc();
}

/** [from, to] covering whole Manila days, as UTC like the controller's range. */
function aptRange(string $from, string $to): array
{
    return [
        Carbon::parse($from . ' 00:00:00', 'Asia/Manila')->utc(),
        Carbon::parse($to . ' 23:59:59', 'Asia/Manila')->utc(),
    ];
}

/**
 * A request with one line per given document type. $rows are history
 * transitions [old, new, Carbon at, ?documentIndex, ?userId]: a null index is a
 * whole-request row, an index is that line's own row. Stored minute columns
 * are junk on purpose.
 *
 * @param  list<DocumentType>  $types
 * @return array{0: DocumentRequest, 1: list<RequestDocument>}
 */
function aptRequest(array $types, int $statusId, Carbon $filed, array $rows): array
{
    $request = DocumentRequest::factory()->create([
        'status_id'    => $statusId,
        'requested_at' => $filed,
    ]);

    $documents = [];
    foreach ($types as $type) {
        $documents[] = RequestDocument::create([
            'request_id'       => $request->request_id,
            'document_type_id' => $type->document_type_id,
            'number_of_copies' => 1,
            'status_id'        => $statusId,
        ]);
    }

    foreach ($rows as [$old, $new, $at, $documentIndex, $userId]) {
        RequestHistory::create([
            'request_id'          => $request->request_id,
            'request_document_id' => $documentIndex !== null ? $documents[$documentIndex]->request_document_id : null,
            'old_status_id'       => $old->value,
            'new_status_id'       => $new->value,
            'changed_at'          => $at,
            'changed_by'          => $userId,
            'minutes_processed'   => 9999,
            'business_minutes'    => 9999,
        ]);
    }

    return [$request, $documents];
}

// ── The rule ─────────────────────────────────────────────────────────────────

test('only Processing time counts: Awaiting Submission, the signing wait and pickup are left out', function () {
    $admin = aptAdmin();
    $type  = aptType('Transcript of Records');

    aptRequest([$type], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
        [S::Processing, S::AwaitingSubmission, aptAt('2026-03-10 10:30:00'), null, $admin->user_id],  // counts 30
        [S::AwaitingSubmission, S::Processing, aptAt('2026-03-10 14:00:00'), null, $admin->user_id],  // paused
        [S::Processing, S::PendingSignature, aptAt('2026-03-10 14:20:00'), null, $admin->user_id],    // counts 20
        [S::PendingSignature, S::ReadyToClaim, aptAt('2026-03-11 16:00:00'), null, $admin->user_id],  // signing wait
        [S::ReadyToClaim, S::Completed, aptAt('2026-03-12 09:00:00'), null, $admin->user_id],         // pickup
    ]);

    $service = app(AnalyticsService::class);
    $range   = aptRange('2026-03-09', '2026-03-13');

    expect($service->overview($range)['avg_processing_minutes'])->toBe(50.0);

    $row = $service->processingTime($range)['by_document_type'][0];
    expect($row['avg_minutes'])->toBe(50.0)
        ->and($row['sample_count'])->toBe(1);
});

test('each document in a bulk request is measured on its own', function () {
    $admin = aptAdmin();
    $fast  = aptType('Certificate of Grades');
    $slow  = aptType('Transcript of Records');

    aptRequest([$fast, $slow], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
        // Whole request reaches Pending Signature after 240 minutes ...
        [S::Processing, S::PendingSignature, aptAt('2026-03-10 14:00:00'), null, $admin->user_id],
        [S::PendingSignature, S::Completed, aptAt('2026-03-11 09:00:00'), null, $admin->user_id],
        // ... but the first document finished on its own after 60.
        [S::Processing, S::ReadyToClaim, aptAt('2026-03-10 11:00:00'), 0, $admin->user_id],
    ]);

    $rows = collect(app(AnalyticsService::class)->processingTime(aptRange('2026-03-09', '2026-03-13'))['by_document_type'])
        ->keyBy('document_name');

    expect($rows['Certificate of Grades']['avg_minutes'])->toBe(60.0)
        ->and($rows['Transcript of Records']['avg_minutes'])->toBe(240.0)
        ->and($rows['Certificate of Grades']['sample_count'])->toBe(1);

    // The overview mean is per transaction (document line), not per request.
    expect(app(AnalyticsService::class)->overview(aptRange('2026-03-09', '2026-03-13'))['avg_processing_minutes'])->toBe(150.0);
});

test('a request still being processed is not counted', function () {
    $admin = aptAdmin();

    aptRequest([aptType('Transcript of Records')], S::Processing->value, aptAt('2026-03-10 10:00:00'), [
        [S::Processing, S::AwaitingSubmission, aptAt('2026-03-10 10:30:00'), null, $admin->user_id],
    ]);

    $service = app(AnalyticsService::class);
    $range   = aptRange('2026-03-09', '2026-03-13');

    expect($service->overview($range)['avg_processing_minutes'])->toBeNull()
        ->and($service->processingTime($range)['by_document_type'])->toBe([])
        ->and($service->processingTime($range)['by_admin'])->toBe([]);
});

// ── Period ───────────────────────────────────────────────────────────────────

test('a transaction belongs to the period in which its processing finished', function () {
    $admin = aptAdmin();

    // Filed Tuesday 10:00, finished Wednesday 10:00: 600 office minutes on
    // Tuesday (10:00-20:00) + 120 on Wednesday (08:00-10:00).
    aptRequest([aptType('Transcript of Records')], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
        [S::Processing, S::ReadyToClaim, aptAt('2026-03-11 10:00:00'), null, $admin->user_id],
        [S::ReadyToClaim, S::Completed, aptAt('2026-03-12 10:00:00'), null, $admin->user_id],
    ]);

    $service = app(AnalyticsService::class);

    expect($service->overview(aptRange('2026-03-11', '2026-03-11'))['avg_processing_minutes'])->toBe(720.0)
        ->and($service->overview(aptRange('2026-03-10', '2026-03-10'))['avg_processing_minutes'])->toBeNull()
        // Claimed on the 12th, but the registrar's work was finished on the 11th.
        ->and($service->overview(aptRange('2026-03-12', '2026-03-12'))['avg_processing_minutes'])->toBeNull();
});

// ── Staff ────────────────────────────────────────────────────────────────────

test('a transaction is credited to whoever finished the registrar\'s part; system transitions credit nobody', function () {
    $alice = aptAdmin();
    $bob   = aptAdmin();
    $type  = aptType('Transcript of Records');

    foreach ([[$alice->user_id, 30], [$bob->user_id, 90], [null, 10]] as [$userId, $minutes]) {
        aptRequest([$type], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
            [S::Processing, S::PendingSignature, aptAt('2026-03-10 10:00:00')->addMinutes($minutes), null, $userId],
            [S::PendingSignature, S::Completed, aptAt('2026-03-11 09:00:00'), null, $bob->user_id],
        ]);
    }

    $result = app(AnalyticsService::class)->processingTime(aptRange('2026-03-09', '2026-03-13'));

    // Three transactions overall, but only the two with a person behind them
    // appear per staff member — and later transitions (bob's claims) do not
    // steal credit for work someone else finished.
    expect($result['by_document_type'][0]['sample_count'])->toBe(3);

    expect($result['by_admin'])->toHaveCount(2);
    expect($result['by_admin'][0]['email'])->toBe($alice->email)
        ->and($result['by_admin'][0]['avg_minutes'])->toBe(30.0)
        ->and($result['by_admin'][0]['requests_handled'])->toBe(1)
        ->and($result['by_admin'][1]['email'])->toBe($bob->email)
        ->and($result['by_admin'][1]['avg_minutes'])->toBe(90.0);
});

// ── Consistency ──────────────────────────────────────────────────────────────

test('registrar_time and processingTime by_document_type are the same figures', function () {
    $admin = aptAdmin();

    aptRequest([aptType('Transcript of Records')], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
        [S::Processing, S::PendingSignature, aptAt('2026-03-10 12:00:00'), null, $admin->user_id],
        [S::PendingSignature, S::Completed, aptAt('2026-03-11 09:00:00'), null, $admin->user_id],
    ]);

    $service = app(AnalyticsService::class);
    $range   = aptRange('2026-03-09', '2026-03-13');

    expect($service->signatureTurnaroundTime($range)['registrar_time'])
        ->toBe($service->processingTime($range)['by_document_type']);
});

test('the AI payload carries the new per-staff figures', function () {
    $admin = aptAdmin();

    aptRequest([aptType('Transcript of Records')], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
        [S::Processing, S::PendingSignature, aptAt('2026-03-10 11:00:00'), null, $admin->user_id],
        [S::PendingSignature, S::Completed, aptAt('2026-03-11 09:00:00'), null, $admin->user_id],
    ]);

    $payload = app(AnalyticsService::class)->buildAiPayload(aptRange('2026-03-09', '2026-03-13'));

    expect($payload['processing_time']['by_admin'][0])
        ->toHaveKeys(['name', 'avg_minutes', 'min_minutes', 'max_minutes', 'requests_handled', 'requests_per_active_day', 'forfeit_rate'])
        ->and($payload['processing_time']['by_admin'][0]['avg_minutes'])->toBe(60.0);
});

test('the history is walked once per range however many panels ask for it', function () {
    $metrics = Mockery::mock(AnalyticsProcessingMetrics::class);
    $metrics->shouldReceive('aggregate')->once()->andReturn([
        'overall'  => ['count' => 0, 'total_minutes' => 0, 'avg_minutes' => null],
        'by_group' => [],
        'by_admin' => [],
    ]);

    $service = new AnalyticsService($metrics);
    $range   = aptRange('2026-03-09', '2026-03-13');

    $service->overview($range);
    $service->byDocumentType($range);
    $service->processingTime($range);
    $service->signatureTurnaroundTime($range);
});

// ── Comparison command ───────────────────────────────────────────────────────

test('the comparison command reports old and new averages for a period', function () {
    $admin = aptAdmin();

    aptRequest([aptType('Transcript of Records')], S::Completed->value, aptAt('2026-03-10 10:00:00'), [
        [S::Processing, S::PendingSignature, aptAt('2026-03-10 11:00:00'), null, $admin->user_id],
        [S::PendingSignature, S::Completed, aptAt('2026-03-11 09:00:00'), null, $admin->user_id],
    ]);

    $this->artisan('analytics:compare-processing', ['--from' => '2026-03-09', '--to' => '2026-03-13'])
        ->expectsOutputToContain('Period 2026-03-09 to 2026-03-13')
        ->assertSuccessful();
});

test('the comparison command rejects bad dates', function () {
    $this->artisan('analytics:compare-processing', ['--from' => '2026-13-40'])->assertFailed();
    $this->artisan('analytics:compare-processing', ['--from' => '2026-03-13', '--to' => '2026-03-09'])->assertFailed();
});
