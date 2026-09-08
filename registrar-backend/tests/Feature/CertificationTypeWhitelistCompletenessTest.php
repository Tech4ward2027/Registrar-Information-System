<?php

use App\Http\Controllers\CertificationTypeController;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| CertificationTypeWhitelistCompletenessTest
|--------------------------------------------------------------------------
| CertificationTypeController::certColumns() is a hand-maintained
| select() whitelist. Every response from that controller (index, show,
| store, update, layouts) only ever returns columns listed there — a
| column can exist on the table, on the model's $fillable/$casts, and be
| written correctly by every FormRequest, and still be silently dropped
| from every JSON response if nobody remembers to add it here too.
|
| This has happened four separate times on this table:
|   1. logbook_category_id / requires_source_submission
|   2. fulfillment_track_id
|   3. cashier_document_patterns
|   4. is_free_eligible / free_issuance_limit (FESPEC-0008) — this one
|      shipped silently: COG never appeared in the Free Requests catalog
|      because the frontend's `is_free_eligible === true` check always
|      saw `undefined`, even though the DB value was set correctly by
|      FreeIssuanceEligibilitySeeder.
|
| Rather than trust the next person to remember a fifth time, this test
| diffs the real `certificate_type` table schema against certColumns()
| and fails the build the moment they drift — turning a silent, easy-to-
| miss production bug into a loud, specific CI failure.
|
| If a future column is *deliberately* meant to stay out of API
| responses (e.g. an internal-only flag), add it to
| $intentionallyExcludedColumns below with a comment explaining why,
| rather than leaving the whitelist to just "happen" to omit it.
|--------------------------------------------------------------------------
*/

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('every certificate_type column is either exposed via certColumns() or explicitly excluded on purpose', function () {
    $intentionallyExcludedColumns = [
        // (none today — every column on this table is meant to be
        // readable by staff through the admin API.)
    ];

    $actualTableColumns = Schema::getColumnListing('certificate_type');

    $controller = app(CertificationTypeController::class);
    $reflection = new ReflectionMethod($controller, 'certColumns');
    $reflection->setAccessible(true);
    $whitelistedColumns = $reflection->invoke($controller);

    $missingFromWhitelist = array_values(array_diff(
        $actualTableColumns,
        $whitelistedColumns,
        $intentionallyExcludedColumns
    ));

    expect($missingFromWhitelist)->toBe([], sprintf(
        "The following certificate_type column(s) exist in the database but are missing from ".
        "CertificationTypeController::certColumns(), so they will be silently stripped from every ".
        "API response (index/show/store/update/layouts): %s. Either add them to certColumns(), or, ".
        "if that's intentional, add them to \$intentionallyExcludedColumns in this test with a comment ".
        "explaining why.",
        implode(', ', $missingFromWhitelist)
    ));

    // Guards the inverse mistake too: a stale whitelist entry referencing
    // a column that no longer exists (e.g. after a column rename) should
    // also be caught rather than silently referencing nothing.
    $whitelistedButMissingFromTable = array_values(array_diff($whitelistedColumns, $actualTableColumns));

    expect($whitelistedButMissingFromTable)->toBe([], sprintf(
        'CertificationTypeController::certColumns() references column(s) that no longer exist on '.
        'certificate_type: %s. Update the whitelist to match the current schema.',
        implode(', ', $whitelistedButMissingFromTable)
    ));
});

test('the certifications index endpoint actually returns is_free_eligible and free_issuance_limit', function () {
    // A schema-level check alone would not have caught the original bug if
    // it had also existed only accidentally in the model but not in the
    // migration — this end-to-end assertion pins the real, observable
    // symptom: staff opening Free Requests must see these fields on every
    // certificate row, not just have them present in the database.
    $user = App\Models\SystemUser::factory()->create(['role_id' => 3, 'status' => 'Activated']);
    Laravel\Sanctum\Sanctum::actingAs($user);

    $access = App\Models\AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student']);

    $cert = App\Models\CertificationType::create([
        'certificate_name'           => 'Certificate of Graduation (Test)',
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => $access->access_id,
        'is_free_eligible'           => true,
        'free_issuance_limit'        => 1,
    ]);

    $response = $this->getJson('/api/certifications');

    $response->assertOk();

    $returned = collect($response->json())->firstWhere('certificate_type_id', $cert->certificate_type_id);

    expect($returned)->not->toBeNull();
    expect($returned)->toHaveKey('is_free_eligible');
    expect($returned)->toHaveKey('free_issuance_limit');
    expect($returned['is_free_eligible'])->toBeTrue();
    expect($returned['free_issuance_limit'])->toBe(1);
});
