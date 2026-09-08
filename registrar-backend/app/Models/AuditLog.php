<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AuditLog extends Model
{
    // Write-once — no updated_at
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'email',
        'role_name',
        'target_user_id',
        'target_email',
        'action',
        'browser',
        'ip_address',
        'metadata',
        'prev_hash',
        'hash',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'metadata'   => 'array',
    ];

    // -------------------------------------------------------
    // Action constants — single source of truth.
    // Use these everywhere instead of raw strings so a typo
    // never silently creates a broken log entry.
    // -------------------------------------------------------
    public const ACTION_LOGIN           = 'login';
    public const ACTION_LOGOUT          = 'logout';
    public const ACTION_ADMIN_CREATED   = 'admin_created';
    public const ACTION_ADMIN_ACTIVATED = 'admin_activated';
    public const ACTION_ADMIN_DELETED   = 'admin_deleted';
    public const ACTION_ADMIN_UPDATED   = 'admin_updated';
    public const ACTION_ADMIN_EXPIRED   = 'admin_expired';
    // RIS-side activation/deactivation (or password change) succeeded but
    // the best-effort push to the IdP failed — see AdminUserService::update().
    // The RIS-side change is NOT rolled back; this exists purely so ops can
    // see, from the audit trail, that the two systems may be out of sync
    // and reconcile the IdP side by hand.
    public const ACTION_ADMIN_IDP_SYNC_FAILED = 'admin_idp_sync_failed';
    public const ACTION_ROLE_ASSIGNED   = 'role_assigned';   // granted (RoleAssignmentService::grant)
    public const ACTION_ROLE_REVOKED    = 'role_revoked';    // explicit revoke (RoleAssignmentService::revoke)
    public const ACTION_ROLE_EXPIRED    = 'role_expired';    // automatic sweep (role-assignments:expire)
    public const ACTION_ROLE_SWITCHED   = 'role_switched';   // session assumed a different held role (Step 3)
    // Work Item #2 — in-place policy edit on an already-Active Admin
    // grant (RoleAssignmentService::editPolicy()) — distinct from
    // ACTION_ROLE_ASSIGNED/REVOKED since no grant/revoke event occurred.
    public const ACTION_ROLE_POLICY_EDITED = 'role_policy_edited';
    public const ACTION_REQUEST_STATUS_CHANGED = 'request_status_changed';
    public const ACTION_REQUEST_ARCHIVED       = 'request_archived';
    public const ACTION_REQUEST_RESTORED       = 'request_restored';
    // BUG FIX (RIS-PROCESS-BUGS #2 — "Non-Functional Delete Button"):
    // new action for DocumentRequestController::destroy(), which now
    // performs a real (soft) delete instead of a forceDelete() that could
    // never succeed against request_document/request_history's FK
    // constraints — see that method's docblock for the full reasoning.
    public const ACTION_REQUEST_DELETED        = 'request_deleted';
    // Deficiency Notice & Withdrawn Status — Phase 1. Always logged (see
    // DocumentRequestController::withdraw()) — unlike the single-item
    // update() endpoint, which currently does not audit-log status
    // changes at all (only bulk-ready/bulk-done and archive/restore do).
    // Withdrawal is financially/audit-sensitive enough (wrong-item-paid
    // reconciliation, duplicate-submission tracking) that it should not
    // share that gap.
    public const ACTION_REQUEST_WITHDRAWN      = 'request_withdrawn';
    // Deficiency Notice & Withdrawn Status — Phase 3. Logged
    // unconditionally by DeficiencyNoticeController, same "always audit,
    // never conditionally" stance ACTION_REQUEST_WITHDRAWN documents
    // above — a hold that pauses processing (and its resolution) is
    // audit-sensitive for the same reason a terminal status change is.
    public const ACTION_DEFICIENCY_NOTICE_ISSUED  = 'deficiency_notice_issued';
    public const ACTION_DEFICIENCY_NOTICE_CLEARED = 'deficiency_notice_cleared';
    public const ACTION_DEFICIENCY_NOTICE_VOIDED  = 'deficiency_notice_voided';
    // Data Retention & Disposal Policy — Section 3.3/3.4. Logged
    // unconditionally, same stance as every other status/notice action
    // above — an escalation past the 30-day compliance window, and a
    // permanent closure on proof of death/incapacity, are both
    // maximally audit-sensitive events.
    public const ACTION_DEFICIENCY_NOTICE_ESCALATED    = 'deficiency_notice_escalated';
    public const ACTION_REQUEST_CLOSED_UNABLE_TO_PROCESS = 'request_closed_unable_to_process';

    // Document / certificate type management — archiving
    public const ACTION_DOCUMENT_TYPE_ARCHIVED    = 'document_type_archived';
    public const ACTION_DOCUMENT_TYPE_RESTORED    = 'document_type_restored';
    public const ACTION_CERTIFICATE_TYPE_ARCHIVED = 'certificate_type_archived';
    public const ACTION_CERTIFICATE_TYPE_RESTORED = 'certificate_type_restored';

    // Document / certificate type management — create/update/delete.
    // Distinct from the archive/restore actions above: these cover the
    // money-facing fields on these rows (cashier_document_patterns —
    // the regex CashierDocumentMatcher/CashierDocumentSuggester use to
    // reconcile a paid receipt line against a requested item —  plus
    // fulfillment_track_id and logbook_category_id) that, until now,
    // could be changed via store()/update()/destroy() with no audit
    // trail at all.
    public const ACTION_DOCUMENT_TYPE_CREATED     = 'document_type_created';
    public const ACTION_DOCUMENT_TYPE_UPDATED     = 'document_type_updated';
    public const ACTION_DOCUMENT_TYPE_DELETED     = 'document_type_deleted';
    public const ACTION_CERTIFICATE_TYPE_CREATED  = 'certificate_type_created';
    public const ACTION_CERTIFICATE_TYPE_UPDATED  = 'certificate_type_updated';
    public const ACTION_CERTIFICATE_TYPE_DELETED  = 'certificate_type_deleted';

    // Logbook category lookup table (see LogbookCategoryController) —
    // previously unaudited entirely, same gap as document/certificate
    // type create/update/delete above.
    public const ACTION_LOGBOOK_CATEGORY_CREATED = 'logbook_category_created';
    public const ACTION_LOGBOOK_CATEGORY_UPDATED = 'logbook_category_updated';
    public const ACTION_LOGBOOK_CATEGORY_DELETED = 'logbook_category_deleted';

    // Fulfillment track lookup table (see FulfillmentTrackController) —
    // new admin screen; audited from the start rather than repeating
    // the gap the two lookup tables above had.
    public const ACTION_FULFILLMENT_TRACK_CREATED = 'fulfillment_track_created';
    public const ACTION_FULFILLMENT_TRACK_UPDATED = 'fulfillment_track_updated';
    public const ACTION_FULFILLMENT_TRACK_DELETED = 'fulfillment_track_deleted';

    // Announcement archiving (distinct from the enable/disable toggle,
    // which is not audit-logged — see the Announcement Archive policy)
    public const ACTION_ANNOUNCEMENT_ARCHIVED = 'announcement_archived';
    public const ACTION_ANNOUNCEMENT_RESTORED = 'announcement_restored';

    // Business calendar management — dated exceptions (holidays,
    // suspensions, one-off events) and recurring overrides (e.g. WFH
    // Mondays). Gated behind role:3,4 + module:business_calendar — see
    // CalendarExceptionController / CalendarOverrideController.
    public const ACTION_CALENDAR_EXCEPTION_CREATED = 'calendar_exception_created';
    public const ACTION_CALENDAR_EXCEPTION_UPDATED = 'calendar_exception_updated';
    public const ACTION_CALENDAR_EXCEPTION_DELETED = 'calendar_exception_deleted';
    public const ACTION_CALENDAR_OVERRIDE_CREATED  = 'calendar_override_created';
    public const ACTION_CALENDAR_OVERRIDE_UPDATED  = 'calendar_override_updated';
    public const ACTION_CALENDAR_OVERRIDE_DELETED  = 'calendar_override_deleted';

    // User management — policy attachment (admins only)
    public const ACTION_POLICY_CREATED  = 'policy_created';
    public const ACTION_POLICY_UPDATED  = 'policy_updated';
    public const ACTION_POLICY_DELETED  = 'policy_deleted';
    public const ACTION_POLICY_ATTACHED = 'policy_attached';
    public const ACTION_POLICY_DETACHED = 'policy_detached';

    // Self-service access requests (see AccessRequestService)
    public const ACTION_ACCESS_REQUEST_SUBMITTED = 'access_request_submitted';
    public const ACTION_ACCESS_REQUEST_APPROVED  = 'access_request_approved';
    public const ACTION_ACCESS_REQUEST_REJECTED  = 'access_request_rejected';
    public const ACTION_ACCESS_REQUEST_EXPIRED   = 'access_request_expired';

    // Cashier OR verification attempts (see NameMatcher, DocumentRequestController::store)
    // Logged for every attempt regardless of outcome — not just failures —
    // via the existing tamper-evident audit_log table rather than a new
    // one, so this survives container recreation the way storage/logs
    // currently does not (see 2026-08-11 incident notes).
    public const ACTION_CASHIER_VERIFICATION = 'cashier_verification';

    // Phase 4 — Cashier Verification Failure Diagnostics
    // (EnrichCashierFailureJob, AuditLogger::logForSystem).
    //
    // A DELIBERATELY SEPARATE row from the ACTION_CASHIER_VERIFICATION
    // entry it enriches — never a mutation of that entry. AuditLog::booted()
    // below hard-blocks updates/deletes on every row (tamper-evident,
    // append-only by design), and the hash chain's own guarantee only
    // covers what AuditLogger::computeHash() feeds it (action/user_id/
    // target_user_id/target_email/created_at — see that class), so
    // reopening a row to stuff enrichment data into its metadata after
    // the fact would work mechanically but would undermine the exact
    // "written once, never touched again" guarantee this table exists to
    // provide. Instead, the enrichment is its own append-only row, linked
    // back to the original via metadata.source_audit_log_id — the audit
    // trail for one failed OR verification is a short, honest sequence of
    // rows instead of one row that quietly changed shape after the fact.
    //
    // Fired asynchronously (queued job) only when a cashier verification
    // fails with reason NOT_FOUND — never on API_ERROR, since that's the
    // Cashier System's own availability, not a name/OR mismatch worth
    // cross-checking against OGOS/the alumni system.
    public const ACTION_CASHIER_VERIFICATION_ENRICHED = 'cashier_verification_enriched';

    // Unmatched cashier receipt labels — admin resolution (see
    // UnmatchedCashierItem, CashierDocumentSuggester)
    public const ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED  = 'unmatched_cashier_item_resolved';
    public const ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED = 'unmatched_cashier_item_dismissed';

    // Cashier OR override — the scoped, audited alternative to blanking
    // CASHIER_API_KEY system-wide (see the cashier_or_overrides
    // migration's docblock for the full rationale). Three distinct
    // rows, never a mutation of one another, same append-only reasoning
    // as ACTION_CASHIER_VERIFICATION_ENRICHED above:
    //   CREATED  — an admin issued the override (metadata carries the
    //              or_number, target student/alumni, and reason).
    //   CONSUMED — the override was actually spent by a successful
    //              DocumentRequestController::store() call (metadata
    //              carries the resulting request_id).
    //   REVOKED  — an admin cancelled an unused override before it was
    //              ever consumed.
    public const ACTION_CASHIER_OVERRIDE_CREATED  = 'cashier_override_created';
    public const ACTION_CASHIER_OVERRIDE_CONSUMED = 'cashier_override_consumed';
    public const ACTION_CASHIER_OVERRIDE_REVOKED  = 'cashier_override_revoked';

    // -------------------------------------------------------
    // FESPEC-0008 — Free Document/Certificate Request.
    //
    // Every step of the admin-filed free-request flow is logged
    // separately (Phase 7 — Security Hardening: "who searched for which
    // account, who verified whose credentials, who approved"), rather
    // than folded into the existing ACTION_REQUEST_STATUS_CHANGED /
    // ACTION_ADMIN_UPDATED entries a self-service request would produce —
    // this is a fraud-relevant surface (free-of-charge issuance) and
    // needs its own distinctly filterable trail.
    // -------------------------------------------------------
    public const ACTION_FREE_REQUEST_ACCOUNT_SEARCHED = 'free_request_account_searched';
    public const ACTION_FREE_REQUEST_GRADUATE_VERIFIED = 'free_request_graduate_verified';
    public const ACTION_FREE_REQUEST_FILED             = 'free_request_filed';
    // Written whenever FreeRequestEligibilityService returns ineligible
    // and staff holding the 'free_requests','Override' capability file
    // the request anyway — metadata always carries a 'reason' key (see
    // FreeRequestPolicy::override()), never written without one.
    public const ACTION_FREE_REQUEST_ELIGIBILITY_OVERRIDDEN = 'free_request_eligibility_overridden';

    // -------------------------------------------------------
    // Relationship back to the acting user (nullable — may be deleted)
    // -------------------------------------------------------
    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }

    // -------------------------------------------------------
    // Relationship to the user the action was performed ON,
    // e.g. the admin account created/updated/deleted. Distinct
    // from user() — that's always the actor.
    // -------------------------------------------------------
    public function targetUser()
    {
        return $this->belongsTo(SystemUser::class, 'target_user_id', 'user_id');
    }

    // -------------------------------------------------------
    // Append-only enforcement (tamper-evident audit log).
    //
    // audit_logs rows are chained by hash (see AuditLogger::log() and the
    // `audit:verify` command) — a row that could be edited or deleted after
    // the fact would silently break that guarantee no matter how careful
    // the hash-chaining logic is. This is the second, independent layer:
    // even a bug or a rogue direct-Eloquent call elsewhere in the
    // application can never update or delete an audit_logs row, full stop.
    //
    // This is an application-layer guard, not a DB-level one — a
    // sufficiently privileged direct SQL statement could still bypass it.
    // Locking down GRANTs on the audit_logs table at the database level is
    // a complementary, infra-level hardening step outside this model's
    // reach and should be applied in production alongside this.
    // -------------------------------------------------------
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('AuditLog rows are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('AuditLog rows are append-only and cannot be deleted.');
        });
    }
}