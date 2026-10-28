<?php

use App\Models\Policy;
use App\Models\SystemUser;
use App\Support\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function tkActingAs(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

// ── Flag: fail closed ───────────────────────────────────────────────────────

test('the tickets flag is declared with a false default (fail closed)', function () {
    // Assert on the source: phpunit.xml forces the env var, so config() alone
    // cannot prove the file's own default.
    expect(file_get_contents(config_path('features.php')))
        ->toMatch("/'tickets'\\s*=>\\s*\\(bool\\)\\s*env\\('FEATURE_TICKETS',\\s*false\\)/");
});

test('the suite runs with the tickets flag off unless a test turns it on', function () {
    expect(config('features.tickets'))->toBeFalse();
});

test('the SPA is told about the tickets flag', function () {
    config(['features.tickets' => true]);

    expect(FeatureFlags::CLIENT_VISIBLE)->toContain('tickets')
        ->and(FeatureFlags::forClient()['tickets'])->toBeTrue();
});

// ── Routes: 404 when off ────────────────────────────────────────────────────

test('ticket routes answer 404 while the flag is off, for every role', function () {
    config(['features.tickets' => false]);

    foreach ([SystemUser::ROLE_STUDENT, SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN] as $role) {
        tkActingAs($role);
        $this->getJson('/api/v1/tickets/categories')->assertNotFound();
    }
});

test('ticket routes still require authentication when the flag is on', function () {
    config(['features.tickets' => true]);

    $this->getJson('/api/v1/tickets/categories')->assertUnauthorized();
});

test('the category list returns the five approved categories in order once the flag is on', function () {
    config(['features.tickets' => true]);
    tkActingAs(SystemUser::ROLE_STUDENT);

    $response = $this->getJson('/api/v1/tickets/categories')->assertOk();

    expect($response->json('data.*.code'))->toBe(['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04', 'CAT-05'])
        ->and($response->json('data.*.priority'))->toBe(['high', 'medium', 'medium', 'low', 'low'])
        ->and($response->json('data.0'))->not->toHaveKey('id');
});

// ── Policy module ───────────────────────────────────────────────────────────

test('tickets is a registered granular policy module with the agreed actions', function () {
    expect(Policy::MODULE_KEYS)->toContain('tickets')
        ->and(Policy::actionsFor('tickets'))->toBe(['View', 'Claim', 'Reply', 'Resolve', 'Manage']);
});

test('an admin holds only the ticket actions their policy grants', function () {
    $policy = Policy::create([
        'name' => 'Ticket Responder', 'is_system' => false,
        'permissions' => ['tickets' => ['View', 'Reply']],
    ]);
    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => $policy->policy_id,
    ]);

    expect($admin->hasModuleAccess('tickets', 'View'))->toBeTrue()
        ->and($admin->hasModuleAccess('tickets', 'Reply'))->toBeTrue()
        ->and($admin->hasModuleAccess('tickets', 'Claim'))->toBeFalse()
        ->and($admin->hasModuleAccess('tickets', 'Resolve'))->toBeFalse()
        ->and($admin->hasModuleAccess('tickets', 'Manage'))->toBeFalse();
});

test('no existing policy is granted tickets: a new module is deny-by-default', function () {
    foreach (Policy::all() as $policy) {
        expect($policy->permissions ?? [])->not->toHaveKey('tickets');
    }
});

// ── config/tickets.php ──────────────────────────────────────────────────────

test('the public magic-link token is never configured below 32 bytes', function () {
    expect(config('tickets.public.token_bytes'))->toBeGreaterThanOrEqual(32);
});

test('config/tickets.php carries every value the policy fixes', function () {
    expect(config('tickets.auto_close_days'))->toBe(3)
        ->and(config('tickets.reminder_after_days'))->toBe(2)
        ->and(config('tickets.max_requester_returns'))->toBe(2)
        ->and(config('tickets.claim_timeout_working_hours'))->toBe(24)
        ->and(config('tickets.retention.years'))->toBe(3)
        ->and(config('tickets.rate_limits.public.verification_emails_per_email_per_hour'))->toBe(3)
        ->and(config('tickets.rate_limits.public.verification_emails_per_ip_per_hour'))->toBe(10)
        ->and(config('tickets.rate_limits.public.tickets_per_email_per_day'))->toBe(3)
        ->and(config('tickets.rate_limits.public.tickets_per_ip_per_day'))->toBe(10)
        ->and(config('tickets.rate_limits.public.open_tickets_per_email'))->toBe(3)
        ->and(config('tickets.rate_limits.replies_per_ticket_per_hour'))->toBe(10)
        ->and(config('tickets.sla.first_response_hours'))->toBe(['high' => 4, 'medium' => 8, 'low' => 8])
        ->and(config('tickets.sla.resolution_days'))->toBe(['high' => 1, 'medium' => 2, 'low' => 2])
        ->and(config('tickets.resolve_requires_claimer'))->toBeFalse();
});
