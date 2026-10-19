<?php

use App\Models\Signatory;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function signatoryMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);
    return $user;
}

function signatoryMake(array $overrides = []): Signatory
{
    return Signatory::create(array_merge([
        'name'       => 'Mhel P. Garcia',
        'position'   => 'Campus Registrar/Head of Registration Office',
        'sort_order' => 0,
    ], $overrides));
}

// ═════════════════════════════════════════════════════════════════════════════
// index() — role:3 (admin) only, unlike document-types/certifications
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot list signatories', function () {
    signatoryMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/v1/signatories')->assertStatus(403);
});

test('admin can list signatories ordered by sort_order', function () {
    // The 2026_08_13_000000_create_signatories_table migration seeds its
    // own two rows with these same names, so this test's rows would
    // otherwise sit alongside duplicates. Start from a clean table so the
    // ordering assertion below is deterministic.
    Signatory::query()->delete();

    $second = signatoryMake(['name' => 'Marissa B. Ferrer, DEM, RPsy', 'position' => 'Director', 'sort_order' => 1]);
    $first  = signatoryMake(['name' => 'Mhel P. Garcia', 'sort_order' => 0]);
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $response = $this->getJson('/api/v1/signatories')->assertOk();

    $names = collect($response->json())->pluck('name')->values()->all();
    expect($names)->toBe(['Mhel P. Garcia', 'Marissa B. Ferrer, DEM, RPsy']);
});

test('super admin can list signatories', function () {
    signatoryMake();
    signatoryMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson('/api/v1/signatories')->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// store() — role:3 (admin) only, via StoreSignatoryRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot create a signatory', function () {
    signatoryMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson('/api/v1/signatories', [
        'name'     => 'Juan Dela Cruz',
        'position' => 'Registrar',
    ])->assertStatus(403);
});

test('admin can create a signatory with valid data', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/signatories', [
        'name'       => 'Juan Dela Cruz',
        'position'   => 'Assistant Registrar',
        'sort_order' => 2,
    ])->assertCreated()
      ->assertJsonPath('name', 'Juan Dela Cruz')
      ->assertJsonPath('position', 'Assistant Registrar')
      ->assertJsonPath('sort_order', 2);
});

test('store fails validation when name is missing', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/signatories', [
        'position' => 'Registrar',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['name']);
});

test('store fails validation when position is missing', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/signatories', [
        'name' => 'Juan Dela Cruz',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['position']);
});

test('store fails validation when name exceeds max length', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/signatories', [
        'name'     => str_repeat('a', 256),
        'position' => 'Registrar',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['name']);
});

test('store fails validation when sort_order is not an integer', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/signatories', [
        'name'       => 'Juan Dela Cruz',
        'position'   => 'Registrar',
        'sort_order' => 'not-a-number',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['sort_order']);
});

// ═════════════════════════════════════════════════════════════════════════════
// update() — role:3 (admin) only, via UpdateSignatoryRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot update a signatory', function () {
    $signatory = signatoryMake();
    signatoryMakeUser(SystemUser::ROLE_STUDENT);

    $this->putJson("/api/v1/signatories/{$signatory->signatory_id}", [
        'name' => 'Renamed',
    ])->assertStatus(403);
});

test('admin can partially update a signatory', function () {
    $signatory = signatoryMake();
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/signatories/{$signatory->signatory_id}", [
        'position' => 'Updated Position',
    ])->assertOk()
      ->assertJsonPath('position', 'Updated Position')
      ->assertJsonPath('name', 'Mhel P. Garcia'); // unchanged
});

test('update returns 404 for a missing signatory', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson('/api/v1/signatories/999', [
        'name' => 'Whatever',
    ])->assertStatus(404)
      ->assertJson(['message' => 'Signatory not found']);
});

test('update fails validation when name is sent empty', function () {
    $signatory = signatoryMake();
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/signatories/{$signatory->signatory_id}", [
        'name' => '',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['name']);
});

// ═════════════════════════════════════════════════════════════════════════════
// destroy() — role:3 (admin) only, no request body
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot delete a signatory', function () {
    $signatory = signatoryMake();
    signatoryMakeUser(SystemUser::ROLE_STUDENT);

    $this->deleteJson("/api/v1/signatories/{$signatory->signatory_id}")
         ->assertStatus(403);
});

test('destroy returns 404 for a missing signatory', function () {
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson('/api/v1/signatories/999')
         ->assertStatus(404);
});

test('admin can delete a signatory', function () {
    $signatory = signatoryMake();
    signatoryMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson("/api/v1/signatories/{$signatory->signatory_id}")
         ->assertOk()
         ->assertJson(['message' => 'Signatory deleted']);

    $this->assertDatabaseMissing('signatories', ['signatory_id' => $signatory->signatory_id]);
});