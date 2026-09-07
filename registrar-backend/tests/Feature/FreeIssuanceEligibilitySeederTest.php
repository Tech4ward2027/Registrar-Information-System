<?php

use App\Models\CertificationType;
use App\Models\DocumentType;
use Database\Seeders\FreeIssuanceEligibilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 * Phase 9 — Rollout: the data seeder that actually turns the feature on.
 *
 * Self-contained with a unique `fie` prefix ("Free Issuance Eligibility"),
 * following the same per-file-prefix convention as the other six
 * FreeRequest test files (fr/frc/frh/frr/frs/frv are all already taken —
 * see FreeRequestReportTest's docblock for the running list).
 *
 * This file does not exercise FreeRequestEligibilityService itself (that's
 * FreeRequestEligibilityServiceTest's job) — it only asserts the seeder
 * writes the exact rows/values Phase 9 specifies, is idempotent, and
 * does not touch any row outside its three targets.
 */

function fieMakeDocType(int $id, array $overrides = []): DocumentType
{
    // Neither DocumentType nor CertificationType has a factory anywhere in
    // this codebase — every other FreeRequest test file builds these via
    // plain ::create() (see FreeRequestServiceTest's frsMakeUnlimitedDocType
    // etc.). This helper needs forceCreate() rather than create(), though:
    // document_type_id isn't in $fillable (by design — it's a plain
    // autoincrement PK, never mass-assigned in application code), but this
    // test suite specifically needs rows at the exact IDs the seeder
    // targets (15, 17), not whatever ID autoincrement happens to hand out.
    return DocumentType::forceCreate(array_merge([
        'document_type_id'        => $id,
        'document_name'           => "Fixture Document Type {$id}",
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => 3,
        'is_free_eligible'        => false,
        'free_issuance_limit'     => null,
    ], $overrides));
}

function fieMakeCertType(int $id, array $overrides = []): CertificationType
{
    // See fieMakeDocType() above — same reasoning, forceCreate() for the
    // same explicit-PK need.
    return CertificationType::forceCreate(array_merge([
        'certificate_type_id'        => $id,
        'certificate_name'           => "Fixture Certificate Type {$id}",
        'certificate_requirements'   => 'Test fixture requirements.',
        'certificate_process_period' => '1 working day',
        'access_id'                  => 3,
        'is_free_eligible'           => false,
        'free_issuance_limit'        => null,
    ], $overrides));
}

test('it sets TOR (document_type 15) free-eligible with a limit of 1', function () {
    fieMakeDocType(15);

    (new FreeIssuanceEligibilitySeeder())->run();

    $tor = DocumentType::find(15);
    expect($tor->is_free_eligible)->toBeTrue();
    expect($tor->free_issuance_limit)->toBe(1);
});

test('it sets LOA (document_type 17) free-eligible with an unlimited (null) limit', function () {
    fieMakeDocType(17);

    (new FreeIssuanceEligibilitySeeder())->run();

    $loa = DocumentType::find(17);
    expect($loa->is_free_eligible)->toBeTrue();
    expect($loa->free_issuance_limit)->toBeNull();
});

test('it sets COG (certificate_type 6) free-eligible with a limit of 1', function () {
    fieMakeCertType(6);

    (new FreeIssuanceEligibilitySeeder())->run();

    $cog = CertificationType::find(6);
    expect($cog->is_free_eligible)->toBeTrue();
    expect($cog->free_issuance_limit)->toBe(1);
});

test('it does not touch any document_type or certificate_type row outside its three targets', function () {
    fieMakeDocType(15);
    fieMakeDocType(17);
    fieMakeCertType(6);

    $untouchedDoc = fieMakeDocType(2);
    $untouchedCert = fieMakeCertType(1);

    (new FreeIssuanceEligibilitySeeder())->run();

    expect($untouchedDoc->refresh()->is_free_eligible)->toBeFalse();
    expect($untouchedDoc->refresh()->free_issuance_limit)->toBeNull();
    expect($untouchedCert->refresh()->is_free_eligible)->toBeFalse();
    expect($untouchedCert->refresh()->free_issuance_limit)->toBeNull();
});

test('it is idempotent — running it twice leaves the same end state', function () {
    fieMakeDocType(15);
    fieMakeDocType(17);
    fieMakeCertType(6);

    $seeder = new FreeIssuanceEligibilitySeeder();
    $seeder->run();
    $seeder->run();

    $tor = DocumentType::find(15);
    $loa = DocumentType::find(17);
    $cog = CertificationType::find(6);

    expect($tor->is_free_eligible)->toBeTrue();
    expect($tor->free_issuance_limit)->toBe(1);
    expect($loa->is_free_eligible)->toBeTrue();
    expect($loa->free_issuance_limit)->toBeNull();
    expect($cog->is_free_eligible)->toBeTrue();
    expect($cog->free_issuance_limit)->toBe(1);
});

test('it does not throw when a target row is missing, and touches nothing else', function () {
    // Deliberately do not create document_type 15/17 or certificate_type 6.
    $unrelatedDoc = fieMakeDocType(99);

    (new FreeIssuanceEligibilitySeeder())->run();

    expect(DocumentType::find(15))->toBeNull();
    expect(DocumentType::find(17))->toBeNull();
    expect(CertificationType::find(6))->toBeNull();
    expect($unrelatedDoc->refresh()->is_free_eligible)->toBeFalse();
});