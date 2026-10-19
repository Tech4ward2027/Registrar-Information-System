<?php

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// POST /document-requests/logbook/export-audit
// Helper names carry a "lea" prefix: Pest test files share one global
// function namespace.

function leaStaff(array $logbookActions): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    $policy = Policy::firstOrCreate(
        ['name' => 'Test Logbook ' . implode('', $logbookActions)],
        ['permissions' => ['dashboard' => ['View'], 'logbook' => $logbookActions], 'is_system' => false]
    );
    $admin->update(['policy_id' => $policy->policy_id]);

    Sanctum::actingAs($admin);

    return $admin;
}

function leaPayload(array $overrides = []): array
{
    return array_merge([
        'format'        => 'docx',
        'mode'          => 'documents',
        'date_from'     => '2026-01-01',
        'date_to'       => '2026-03-31',
        'row_count'     => 42,
        'section_count' => 3,
    ], $overrides);
}

const LEA_URL = '/api/v1/document-requests/logbook/export-audit';

it('records a logbook export with metadata only', function () {
    $admin = leaStaff(['View', 'Export']);

    $this->postJson(LEA_URL, leaPayload())->assertCreated();

    $row = AuditLog::where('action', AuditLog::ACTION_LOGBOOK_EXPORTED)->first();
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($admin->user_id)
        ->and($row->metadata)->toMatchArray([
            'format'        => 'docx',
            'mode'          => 'documents',
            'date_from'     => '2026-01-01',
            'date_to'       => '2026-03-31',
            'row_count'     => 42,
            'section_count' => 3,
        ]);
});

it('rejects staff who only have logbook View', function () {
    leaStaff(['View']);

    $this->postJson(LEA_URL, leaPayload())->assertForbidden();

    expect(AuditLog::where('action', AuditLog::ACTION_LOGBOOK_EXPORTED)->count())->toBe(0);
});

it('rejects unauthenticated callers', function () {
    $this->postJson(LEA_URL, leaPayload())->assertUnauthorized();
});

it('validates the payload', function (array $bad, string $field) {
    leaStaff(['View', 'Export']);

    $this->postJson(LEA_URL, leaPayload($bad))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown format'  => [['format' => 'pdf'], 'format'],
    'unknown mode'    => [['mode' => 'everything'], 'mode'],
    'bad date'        => [['date_from' => '03/01/2026'], 'date_from'],
    'range reversed'  => [['date_from' => '2026-04-01', 'date_to' => '2026-03-01'], 'date_to'],
    'negative rows'   => [['row_count' => -1], 'row_count'],
]);
