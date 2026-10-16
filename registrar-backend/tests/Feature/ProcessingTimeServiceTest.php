<?php

use App\Enums\RequestStatusEnum as S;
use App\Models\DocumentRequest;
use App\Models\RequestHistory;
use App\Services\BusinessCalendarService;
use App\Services\ProcessingTimeService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/*
 * No database. The business calendar is stubbed as plain elapsed minutes so
 * these tests pin down the STATUS rules of the COMPUTATION RULE (what counts,
 * what pauses, where the clock stops). Office-hours arithmetic itself is
 * covered by BusinessCalendarServiceTest.
 *
 * Helper names carry a "ptt" prefix: Pest test files share one global
 * function namespace.
 */

function pttService(?Closure $minutesBetween = null): ProcessingTimeService
{
    $calendar = Mockery::mock(BusinessCalendarService::class);
    $calendar->shouldReceive('minutesBetween')->andReturnUsing(
        $minutesBetween ?? fn ($start, $end) => (int) $start->diffInMinutes($end)
    );

    return new ProcessingTimeService($calendar);
}

/** A row: [old, new, 'H:i' on 2026-03-10] with optional item ids. */
function pttRow(int $id, ?int $old, int $new, string $at, ?int $documentId = null, ?int $certificateId = null): RequestHistory
{
    $row = new RequestHistory([
        'request_document_id'    => $documentId,
        'request_certificate_id' => $certificateId,
        'old_status_id'          => $old,
        'new_status_id'          => $new,
        'changed_at'             => Carbon::parse("2026-03-10 {$at}:00", 'UTC'),
    ]);
    $row->request_history_id = $id;

    return $row;
}

function pttRequest(string $requestedAt, array $rows): DocumentRequest
{
    $request = new DocumentRequest();
    $request->request_id   = 1;
    $request->requested_at = Carbon::parse("2026-03-10 {$requestedAt}:00", 'UTC');
    $request->setRelation('history', new EloquentCollection($rows));

    return $request;
}

test('the clock stops when the request enters Pending Signature', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::PendingSignature->value, '11:00'),
    ]);

    $result = pttService()->forRequest($request);

    expect($result->minutes)->toBe(120)
        ->and($result->endStatusId)->toBe(S::PendingSignature->value)
        ->and($result->processedAt->format('H:i'))->toBe('11:00');
});

test('the signing wait, pickup wait and claiming never change the figure', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::PendingSignature->value, '11:00'),
        pttRow(2, S::PendingSignature->value, S::ReadyToClaim->value, '16:00'),
        pttRow(3, S::ReadyToClaim->value, S::Completed->value, '19:30'),
    ]);

    $result = pttService()->forRequest($request);

    expect($result->minutes)->toBe(120)
        ->and($result->processedAt->format('H:i'))->toBe('11:00')
        ->and($result->claimedAt->format('H:i'))->toBe('19:30');
});

test('a request needing no signature stops at Ready to Claim', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::ReadyToClaim->value, '10:00'),
        pttRow(2, S::ReadyToClaim->value, S::Completed->value, '15:00'),
    ]);

    $result = pttService()->forRequest($request);

    expect($result->minutes)->toBe(60)
        ->and($result->endStatusId)->toBe(S::ReadyToClaim->value);
});

test('time in Awaiting Submission is never counted and counting restarts on Processing', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::AwaitingSubmission->value, S::Processing->value, '14:00'),
        pttRow(2, S::Processing->value, S::PendingSignature->value, '14:10'),
    ]);

    expect(pttService()->forRequest($request)->minutes)->toBe(10);
});

test('every pause is excluded, not just the latest one', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::AwaitingSubmission->value, '10:00'),   // counts 60
        pttRow(2, S::AwaitingSubmission->value, S::Processing->value, '15:00'),   // paused 300
        pttRow(3, S::Processing->value, S::AwaitingSubmission->value, '15:30'),   // counts 30
        pttRow(4, S::AwaitingSubmission->value, S::Processing->value, '18:00'),   // paused 150
        pttRow(5, S::Processing->value, S::PendingSignature->value, '18:20'),     // counts 20
    ]);

    expect(pttService()->forRequest($request)->minutes)->toBe(110);
});

