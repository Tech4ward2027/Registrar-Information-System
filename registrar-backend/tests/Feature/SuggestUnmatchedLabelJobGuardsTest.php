<?php

/**
 * MT-39 — Repeated suggestion jobs, resolution protection and audit records.
 *
 * Subcases:
 *   A  already-suggested row, force=false  -> skipped, suggestion service not invoked
 *   B  unresolved row, force=true          -> regenerated once, suggestion_accepted reset to null
 *   C  resolved row, force=false / true    -> skipped in both modes
 *   D  deleted item id                     -> no exception, nothing recreated
 *   E  row resolved while generating       -> the job's final write must not overwrite it
 *   F  resolve / dismiss through the API   -> exactly one audit event each, correct metadata
 *
 * How "the service was invoked" is observed
 * -----------------------------------------
 * LabelSuggestionService is final and cannot be mocked, so it is left real.
 * Its only injectable collaborator that is called exactly once per generate()
 * on an ambiguous label is LabelReranker (non-final). A spy subclass is bound
 * into the container: it reports itself available, counts rerank() calls and
 * returns null (so the rules ranking is kept). An ambiguous fixture (two
 * catalogue entries with the same name) guarantees generate() reaches the
 * reranker, so spy->calls is 1 for "service ran" and 0 for "service skipped".
 * The job's own guards are exercised unmodified.
 *
 * Scope note: these tests call handle() directly. They prove the guards and the
 * final conditional write, not queue-worker scheduling or ShouldBeUnique
 * locking (which needs a real queue/cache lock and is out of scope here).
 *
 * Helpers and the spy class are prefixed `mt39` / `Mt39` to avoid global clashes.
 */

use App\Jobs\SuggestUnmatchedLabelTargetJob;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use App\Services\LabelReranker;
use App\Services\LabelSuggestionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Counts rerank() calls; optionally runs a hook mid-generation. */
final class Mt39SpyReranker extends LabelReranker
{
    public int $calls = 0;
    public ?\Closure $onRerank = null;

    public function isAvailable(): bool
    {
        return true;
    }

    public function rerank(string $rawLabel, array $candidates): ?array
    {
        $this->calls++;

        if ($this->onRerank !== null) {
            ($this->onRerank)();
        }

        return null; // keep the rule-based ranking
    }
}

const MT39_LABEL = 'Mt39 Duplicate Fee';

function mt39_spy(): Mt39SpyReranker
{
    $spy = new Mt39SpyReranker();
    // Bound before LabelSuggestionService is resolved, so it receives the spy.
    app()->instance(LabelReranker::class, $spy);

    return $spy;
}

function mt39_service(): LabelSuggestionService
{
    return app(LabelSuggestionService::class);
}

function mt39_item(string $label = MT39_LABEL): UnmatchedCashierItem
{
    return UnmatchedCashierItem::create([
        'raw_label'        => $label,
        'normalised_label' => UnmatchedCashierItem::normaliseLabel($label),
        'occurrence_count' => 1,
        'first_seen_at'    => now(),
        'last_seen_at'     => now(),
    ]);
}

function mt39_row(int $id): ?object
{
    // The primary key is not `id`, so DB::table()->find() cannot be used.
    return DB::table('unmatched_cashier_items')->where('unmatched_cashier_item_id', $id)->first();
}

function mt39_run(UnmatchedCashierItem|int $item, bool $force = false): void
{
    $id = $item instanceof UnmatchedCashierItem ? $item->getKey() : $item;

    (new SuggestUnmatchedLabelTargetJob($id, $force))->handle(mt39_service());
}

function mt39_make_admin(): SystemUser
{
    $policy = Policy::create([
        'name'        => 'MT39 Reconciliation ' . uniqid(),
        'permissions' => ['cashier_reconciliation' => ['Access']],
        'is_system'   => false,
    ]);

    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);
    Sanctum::actingAs($admin);

    return $admin;
}

/** Audit events of one action that concern one item, matched by id (never "latest"). */
function mt39_audit_events(string $action, int $itemId): array
{
    return AuditLog::where('action', $action)->get()
        ->filter(fn (AuditLog $log) => ($log->metadata['unmatched_cashier_item_id'] ?? null) === $itemId)
        ->values()
        ->all();
}

