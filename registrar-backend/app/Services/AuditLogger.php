<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records user actions to the audit_log table.
 *
 * Registered as a singleton in AppServiceProvider so the same instance
 * is shared across the request lifecycle.  Being an instance class
 * (rather than static) means it can be swapped for a test double:
 *
 *   $this->instance(AuditLogger::class, Mockery::mock(AuditLogger::class));
 *
 * ── Tamper-evident hash chain ──────────────────────────────────────────
 * Every row stores prev_hash (the previous row's hash, or '0' for the
 * very first row ever written) and hash = sha256(prev_hash . '|' .
 * json_encode([action, actor user_id, target_user_id, target_email,
 * created_at])), computed from that row's own final field values.
 *
 * Any later edit to a row (bypassing the model's append-only guard — see
 * AuditLog::booted()) or a gap/reorder in the chain becomes detectable by
 * recomputing every hash from prev_hash forward, which is exactly what
 * the `audit:verify` Artisan command does. This does not itself prevent
 * tampering (a direct SQL UPDATE at the database level can still rewrite
 * both a row and its stored hash) — it makes tampering *evident*, by
 * requiring the tamperer to also correctly recompute every subsequent
 * row's hash to stay consistent, and by giving `audit:verify` something
 * concrete to check against.
 *
 * The insert + "read the previous hash" step below run inside a
 * transaction with a row lock on the last row, so two concurrent log()
 * calls can never both read the same prev_hash and silently fork the
 * chain into two branches with the same parent.
 */
class AuditLogger
{
    // -------------------------------------------------------
    // Log an action for a given user.
    //
    // Usage — inject via constructor, then call:
    //   $this->auditLogger->log($request, $actor, AuditLog::ACTION_LOGIN);
    //
    // $user is always the ACTOR — the authenticated user who performed the
    // action, never the record being acted on. For actions performed ON
    // another user (e.g. an admin being created/updated/deleted), pass
    // that user's identity via $metadata using the 'target_user_id' /
    // 'target_email' keys — they're lifted into dedicated indexed columns
    // automatically so the target can be queried/joined on directly. Any
    // other keys are kept as-is in the metadata JSON column.
    //
    //   $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_ADMIN_CREATED, [
    //       'target_user_id' => $newAdmin->user_id,
    //       'target_email'   => $newAdmin->email,
    //   ]);
    //
    // The Request is needed to extract browser + IP address.
    // -------------------------------------------------------
    public function log(
        Request    $request,
        SystemUser $user,
        string     $action,
        array      $metadata = []
    ): AuditLog {
        return $this->writeEntry(
            user:       $user,
            action:     $action,
            metadata:   $metadata,
            browser:    $this->parseBrowser($request->userAgent()),
            ipAddress:  $request->ip(),
        );
    }

    /**
     * Same tamper-evident write path as log(), for callers with no live
     * HTTP Request to read IP/user-agent from — namely queued jobs
     * (e.g. EnrichCashierFailureJob, dispatched from a controller action
     * but executed later on a worker, well outside that request's
     * lifecycle).
     *
     * Deliberately NOT a second, parallel hash-chain implementation:
     * both this and log() delegate to the same writeEntry() below, so
     * there is exactly one place that ever computes a chained hash or
     * inserts into audit_logs. A caller dispatching a job should capture
     * $request->ip() / $request->userAgent() at dispatch time (while the
     * request is still live) and pass the raw strings through the job's
     * constructor — see EnrichCashierFailureJob — rather than attempting
     * to serialize/reuse the Request object itself.
     *
     * $userAgent is the RAW user-agent string (not yet parsed) so the
     * browser label is derived identically to the live-request path.
     */
    public function logForSystem(
        SystemUser $user,
        string     $action,
        array      $metadata = [],
        ?string    $ipAddress = null,
        ?string    $userAgent = null,
    ): AuditLog {
        return $this->writeEntry(
            user:      $user,
            action:    $action,
            metadata:  $metadata,
            browser:   $this->parseBrowser($userAgent),
            ipAddress: $ipAddress,
        );
    }

