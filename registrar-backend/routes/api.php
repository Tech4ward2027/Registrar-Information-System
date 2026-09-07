<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SystemUserController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\StudentAcademicRecordController;
use App\Http\Controllers\RequestStatusController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\CertificationTypeController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\DeficiencyNoticeController;
use App\Http\Controllers\RequestDocumentController;
use App\Http\Controllers\RequestHistoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\SsoCallbackController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AiQueryController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\RequestPurposeController;
use App\Http\Controllers\LogbookCategoryController;
use App\Http\Controllers\FulfillmentTrackController;
use App\Http\Controllers\UnmatchedCashierItemController;
use App\Http\Controllers\CashierOrOverrideController;
use App\Http\Controllers\FreeRequestController;
use App\Http\Controllers\FreeRequestReportController;
use App\Http\Controllers\AlumniSystemController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\SignatoryController;
use App\Http\Controllers\BusinessHoursController;
use App\Http\Controllers\CalendarExceptionController;
use App\Http\Controllers\CalendarOverrideController;
use App\Http\Controllers\SuperAdminAnalyticsController;
use App\Http\Controllers\SecurityEventController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:60,1');

Route::post('/auth/callback', [SsoCallbackController::class, 'handle'])
    ->middleware('throttle:20,1');

Route::get('announcements',               [AnnouncementController::class, 'index']);
Route::get('announcements/{announcement}', [AnnouncementController::class, 'show']);

// Step 4: lets the public request form tell requesters whether the
// Registrar is open right now, and when processing begins if not.
Route::get('/business-hours/status', [BusinessHoursController::class, 'status'])
    ->middleware('throttle:60,1');