beforeEach(function () {
    Http::fake();
    config(['features.ai_label_suggestions' => false]);

    DocumentType::query()->delete();
    CertificationType::query()->delete();

    // Two catalogue entries with an identical name => tied scores => the
    // rules result is flagged ambiguous, so generate() always reaches the reranker.
    foreach ([1, 2] as $_) {
        DocumentType::create([
            'document_name'             => MT39_LABEL,
            'document_description'      => '',
            'document_process_period'   => 5,
            'access_id'                 => 1,
            'cashier_document_patterns' => [],
        ]);
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// Precondition: proves the spy really observes generate()
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39 precondition: a fresh unresolved item reaches the service exactly once', function () {
    $spy  = mt39_spy();
    $item = mt39_item();

    mt39_run($item);

    $row = mt39_row($item->getKey());
    expect($spy->calls)->toBe(1)
        ->and($row->suggested_at)->not->toBeNull()
        ->and($row->suggestion_source)->toBe('rules')
        ->and($row->resolved_at)->toBeNull();
    Http::assertNothingSent();
});

// ═════════════════════════════════════════════════════════════════════════════
// MT-39A — already suggested, force = false
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39A an already-suggested item is skipped when force is false', function () {
    $spy  = mt39_spy();
    $item = mt39_item();

    DB::table('unmatched_cashier_items')->where('unmatched_cashier_item_id', $item->getKey())->update([
        'suggestions'         => json_encode([['key' => 'marker']]),
        'suggestion_source'   => 'marker',
        'suggested_at'        => now()->subHour(),
        'suggestion_accepted' => true,
    ]);
    $before = (array) mt39_row($item->getKey());

    mt39_run($item, force: false);

    expect($spy->calls)->toBe(0)
        ->and((array) mt39_row($item->getKey()))->toBe($before);
    Http::assertNothingSent();
});

// ═════════════════════════════════════════════════════════════════════════════
// MT-39B — force = true regenerates an unresolved item
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39B force regenerates an unresolved item once and resets suggestion_accepted', function () {
    $spy  = mt39_spy();
    $item = mt39_item();

    DB::table('unmatched_cashier_items')->where('unmatched_cashier_item_id', $item->getKey())->update([
        'suggestions'         => json_encode([['key' => 'marker']]),
        'suggestion_source'   => 'marker',
        'suggested_at'        => now()->subHour(),
        'suggestion_accepted' => true,
    ]);
    $previousSuggestedAt = mt39_row($item->getKey())->suggested_at;

    mt39_run($item, force: true);

    $row = mt39_row($item->getKey());
    expect($spy->calls)->toBe(1)
        ->and($row->suggestion_source)->toBe('rules')
        ->and($row->suggestion_accepted)->toBeNull()
        ->and($row->suggested_at)->not->toBe($previousSuggestedAt)
        ->and(json_decode($row->suggestions, true))->not->toBe([['key' => 'marker']])
        ->and($row->resolved_at)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// MT-39C — resolved item is never regenerated, with or without force
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39C a resolved item is skipped in both modes', function (bool $force) {
    $spy   = mt39_spy();
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $item  = mt39_item();

    DB::table('unmatched_cashier_items')->where('unmatched_cashier_item_id', $item->getKey())->update([
        'resolved_at' => now()->subMinute(),
        'resolved_by' => $admin->user_id,
    ]);
    $before = (array) mt39_row($item->getKey());

    mt39_run($item, $force);

    expect($spy->calls)->toBe(0)
        ->and((array) mt39_row($item->getKey()))->toBe($before);
})->with([
    'force = false' => [false],
    'force = true'  => [true],
]);

// ═════════════════════════════════════════════════════════════════════════════
// MT-39D — deleted item id
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39D a deleted item id is handled without an exception and recreates nothing', function (bool $force) {
    $spy  = mt39_spy();
    $item = mt39_item();
    $id   = $item->getKey();
    $item->delete();

    expect(fn () => mt39_run($id, $force))->not->toThrow(\Throwable::class);

    expect($spy->calls)->toBe(0)
        ->and(mt39_row($id))->toBeNull()
        ->and(UnmatchedCashierItem::count())->toBe(0);
})->with([
    'force = false' => [false],
    'force = true'  => [true],
]);