    /**
     * Single source of truth for writing a chained audit_logs row —
     * both log() (live Request) and logForSystem() (queued jobs) funnel
     * through here so the hash-chain algorithm, the row-lock-then-insert
     * transaction, and the target_user_id/target_email extraction can
     * never drift between the two entry points.
     */
    private function writeEntry(
        SystemUser $user,
        string     $action,
        array      $metadata,
        ?string    $browser,
        ?string    $ipAddress,
    ): AuditLog {
        $targetUserId = $metadata['target_user_id'] ?? null;
        $targetEmail  = $metadata['target_email'] ?? null;
        unset($metadata['target_user_id'], $metadata['target_email']);

        $createdAt = now();

        return DB::transaction(function () use (
            $user, $action, $metadata, $targetUserId, $targetEmail, $createdAt, $browser, $ipAddress
        ) {
            $prevHash = $this->lockAndFetchLastHash();

            $hash = $this->computeHash($prevHash, [
                'action'         => $action,
                'user_id'        => $user->user_id,
                'target_user_id' => $targetUserId,
                'target_email'   => $targetEmail,
                'created_at'     => (string) $createdAt,
            ]);

            return AuditLog::create([
                'user_id'        => $user->user_id,
                'email'          => $user->email,
                'role_name'      => $this->resolveRoleName($user->role_id),
                'target_user_id' => $targetUserId,
                'target_email'   => $targetEmail,
                'action'         => $action,
                'browser'        => $browser,
                'ip_address'     => $ipAddress,
                'metadata'       => !empty($metadata) ? $metadata : null,
                'prev_hash'      => $prevHash,
                'hash'           => $hash,
                'created_at'     => $createdAt,
            ]);
        });
    }

    /**
     * Compute a chained hash the exact same way for every caller — the
     * live insert path above and the one-time backfill in the
     * add_hash_chain_to_audit_logs migration both must produce identical
     * output for identical input, or `audit:verify` would flag every
     * pre-migration row as broken. Keep this the single source of truth
     * for the algorithm; if it ever changes, the migration's backfill
     * copy must change with it.
     */
    public function computeHash(string $prevHash, array $payload): string
    {
        return hash('sha256', $prevHash . '|' . json_encode($payload));
    }

    /**
     * Read the most recently written row's hash, taking a row lock on it
     * so a concurrent log() call blocks until this transaction commits
     * instead of both reading the same prev_hash and forking the chain.
     *
     * '0' — the documented genesis value — when the table is empty.
     */
    private function lockAndFetchLastHash(): string
    {
        $last = AuditLog::orderByDesc('id')->lockForUpdate()->first(['id', 'hash']);

        return $last->hash ?? '0';
    }

    // -------------------------------------------------------
    // Resolve role_id to a human-readable name.
    // Mirrors UserResource::resolveRoleName() intentionally —
    // audit logs should use the same labels the UI shows.
    // -------------------------------------------------------
    private function resolveRoleName(int $roleId): string
    {
        return match ($roleId) {
            SystemUser::ROLE_STUDENT             => 'student',
            SystemUser::ROLE_ALUMNI              => 'alumni',
            SystemUser::ROLE_ADMIN               => 'admin',
            SystemUser::ROLE_SUPER_ADMIN         => 'super_admin',
            // Undergrad Requestor Registration — Phase 1 (D1).
            SystemUser::ROLE_UNDERGRAD_REQUESTOR => 'undergrad_requestor',
            default                              => 'unknown',
        };
    }

    // -------------------------------------------------------
    // Parse a readable browser name from the User-Agent string.
    // Returns e.g. "Chrome", "Safari", "Firefox", "Edge",
    // "Mobile Safari", or the raw agent if unrecognised.
    // -------------------------------------------------------
    private function parseBrowser(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Edg')     => 'Edge',
            str_contains($userAgent, 'OPR')     => 'Opera',
            str_contains($userAgent, 'Chrome')  => 'Chrome',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Safari') &&
            str_contains($userAgent, 'Mobile')  => 'Mobile Safari',
            str_contains($userAgent, 'Safari')  => 'Safari',
            default                             => substr($userAgent, 0, 100),
        };
    }
}