test('a request that has not stopped yet is unmeasured, not guessed', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::AwaitingSubmission->value, S::Processing->value, '14:00'),
    ]);

    $result = pttService()->forRequest($request);

    expect($result->minutes)->toBeNull()
        ->and($result->isMeasured())->toBeFalse();
});

test('a request with no history at all is unmeasured', function () {
    expect(pttService()->forRequest(pttRequest('09:00', []))->minutes)->toBeNull();
});

test('a missing old status inherits the previous status', function () {
    $request = pttRequest('09:00', [
        pttRow(1, null, S::PendingSignature->value, '09:45'),
    ]);

    expect(pttService()->forRequest($request)->minutes)->toBe(45);
});

test('the calendar is asked per Processing span so it can skip closed hours', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::PendingSignature->value, '11:00'),
    ]);

    // Stub reports only 30 office-hours minutes for the span.
    $result = pttService(fn () => 30)->forRequest($request);

    expect($result->minutes)->toBe(30);
});

test('a calendar failure yields an unmeasured record instead of an exception', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::PendingSignature->value, '11:00'),
    ]);

    $result = pttService(fn () => throw new RuntimeException('No default business calendar'))->forRequest($request);

    expect($result->minutes)->toBeNull();
});

test('documents in one request are measured independently', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::AwaitingSubmission->value, S::Processing->value, '10:00'),
        pttRow(2, S::Processing->value, S::PendingSignature->value, '10:30', documentId: 11),
        pttRow(3, S::Processing->value, S::PendingSignature->value, '12:00', documentId: 12),
        pttRow(4, S::Processing->value, S::PendingSignature->value, '12:00'),   // aggregate: slowest item
    ]);

    $service = pttService();
    $history = $service->sortedHistory($request);

    expect($service->forDocument($request, 11, $history)->minutes)->toBe(30)    // faster document
        ->and($service->forDocument($request, 12, $history)->minutes)->toBe(120) // slower document
        ->and($service->forRequest($request, $history)->minutes)->toBe(120);
});

test('an item with no rows of its own follows the whole-request timeline', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::PendingSignature->value, '10:00'),
    ]);

    expect(pttService()->forDocument($request, 99)->minutes)->toBe(60)
        ->and(pttService()->forCertificate($request, 99)->minutes)->toBe(60);
});

test('rows are ordered by time even when stored out of order', function () {
    $request = pttRequest('09:00', [
        pttRow(2, S::PendingSignature->value, S::ReadyToClaim->value, '16:00'),
        pttRow(1, S::Processing->value, S::PendingSignature->value, '11:00'),
    ]);

    expect(pttService()->forRequest($request)->minutes)->toBe(120);
});

test('summaryFor returns the request figure and one entry per line item', function () {
    $request = pttRequest('09:00', [
        pttRow(1, S::Processing->value, S::PendingSignature->value, '11:00'),
    ]);

    $document    = (object) ['request_document_id' => 5];
    $certificate = (object) ['request_certificate_id' => 6];
    $request->setRelation('documents', new EloquentCollection([$document]));
    $request->setRelation('certificates', new EloquentCollection([$certificate]));

    $summary = pttService()->summaryFor($request);

    expect($summary['processing']['minutes'])->toBe(120)
        ->and($summary['processing']['processed_at'])->toBe('2026-03-10T11:00:00Z')
        ->and($summary['items_processing'])->toHaveCount(2)
        ->and($summary['items_processing'][0])->toMatchArray(['type' => 'document', 'id' => 5, 'minutes' => 120])
        ->and($summary['items_processing'][1])->toMatchArray(['type' => 'certificate', 'id' => 6, 'minutes' => 120]);
});
