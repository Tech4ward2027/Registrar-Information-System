<?php

namespace App\Http\Controllers;

use App\Contracts\CashierServiceInterface;
use App\Models\AuditLog;
use App\Models\FailureReasonCode;
use App\Models\SecurityEvent;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\NameMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cashier Reconciliation > Failed verifications.
 *
 * Replaces the "Postman -> tinker -> OGOS" routine: one row per failed
 * cashier_verification audit entry, joined to its linked
 * cashier_verification_enriched row (source_audit_log_id), with the
 * diagnosis codes the enrichment job attached.
 *
 * Access: role 3/4 + the cashier_reconciliation module + the system_health
 * feature flag (see routes/api.php).
 *
 * Query discipline (audit_logs is large and the filters read JSON):
 *   - every query pins `action` and a clamped date range first
 *     (idx_audit_logs_action_created), JSON-path filters come second;
 *   - results are always paginated, per_page <= 50;
 *   - OR number is an EXACT match, email is a prefix match (min 3 chars),
 *     so the search box cannot be used to browse or enumerate receipts;
 *   - the code filter scans at most CODE_SCAN_LIMIT enrichment rows.
 *
 * Wording rule: diagnosis codes are "likely contributing factors", never a
 * fault verdict. The Cashier API does not return the on-file name, so the
 * cashier-side cause cannot be determined from RIS.
 *
 * Re-check: only ever from an existing failed audit row (no free-text OR or
 * name input, so it cannot enumerate receipts), throttled per user, with a
 * per-row cooldown, audited as its own action. CashierService::
 * verifyPayment() only posts a lookup (or answers from the mock), so it has
 * no side effects on the OR; the re-check never approves or changes anything.
 */
class FailedCashierVerificationController extends Controller
{
    private const DEFAULT_DAYS     = 30;
    private const MAX_DAYS         = 90;
    private const MAX_PER_PAGE     = 50;
    private const CODE_SCAN_LIMIT  = 5000;
    private const RECHECK_COOLDOWN = 30; // seconds, per audit row
    private const CROSSLINK_DAYS   = 7;

