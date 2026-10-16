<?php

use App\Models\SystemUser;
use App\Support\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Same reason as SuperAdminAnalyticsControllerTest: the analytics endpoints
// cache under the 'analytics' tag in the process-wide array store.
beforeEach(fn () => Cache::tags(['analytics'])->flush());

function p0ActingAs(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

// ═════════════════════════════════════════════════════════════════════════════
// Safe defaults (fail closed)
// ═════════════════════════════════════════════════════════════════════════════

test('every new feature flag is declared with a false default (fail closed)', function () {
    // Assert on the source rather than runtime config: phpunit.xml forces these
    // env vars, so config() alone could not prove the file's own default.
    $source = file_get_contents(config_path('features.php'));

    foreach ([
        'ai_label_suggestions' => 'FEATURE_AI_LABEL_SUGGESTIONS',
        'system_health'        => 'FEATURE_SYSTEM_HEALTH',
        'analytics_ai_legacy'  => 'FEATURE_ANALYTICS_AI_LEGACY',
    ] as $flag => $env) {
        expect($source)->toMatch("/'{$flag}'\\s*=>\\s*\\(bool\\)\\s*env\\('{$env}',\\s*false\\)/");
    }
});

test('the test suite runs with all new flags off', function () {
    expect(config('features.ai_label_suggestions'))->toBeFalse()
        ->and(config('features.system_health'))->toBeFalse()
        ->and(config('features.analytics_ai_legacy'))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════════════
// Retired AI endpoints are gated server-side
// ═════════════════════════════════════════════════════════════════════════════

test('ai-report returns 404 when analytics_ai_legacy is off', function () {
    config(['features.analytics_ai_legacy' => false]);
    p0ActingAs(SystemUser::ROLE_SUPER_ADMIN);

    $this->postJson('/api/analytics/ai-report')->assertNotFound();
});

test('ai-query returns 404 when analytics_ai_legacy is off', function () {
    config(['features.analytics_ai_legacy' => false]);
    p0ActingAs(SystemUser::ROLE_SUPER_ADMIN);

    $this->postJson('/api/analytics/ai-query', ['question' => 'How many requests?'])->assertNotFound();
});

test('ai-report and ai-query pass the feature gate when analytics_ai_legacy is on', function () {
    config(['features.analytics_ai_legacy' => true]);
    Http::fake(); // never reach the real Anthropic API, whatever the host env holds
    p0ActingAs(SystemUser::ROLE_SUPER_ADMIN);

    // Not asserting the AI result (that is the retired feature's own
    // behaviour); only that the feature gate no longer hides the routes.
    expect($this->postJson('/api/analytics/ai-report')->status())->not->toBe(404);
    expect($this->postJson('/api/analytics/ai-query', ['question' => 'How many requests?'])->status())->not->toBe(404);
});

test('a student still gets 403, not 404, on ai-report when the flag is on (role gate unchanged)', function () {
    config(['features.analytics_ai_legacy' => true]);
    p0ActingAs(SystemUser::ROLE_STUDENT);

    $this->postJson('/api/analytics/ai-report')->assertForbidden();
});

// ═════════════════════════════════════════════════════════════════════════════
// Flag reporting to the SPA (allow-list)
// ═════════════════════════════════════════════════════════════════════════════

test('FeatureFlags::forClient exposes exactly the allow-listed flags as booleans', function () {
    config([
        'features.analytics_ai_legacy'  => true,
        'features.system_health'        => false,
        'features.ai_label_suggestions' => false,
    ]);

    $flags = FeatureFlags::forClient();

    expect(array_keys($flags))->toEqualCanonicalizing(FeatureFlags::CLIENT_VISIBLE)
        ->and($flags['analytics_ai_legacy'])->toBeTrue()
        ->and($flags['system_health'])->toBeFalse()
        ->and($flags['ai_label_suggestions'])->toBeFalse();
});

test('FeatureFlags::forClient never exposes flags outside the allow-list', function () {
    expect(FeatureFlags::forClient())->not->toHaveKey('free_request_page');
});

test('GET /api/me reports the allow-listed feature flags', function () {
    config(['features.analytics_ai_legacy' => true, 'features.system_health' => false]);
    p0ActingAs(SystemUser::ROLE_ADMIN);

    $response = $this->getJson('/api/me')->assertOk();

    expect($response->json('data.features.analytics_ai_legacy'))->toBeTrue()
        ->and($response->json('data.features.system_health'))->toBeFalse()
        ->and($response->json('data.features'))->not->toHaveKey('free_request_page');
});
