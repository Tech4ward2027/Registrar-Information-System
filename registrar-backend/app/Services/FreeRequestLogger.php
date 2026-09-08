<?php

namespace App\Services;

use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FESPEC-0008 — Phase 8 Observability.
 *
 * Structured, ops-facing logging for the free-request flow, written to
 * its own log channel (config/logging.php: 'free_requests').
 *
 * This is deliberately separate from AuditLogger:
 *
 *  - AuditLogger (app/Services/AuditLogger.php) writes tamper-evident,
 *    hash-chained rows to the `audit_logs` table. It exists for
 *    compliance/legal traceability — "who did what, when, and why" —
 *    and is queried through the app itself (AuditLogController).
 *
 *  - FreeRequestLogger writes structured entries to a plain log file.
 *    It exists for operational visibility — tailing, grepping, or
 *    shipping to a log aggregator (ELK/Datadog/CloudWatch) — filterable
 *    independently of the paid/self-service request flow's own logs,
 *    per the Phase 8 plan's requirement.
 *
 * Both get written at the same call sites in FreeRequestController;
 * neither replaces the other, and this class never writes to the
 * database.
 *
 * Deliberately NOT called from FreeRequestService or
 * FreeRequestEligibilityService. FreeRequestEligibilityService is
 * documented (Phase 2) as pure and side-effect-free specifically so it
 * stays trivially unit-testable — introducing a Log:: call there would
 * quietly break that. FreeRequestService's own docblock establishes
 * that audit logging (and now, by the same reasoning, this structured
 * logging) is a controller-layer concern, performed after a service
 * call succeeds/fails, using the live Illuminate\Http\Request. Matching
 * that existing split here rather than introducing a second one.
 */
class FreeRequestLogger
{
    public const ACTION_ACCOUNT_SEARCHED    = 'free_request.account_searched';
    public const ACTION_ELIGIBILITY_CHECKED = 'free_request.eligibility_checked';
    public const ACTION_FILED               = 'free_request.filed';
    public const ACTION_GRADUATE_VERIFIED   = 'free_request.graduate_verified';
    public const ACTION_OVERRIDDEN          = 'free_request.override_applied';
    public const ACTION_REJECTED            = 'free_request.rejected';

    /**
     * Write one structured entry to the 'free_requests' log channel.
     *
     * @param string $action One of the ACTION_* constants above.
     * @param Request $request The live HTTP request, for the same
     *        correlation-id/IP context AuditLogger already captures.
     * @param SystemUser|null $actor The acting admin, if any (null is
     *        valid — e.g. this could be extended to unauthenticated
     *        rejection paths in future without changing the signature).
     * @param array<string, mixed> $context Arbitrary structured fields.
     *        Keep this JSON-serializable and free of raw PII beyond IDs
     *        (no names, no document text) — the same rule this codebase
     *        already applies to AuditLogger payloads.
     */
    public static function log(
        string $action,
        Request $request,
        ?SystemUser $actor,
        array $context = [],
    ): void {
        Log::channel('free_requests')->info($action, array_merge([
            'correlation_id' => $request->header('X-Request-Id') ?? (string) Str::uuid(),
            'actor_id'       => $actor?->user_id,
            'actor_role_id'  => $actor?->role_id,
            'ip'             => $request->ip(),
        ], $context));
    }
}