// Step 5: heads-up list of upcoming closures (suspensions, events, WFH
// days) for the same public request-form banner.
Route::get('/business-hours/upcoming-closures', [BusinessHoursController::class, 'upcomingClosures'])
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES
|--------------------------------------------------------------------------
*/
// General authenticated endpoints: 60 requests per minute.
// Analytics endpoints get a tighter limit (10/min) because each call
// can trigger heavy DB aggregation or a paid Anthropic API call.
Route::middleware(['auth:sanctum', 'active', 'throttle:60,1'])->group(function () {

    // ── OGOS student data ────────────────────────────────────────────────────
    Route::prefix('students')->group(function () {
        Route::get('search',                    [StudentProfileController::class, 'search']);
        Route::get('{studentNumber}/ogos',      [StudentProfileController::class, 'showByStudentNumber']);
        Route::get('{studentNumber}/personal-info', [StudentProfileController::class, 'personalInfo']);
        Route::get('{studentNumber}/addresses', [StudentProfileController::class, 'addresses']);
    });

    // ── Alumni System (PUPTAPS) integration ─────────────────────────────────────
    Route::prefix('alumni-system')->group(function () {
        Route::get('/',      [AlumniSystemController::class, 'index']);
        Route::get('/{id}',  [AlumniSystemController::class, 'show']);
    });

    // Auth
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Step 3 of Multi-Role Assignments: assume a different role this
    // session currently holds an Active grant for (e.g. a student-staff
    // account switching from Student to their restricted Admin role).
    // Caller-only — see SwitchRoleRequest / RoleAssignmentService::switchTo().
    Route::post('/auth/switch-role', [AuthController::class, 'switchRole']);

    // Broadcasting auth
    // Resolve the sanctum user once before passing to Broadcast::auth().
    // Using setUserResolver(fn() => $request->user('sanctum')) causes infinite
    // recursion because Broadcast::auth() calls $request->user() internally,
    // which re-invokes the resolver, which calls $request->user() again, etc.
    Route::post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
        $user = $request->user('sanctum');
        return \Illuminate\Support\Facades\Broadcast::auth(
            $request->setUserResolver(fn () => $user)
        );
    });

    // Notifications — all roles, own records only
    Route::prefix('notifications')->group(function () {
        Route::get('/',            [NotificationController::class, 'index']);
        Route::get('unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('{id}',         [NotificationController::class, 'show']);
        Route::post('read-all',    [NotificationController::class, 'markAllAsRead']);
        Route::post('{id}/read',   [NotificationController::class, 'markAsRead']);
        Route::delete('{id}',      [NotificationController::class, 'destroy']);
    });

    // Document requests
    //
    // Work Item #1 — Granular Per-Action Permissions: the module:...
    // tags below on index/show/update/claim are the COARSE gate only.
    // - index/show: 'module:dashboard,View' — safe to apply to every
    //   authenticated role because SystemUser::hasModuleAccess() always
    //   returns true for non-admins (students/alumni), so this never
    //   blocks a requester viewing their own requests; it only ever
    //   restricts an admin whose policy lacks dashboard View entirely.
    // - update: 'module:dashboard,Process|Complete' — blocks any admin
    //   with ZERO dashboard write access outright. It cannot by itself
    //   distinguish "this call sets ReadyToClaim" (needs Process) from
    //   "this call sets Completed" (needs Complete) — PUT is one
    //   endpoint for every status transition — so the fine-grained,
    //   target-status-dependent check lives in
    //   DocumentRequestService::updateRequest() instead. See that
    //   file's authorizeStatusChange().
    // - claim: 'module:dashboard,Complete' — a single, unconditional
    //   action gate, not an OR list: claimRequest() can only ever
    //   produce Completed, so there's no ambiguity to resolve later the
    //   way there is for update().
    // - logbook (this file's dashboard "export the queue as a log"
    //   view, distinct from the /request-history module below): now
    //   explicitly 'module:logbook,View' rather than bare
    //   'module:logbook'. Previously the untagged form meant "any
    //   logbook access at all" via hasModuleAccess($module) with no
    //   $action, so a policy granting only Export (no View) still
    //   passed the gate — incoherent for a GET route. Behaviorally
    //   identical for every real policy today (PolicyService now backs
    //   View into any granted logbook action — see sanitizePermissions()
    //   — so no existing policy can have Export without View), but
    //   spelling it out here removes the implicit "no action = any
    //   action" reading for a route that only ever needs View.
    // - counts: now carries 'module:dashboard,View' alongside role:3,4.
    //   This endpoint returns per-status counts for the same dashboard
    //   queue that index/show/logbook already gate — it had no module
    //   check at all before, which was a gap (an admin with zero
    //   dashboard access could still see how many requests were in each
    //   status). Counts are read-only and derived from the same View
    //   permission as the list itself, so this reuses that action
    //   rather than inventing a new one.
    Route::prefix('document-requests')->group(function () {
        Route::get('/',                           [DocumentRequestController::class, 'index'])->middleware('module:dashboard,View');
        Route::get('logbook',                     [DocumentRequestController::class, 'logbook'])->middleware(['role:3,4', 'module:logbook,View']);
        Route::get('counts',                      [DocumentRequestController::class, 'counts'])->middleware(['role:3,4', 'module:dashboard,View']);
        Route::post('archive-bulk',                [DocumentRequestController::class, 'archiveBulk'])->middleware('role:3');
        Route::post('restore-bulk',                [DocumentRequestController::class, 'restoreBulk'])->middleware('role:3');
        // Bulk Ready / Bulk Done — Multi-Item/Mixed-Status Batch rules.
        // Same coarse role/module gate as the single-item status routes
        // below (updateDocumentItemStatus()/updateCertificateItemStatus()):
        // the fine-grained Process-vs-Complete check happens inside
        // RequestItemStatusService once the actual target status is known,
        // same split update()/the single-item routes already use. Kept
        // under /document-requests (not /request-documents) since the
        // input is a batch of REQUEST ids, not line-item ids — the service
        // resolves each request's own eligible children internally.
        Route::post('bulk-ready', [DocumentRequestController::class, 'bulkReadyItems'])
            ->middleware(['role:3', 'module:dashboard,Process|Complete']);
        Route::post('bulk-done',  [DocumentRequestController::class, 'bulkDoneItems'])
            ->middleware(['role:3', 'module:dashboard,Process|Complete']);
        Route::post('claim',                       [DocumentRequestController::class, 'claim'])->middleware(['role:3', 'module:dashboard,Complete']);
        // Dedicated throttle on top of the group's throttle:60,1 — OR
        // numbers look sequential (see cashier sample data), so this is a
        // soft enumeration surface (probing which numbers return `valid`)
        // even though it never creates or discloses a DocumentRequest.
        // 10/min is generous for a real student retrying a mistyped OR a
        // few times, tight enough to make scripted probing impractical.
        // Distinct prefix ('verify-or') is required, not cosmetic: Laravel's
        // ThrottleRequests keys a limiter as $prefix.sha1($userId), with an
        // empty prefix by default. Without one here, this middleware shares
        // its cache key with the group's throttle:60,1 above (same user,
        // same empty prefix) — every request then increments BOTH counters,
        // and the tighter one here trips at roughly half its configured
        // value (10/min effectively became ~5/min). See the same fix
        // applied to system-users store, ai-report/ai-query, and
        // search-users below — all had the identical collision.
        Route::post('verify-or', [DocumentRequestController::class, 'verifyOfficialReceipt'])
            ->middleware(['role:1,2', 'throttle:10,1,verify-or']);
        Route::get('{documentRequest}', [DocumentRequestController::class, 'show'])->middleware('module:dashboard,View');
        Route::post('/', [DocumentRequestController::class, 'store'])->middleware('role:1,2');
        Route::put('{documentRequest}',    [DocumentRequestController::class, 'update'])->middleware(['role:3', 'module:dashboard,Process|Complete']);
        // Deficiency Notice & Withdrawn Status — Phase 1. Same coarse
        // role/module gate as every other admin status-write action on
        // this resource. Unlike update(), withdraw() always requires
        // exactly 'Process' — never 'Complete' — since it can only ever
        // be reached from AwaitingSubmission/Processing/PendingSignature
        // (see RequestStatusEnum::allowedTransitions()), the same clean
        // single-action shape as claim() above requiring exactly
        // 'Complete'. Fine-grained enforcement happens in
        // DocumentRequestPolicy::withdraw(), called from
        // WithdrawDocumentRequestRequest::authorize().
        Route::post('{documentRequest}/withdraw', [DocumentRequestController::class, 'withdraw'])
            ->middleware(['role:3', 'module:dashboard,Process']);
        // Data Retention & Disposal Policy — Section 3.4. Same coarse
        // role/module gate as withdraw() above — see
        // DocumentRequestPolicy::closeUnableToProcess()'s docblock for
        // a flagged note on whether this specific action deserves a
        // stricter tier given its real-world gravity.
        Route::post('{documentRequest}/close-unable-to-process', [DocumentRequestController::class, 'closeUnableToProcess'])
            ->middleware(['role:3', 'module:dashboard,Process']);
        // Deficiency Notice & Withdrawn Status — Phase 3. Same coarse
        // role/module gate as withdraw() above — always exactly
        // 'Process', never 'Complete', same clean single-action shape.
        // Fine-grained enforcement happens in
        // DocumentRequestPolicy::issueDeficiencyNotice(), called from
        // IssueDeficiencyNoticeRequest::authorize().
        Route::post('{documentRequest}/deficiency-notices', [DeficiencyNoticeController::class, 'issue'])
            ->middleware(['role:3', 'module:dashboard,Process']);
        // Item-level status — see RequestItemStatusService and
        // DocumentRequestController::updateDocumentItemStatus()/
        // updateCertificateItemStatus(). Same coarse role/module gate as
        // the whole-request update() above; the Process-vs-Complete
        // fine-grained check happens inside the service once the actual
        // target status is known, same split as update() already uses.
        Route::put('{documentRequest}/documents/{requestDocument}',
            [DocumentRequestController::class, 'updateDocumentItemStatus'])
            ->middleware(['role:3', 'module:dashboard,Process|Complete']);
        Route::put('{documentRequest}/certificates/{requestCertificate}',
            [DocumentRequestController::class, 'updateCertificateItemStatus'])
            ->middleware(['role:3', 'module:dashboard,Process|Complete']);
        // Real print/generation signal — see DocumentRequestService::
        // markCertificatesGenerated() and migration
        // 2026_08_29_000010_add_generated_at_to_request_certificate. Gated
        // the same as the item-status writes above: staff need at least
        // Process access on the dashboard module to record a certificate
        // as generated.
        Route::post('{documentRequest}/mark-certificates-generated',
            [DocumentRequestController::class, 'markCertificatesGenerated'])
            ->middleware(['role:3', 'module:dashboard,Process|Complete']);
        Route::patch('{documentRequest}/archive', [DocumentRequestController::class, 'archive'])->middleware('role:3');
        Route::patch('{documentRequest}/restore', [DocumentRequestController::class, 'restore'])->middleware('role:3');
        Route::delete('{documentRequest}', [DocumentRequestController::class, 'destroy'])->middleware('role:3');
    });

    // Deficiency Notice & Withdrawn Status — Phase 3. clear()/void() act
    // on a RequestRemark directly (not nested under a specific
    // document-requests/{documentRequest} URL) since a Deficiency Notice
    // is addressable by its own id once issued — same flat-resource
    // shape request-history's own top-level routes already use
    // elsewhere in this file. Same coarse role/module gate as every
    // other admin status-write action on this feature; fine-grained
    // enforcement happens in RequestRemarkPolicy::clear()/void(),
    // called from ClearDeficiencyNoticeRequest::authorize()/
    // VoidDeficiencyNoticeRequest::authorize().
    Route::prefix('deficiency-notices')->group(function () {
        Route::post('{deficiencyNotice}/clear', [DeficiencyNoticeController::class, 'clear'])
            ->middleware(['role:3', 'module:dashboard,Process']);
        Route::post('{deficiencyNotice}/void', [DeficiencyNoticeController::class, 'void'])
            ->middleware(['role:3', 'module:dashboard,Process']);
    });

    // Request documents (line-items)
    Route::prefix('request-documents')->group(function () {
        Route::get('/',     [RequestDocumentController::class, 'index']);
        Route::get('{id}',  [RequestDocumentController::class, 'show']);
        Route::post('/',    [RequestDocumentController::class, 'store'])->middleware('role:1,2');
        Route::put('{id}',  [RequestDocumentController::class, 'update'])->middleware('role:3');
        Route::delete('{id}', [RequestDocumentController::class, 'destroy'])->middleware('role:3');
    });

    // Business calendar management — dated exceptions (holidays,
    // suspensions, events) and recurring overrides (e.g. WFH Mondays).
    // Unlike announcements (role:4 only), this is open to any admin whose
    // policy grants the "business_calendar" module — super admin always
    // bypasses via RoleMiddleware regardless of policy. GET is included
    // here (not left open like `GET /policies`) since exception/override
    // rows can include internal notes not meant for students/alumni; the
    // public banner reads from /business-hours/* instead, which returns
    // only the derived open/closed status, not the raw admin records.
    Route::middleware(['role:3,4', 'module:business_calendar'])->group(function () {
        Route::apiResource('calendar-exceptions', CalendarExceptionController::class)
            ->parameters(['calendar-exceptions' => 'exception'])
            ->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('calendar-overrides', CalendarOverrideController::class)
            ->parameters(['calendar-overrides' => 'override'])
            ->only(['index', 'store', 'update', 'destroy']);
    });

    // Cashier OR override — the scoped, audited admin bypass for one
    // (or_number, student) pair when a real receipt is wrongly rejected
    // by the Cashier API (see CashierOrOverrideController and the
    // cashier_or_overrides migration's docblock for the full design).
    //
    // Gated by policy, same pattern as business_calendar directly
    // above: any admin whose attached policy grants the
    // "cashier_overrides" module can create/list/revoke overrides;
    // super admin always has it via SystemUser::hasModuleAccess()'s
    // unconditional super-admin bypass, with no policy attachment
    // needed. This is a deliberately narrower gate than the general
    // "role:3" admin group — bypassing a money-facing check is
    // sensitive enough that it shouldn't be something every admin
    // account gets by default just by being role_id=3; a super admin
    // grants it explicitly per-admin via Policy Management, the same
    // way business_calendar or access_requests access is granted.
    Route::middleware(['role:3,4', 'module:cashier_overrides'])->group(function () {
        // Distinct throttle prefix so this doesn't share a rate-limit
        // bucket with role-assignments' own search-users route — same
        // reasoning as that route's own comment on why an unprefixed
        // throttle would collide.
        Route::get('cashier-overrides/search-users', [CashierOrOverrideController::class, 'searchUsers'])
            ->middleware('throttle:30,1,cashier-overrides-search-users');

        Route::get('cashier-overrides',              [CashierOrOverrideController::class, 'index']);
        Route::post('cashier-overrides',              [CashierOrOverrideController::class, 'store']);
        Route::post('cashier-overrides/{id}/revoke',  [CashierOrOverrideController::class, 'revoke']);
    });

    // Free Document/Certificate Request (FESPEC-0008) — the admin
    // Free Request page. Staff on-behalf-of filing for the Free
    // Documents/Certificates Request Policy and the First Copy Free
    // Issuance for Graduates Policy — see FreeRequestController's own
    // docblock for why this has no Laravel Policy class (same
    // model-less, route-middleware-gated pattern as cashier_overrides
    // directly above).
    //
    // 'View' covers the read-only search/eligibility actions every
    // Free Request page user needs just to see the screen. 'File' is
    // the actual filing action. 'Verify' and 'Override' are NOT
    // route-level gates — a single POST /free-requests call may or may
    // not require either depending on what's being filed, so those two
    // are enforced inside FreeRequestService::assertCapability() at the
    // moment they're actually exercised (see that method's docblock).
    // Gating store() at 'File' here is still correct even for a filing
    // that turns out to need Verify/Override: File is a strict subset
    // of what those two additionally require, never a bypass of them.
    Route::middleware(['role:3,4', 'module:free_requests,View'])->group(function () {
        // Distinct throttle prefix, same reasoning as cashier-overrides'
        // own search-users route immediately above.
        Route::get('free-requests/search-accounts', [FreeRequestController::class, 'searchAccounts'])
            ->middleware('throttle:30,1,free-requests-search-accounts');

        Route::post('free-requests/eligibility', [FreeRequestController::class, 'eligibility'])
            ->middleware('throttle:30,1,free-requests-eligibility');

        // Phase 8 — Observability. Same 'View' gate as the two routes
        // above (see FreeRequestReportController's own docblock on why
        // this doesn't have its own module/action yet), and the same
        // 30/min read-only throttle bucket family used elsewhere in
        // this group — this is a periodic admin report, not a
        // high-frequency page, so it doesn't need its own tighter or
        // looser limit.
        Route::get('free-requests/reports/monthly-volume', [FreeRequestReportController::class, 'monthlyVolume'])
            ->middleware('throttle:30,1,free-requests-reports');
    });

    Route::middleware(['role:3,4', 'module:free_requests,File'])->group(function () {
        // Phase 7 — Security Hardening: this was the one action in the
        // whole free-request flow with no rate limit at all — every
        // read-only endpoint above (search-accounts, eligibility) had
        // one, but the actual write/filing action did not. Tighter than
        // the 30/min read limiters above: this performs a real DB write
        // (a free-of-charge document/certificate issuance) and briefly
        // holds a row lock on the target account (see
        // FreeRequestService::fileFreeRequest()'s docblock), so it's
        // both a higher-value abuse target and more expensive per call.
        // 10/min per authenticated admin is generous for genuine
        // counter-service filing pace while meaningfully bounding
        // automated abuse or override brute-forcing.
        Route::post('free-requests', [FreeRequestController::class, 'store'])
            ->middleware('throttle:10,1,free-requests-store');
    });

    // Request history — READ ONLY. History is written only by DocumentRequestService.
    Route::middleware(['role:3,4', 'module:logbook,View'])->prefix('request-history')->group(function () {
        Route::get('/',    [RequestHistoryController::class, 'index']);
        Route::get('{id}', [RequestHistoryController::class, 'show']);
    });

    // Read-only reference data — all authenticated roles
    Route::get('document-types',             [DocumentTypeController::class, 'index']);
    Route::get('document-types/{id}',        [DocumentTypeController::class, 'show']);
    Route::get('certifications',             [CertificationTypeController::class, 'index']);
    Route::get('certifications/layouts',     [CertificationTypeController::class, 'layouts']);
    Route::get('certifications/{id}',        [CertificationTypeController::class, 'show']);
    Route::get('request-statuses',           [RequestStatusController::class, 'index']);
    Route::get('request-statuses/{id}',      [RequestStatusController::class, 'show']);
    Route::get('request-purposes',      [RequestPurposeController::class, 'index']);
    Route::get('request-purposes/{id}', [RequestPurposeController::class, 'show']);
    Route::get('logbook-categories',      [LogbookCategoryController::class, 'index']);
    Route::get('logbook-categories/{id}', [LogbookCategoryController::class, 'show']);
    Route::get('fulfillment-tracks',      [FulfillmentTrackController::class, 'index']);
    Route::get('fulfillment-tracks/{id}', [FulfillmentTrackController::class, 'show']);
    Route::get('programs', [ProgramController::class, 'index']);

    // Admin only (role 3 — superadmin bypasses via RoleMiddleware)
    Route::middleware('role:3')->group(function () {
        Route::post('document-types',              [DocumentTypeController::class, 'store']);
        Route::put('document-types/{id}',          [DocumentTypeController::class, 'update']);
        Route::delete('document-types/{id}',       [DocumentTypeController::class, 'destroy']);
        Route::patch('document-types/{id}/archive', [DocumentTypeController::class, 'archive']);
        Route::patch('document-types/{id}/restore', [DocumentTypeController::class, 'restore']);
        Route::post('certifications',              [CertificationTypeController::class, 'store']);
        Route::put('certifications/{id}',          [CertificationTypeController::class, 'update']);
        Route::delete('certifications/{id}',       [CertificationTypeController::class, 'destroy']);
        Route::patch('certifications/{id}/archive', [CertificationTypeController::class, 'archive']);
        Route::patch('certifications/{id}/restore', [CertificationTypeController::class, 'restore']);
        Route::put('certifications/{id}/layout',          [CertificationTypeController::class, 'updateLayout']);
        Route::post('certifications/{id}/layout/logo',    [CertificationTypeController::class, 'uploadLayoutLogo']);
        Route::post('request-statuses',        [RequestStatusController::class, 'store']);
        Route::put('request-statuses/{id}',    [RequestStatusController::class, 'update']);
        Route::delete('request-statuses/{id}', [RequestStatusController::class, 'destroy']);
        Route::apiResource('students',         StudentProfileController::class);
        Route::apiResource('academic-records', StudentAcademicRecordController::class);

        Route::prefix('analytics')->middleware(['throttle:60,1', 'module:analytics'])->group(function () {
            Route::get('overview',         [AnalyticsController::class, 'overview']);
            Route::get('volume-trend',     [AnalyticsController::class, 'volumeTrend']);
            Route::get('by-document-type', [AnalyticsController::class, 'byDocumentType']);
            Route::get('by-status',        [AnalyticsController::class, 'byStatus']);
            Route::get('processing-time',  [AnalyticsController::class, 'processingTime']);
            Route::get('signature-turnaround', [AnalyticsController::class, 'signatureTurnaround']);
            Route::get('peak-hours',       [AnalyticsController::class, 'peakHours']);
            Route::get('by-purpose',       [AnalyticsController::class, 'byPurpose']);
            // Distinct prefixes below — see the verify-or route's comment
            // for why an unprefixed throttle stacked under the group's
            // throttle:60,1 shares its counter and trips at roughly half
            // its configured value.
            Route::post('ai-report', [AnalyticsController::class, 'aiReport'])
                ->middleware('throttle:30,1,ai-report');
            // Phase 3 — Conversational NLQ
            Route::post('ai-query', [AiQueryController::class, 'query'])
                ->middleware('throttle:30,1,ai-query');
        });

        Route::post('request-purposes',        [RequestPurposeController::class, 'store']);
        Route::put('request-purposes/{id}',    [RequestPurposeController::class, 'update']);
        Route::delete('request-purposes/{id}', [RequestPurposeController::class, 'destroy']);

        Route::post('logbook-categories',        [LogbookCategoryController::class, 'store']);
        Route::put('logbook-categories/{id}',    [LogbookCategoryController::class, 'update']);
        Route::delete('logbook-categories/{id}', [LogbookCategoryController::class, 'destroy']);

        Route::post('fulfillment-tracks',        [FulfillmentTrackController::class, 'store']);
        Route::put('fulfillment-tracks/{id}',    [FulfillmentTrackController::class, 'update']);
        Route::delete('fulfillment-tracks/{id}', [FulfillmentTrackController::class, 'destroy']);

        // Unmatched cashier receipt labels — admin review screen backing
        // the naming-drift fix from CashierDocumentSuggester. See
        // UnmatchedCashierItemController's class docblock.
        Route::get('unmatched-cashier-items',              [UnmatchedCashierItemController::class, 'index']);
        Route::post('unmatched-cashier-items/{id}/resolve', [UnmatchedCashierItemController::class, 'resolve']);
        Route::post('unmatched-cashier-items/{id}/dismiss', [UnmatchedCashierItemController::class, 'dismiss']);

        // Signatories (certificate signees) — admin-only end to end,
        // unlike document-types/certifications above whose GET is open to
        // all authenticated roles. See create_signatories_table migration.
        Route::get('signatories',           [SignatoryController::class, 'index']);
        Route::post('signatories',          [SignatoryController::class, 'store']);
        Route::put('signatories/{id}',      [SignatoryController::class, 'update']);
        Route::delete('signatories/{id}',   [SignatoryController::class, 'destroy']);
    });

    // Superadmin only (role 4)
    Route::middleware('role:4')->group(function () {
        // Admin creation gets its own dedicated, tighter throttle on top of
        // the group's throttle:60,1 — this is now the primary defense
        // against bulk/automated admin creation (see IdpClient::createUser()
        // docblock re: x-api-key-only auth on the IdP side). Distinct
        // prefix required — see the verify-or route's comment for why an
        // unprefixed throttle here would share the group's counter.
        Route::apiResource('system-users', SystemUserController::class)->except(['store']);
        Route::post('system-users', [SystemUserController::class, 'store'])
            ->middleware('throttle:5,1,system-users-store')
            ->name('system-users.store');

        // User Management — Policy Attachment: reusable admin permission
        // policies. Work Item #2 — Admin Management Consolidation:
        // attaching/editing a specific admin's policy no longer happens
        // here — see PATCH /role-assignments/{roleAssignment}/policy
        // below, the one remaining place a policy is ever attached to an
        // account. NOTE: GET (read) is intentionally NOT here — see
        // below. Only create/edit/delete of a policy itself is
        // Super-Admin-only.
        Route::post('policies',          [PolicyController::class, 'store']);
        Route::put('policies/{id}',      [PolicyController::class, 'update']);
        Route::delete('policies/{id}',   [PolicyController::class, 'destroy']);

        Route::get('audit-logs',         [AuditLogController::class, 'index']);
        Route::get('audit-logs/filters', [AuditLogController::class, 'filters']);

        // Phase 3 — Audit Log Revamp: RIS-Only Security Events. Deliberately
        // a separate table/controller from audit-logs above, not folded
        // into it — see the plan doc's "Trade-off" section and
        // create_security_events_table migration's docblock for the full
        // reasoning (different volume, different retention, different
        // consumer). Read-only: writes happen internally via
        // SecurityEventLogger from LocalAuthService/AuthController, never
        // through this controller.
        Route::get('security-events',         [SecurityEventController::class, 'index']);
        Route::get('security-events/filters', [SecurityEventController::class, 'filters']);

        // Phase 2 — SuperAdmin Analytics Dashboard (system-level, not
        // scoped to a single Registrar's request queue — see
        // SuperAdminAnalyticsController's class docblock for how this
        // differs from /analytics above). Named throttle segment
        // ('system-analytics') so this group's counter doesn't get
        // folded into the outer role:4 group's throttle:60,1 — see the
        // verify-or route's comment elsewhere in this file for why an
        // unnamed throttle stacked under an outer group shares its
        // counter instead of getting its own bucket.
        Route::prefix('system-analytics')->middleware('throttle:60,1,system-analytics')->group(function () {
            Route::get('admin-roster-health',        [SuperAdminAnalyticsController::class, 'adminRosterHealth']);
            Route::get('access-request-throughput',  [SuperAdminAnalyticsController::class, 'accessRequestThroughput']);
            Route::get('cashier-verification-health', [SuperAdminAnalyticsController::class, 'cashierVerificationHealth']);
            Route::get('scheduled-jobs-health',       [SuperAdminAnalyticsController::class, 'scheduledJobsHealth']);
        });
        Route::post('announcements',                      [AnnouncementController::class, 'store']);
        Route::put('announcements/{announcement}',        [AnnouncementController::class, 'update']);
        Route::delete('announcements/{announcement}',     [AnnouncementController::class, 'destroy']);
        Route::patch('announcements/{id}/archive',        [AnnouncementController::class, 'archive']);
        Route::patch('announcements/{id}/restore',        [AnnouncementController::class, 'restore']);
    });

    // GET /policies (read-only): needed by any admin who can submit an
    // access request — RequestAccessPage.jsx's "Requested Policy" dropdown
    // — not just Super Admins managing policies. Deliberately gated the
    // same way as POST /access-requests below, not left wide open to every
    // authenticated role. Mutating a policy (create/edit/delete, above)
    // stays Super-Admin-only.
    Route::get('policies', [PolicyController::class, 'index'])
        ->middleware(['role:3,4', 'module:access_requests']);

    // Self-service access requests: delegated intake, centralized approval.
    // store() is available to any admin with the 'access_requests' module
    // (not just super admins) — everything else is super-admin-only, since
    // approval is what actually creates a SystemUser row.
    Route::prefix('access-requests')->group(function () {
        Route::post('/', [AccessRequestController::class, 'store'])
            ->middleware(['role:3,4', 'module:access_requests']);

        Route::middleware('role:4')->group(function () {
            Route::get('/',                     [AccessRequestController::class, 'index']);
            Route::post('{accessRequest}/approve', [AccessRequestController::class, 'approve']);
            Route::post('{accessRequest}/reject',  [AccessRequestController::class, 'reject']);
        });
    });

    // Role assignments — onboarding/offboarding a secondary role onto an
    // existing account (e.g. the Admin side of a "student staff" who
    // already holds Student). Grant/revoke/full-history are Super-Admin
    // only; 'mine' is any authenticated user reading their own currently
    // held roles (used by the frontend role switcher).
    Route::prefix('role-assignments')->group(function () {
        Route::get('mine', [RoleAssignmentController::class, 'mine']);

        Route::middleware('role:4')->group(function () {
            // Dedicated throttle stacked on top of the group's
            // throttle:60,1 — this endpoint returns a broader slice of
            // the user directory than anything else Super Admin can
            // query, so it gets its own tighter ceiling against
            // scripted enumeration. Distinct prefix required — see the
            // verify-or route's comment for why an unprefixed throttle
            // here would share the group's counter.
            Route::get('search-users', [RoleAssignmentController::class, 'searchUsers'])
                ->middleware('throttle:30,1,search-users');

            Route::get('/',                          [RoleAssignmentController::class, 'index']);
            Route::post('/',                          [RoleAssignmentController::class, 'store']);
            Route::post('{roleAssignment}/revoke',    [RoleAssignmentController::class, 'revoke']);

            // Work Item #2 — Admin Management Consolidation: edit the
            // policy on an already-Active Admin grant in place, without
            // a revoke/regrant cycle. This is now the ONLY endpoint that
            // writes a policy onto an existing admin account — see
            // RoleAssignmentService::editPolicy().
            Route::patch('{roleAssignment}/policy',   [RoleAssignmentController::class, 'editPolicy']);
        });
    });
});
/*
|--------------------------------------------------------------------------
| LOCAL AUTH ROUTES  (added by apply_local_auth.py)
|--------------------------------------------------------------------------
| POST /api/auth/local-login         — always local, bypasses IDP
| POST /api/auth/local-password      — superadmin: set a user's local pwd
| GET  /api/auth/local-auth-status   — superadmin: list local-auth coverage
*/
use App\Http\Controllers\LocalAuthController;

Route::post('/auth/local-login', [LocalAuthController::class, 'login'])
    ->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'active', 'role:4'])->group(function () {
    Route::post('/auth/local-password',    [LocalAuthController::class, 'setPassword']);
    Route::get('/auth/local-auth-status',  [LocalAuthController::class, 'status']);
});