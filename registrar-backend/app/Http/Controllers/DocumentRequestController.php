<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use App\Models\AuditLog;
use App\Models\CashierOrOverride;
use App\Contracts\DocumentRequestServiceInterface;
use App\Http\Requests\DocumentRequest\BulkRequestIdsRequest;
use App\Http\Requests\DocumentRequest\ClaimDocumentRequestRequest;
use App\Http\Requests\DocumentRequest\CloseRequestUnableToProcessRequest;
use App\Http\Requests\DocumentRequest\IndexDocumentRequestsRequest;
use App\Http\Requests\DocumentRequest\StoreDocumentRequestRequest;
use App\Http\Requests\DocumentRequest\UpdateDocumentRequestRequest;
use App\Http\Requests\DocumentRequest\VerifyOfficialReceiptRequest;
use App\Http\Requests\DocumentRequest\WithdrawDocumentRequestRequest;
use App\Http\Requests\RequestItem\UpdateRequestCertificateStatusRequest;
use App\Http\Requests\RequestItem\UpdateRequestDocumentStatusRequest;
use App\Http\Resources\DocumentRequestListResource;
use App\Services\DocumentRequestService;
use App\Services\RequestItemStatusService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Contracts\CashierServiceInterface;
use App\Services\CashierDocumentMatcher;
use App\Services\CashierDocumentSuggester;
use App\Services\NameMatcher;
use App\Jobs\EnrichCashierFailureJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Document request HTTP controller.
 *
 * Responsibilities: validate input, authorize, delegate to DocumentRequestService, return JSON.
 * Business logic lives entirely in DocumentRequestService.
 *
 * Validation for store()/update()/archiveBulk()/restoreBulk()/
 * bulkReadyItems()/bulkDoneItems() now lives in App\Http\Requests\
 * DocumentRequest\* — see each class's rules(). Their authorize() methods
 * also replace the explicit $this->authorize() calls store()/update() used
 * to make (archive()/restore()/view()/delete() below still call
 * $this->authorize() directly since those don't take a FormRequest —
 * there's nothing to validate on those routes).
 */
class DocumentRequestController extends Controller
{
    private const RELATIONS = [
        'user',
        'studentProfile',
        'academicRecord',
        'alumniProfile',
        'alumniAcademicRecord',
        // Undergrad Requestor Registration — Phase 5. Unlike
        // studentProfile/alumniProfile above, this is not a belongsTo
        // through a stored FK on document_request (all four
        // academic-record FK columns stay NULL for this role — see
        // DocumentRequestService::buildRequestData()); it's a hasOne
        // joined on user_id, since an Undergrad Requestor has no
        // academic-record row for a request to point at. See
        // DocumentRequest::undergradRequestorProfile().
        'undergradRequestorProfile',
        'status',
        'requestPurpose',
        'documents.documentType',
        'documents.status',
        'certificates.certificationType',
        'certificates.status',
        'archivedByUser',
        // Deficiency Notice & Withdrawn Status — Phase 1. Nearly always
        // null (only set when withdrawal_reason implies a duplicate/
        // corrected resubmission) — cheap to always eager-load since
        // it's a single nullable belongsTo, same reasoning as
        // archivedByUser above.
        'supersedingRequest',
        // Phase 3 — see DocumentRequest::releaseGroups(). Nearly always
        // empty; only populated for requests whose items span more than
        // one fulfillment_track. Loading fulfillmentTrack alongside it
        // so the frontend can label each ticket without a second call.
        'releaseGroups.fulfillmentTrack',
        // Deficiency Notice & Withdrawn Status — Phase 3. Almost always
        // null — cheap single-row nullable hasOne lookup, same
        // "always eager-load, it's cheap" reasoning as supersedingRequest
        // above. Lets the frontend render the hold banner/staleness
        // badge (Phase 4) straight from show()'s payload, at no extra
        // round trip — see DocumentRequest::openDeficiencyNotice()'s
        // docblock and this feature's Phase 3 exit criteria.
        'openDeficiencyNotice.issuedByUser',
        // Phase 5: open notices attached to single documents/certificates.
        // Each row carries request_document_id / request_certificate_id so
        // the UI can flag the matching item row.
        'openItemDeficiencyNotices.issuedByUser',
    ];

    /**
     * Eager-load definition for the staff list and the logbook: identical to
     * RELATIONS except the undergrad profile is restricted to plaintext
     * identity columns. The encrypted columns (phone, present_address,
     * date_of_birth, reason_for_non_enrollment) are then never fetched, let
     * alone decrypted, for a list. user_id must stay selected — it is the
     * key the hasOne is matched on. Keep the columns in sync with
     * DocumentRequestListResource::UNDERGRAD_LIST_FIELDS.
     */
    private const UNDERGRAD_LIST_RELATION = 'undergradRequestorProfile:'
        . 'undergrad_requestor_profile_id,user_id,first_name,middle_name,last_name,suffix,student_number,program';

    private static function listRelations(): array
    {
        return array_map(
            fn (string $relation) => $relation === 'undergradRequestorProfile'
                ? self::UNDERGRAD_LIST_RELATION
                : $relation,
            self::RELATIONS
        );
    }

    public function __construct(
        private DocumentRequestServiceInterface $requestService,
        private CashierServiceInterface          $cashierService,
        private CashierDocumentMatcher          $documentMatcher,
        private CashierDocumentSuggester        $documentSuggester,
        private AuditLogger                     $auditLogger,
        private NameMatcher                     $nameMatcher,
        private RequestItemStatusService        $itemStatusService,
    ) {}

    // -------------------------------------------------------------------------
    // GET /document-requests
    // -------------------------------------------------------------------------
    // Staff: server-side search, filtering, ordering and pagination. The
    // browser renders one page; it no longer downloads "the newest 200" and
    // filters them locally (which hid any older request from search).
    //
    // Query params (all optional; see IndexDocumentRequestsRequest):
    //   search, status, classification, document, sort, view, page, per_page
    //   all_statuses  legacy flag — still honoured
    //
    // Backward compatible: with no new params the response is the same
    // paginator shape ordered newest-first. The Completed-last ordering only
    // applies when `sort` is sent, so an old client's window is unchanged.
    //
    // Non-staff (students / alumni / undergrad requestors): unchanged — all of
    // their OWN requests.
    // -------------------------------------------------------------------------
    public function index(IndexDocumentRequestsRequest $request)
    {
        /** @var SystemUser $user */
        $user = Auth::user();

        if (!$user instanceof SystemUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!$user->isStaff()) {
            // Their volume is low enough that a full ->get() is safe, and
            // this is the requester's own data, so the full relations apply.
            return response()->json([
                'data' => DocumentRequest::with(self::RELATIONS)
                    ->where('user_id', $user->user_id)
                    ->orderByDesc('requested_at')
                    ->get(),
            ], 200);
        }

        $query = DocumentRequest::with(self::listRelations());

        // Archived Records tab: bypass the global scope and return ONLY
        // archived requests, regardless of status/age.
        if ($request->isArchivedView()) {
            $query->withArchived()->where('document_request.is_archived', true);
        }

        $query->search($request->searchTerm())
              ->withStatusName($request->statusName())
              ->withClassification($request->classification())
              ->withItemNamed($request->documentName());

        // Default "actionable work" window — skipped for the archive tab,
        // when the caller asked for everything, or when narrowing by
        // status/search/etc. (same rule the client applied via isFiltering).
        if (!$request->isArchivedView() && !$request->wantsAllStatuses() && !$request->isFiltering()) {
            $query->activeDashboardWindow();
        }

        if ($request->sortOption() !== null) {
            $query->dashboardOrder($request->sortOption());
        } elseif ($request->isArchivedView()) {
            $query->orderByDesc('document_request.archived_on')->orderByDesc('document_request.request_id');
        } else {
            $query->orderByDesc('document_request.requested_at')->orderByDesc('document_request.request_id');
        }

        $page = $query->paginate($request->perPage())
            ->through(fn (DocumentRequest $row) => (new DocumentRequestListResource($row))->resolve());

        return response()->json($page, 200);
    }


    // -------------------------------------------------------------------------
    // GET /document-requests/counts
    // -------------------------------------------------------------------------
    // Total count per status across the WHOLE table (not one page, and not
    // the default dashboard window) — the staff dashboard stat cards must use
    // this instead of counting the list response.
    //
    // Every known status is present in the response (0 when empty) so a card
    // never reads `undefined`. "Archived" is a cross-cutting flag rather than
    // a status, so it is reported as its own key. Archived requests are
    // excluded from the status counts (global scope), so the two never
    // double count. One grouped query on dr_status_id_idx + one indexed count.
    // Staff/superadmin only (role:3,4) — same audience as the dashboard.
    public function counts()
    {
        $counts = RequestStatus::query()->pluck('status_name')
            ->mapWithKeys(fn ($name) => [$name => 0])
            ->all();

        $grouped = DocumentRequest::query()
            ->join('request_status', 'document_request.status_id', '=', 'request_status.status_id')
            ->selectRaw('request_status.status_name, COUNT(*) as total')
            ->groupBy('request_status.status_name')
            ->pluck('total', 'status_name');

        foreach ($grouped as $name => $total) {
            $counts[$name] = (int) $total;
        }

        $counts['Archived'] = DocumentRequest::withArchived()
            ->where('document_request.is_archived', true)
            ->count();

        return response()->json($counts, 200);
    }

    // -------------------------------------------------------------------------
    // GET /document-requests/logbook
    // Returns completed requests with embedded history — purpose-built for the
    // Logbook page.  Avoids the N+1 page-loop + separate history fetch the
    // frontend previously performed.
    // Staff/superadmin only (enforced by route middleware role:3,4).
    // -------------------------------------------------------------------------
    // BE-1 migration: added from/to/doc_type filters
    // Accepts optional query params:
    //   ?from=YYYY-MM-DD    filter requests on or after this date
    //   ?to=YYYY-MM-DD      filter requests on or before this date
    //   ?doc_type=string    filter by document_name (partial, case-insensitive)
    //   ?per_page=int       page size, default 25, capped at 100
    //   ?page=int           page number, default 1 (standard Laravel paginator)
    //
    // BE-2: was ->get() with no limit — every completed request, forever,
    // in one response. Switched to paginate(); page size is capped server
    // -side so a client can't force an unbounded query by passing a huge
    // per_page. requested_at already has an index (dr_requested_at_idx
    // from the base schema migration) so ordering + the from/to filters
    // stay index-backed.
    public function logbook(Request $request)
    {
        $query = DocumentRequest::with(array_merge(self::listRelations(), ['history']))
            ->whereHas('status', fn ($q) => $q->where('status_name', 'Completed'));

        if ($from = $request->query('from')) {
            $query->whereDate('requested_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('requested_at', '<=', $to);
        }

        if ($docType = $request->query('doc_type')) {
            $query->whereHas('documents.documentType', function ($q) use ($docType) {
                $q->where('document_name', 'like', '%' . $docType . '%');
            });
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        // Same privacy treatment as index(): no decrypted undergrad PII in
        // a list row, plus display_name / student_number / requester_type.
        $page = $query->orderByDesc('requested_at')->orderByDesc('request_id')
            ->paginate($perPage)
            ->through(fn (DocumentRequest $row) => (new DocumentRequestListResource($row))->resolve());

        return response()->json($page, 200);
    }

    // -------------------------------------------------------------------------
    // GET /document-requests/{documentRequest}
    // -------------------------------------------------------------------------
    // Manual lookup (not implicit route-model binding) because
    // ExcludeArchivedScope would otherwise 404 an archived record —
    // Archive Rules requires archived records to remain viewable
    // (read-only) from the Archived Records tab.
    public function show($id)
    {
        $documentRequest = DocumentRequest::withArchived()->findOrFail($id);
        $this->authorize('view', $documentRequest);
        return response()->json($documentRequest->load(self::RELATIONS), 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests
    // -------------------------------------------------------------------------
    public function store(StoreDocumentRequestRequest $request)
    {
        $validated = $request->validated();

        if (empty($validated['documents']) && empty($validated['certificates'])) {
            return response()->json([
                'message' => 'At least one document or certificate must be requested.',
            ], 422);
        }

        // or-validation: single-use check
        if (!empty($validated['or_number'])) {
            if ($this->cashierService->isOrAlreadyUsed($validated['or_number'])) {
                $message = 'This OR number has already been used for a previous request. Each Official Receipt can only be used once.';
                return response()->json([
                    'message' => $message,
                    'errors'  => ['or_number' => [$message]],
                ], 422);
            }
        }

        // or-validation: verify OR before creating request
        //
        // This is the money-facing gate and stays exactly as strict as it
        // always was: verifyReceiptAgainstCashier() below is a straight
        // extraction of what used to live inline here (same retry loop,
        // same audit log call, same error messages) — nothing about it
        // got weaker when verifyOfficialReceipt() below started reusing
        // it. CashierDocumentMatcher (the strict cross-check against
        // whatever the student actually confirmed) still runs here and
        // ONLY here, unchanged.
        // Set below when an admin-issued CashierOrOverride is what let
        // this OR pass verification (see verifyReceiptAgainstCashier())
        // — consumed (marked used) only after the request is actually
        // created, so a submission that fails validation elsewhere
        // doesn't burn the override for nothing.
        $consumedOverride = null;

        if (!empty($validated['or_number'])) {
            /** @var \App\Models\SystemUser $user */
            $user = Auth::user();

            $verification = $this->verifyReceiptAgainstCashier($request, $user, $validated['or_number']);

            if ($verification['error'] !== null) {
                return $verification['error'];
            }

            // document-validation: cross-check paid items against requested items.
            // Only runs when the cashier API returns items[] (live mode).
            // Mock mode / no-profile skips all checks gracefully — every
            // item passes when there is nothing to match against. An
            // admin-overridden OR is NOT mock (is_mock is explicitly
            // false in that branch of verifyReceiptAgainstCashier()) —
            // it still runs this check, against the admin's
            // verified_items instead of a live API response, so an
            // override never weakens the paid-vs-requested guarantee.
            if (!$verification['is_mock']) {
                $matchResult = $this->documentMatcher->match(
                    cashierItems: $verification['cashier_items'],
                    documents:    $validated['documents']    ?? [],
                    certificates: $validated['certificates'] ?? [],
                );

                if (!$matchResult['valid']) {
                    return response()->json([
                        'message' => $matchResult['message'],
                        'errors'  => $matchResult['errors'],
                    ], 422);
                }
            }

            $consumedOverride = $verification['override'] ?? null;
        }

        // The isOrAlreadyUsed() pre-check above is a plain SELECT with no
        // locking — it exists purely to fail fast with a friendly message
        // in the common (non-racing) case. It cannot, by itself, prevent
        // two near-simultaneous submissions of the same OR from both
        // passing it before either INSERT commits. The
        // document_request_or_number_unique DB constraint (see
        // 2026_08_29_000009_add_unique_index_to_document_request_or_number)
        // is what actually closes that race: whichever request wins the
        // INSERT succeeds, the loser hits a 23000 duplicate-key violation
        // here and gets the exact same user-facing message as the
        // pre-check, instead of an uncaught 500.
        try {
            $documentRequest = $this->requestService->createRequest(Auth::user(), $validated);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' && !empty($validated['or_number'])) {
                $message = 'This OR number has already been used for a previous request. Each Official Receipt can only be used once.';
                return response()->json([
                    'message' => $message,
                    'errors'  => ['or_number' => [$message]],
                ], 422);
            }

            throw $e;
        }

        if ($consumedOverride !== null) {
            $this->consumeOverride($request, $consumedOverride, $documentRequest, Auth::user());
        }

        return response()->json($documentRequest->load(self::RELATIONS), 201);
    }

    // -------------------------------------------------------------------------
    // Marks a CashierOrOverride as spent by the DocumentRequest it just
    // enabled. Called only from store() (a real submission that actually
    // succeeded), never from verifyOfficialReceipt() — the pre-submission
    // step must be able to report "this override would apply" without
    // spending it, the same way a normal OR is never marked used just by
    // being checked.
    //
    // Guarded with an atomic where(used_at, null) update rather than a
    // plain save(): two near-simultaneous store() calls racing on the
    // same override (e.g. a double-click submit) must not both succeed
    // in consuming it — only the first UPDATE that actually matches
    // used_at IS NULL affects a row; the loser's update() call affects
    // zero rows and is simply skipped rather than erroring, since by the
    // time it runs the override having already been consumed by the
    // other request is the correct outcome, not a failure to surface.
    // -------------------------------------------------------------------------
    private function consumeOverride(
        Request          $request,
        CashierOrOverride $override,
        DocumentRequest  $documentRequest,
        SystemUser       $actor,
    ): void {
        $consumed = CashierOrOverride::query()
            ->whereKey($override->override_id)
            ->whereNull('used_at')
            ->update([
                'used_at'             => now(),
                'used_by_request_id'  => $documentRequest->request_id,
            ]);

        if ($consumed === 0) {
            return; // already consumed by a concurrent request — nothing to log again
        }

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CASHIER_OVERRIDE_CONSUMED, [
            'override_id' => $override->override_id,
            'or_number'   => $override->or_number,
            'request_id'  => $documentRequest->request_id,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/verify-or
    //
    // New first step of the request wizard (OR-first reorder): verifies the
    // OR number/payment date against the cashier API and returns document
    // suggestions derived from the receipt — WITHOUT creating a
    // DocumentRequest. The frontend uses this to pre-populate the Documents
    // step; everything it returns stays editable, and store() above still
    // re-verifies and re-matches from scratch at final submit. See
    // CashierDocumentSuggester's class docblock for why this is a distinct,
    // deliberately softer check than the strict matcher.
    // -------------------------------------------------------------------------
    public function verifyOfficialReceipt(VerifyOfficialReceiptRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Same single-use guard as store() — no point suggesting documents
        // against an OR that final submit will reject outright. This does
        // NOT mark the OR as used; only a successful store() does that
        // (by way of the created DocumentRequest row itself).
        if ($this->cashierService->isOrAlreadyUsed($validated['or_number'])) {
            $message = 'This OR number has already been used for a previous request. Each Official Receipt can only be used once.';
            return response()->json([
                'message' => $message,
                'errors'  => ['or_number' => [$message]],
            ], 422);
        }

        /** @var \App\Models\SystemUser $user */
        $user = Auth::user();

        $verification = $this->verifyReceiptAgainstCashier($request, $user, $validated['or_number']);

        if ($verification['error'] !== null) {
            return $verification['error'];
        }

        $suggestions = $this->documentSuggester->suggest($verification['cashier_items']);

        return response()->json([
            'valid'        => true,
            'or_number'    => $validated['or_number'],
            'receipt_date' => $validated['receipt_date'],
            'is_mock'      => $verification['is_mock'],
            'suggestions'  => $suggestions,
        ], 200);
    }

    // -------------------------------------------------------------------------
    // Shared OR-verification helper — used by store() (final, strict
    // submission) and verifyOfficialReceipt() (pre-submission suggestion
    // step) so the name-candidate retry loop and audit trail can never
    // drift between the two call sites. Extracted unchanged from what used
    // to be store()'s own inline logic.
    //
    // Does NOT run CashierDocumentMatcher or CashierDocumentSuggester —
    // those stay specific to each caller. This method only answers "is
    // this OR number, for this user, valid?" and hands back the raw
    // cashier line items for the caller to do what it needs with.
    //
    // Also checks CashierOrOverride::activeFor() before touching the
    // real Cashier API at all — see that check's inline comment below
    // for the full rationale. When an override applies, 'override'
    // carries the CashierOrOverride model so store() (and only store())
    // can mark it consumed after the request is actually created;
    // verifyOfficialReceipt() reads the same key but never consumes it.
    //
    // @return array{
    //     error: JsonResponse|null,
    //     cashier_items: array,
    //     is_mock: bool,
    //     override: \App\Models\CashierOrOverride|null,
    // }
    // -------------------------------------------------------------------------
    private function verifyReceiptAgainstCashier(Request $request, \App\Models\SystemUser $user, string $orNumber): array
    {
        // Admin override check — FIRST, before any Cashier API call or
        // NameMatcher retry attempt. See CashierOrOverride and the
        // cashier_or_overrides migration's docblock: this is the scoped,
        // audited escape hatch for a real receipt the Cashier API
        // happens to reject, instead of blanking CASHIER_API_KEY
        // system-wide. Scoped to exactly this (or_number, user_id) pair
        // — every other student's OR still goes through the full,
        // unmodified verification path below, untouched.
        //
        // is_mock is explicitly false here (not true): mock mode exists
        // to bypass verification when there is NO real payment context
        // (local dev, CASHIER_API_KEY unset); an override instead
        // represents an admin having actively confirmed a REAL receipt,
        // so CashierDocumentMatcher must still run against it — see
        // store()'s `if (!$verification['is_mock'])` branch, and the
        // 'override' key returned below, which store() uses to mark the
        // override consumed only after the request is actually created.
        $override = CashierOrOverride::activeFor($orNumber, $user->user_id);

        if ($override !== null) {
            $this->auditLogger->log($request, $user, \App\Models\AuditLog::ACTION_CASHIER_VERIFICATION, [
                'or_number'      => $orNumber,
                'method'         => 'admin_override',
                'override_id'    => $override->override_id,
                'final_approved' => true,
                'is_mock'        => false,
            ]);

            return [
                'error'         => null,
                'cashier_items' => $override->verified_items ?? [],
                'is_mock'       => false,
                'override'      => $override,
            ];
        }

        // Resolve the customer name from the user's profile. Students,
        // alumni, and (Phase 5) Undergrad Requestors each have their own
        // profile table; admins submitting on behalf of a student are not
        // expected to hit this path (walk-in requests bypass OR validation
        // entirely) — and verifyOfficialReceipt() is only ever reachable
        // by role:1,2,5 (student/alumni/undergrad-requestor) per the
        // route, so this branch is a defensive fallback there, not the
        // common case.
        //
        // Undergrad Requestor Registration — Phase 5: undergradRequestorProfile
        // added to the chain. Without it, an Undergrad Requestor's real
        // OR verification would silently fall through to the $profile
        // === null branch below, which returns is_mock = true and SKIPS
        // the Cashier cross-check entirely — turning a missing fallback
        // into an accidental bypass of a money-facing control, not a
        // harmless no-op.
        $profile = $user->studentProfile ?? $user->alumniProfile ?? $user->undergradRequestorProfile ?? null;

        if (!$profile) {
            return ['error' => null, 'cashier_items' => [], 'is_mock' => true, 'override' => null];
        }

        // The Cashier API does exact string matching against a free-text
        // "Customer Name" field the Cashier System itself doesn't validate
        // (confirmed via screenshot of its payment form). On failure it
        // never tells us what name is actually on file — NOT_FOUND means
        // either "wrong OR" or "wrong name", indistinguishably — so
        // there's nothing to compare against after the fact. Instead: try
        // a small set of plausible name formattings for this same person,
        // stopping at the first one the API accepts. Every attempt is
        // logged so the eventual decision is traceable.
        $candidates = $this->nameMatcher->candidatesFor(
            $profile->last_name   ?? '',
            $profile->first_name  ?? '',
            $profile->middle_name ?? '',
            $profile->suffix      ?? '',
        );

        // Candidate name formattings are tried in two phases inside
        // CashierService::verifyPaymentAny(): the primary (most-likely)
        // format alone first, then — only if that comes back as a
        // genuine NOT_FOUND rather than an API outage — the remaining
        // candidates CONCURRENTLY via Http::pool(). This replaces the
        // old fully-sequential retry loop, which meant a genuine name
        // mismatch — the common case this candidate list exists to
        // solve, not a rare edge case — paid a full HTTP timeout once
        // per candidate, up to NameMatcher::MAX_CANDIDATES times, on a
        // single "Next" click. See verifyPaymentAny()'s docblock for the
        // full two-phase rationale. Priority order (most-likely-correct
        // first) is still respected: if more than one candidate matches,
        // the earliest one in $candidates wins, same as the old
        // sequential loop.
        $result = $this->cashierService->verifyPaymentAny($orNumber, $candidates);

        $verification = [
            'valid'  => $result['valid'],
            'reason' => $result['reason'],
            'data'   => $result['data'],
        ];
        $matchedName = $result['matched_name'];
        $attemptsLog = $result['attempts'];

        $isMockAttempt = $verification['data']['_mock'] ?? false;

        $auditEntry = $this->auditLogger->log($request, $user, \App\Models\AuditLog::ACTION_CASHIER_VERIFICATION, [
            'or_number'      => $orNumber,
            'attempts'       => $attemptsLog,
            'matched_name'   => $verification['valid'] ? $matchedName : null,
            'is_mock'        => $isMockAttempt,
            'final_approved' => $verification['valid'],
        ]);

        if (!$verification['valid']) {
            $reason = $verification['reason'] ?? 'NOT_FOUND';

            // Phase 4a — Cashier Verification Failure Diagnostics.
            // Only worth enriching on a real lookup miss (NOT_FOUND):
            // API_ERROR is the Cashier System's own availability, not a
            // data mismatch, and mock mode (no CASHIER_API_KEY configured)
            // never produces NOT_FOUND to begin with — see
            // CashierService::mockResponse(), which always returns valid.
            // Dispatched (queued, not inline) so this student's failed
            // submission is never delayed by a call made purely for the
            // registrar's later benefit.
            //
            // Wrapped in try/catch: on QUEUE_CONNECTION=database/redis
            // (production) dispatch() only enqueues and this is a no-op
            // fast path. But on QUEUE_CONNECTION=sync (phpunit.xml, and
            // often local dev), Laravel's SyncQueue runs the job INLINE,
            // in this same request, and — after calling the job's own
            // failed() hook — RE-THROWS whatever the job threw (e.g. an
            // OGOS auth failure). Without this catch, that exception
            // propagates straight out of this method and turns the
            // primary "OR not found" outcome below into an uncaught 500,
            // even though the actual document-request logic already
            // finished correctly. A best-effort background diagnostic
            // must never be able to take down the user-facing response
            // it was only ever meant to enrich, regardless of which
            // queue driver happens to be configured.
            if ($reason === 'NOT_FOUND' && !$isMockAttempt) {
                try {
                    EnrichCashierFailureJob::dispatch(
                        sourceAuditLogId: $auditEntry->id,
                        actorUserId:      $user->user_id,
                        orNumber:         $orNumber,
                        ipAddress:        $request->ip(),
                        userAgent:        $request->userAgent(),
                    );
                } catch (Throwable $e) {
                    Log::warning('EnrichCashierFailureJob dispatch failed; continuing without enrichment', [
                        'source_audit_log_id' => $auditEntry->id,
                        'or_number'            => $orNumber,
                        'message'              => $e->getMessage(),
                    ]);
                }
            }

            $message = match ($reason) {
                'NOT_FOUND' => 'The OR number could not be found. Please check your Official Receipt and try again.',
                'API_ERROR' => 'Payment verification is temporarily unavailable. Please try again later.',
                default     => 'Payment verification failed. Please contact the registrar\'s office.',
            };

            return [
                'error' => response()->json([
                    'message' => $message,
                    'errors'  => ['or_number' => [$message]],
                ], 422),
                'cashier_items' => [],
                'is_mock'       => $isMockAttempt,
                'override'      => null,
            ];
        }

        return [
            'error'         => null,
            'cashier_items' => $verification['data']['items'] ?? [],
            'is_mock'       => $isMockAttempt,
            'override'      => null,
        ];
    }

    // -------------------------------------------------------------------------
    // PUT /document-requests/{documentRequest}
    // -------------------------------------------------------------------------
    public function update(UpdateDocumentRequestRequest $request, DocumentRequest $documentRequest)
    {
        $validated = $request->validated();

        $documentRequest = $this->requestService->updateRequest($documentRequest, $validated);

        return response()->json($documentRequest->load(self::RELATIONS), 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/{documentRequest}/withdraw
    //
    // Deficiency Notice & Withdrawn Status — Phase 1. Deliberately a
    // separate route/method from update() (same reasoning as archive()/
    // restore() being separate from update()) — see
    // WithdrawDocumentRequestRequest and DocumentRequestService::withdraw().
    //
    // Always logged to audit_logs, unconditionally — unlike update(),
    // which currently only logs a status change via the bulk-ready/
    // bulk-done endpoints (see AuditLog::ACTION_REQUEST_WITHDRAWN's
    // docblock). Withdrawal closes out a request permanently and is
    // financially/audit-sensitive (wrong-item-paid reconciliation,
    // duplicate-submission tracking), so it should not inherit that gap.
    // -------------------------------------------------------------------------
    public function withdraw(WithdrawDocumentRequestRequest $request, DocumentRequest $documentRequest)
    {
        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $documentRequest = $this->requestService->withdraw($documentRequest, $validated);

        // Deficiency Notice & Withdrawn Status — Phase 5 ("Withdraw while
        // a Deficiency Notice is open"). DocumentRequestService::
        // withdraw() auto-voids an open notice (if any) as part of this
        // same staff action — see that method's docblock for why block-
        // vs-auto-void was decided in favor of auto-void. Read via the
        // transient (never-persisted) attribute it sets on the returned
        // model rather than issuing a second query for it.
        $autoVoidedRemarkId = $documentRequest->getAttribute('auto_voided_deficiency_notice_id');

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUEST_WITHDRAWN, [
            'request_id'                       => $documentRequest->request_id,
            'withdrawal_reason'                => $documentRequest->withdrawal_reason,
            'superseded_by_request_id'         => $documentRequest->superseded_by_request_id,
            'auto_voided_deficiency_notice_id' => $autoVoidedRemarkId,
        ]);

        // A second, distinct audit entry for the notice itself — so
        // anyone auditing request_remarks activity specifically (e.g.
        // "show me every time notice #N changed state") sees this
        // resolution too, exactly as they would had staff called
        // DeficiencyNoticeController::void() directly instead of it
        // happening automatically. Keeps both audit trails (the
        // request's and the notice's) independently complete.
        foreach ($documentRequest->getAttribute('auto_voided_deficiency_notice_ids') ?? [] as $voidedId) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED, [
                'request_id'  => $documentRequest->request_id,
                'remark_id'   => $voidedId,
                'auto_voided' => true,
            ]);
        }

        return response()->json($documentRequest->load(self::RELATIONS), 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/{documentRequest}/close-unable-to-process
    //
    // Data Retention & Disposal Policy — Section 3.4. Closes a request
    // whose open Deficiency Notice can never be complied with (deceased
    // or permanently incapacitated requestor). Mirrors withdraw()'s
    // controller shape exactly — see that method's comments for the
    // reasoning behind the transient-attribute read and the second,
    // distinct audit entry for the auto-voided notice.
    // -------------------------------------------------------------------------
    public function closeUnableToProcess(CloseRequestUnableToProcessRequest $request, DocumentRequest $documentRequest)
    {
        $validated = $request->validated();

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $documentRequest = $this->requestService->closeUnableToProcess($documentRequest, $validated);

        $voidedRemarkId = $documentRequest->getAttribute('closed_deficiency_notice_id');

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUEST_CLOSED_UNABLE_TO_PROCESS, [
            'request_id'               => $documentRequest->request_id,
            'closure_reason'           => $documentRequest->closure_reason,
            'closure_proof_reference'  => $documentRequest->closure_proof_reference,
            'voided_deficiency_notice_id' => $voidedRemarkId,
        ]);

        foreach ($documentRequest->getAttribute('closed_deficiency_notice_ids') ?? [] as $voidedId) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED, [
                'request_id'  => $documentRequest->request_id,
                'remark_id'   => $voidedId,
                'auto_voided' => true,
            ]);
        }

        return response()->json($documentRequest->load(self::RELATIONS), 200);
    }

    // -------------------------------------------------------------------------
    // PUT /document-requests/{documentRequest}/documents/{requestDocument}
    //
    // Item-level status — advances ONE document line item without forcing
    // every other item on the request through the same transition. See
    // RequestItemStatusService for the transition/permission/history/
    // aggregate logic; this method stays a thin adapter, same division of
    // responsibility as update() above.
    //
    // Route-model binding resolves {requestDocument} globally by its own
    // primary key, so it's possible (malformed URL, stale link) for it to
    // belong to a DIFFERENT request than {documentRequest} — guarded
    // explicitly here rather than trusting the URL nesting, since Laravel
    // does not scope implicit bindings to a parent by default.
    // -------------------------------------------------------------------------
    public function updateDocumentItemStatus(
        UpdateRequestDocumentStatusRequest $request,
        DocumentRequest $documentRequest,
        RequestDocument $requestDocument,
    ) {
        if ((int) $requestDocument->request_id !== (int) $documentRequest->request_id) {
            abort(404, 'This document item does not belong to the specified request.');
        }

        $requestDocument = $this->itemStatusService->advanceDocumentItem(
            $requestDocument,
            (int) $request->validated('status_id'),
        );

        return response()->json($requestDocument->load(['documentType', 'status']), 200);
    }

    // -------------------------------------------------------------------------
    // PUT /document-requests/{documentRequest}/certificates/{requestCertificate}
    //
    // Mirrors updateDocumentItemStatus() exactly, for the certificate side.
    // -------------------------------------------------------------------------
    public function updateCertificateItemStatus(
        UpdateRequestCertificateStatusRequest $request,
        DocumentRequest $documentRequest,
        RequestCertificate $requestCertificate,
    ) {
        if ((int) $requestCertificate->request_id !== (int) $documentRequest->request_id) {
            abort(404, 'This certificate item does not belong to the specified request.');
        }

        $requestCertificate = $this->itemStatusService->advanceCertificateItem(
            $requestCertificate,
            (int) $request->validated('status_id'),
        );

        return response()->json($requestCertificate->load(['certificationType', 'status']), 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/{documentRequest}/mark-certificates-generated
    //
    // Called from GenerateCertificate.jsx's print action — records the real,
    // server-side "generated/printed" signal both ReadyToClaim guards now
    // check (see DocumentRequestService::markCertificatesGenerated()).
    // Replaces the client-only printedCertificateIds localStorage flag,
    // which never reached the server.
    //
    // Optional request_certificate_id (validated inline — this is a single
    // nullable field, not worth its own FormRequest class) scopes the write
    // to one line item when the frontend can resolve which certificate it
    // just printed; omitted, it falls back to marking every ungenerated
    // certificate on the request, same as before per-item targeting existed.
    // -------------------------------------------------------------------------
    public function markCertificatesGenerated(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $validated = $request->validate([
            'request_certificate_id' => 'sometimes|nullable|integer|exists:request_certificate,request_certificate_id',
        ]);

        $this->documentRequestService->markCertificatesGenerated(
            $documentRequest,
            $validated['request_certificate_id'] ?? null
        );

        return response()->json([
            'message'      => 'Certificate(s) marked as generated.',
            'request_id'   => $documentRequest->request_id,
            'certificates' => $documentRequest->fresh()->certificates,
        ], 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/claim
    //
    // QR Code Claiming Policy v1.0. No {documentRequest} route param —
    // staff arrive with a scanned uuid or a typed claim_code, not a known
    // request_id, so lookup happens inside the service. Authorization is
    // handled by ClaimDocumentRequestRequest::authorize() (staff-only),
    // same as every other write action in this controller.
    // -------------------------------------------------------------------------
    public function claim(ClaimDocumentRequestRequest $request)
    {
        $documentRequest = $this->requestService->claimRequest($request->validated());

        return response()->json($documentRequest->load(self::RELATIONS), 200);
    }

    // -------------------------------------------------------------------------
    // DELETE /document-requests/{documentRequest}
    //
    // BUG FIX (RIS-PROCESS-BUGS #2 — "Non-Functional Delete Button")
    // ---------------------------------------------------------------------
    // Root cause: this previously called forceDelete(), a genuine hard
    // delete. request_document (request_document_ibfk_1) and
    // request_history (request_history_ibfk_1) both have a foreign key to
    // document_request with NO onDelete cascade — MySQL's default is
    // RESTRICT — and every real request has at least one request_document
    // row (the document actually being requested) plus request_history
    // rows from its own status transitions. So the FK check in the old
    // try/catch below was tripping on essentially every request that
    // exists, and the button could only ever "succeed" on a request with
    // no documents and no history — which isn't a real request. That's
    // why the button looked "non-functional": the code was correct, the
    // action it performed was just unreachable in practice.
    //
    // Fix: perform a real SOFT delete (DocumentRequest::delete(), via the
    // SoftDeletes trait already declared on the model) instead of a hard
    // forceDelete(). This is the industry-standard pattern for a "delete"
    // action in a system that also maintains an audit trail — it:
    //   - actually succeeds, unconditionally, without touching child rows
    //     or the FK constraints protecting them;
    //   - immediately removes the request from index()'s default listing
    //     (SoftDeletes' global scope excludes deleted_at IS NOT NULL rows
    //     automatically, same as every other Eloquent query on this model);
    //   - preserves request_document/request_history/notifications intact
    //     for audit/compliance purposes, consistent with this codebase's
    //     existing append-only conventions elsewhere (AuditLog, the
    //     is_archived reversible-archive pattern above) rather than
    //     permanently destroying a registrar record and its history;
    //   - is trivially reversible at the DB level if a delete turns out to
    //     have been a mistake (no built-in "undelete" route is added here,
    //     since the report only asked for delete to work — add one the
    //     same way restore() undoes archive() if that's needed later).
    //
    // A genuine permanent purge (forceDelete) is intentionally NOT wired
    // up to this endpoint. If a true hard-delete capability is needed
    // later (e.g. a Super-Admin-only "purge" action), it should be a
    // separate, more tightly-scoped endpoint — silently swapping in a
    // real hard delete here would let this button destroy processing
    // history for a request that's already gone through the registrar
    // workflow, which is a data-integrity/compliance regression, not a
    // fix.
    // -------------------------------------------------------------------------
    public function destroy(DocumentRequest $documentRequest)
    {
        $this->authorize('delete', $documentRequest);

        /** @var SystemUser $actor */
        $actor = Auth::user();
        $requestId = $documentRequest->request_id;

        $documentRequest->delete();

        $this->auditLogger->log(request(), $actor, AuditLog::ACTION_REQUEST_DELETED, [
            'request_id' => $requestId,
        ]);

        return response()->json(['message' => 'Request deleted successfully'], 200);
    }

    // -------------------------------------------------------------------------
    // Archive / Restore
    //
    // Reversible and independent of status_id — see Archive Eligibility
    // Policy – Administrator. Uses manual lookups (DocumentRequest::withArchived())
    // rather than implicit route-model binding since ExcludeArchivedScope
    // would otherwise 404 the restore endpoint before it ever runs.
    // -------------------------------------------------------------------------

    // PATCH /document-requests/{id}/archive
    public function archive($id)
    {
        $documentRequest = DocumentRequest::withArchived()->findOrFail($id);
        $this->authorize('archive', $documentRequest);

        /** @var SystemUser $actor */
        $actor = Auth::user();
        $documentRequest = $this->requestService->archiveRequest($documentRequest, $actor);

        $this->auditLogger->log(request(), $actor, AuditLog::ACTION_REQUEST_ARCHIVED, [
            'request_id' => $documentRequest->request_id,
        ]);

        return response()->json(
            DocumentRequest::withArchived()->with(self::RELATIONS)->find($documentRequest->request_id),
            200
        );
    }

    // PATCH /document-requests/{id}/restore
    public function restore($id)
    {
        $documentRequest = DocumentRequest::withArchived()->findOrFail($id);
        $this->authorize('restore', $documentRequest);

        /** @var SystemUser $actor */
        $actor = Auth::user();
        $documentRequest = $this->requestService->restoreRequest($documentRequest, $actor);

        $this->auditLogger->log(request(), $actor, AuditLog::ACTION_REQUEST_RESTORED, [
            'request_id' => $documentRequest->request_id,
        ]);

        return response()->json(
            DocumentRequest::withArchived()->with(self::RELATIONS)->find($documentRequest->request_id),
            200
        );
    }

    // POST /document-requests/archive-bulk  { request_ids: [...] }
    public function archiveBulk(BulkRequestIdsRequest $request)
    {
        /** @var SystemUser $actor */
        $actor = Auth::user();
        $validated = $request->validated();

        $result = $this->requestService->archiveRequests($validated['request_ids'], $actor);

        foreach ($result['archived'] as $requestId) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUEST_ARCHIVED, [
                'request_id' => $requestId,
                'bulk'       => true,
            ]);
        }

        return response()->json($result, 200);
    }

    // POST /document-requests/restore-bulk  { request_ids: [...] }
    public function restoreBulk(BulkRequestIdsRequest $request)
    {
        /** @var SystemUser $actor */
        $actor = Auth::user();
        $validated = $request->validated();

        $result = $this->requestService->restoreRequests($validated['request_ids'], $actor);

        foreach ($result['restored'] as $requestId) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUEST_RESTORED, [
                'request_id' => $requestId,
                'bulk'       => true,
            ]);
        }

        return response()->json($result, 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/bulk-ready  { request_ids: [...] }
    //
    // "Bulk Ready" — Multi-Item/Mixed-Status Batch rules. Reuses
    // BulkRequestIdsRequest (already validates request_ids as a 1–200
    // item array of distinct integers and gates on isStaff()) rather than
    // adding a near-duplicate FormRequest, since this endpoint takes the
    // exact same input shape as archiveBulk()/restoreBulk() above — the
    // target status (ReadyToClaim) is fixed by which endpoint is called,
    // never taken from the request body, so there is no separate "status"
    // field to validate.
    //
    // Delegates entirely to RequestItemStatusService::bulkAdvanceItems(),
    // which evaluates every selected request's document/certificate
    // children individually, skips ineligible items/requests without
    // blocking eligible ones, and rolls up each affected request's (and
    // release group's) aggregate status afterward — see that method's
    // docblock for the full eligibility rules.
    // -------------------------------------------------------------------------
    public function bulkReadyItems(BulkRequestIdsRequest $request): JsonResponse
    {
        /** @var SystemUser $actor */
        $actor     = Auth::user();
        $validated = $request->validated();

        $result = $this->itemStatusService->bulkAdvanceItems(
            $validated['request_ids'],
            RequestStatusEnum::ReadyToClaim,
        );

        // One audit entry per request whose AGGREGATE status actually
        // changed as a result of this batch — not per item, and not for
        // requests where every item was skipped — mirroring how
        // archiveBulk()/restoreBulk() above log one entry per request
        // actually affected, not one per id submitted.
        foreach ($result['requests_status_changed'] as $requestId) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUEST_STATUS_CHANGED, [
                'request_id'     => $requestId,
                'bulk'           => true,
                'target_status'  => RequestStatusEnum::ReadyToClaim->name,
            ]);
        }

        return response()->json($result, 200);
    }

    // -------------------------------------------------------------------------
    // POST /document-requests/bulk-done  { request_ids: [...] }
    //
    // "Bulk Done" — mirrors bulkReadyItems() exactly, targeting Completed
    // instead of ReadyToClaim. Per the Mixed-Status Batch rule, only
    // items currently ReadyToClaim are eligible (RequestStatusEnum::
    // ReadyToClaim is the only case whose allowedTransitions() includes
    // Completed), so this naturally excludes anything not already ready
    // for pickup without any extra branching here.
    // -------------------------------------------------------------------------
    public function bulkDoneItems(BulkRequestIdsRequest $request): JsonResponse
    {
        /** @var SystemUser $actor */
        $actor     = Auth::user();
        $validated = $request->validated();

        $result = $this->itemStatusService->bulkAdvanceItems(
            $validated['request_ids'],
            RequestStatusEnum::Completed,
        );

        foreach ($result['requests_status_changed'] as $requestId) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_REQUEST_STATUS_CHANGED, [
                'request_id'     => $requestId,
                'bulk'           => true,
                'target_status'  => RequestStatusEnum::Completed->name,
            ]);
        }

        return response()->json($result, 200);
    }
}