    public const NOTE = 'Diagnosis codes are likely contributing factors, not a fault verdict. '
        . 'The Cashier API does not return the name it has on file, so the cashier-side cause '
        . 'cannot be determined from RIS.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CashierServiceInterface $cashier,
        private readonly NameMatcher $nameMatcher,
    ) {}

    // ------------------------------------------------------------------
    // GET failed-cashier-verifications
    // ------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'days'      => 'nullable|integer|min:1|max:' . self::MAX_DAYS,
            'or_number' => 'nullable|string|max:50',
            'email'     => 'nullable|string|min:3|max:100',
            'code'      => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'reason'    => 'nullable|in:NOT_FOUND,API_ERROR',
            'per_page'  => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
        ]);

        $days = (int) ($v['days'] ?? self::DEFAULT_DAYS);
        $from = CarbonImmutable::today()->subDays($days - 1)->startOfDay();

        $query = $this->failedQuery()->where('created_at', '>=', $from);

        if (!empty($v['or_number'])) {
            $query->where('metadata->or_number', trim($v['or_number']));
        }
        if (!empty($v['email'])) {
            $query->where('email', 'like', $this->escapeLike(trim($v['email'])) . '%');
        }
        if (!empty($v['reason'])) {
            $query->where('metadata->failure_reason', $v['reason']);
        }
        if (!empty($v['code'])) {
            $ids = $this->sourceIdsWithCode($v['code'], $from);
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 20), ['id', 'user_id', 'email', 'metadata', 'created_at']);

        $enrichment = $this->enrichmentFor($page->getCollection());

        $rows = $page->getCollection()->map(function (AuditLog $row) use ($enrichment) {
            $e = $enrichment[$row->id] ?? null;

            return [
                'id'                => $row->id,
                'created_at'        => $row->created_at?->toIso8601String(),
                'or_number'         => $row->metadata['or_number'] ?? null,
                'email'             => $row->email,
                'failure_reason'    => $row->metadata['failure_reason'] ?? null,
                'attempts_count'    => is_array($row->metadata['attempts'] ?? null) ? count($row->metadata['attempts']) : 0,
                'enrichment_status' => $e['enrichment_status'] ?? null,
                'source_system'     => $e['source_system'] ?? null,
                'diagnosis_codes'   => array_values($e['diagnosis_codes'] ?? []),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'days'         => $days,
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // GET failed-cashier-verifications/{auditLogId}   (audited)
    // ------------------------------------------------------------------
    public function show(Request $request, int $auditLogId): JsonResponse
    {
        $row = $this->failedQuery()->whereKey($auditLogId)->firstOrFail();

        // Audit FIRST: if the access record cannot be written, the detail
        // is not returned (fail closed). Metadata only.
        $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_FAILED_VERIFICATION_VIEWED, [
            'source_audit_log_id' => $row->id,
            'target_user_id'      => $row->user_id,
            'target_email'        => $row->email,
        ]);

        $enrichment = $this->enrichmentFor(collect([$row]))[$row->id] ?? null;
        $codes      = array_values($enrichment['diagnosis_codes'] ?? []);

        $catalog = $codes === []
            ? collect()
            : FailureReasonCode::whereIn('code', $codes)->get()->keyBy('code');

        $diagnosis = array_map(static function (string $code) use ($catalog) {
            $c = $catalog->get($code);

            return [
                'code'        => $code,
                'category'    => $c?->category,
                'severity'    => $c?->severity,
                'description' => $c?->description,
            ];
        }, $codes);

        return response()->json(['data' => [
            'id'                => $row->id,
            'created_at'        => $row->created_at?->toIso8601String(),
            'or_number'         => $row->metadata['or_number'] ?? null,
            'email'             => $row->email,
            'failure_reason'    => $row->metadata['failure_reason'] ?? null,
            // Name formats RIS tried and what the Cashier API answered for each.
            'attempts'          => $this->safeAttempts($row->metadata['attempts'] ?? []),
            'enrichment_status' => $enrichment['enrichment_status'] ?? null,
            'source_system'     => $enrichment['source_system'] ?? null,
            'on_file_snapshot'  => $enrichment['on_file_snapshot'] ?? null,
            'diagnosis'         => $diagnosis,
            'cross_links'       => $this->crossLinks($row),
            'note'              => self::NOTE,
        ]]);
    }

    // ------------------------------------------------------------------
    // POST failed-cashier-verifications/{auditLogId}/recheck   (audited)
    // ------------------------------------------------------------------
    public function recheck(Request $request, int $auditLogId): JsonResponse
    {
        $row = $this->failedQuery()->whereKey($auditLogId)->firstOrFail();

        // Mock mode (no API key) answers "valid" for everything, so a
        // re-check would be meaningless there.
        if (!filled(config('services.cashier.api_key'))) {
            return response()->json([
                'message' => 'Re-check is unavailable: the Cashier API is not configured in this environment.',
            ], 409);
        }

        $orNumber = (string) ($row->metadata['or_number'] ?? '');
        $subject  = $row->user_id ? SystemUser::find($row->user_id) : null;
        $profile  = $subject?->studentProfile ?? $subject?->alumniProfile ?? $subject?->undergradRequestorProfile ?? null;

        if ($orNumber === '' || $profile === null) {
            return response()->json([
                'message' => 'This failure cannot be re-checked: the account or its profile no longer exists.',
            ], 422);
        }

        // Per-row cooldown so one row cannot be used to hammer the Cashier API.
        if (!Cache::add('failed-verification-recheck:' . $row->id, 1, self::RECHECK_COOLDOWN)) {
            return response()->json([
                'message' => 'This record was re-checked a moment ago. Please wait before trying again.',
            ], 429);
        }

        // Candidates are regenerated from the STORED profile, never from input.
        $candidates = $this->nameMatcher->candidatesFor(
            $profile->last_name ?? '',
            $profile->first_name ?? '',
            $profile->middle_name ?? '',
            $profile->suffix ?? '',
        );

        $outcome  = 'error';
        $attempts = [];
        $matched  = null;

        try {
            $result   = $this->cashier->verifyPaymentAny($orNumber, $candidates);
            $attempts = $this->safeAttempts($result['attempts'] ?? []);
            $matched  = $result['valid'] ? ($result['matched_name'] ?? null) : null;
            $outcome  = $result['valid']
                ? 'matched'
                : (($result['reason'] ?? null) === 'API_ERROR' ? 'api_error' : 'not_found');
        } catch (Throwable $e) {
            Log::warning('Failed-verification re-check errored', [
                'source_audit_log_id' => $row->id,
                'exception'           => $e::class,
            ]);
        }

        $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_FAILED_VERIFICATION_RECHECKED, [
            'source_audit_log_id' => $row->id,
            'outcome'             => $outcome,
            'attempts_count'      => count($attempts),
            'target_user_id'      => $row->user_id,
            'target_email'        => $row->email,
        ]);

        return response()->json(['data' => [
            'outcome'      => $outcome,
            'matched_name' => $matched,
            'attempts'     => $attempts,
            // Explicit so the UI cannot mistake this for an approval.
            'approved'     => false,
            'note'         => 'Advisory only. A re-check never approves a request or changes any record.',
        ]], $outcome === 'error' ? 502 : 200);
    }

    // ------------------------------------------------------------------

    /** Real (non-mock, non-override) failed verification rows. */
    private function failedQuery()
    {
        return AuditLog::query()
            ->where('action', AuditLog::ACTION_CASHIER_VERIFICATION)
            ->where('metadata->final_approved', false);
    }

    /**
     * Enrichment rows keyed by the verification row they enrich.
     *
     * @param  \Illuminate\Support\Collection<int, AuditLog>  $rows
     * @return array<int, array>
     */
    private function enrichmentFor($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();
        $out = [];

        // One narrow window per row (the job runs minutes after the
        // failure), OR-ed together: at most 50 ranges on the
        // (action, created_at) index, never a wide scan.
        AuditLog::query()
            ->where('action', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)
            ->where(function ($q) use ($rows) {
                foreach ($rows as $r) {
                    $start = CarbonImmutable::parse($r->created_at);
                    $q->orWhereBetween('created_at', [$start, $start->addDay()]);
                }
            })
            ->orderBy('id')
            ->limit(self::CODE_SCAN_LIMIT)
            ->get(['id', 'metadata'])
            ->each(function (AuditLog $e) use ($ids, &$out) {
                $src = (int) ($e->metadata['source_audit_log_id'] ?? 0);
                if (in_array($src, $ids, true)) {
                    $out[$src] = $e->metadata; // latest enrichment wins
                }
            });

        return $out;
    }

    /** @return list<int> verification-row ids whose enrichment carries $code */
    private function sourceIdsWithCode(string $code, CarbonImmutable $from): array
    {
        return AuditLog::query()
            ->where('action', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)
            ->where('created_at', '>=', $from)
            ->whereJsonContains('metadata->diagnosis_codes', $code)
            ->orderByDesc('id')
            ->limit(self::CODE_SCAN_LIMIT)
            ->get(['id', 'metadata'])
            ->map(fn (AuditLog $e) => (int) ($e->metadata['source_audit_log_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Counts only, never other people's rows: "also N failures / N
     * provisioning failures this week" for the same account.
     */
    private function crossLinks(AuditLog $row): array
    {
        $since = CarbonImmutable::now()->subDays(self::CROSSLINK_DAYS);

        $otherFailures = $row->user_id
            ? $this->failedQuery()
                ->where('user_id', $row->user_id)
                ->where('created_at', '>=', $since)
                ->where('id', '!=', $row->id)
                ->count()
            : 0;

        $provisioning = DB::table('security_events')
            ->where('event_type', SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED)
            ->where('email', $row->email)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'window_days'                 => self::CROSSLINK_DAYS,
            'other_failed_verifications'  => $otherFailures,
            'provisioning_failures'       => $provisioning,
        ];
    }

    /** @return list<array{name:string,valid:bool,reason:?string}> */
    private function safeAttempts(mixed $attempts): array
    {
        if (!is_array($attempts)) {
            return [];
        }

        $out = [];
        foreach (array_slice($attempts, 0, 50) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $out[] = [
                'name'   => (string) ($a['name'] ?? ''),
                'valid'  => (bool) ($a['valid'] ?? false),
                'reason' => isset($a['reason']) ? (string) $a['reason'] : null,
            ];
        }

        return $out;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
