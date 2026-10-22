<?php

/**
 * MT-38 — Backend authorization and safe suggestion display.
 *
 * Endpoints under test (routes/api.php, prefix /api/v1):
 *   GET  /unmatched-cashier-items
 *   POST /unmatched-cashier-items/{id}/resolve
 *   POST /unmatched-cashier-items/{id}/dismiss
 *
 * Verified authorization basis (routes/api.php, RoleMiddleware, EnsureModuleAccess):
 *   auth:sanctum            -> unauthenticated            => 401
 *   role:3,4                -> student / alumni           => 403
 *   module:cashier_reconciliation
 *                           -> admin WITHOUT the grant    => 403
 *                           -> admin WITH the grant       => allowed
 *   Super admin (role 4) bypasses both the role and the module check.
 *
 * Every denied request must also leave the database untouched and must not
 * leak record data, stack traces or credentials in the response body.
 *
 * All helpers are prefixed `mt38_` because Pest helper functions are global
 * and would otherwise collide with helpers in other test files.
 */

use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const MT38_LABEL = 'Mt38 Confidential Receipt Label';

/** Grants the module the routes require. */
function mt38_reconciliation_policy(): Policy
{
    return Policy::create([
        'name'        => 'MT38 Reconciliation ' . uniqid(),
        'permissions' => ['cashier_reconciliation' => ['Access']],
        'is_system'   => false,
    ]);
}

/**
 * Signs in a caller of the given kind (or leaves the request unauthenticated).
 *
 * @param 'guest'|'student'|'alumni'|'admin_no_policy'|'admin_other_module'|'admin_granted'|'super_admin' $kind
 */
function mt38_sign_in(string $kind): ?SystemUser
{
    if ($kind === 'guest') {
        return null;
    }

    $attributes = match ($kind) {
        'student'            => ['role_id' => SystemUser::ROLE_STUDENT],
        'alumni'             => ['role_id' => SystemUser::ROLE_ALUMNI],
        'super_admin'        => ['role_id' => SystemUser::ROLE_SUPER_ADMIN],
        'admin_no_policy'    => ['role_id' => SystemUser::ROLE_ADMIN],
        'admin_other_module' => [
            'role_id'   => SystemUser::ROLE_ADMIN,
            'policy_id' => Policy::create([
                'name'        => 'MT38 Other ' . uniqid(),
                'permissions' => ['dashboard' => ['View', 'Process', 'Complete']],
                'is_system'   => false,
            ])->policy_id,
        ],
        'admin_granted'      => [
            'role_id'   => SystemUser::ROLE_ADMIN,
            'policy_id' => mt38_reconciliation_policy()->policy_id,
        ],
    };

    $user = SystemUser::factory()->create($attributes + ['status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

function mt38_item(string $label = MT38_LABEL): UnmatchedCashierItem
{
    return UnmatchedCashierItem::create([
        'raw_label'        => $label,
        'normalised_label' => UnmatchedCashierItem::normaliseLabel($label),
        'occurrence_count' => 4,
        'first_seen_at'    => now()->subDays(2),
        'last_seen_at'     => now()->subHour(),
    ]);
}

function mt38_target(): DocumentType
{
    return DocumentType::create([
        'document_name'             => 'MT38 Target Document',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['existing pattern'],
    ]);
}

/** Raw, cast-free snapshot of every row the three endpoints can mutate. */
function mt38_snapshot(UnmatchedCashierItem $item, DocumentType $target): array
{
    return [
        'item'   => (array) DB::table('unmatched_cashier_items')
            ->where('unmatched_cashier_item_id', $item->getKey())->first(),
        'target' => (array) DB::table($target->getTable())
            ->where($target->getKeyName(), $target->getKey())->first(),
        'audit'  => AuditLog::count(),
    ];
}

/** @return \Illuminate\Testing\TestResponse */
function mt38_call(string $endpoint, int|string $id, int $targetId)
{
    return match ($endpoint) {
        'list'    => test()->getJson('/api/v1/unmatched-cashier-items'),
        'resolve' => test()->postJson("/api/v1/unmatched-cashier-items/{$id}/resolve", [
            'document_type_id' => $targetId,
        ]),
        'dismiss' => test()->postJson("/api/v1/unmatched-cashier-items/{$id}/dismiss"),
    };
}

/** The response body must never expose record data, internals or credentials. */
function mt38_assert_body_is_safe($response): void
{
    $body = (string) $response->getContent();

    expect($body)
        ->not->toContain(MT38_LABEL)
        ->not->toContain('existing pattern')
        ->not->toContain('"trace"')
        ->not->toContain('"exception"')
        ->not->toContain('"file"')
        ->not->toContain('password')
        ->not->toContain('SQLSTATE');
}

// ═════════════════════════════════════════════════════════════════════════════
// MT-38A — denied callers (12 endpoint x caller cases plus the admin variants)
// ═════════════════════════════════════════════════════════════════════════════

dataset('mt38_endpoints', ['list', 'resolve', 'dismiss']);

dataset('mt38_denied_callers', [
    'unauthenticated'                   => ['guest', 401],
    'student'                           => ['student', 403],
    'alumni'                            => ['alumni', 403],
    'admin without the module grant'    => ['admin_other_module', 403],
    'admin with no policy attached'     => ['admin_no_policy', 403],
]);

test('MT-38A denied caller is rejected and nothing is changed or leaked', function (string $endpoint, string $kind, int $expected) {
    $item   = mt38_item();
    $target = mt38_target();
    mt38_sign_in($kind);

    $before   = mt38_snapshot($item, $target);
    $response = mt38_call($endpoint, $item->getKey(), $target->getKey());

    $response->assertStatus($expected);
    mt38_assert_body_is_safe($response);

    // Database integrity: item, target patterns and audit trail are untouched.
    expect(mt38_snapshot($item, $target))->toBe($before);
})->with('mt38_endpoints')->with('mt38_denied_callers');

test('MT-38A authorization runs before the record lookup, so a denied caller cannot probe which ids exist', function (string $endpoint) {
    mt38_sign_in('student');

    $existing = mt38_call($endpoint, mt38_item()->getKey(), 1);
    $missing  = mt38_call($endpoint, 99999999, 1);

    // Same status for an existing and a non-existing id: 403, never 404.
    expect($existing->getStatusCode())->toBe(403)
        ->and($missing->getStatusCode())->toBe(403);
})->with(['resolve', 'dismiss']);

// ═════════════════════════════════════════════════════════════════════════════
// MT-38A — permitted callers
// ═════════════════════════════════════════════════════════════════════════════

dataset('mt38_allowed_callers', [
    'admin with the module grant' => ['admin_granted'],
    'super admin (bypass)'        => ['super_admin'],
]);

test('MT-38A permitted caller can list unmatched items', function (string $kind) {
    $item = mt38_item();
    mt38_sign_in($kind);

    $response = test()->getJson('/api/v1/unmatched-cashier-items');

    $response->assertOk()
        ->assertJsonPath('data.0.unmatched_cashier_item_id', $item->getKey());
})->with('mt38_allowed_callers');

test('MT-38A permitted caller resolving attaches the label exactly once and records the actor', function (string $kind) {
    $item   = mt38_item();
    $target = mt38_target();
    $actor  = mt38_sign_in($kind);

    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [
        'document_type_id' => $target->getKey(),
    ])->assertOk();

    expect($target->fresh()->cashier_document_patterns)->toBe(['existing pattern', MT38_LABEL])
        ->and($item->fresh()->resolved_at)->not->toBeNull()
        ->and($item->fresh()->resolved_by)->toBe($actor->user_id);

    // A repeat is rejected and must not append the pattern a second time.
    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [
        'document_type_id' => $target->getKey(),
    ])->assertStatus(409);

    expect($target->fresh()->cashier_document_patterns)->toBe(['existing pattern', MT38_LABEL]);
})->with('mt38_allowed_callers');

test('MT-38A permitted caller dismissing resolves the item without adding any pattern', function (string $kind) {
    $item   = mt38_item();
    $target = mt38_target();
    $actor  = mt38_sign_in($kind);

    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/dismiss")->assertOk();

    expect($item->fresh()->resolved_at)->not->toBeNull()
        ->and($item->fresh()->resolved_by)->toBe($actor->user_id)
        ->and($target->fresh()->cashier_document_patterns)->toBe(['existing pattern']);
})->with('mt38_allowed_callers');

test('MT-38A a permitted caller sending an invalid resolve payload changes nothing', function () {
    $item   = mt38_item();
    $target = mt38_target();
    mt38_sign_in('admin_granted');

    $before = mt38_snapshot($item, $target);

    // Neither id, then both ids: the request class requires exactly one.
    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [])
        ->assertStatus(422);
    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [
        'document_type_id'    => $target->getKey(),
        'certificate_type_id' => 1,
    ])->assertStatus(422);

    expect(mt38_snapshot($item, $target))->toBe($before);
});

