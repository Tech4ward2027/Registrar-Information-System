<?php

use App\Models\DocumentRequest;
use App\Models\RequestPurpose;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Regression test for the peak-hours timezone bug:
 *
 * requested_at is stored in UTC. A request submitted at 1:15 PM Asia/Manila
 * time (UTC+8) corresponds to 5:15 AM UTC.
 *
 * The analytics service must convert the stored UTC timestamp to the
 * configured display timezone before extracting the hour.
 *
 * This test explicitly stores the UTC instant so that the test does not
 * depend on Eloquent or the SQLite driver performing implicit timezone
 * conversion.
 */
test('peak hours reports the local Asia/Manila hour, not the raw UTC hour', function () {
    config(['app.display_timezone' => 'Asia/Manila']);

    $status = RequestStatus::firstOrCreate(
        ['status_id' => 1],
        ['status_name' => 'Processing']
    );

    $purpose = RequestPurpose::firstOrCreate(
        ['request_purpose_id' => 1],
        ['purpose_name' => 'DFA']
    );

    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status' => 'Activated',
    ]);

    /*
     * 1:15 PM Asia/Manila == 5:15 AM UTC.
     *
     * Convert explicitly to UTC before persistence. This makes the test
     * represent the same storage contract used by the application:
     *
     *     Local time  ->  UTC stored value
     *     13:15 +08   ->  05:15 UTC
     */
    $utcInstant = \Carbon\Carbon::parse(
        '2026-03-10 13:15:00',
        'Asia/Manila'
    )->utc();

    DocumentRequest::factory()->create([
        'user_id' => $user->user_id,
        'status_id' => $status->status_id,
        'request_purpose_id' => $purpose->request_purpose_id,
        'requested_at' => $utcInstant,
    ]);

    /*
     * Verify that the test really persisted the intended UTC instant.
     *
     * This prevents the regression test from silently passing/failing
     * because of driver-specific datetime serialization behavior.
     */
    $storedRequestedAt = DB::table('document_request')
        ->where('user_id', $user->user_id)
        ->value('requested_at');

    expect($storedRequestedAt)->toBe('2026-03-10 05:15:00');

    $service = app(AnalyticsService::class);

    $hours = $service->peakHours([
        \Carbon\Carbon::parse('2026-03-10 00:00:00', 'UTC'),
        \Carbon\Carbon::parse('2026-03-11 00:00:00', 'UTC'),
    ]);

    $byHour = collect($hours)->keyBy('hour');

    expect($byHour[13]['total'])->toBe(1)
        ->and($byHour[5]['total'])->toBe(0);
});