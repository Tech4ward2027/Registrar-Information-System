<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SecurityEvent
 * =============
 * RIS-only security/debug signal — failed local-auth login attempts and
 * IDP-unreachable fallback events. See the create_security_events_table
 * migration's docblock for why this is a separate table from AuditLog
 * rather than new action constants on it.
 *
 * Unlike AuditLog, this table is NOT hash-chained — it's operational
 * signal for the RIS team, not a tamper-evident compliance record, and is
 * expected to be pruned on a retention schedule (see PruneSecurityEvents)
 * rather than kept forever.
 *
 * ── Phase 6 (Undergrad Requestor Registration) ────────────────────────
 * Three event types are added below for the feature's public,
 * unauthenticated surface. The dividing line between this table and
 * audit_logs is unchanged and worth restating, because the new events sit
 * right on it:
 *
 *   audit_logs      STATE CHANGES. Something in the database is now
 *                   different, and a human or a job is answerable for it:
 *                   submitted, email-verified, approved, rejected,
 *                   expired, purged, activated.
 *
 *   security_events AUTH-RELEVANT ATTEMPTS, especially ones that CHANGED
 *                   NOTHING. A denied SSO login, a wrong confirmation
 *                   token, a tripped rate limit — none of these move the
 *                   system's state, so none of them belong in a
 *                   hash-chained ledger of state changes. They are still
 *                   exactly what you want in front of you when asking
 *                   "is someone probing this endpoint?"
 *
 * Together the two tables give Phase 6's "no silent gaps" requirement a
 * literal answer: every transition in this feature's lifecycle lands in
 * one or the other.
 */
class SecurityEvent extends Model
{
    protected $primaryKey = 'security_event_id';

    // Write-once — no updated_at column exists on this table.
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'reason',
        'email',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'metadata'   => 'array',
    ];

    // -------------------------------------------------------
    // event_type constants — single source of truth, same pattern as
    // AuditLog's ACTION_* constants, so a typo never silently creates
    // an unqueryable event_type value.
    //
    // Values stay well inside the column's varchar(50).
    // -------------------------------------------------------
    public const EVENT_TYPE_LOGIN_FAILED    = 'login_failed';
    public const EVENT_TYPE_IDP_UNREACHABLE = 'idp_unreachable';

    /**
     * Phase 6 — an SSO login that RIS refused after the IdP had already
     * authenticated the person successfully. Previously these were
     * Log::warning() lines only: readable by whoever thinks to grep
     * storage/logs, invisible to everything else, and gone whenever the
     * container is recreated. A refused authentication is precisely the
     * kind of thing that should be queryable.
     */
    public const EVENT_TYPE_SSO_LOGIN_DENIED = 'sso_login_denied';

    /**
     * Phase 6 — a failed attempt to confirm an onboarding email address.
     * The response to the caller is deliberately vague (it has to be, or
     * the endpoint enumerates accounts); the specific cause lives here.
     */
    public const EVENT_TYPE_ONBOARDING_CONFIRM_FAILED = 'onboarding_confirm_failed';

    /**
     * Phase 6 — a request rejected by one of the public onboarding
     * endpoints' rate limiters. Deduplicated before insert (see
     * SecurityEventLogger::recordOnboardingThrottled()) so that a flood
     * cannot turn our own defence into a write amplifier.
     */
    public const EVENT_TYPE_ONBOARDING_THROTTLED = 'onboarding_throttled';

    // -------------------------------------------------------
    // reason constants — subtypes of EVENT_TYPE_LOGIN_FAILED.
    // Mirrors the distinct \RuntimeException branches inside
    // LocalAuthService::attempt().
    // -------------------------------------------------------
    public const REASON_USER_NOT_FOUND      = 'user_not_found';
    public const REASON_LOCAL_AUTH_DISABLED = 'local_auth_disabled';
    public const REASON_BAD_PASSWORD        = 'bad_password';
    public const REASON_INACTIVE_ACCOUNT    = 'inactive_account';

    // -------------------------------------------------------
    // reason constants — subtypes of EVENT_TYPE_SSO_LOGIN_DENIED.
    // One per exception UserProvisioningService can throw, so the table
    // can be filtered by cause without parsing a message string.
    // -------------------------------------------------------
    public const REASON_UNREGISTERED_ACCOUNT = 'unregistered_account';
    public const REASON_ACCOUNT_DEACTIVATED  = 'account_deactivated';
    public const REASON_ACCOUNT_EXPIRED      = 'account_expired';
    public const REASON_ACCOUNT_REJECTED     = 'account_rejected';

    // -------------------------------------------------------
    // reason constants — subtypes of
    // EVENT_TYPE_ONBOARDING_CONFIRM_FAILED.
    // -------------------------------------------------------
    public const REASON_UNKNOWN_ACCOUNT  = 'unknown_account';
    public const REASON_TOKEN_INVALID    = 'token_invalid';
    public const REASON_TOKEN_EXPIRED    = 'token_expired';
    public const REASON_ALREADY_VERIFIED = 'already_verified';

    // -------------------------------------------------------
    // reason constants — subtypes of EVENT_TYPE_ONBOARDING_THROTTLED,
    // naming which public endpoint was being hammered.
    // -------------------------------------------------------
    public const REASON_THROTTLED_REGISTER = 'register';
    public const REASON_THROTTLED_CONFIRM  = 'confirm_email';

    // -------------------------------------------------------
    // Write-once enforcement.
    //
    // Mirrors AuditLog::booted() exactly — see that model's docblock for
    // the full reasoning. The one deliberate difference: retention
    // pruning (PruneSecurityEvents) deletes rows via a query-builder mass
    // delete (SecurityEvent::where(...)->delete()), which Eloquent does
    // NOT route through individual model events — so the guard below
    // only ever blocks a single-row $model->delete()/->update() call,
    // which is exactly the "deletion allowed only via retention job"
    // rule this class is supposed to enforce. No bypass flag needed.
    // -------------------------------------------------------
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('SecurityEvent rows are write-once and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException(
                'SecurityEvent rows cannot be deleted individually. '
                . 'Use the retention job (PruneSecurityEvents) or a direct mass-delete query.'
            );
        });
    }
}