// ═════════════════════════════════════════════════════════════════════════════
// MT-39E — item resolved by an admin while the job is generating
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39E a resolve that lands during generation is not overwritten by the job', function () {
    $spy   = mt39_spy();
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $item  = mt39_item();

    // The job passes its guards (item unresolved), starts generating, and the
    // admin resolves the row at exactly that moment — before the final write.
    $spy->onRerank = function () use ($item, $admin) {
        DB::table('unmatched_cashier_items')
            ->where('unmatched_cashier_item_id', $item->getKey())
            ->update(['resolved_at' => now(), 'resolved_by' => $admin->user_id, 'suggestion_accepted' => true]);
    };

    mt39_run($item);

    $row = mt39_row($item->getKey());
    expect($spy->calls)->toBe(1)                       // generation really ran
        ->and($row->resolved_at)->not->toBeNull()      // resolution survived
        ->and($row->resolved_by)->toBe($admin->user_id)
        ->and($row->suggestion_accepted)->toBe(1)      // not reset to null by the job
        ->and($row->suggestions)->toBeNull()           // job wrote none of its four columns
        ->and($row->suggestion_source)->toBeNull()
        ->and($row->suggested_at)->toBeNull();
});

test('MT-39E the job is marked unique per item id', function () {
    $job = new SuggestUnmatchedLabelTargetJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->uniqueFor)->toBeGreaterThan(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// MT-39F — audit evidence for resolve and dismiss through the real API
// ═════════════════════════════════════════════════════════════════════════════

test('MT-39F resolving through the API writes exactly one audit event with the expected metadata', function () {
    $admin  = mt39_make_admin();
    $item   = mt39_item('Mt39 Resolve Label');
    $target = DocumentType::create([
        'document_name' => 'MT39 Resolve Target', 'document_description' => '',
        'document_process_period' => 5, 'access_id' => 1, 'cashier_document_patterns' => [],
    ]);

    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [
        'document_type_id' => $target->getKey(),
    ])->assertOk();

    $events = mt39_audit_events(AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED, $item->getKey());
    expect($events)->toHaveCount(1);

    /** @var AuditLog $event */
    $event = $events[0];
    expect($event->user_id)->toBe($admin->user_id)
        ->and($event->metadata)->toMatchArray([
            'unmatched_cashier_item_id' => $item->getKey(),
            'raw_label'                 => 'Mt39 Resolve Label',
            'attached_to_type'          => 'document',
            'attached_to_id'            => $target->getKey(),
            'attached_to_name'          => 'MT39 Resolve Target',
        ])
        // Catalogue metadata only: no personal data keys.
        ->and(array_keys($event->metadata))->not->toContain('email', 'name', 'student_id')
        // Tamper-evident chain fields are populated.
        // (prev_hash is the genesis value '0' for the first row, so test for a
        // non-blank string rather than using toBeEmpty(), which treats '0' as empty.)
        ->and($event->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($event->prev_hash)->toBeString()->not->toBe('')
        // And it did not also log a dismissal.
        ->and(mt39_audit_events(AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED, $item->getKey()))->toBeEmpty();

    // Repeating the call is rejected and must not add a second event.
    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [
        'document_type_id' => $target->getKey(),
    ])->assertStatus(409);

    expect(mt39_audit_events(AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED, $item->getKey()))->toHaveCount(1);
});

test('MT-39F dismissing through the API writes exactly one audit event with the expected metadata', function () {
    $admin = mt39_make_admin();
    $item  = mt39_item('Mt39 Dismiss Label');

    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/dismiss")->assertOk();

    $events = mt39_audit_events(AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED, $item->getKey());
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event->user_id)->toBe($admin->user_id)
        ->and($event->metadata)->toMatchArray([
            'unmatched_cashier_item_id' => $item->getKey(),
            'raw_label'                 => 'Mt39 Dismiss Label',
        ])
        ->and(mt39_audit_events(AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED, $item->getKey()))->toBeEmpty();

    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/dismiss")->assertStatus(409);

    expect(mt39_audit_events(AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED, $item->getKey()))->toHaveCount(1);
});

test('MT-39F rejected resolve attempts write no audit event', function () {
    mt39_make_admin();
    $item = mt39_item('Mt39 Rejected Label');

    // Validation failure (no target) and unknown target: neither may be audited as a resolution.
    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [])->assertStatus(422);
    test()->postJson("/api/v1/unmatched-cashier-items/{$item->getKey()}/resolve", [
        'document_type_id' => 99999999,
    ])->assertStatus(422);

    expect(AuditLog::where('action', AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED)->count())->toBe(0)
        ->and(mt39_row($item->getKey())->resolved_at)->toBeNull();
});