// ═════════════════════════════════════════════════════════════════════════════
// MT-38B — safe display of suggestion / model output
// ═════════════════════════════════════════════════════════════════════════════

function mt38_item_with_hostile_suggestion(): UnmatchedCashierItem
{
    $item = mt38_item();

    DB::table('unmatched_cashier_items')
        ->where('unmatched_cashier_item_id', $item->getKey())
        ->update([
            'suggestions' => json_encode([[
                'key'       => 'd1',
                'type'      => 'document',
                'id'        => 1,
                'name'      => 'Transcript <script>alert(1)</script>',
                'score'     => 0.9,
                'ai_reason' => '<img src=x onerror=alert(1)>',
            ]], JSON_UNESCAPED_UNICODE),
            'suggestion_source' => 'llm',
            'suggested_at'      => now(),
        ]);

    return $item;
}

test('MT-38B suggestion fields are hidden from the list while the AI flag is off', function () {
    config(['features.ai_label_suggestions' => false]);
    mt38_item_with_hostile_suggestion();
    mt38_sign_in('admin_granted');

    $row = test()->getJson('/api/v1/unmatched-cashier-items')->assertOk()->json('data.0');

    expect($row)->not->toHaveKeys(['suggestions', 'suggestion_source', 'suggested_at', 'suggestion_accepted']);
});

test('MT-38B model-supplied text is returned as inert JSON data, never as HTML', function () {
    config(['features.ai_label_suggestions' => true]);
    mt38_item_with_hostile_suggestion();
    mt38_sign_in('admin_granted');

    $response = test()->getJson('/api/v1/unmatched-cashier-items')->assertOk();

    // Served as application/json, so a browser never renders it as markup.
    expect((string) $response->headers->get('Content-Type'))->toContain('application/json');

    // The payload round-trips byte-for-byte as data: the API neither executes
    // nor silently rewrites it. Escaping on render is the frontend's duty,
    // so the string must reach the client intact and clearly as a JSON string.
    $suggestion = $response->json('data.0.suggestions.0');
    expect($suggestion['name'])->toBe('Transcript <script>alert(1)</script>')
        ->and($suggestion['ai_reason'])->toBe('<img src=x onerror=alert(1)>');

    // Only the documented suggestion fields are exposed (no internal pattern lists).
    expect($suggestion)->not->toHaveKey('patterns');
});
