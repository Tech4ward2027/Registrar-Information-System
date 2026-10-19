<?php

namespace App\Jobs;

use App\Contracts\AlumniSystemClientInterface;
use App\Exceptions\OgosException;
use App\Models\AuditLog;
use App\Models\FailureReasonCode;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\CashierFailureDiagnosisService;
use App\Services\Ogos\OgosStudentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/*
|--------------------------------------------------------------------------
| EnrichCashierFailureJob (Phase 4 — Cashier Verification Failure Diagnostics)
|--------------------------------------------------------------------------
| Dispatched from DocumentRequestController::verifyReceiptAgainstCashier()
| when a Cashier OR-verification attempt fails with reason NOT_FOUND (never
| on API_ERROR — that's the Cashier System's own availability, not a
| name/OR mismatch). Not dispatched inline in the request/response path: a
| student's failed submission is never delayed by an extra third-party call
| made purely for the registrar's later benefit — this runs on the queue
| worker (ShouldQueue, database driver — see config/queue.php), mirroring
| the existing SendBulkNotificationJob pattern.
|
| What it does: pulls a live snapshot of what OGOS (students) or the
| alumni system (alumni) currently has on file for the person who
| attempted the OR, and writes it as a NEW, separate audit_logs row —
| never a mutation of the original cashier_verification entry. See
| AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED's docblock for why an
| in-place metadata update was deliberately rejected in favor of a
| second, linked row (audit_logs is hash-chained and append-only by
| design — AuditLog::booted() enforces this at the model layer).
|
| Surfaced as raw data (on-file name vs. each candidate RIS tried) — not
| an auto-generated "fault" verdict, since name matching is inherently
| fuzzy (see NameMatcher's class docblock). By the time a registrar looks
| at this, OGOS/the alumni system's record may have already changed
| again; this snapshot is a point-in-time reference, not a live link.
|--------------------------------------------------------------------------
*/
class EnrichCashierFailureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounded retry (Phase 4c): 3 attempts is enough to ride out a brief
     * OGOS blip without hammering it, and short of that, further retries
     * won't help — an enrichment_status of 'failed' (written in failed()
     * below) makes a stuck/exhausted enrichment visibly incomplete rather
     * than silently assumed done.
     */
    public int $tries = 3;

    /** Backoff in seconds between attempts — spaced out, not hammering a possibly-down OGOS. */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly int     $sourceAuditLogId,
        public readonly int     $actorUserId,
        public readonly string  $orNumber,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}

    public function handle(
        AuditLogger                 $auditLogger,
        OgosStudentService          $ogosStudentService,
        AlumniSystemClientInterface $alumniSystemClient,
        CashierFailureDiagnosisService $diagnosis,
    ): void {
        $actor = SystemUser::find($this->actorUserId);

        // Actor account no longer exists (e.g. deleted between the failed
        // OR attempt and this job running) — nothing meaningful to write.
        // Not an error condition, so don't retry or mark failed.
        if (!$actor) {
            return;
        }

        [$snapshot, $sourceSystem, $status, $failureReason] = $this->fetchSnapshot(
            $actor,
            $ogosStudentService,
            $alumniSystemClient,
        );

        $auditLogger->logForSystem(
            user:      $actor,
            action:    AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED,
            metadata:  [
                'source_audit_log_id' => $this->sourceAuditLogId,
                'or_number'           => $this->orNumber,
                'source_system'       => $sourceSystem,
                'on_file_snapshot'    => $snapshot,
                'enrichment_status'   => $status,
                'failure_reason'      => $failureReason,
                // System Health Phase 2b — "likely contributing factors",
                // never a fault verdict (the Cashier API does not return
                // the on-file name). Appended to THIS row at creation;
                // the original cashier_verification row and the
                // append-only / hash-chain guarantees are untouched.
                'diagnosis_codes'     => $this->diagnosisCodes($diagnosis, $actor, $snapshot, $failureReason),
            ],
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
        );
    }

    /**
     * Best-effort: a bug or catalog problem here must never stop the
     * enrichment row (the snapshot the registrar actually needs) from
     * being written, so any failure degrades to "no codes".
     *
     * @return array<int, string>
     */
    private function diagnosisCodes(
        CashierFailureDiagnosisService $diagnosis,
        SystemUser $actor,
        ?array $snapshot,
        ?string $failureReason,
    ): array {
        try {
            $profile = $actor->studentProfile ?? $actor->alumniProfile ?? $actor->undergradRequestorProfile ?? null;

            $attempts = AuditLog::find($this->sourceAuditLogId)?->metadata['attempts'] ?? [];

            $codes = $diagnosis->diagnose(
                localProfile:        $profile ? $profile->only(['first_name', 'middle_name', 'last_name', 'suffix']) : [],
                snapshot:            $snapshot,
                sourceFailureReason: $failureReason,
                attempts:            is_array($attempts) ? $attempts : [],
            );

            // Operators can switch a noisy code off in the catalog.
            return array_values(array_diff($codes, FailureReasonCode::inactiveCodes()));
        } catch (Throwable $e) {
            Log::warning('EnrichCashierFailureJob: diagnosis failed; continuing without codes', [
                'source_audit_log_id' => $this->sourceAuditLogId,
                'message'             => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{0: ?array, 1: ?string, 2: string, 3: ?string}
     *   [snapshot, source_system, enrichment_status, failure_reason]
     *   enrichment_status is one of: 'complete' | 'not_found' | 'failed'
     */
    private function fetchSnapshot(
        SystemUser                  $actor,
        OgosStudentService          $ogosStudentService,
        AlumniSystemClientInterface $alumniSystemClient,
    ): array {
        // Student path — OGOS is the source of truth (same system
        // OgosStudentService::provisionStudentData() syncs profile data
        // from on every login).
        if ($actor->studentProfile) {
            try {
                $student = $ogosStudentService->getClient()->getStudentByEmail($actor->email);

                return [
                    [
                        'first_name'     => $student->firstName,
                        'middle_name'    => $student->middleName,
                        'last_name'      => $student->lastName,
                        'suffix'         => $student->suffix,
                        'student_number' => $student->studentNumber,
                    ],
                    'ogos',
                    'complete',
                    null,
                ];
            } catch (OgosException $e) {
                // A clean 404 is a stable, informative answer (OGOS has no
                // record for this email) — not worth retrying 3x. Anything
                // else (5xx, connection error, 401 M2M failure) is
                // transient, so rethrow and let tries/backoff handle it.
                if ($e->getCode() === 404) {
                    return [null, 'ogos', 'not_found', CashierFailureDiagnosisService::SOURCE_FAILURE_OGOS_NOT_FOUND];
                }

                Log::warning('EnrichCashierFailureJob: OGOS lookup failed, will retry', [
                    'source_audit_log_id' => $this->sourceAuditLogId,
                    'message'              => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        // Alumni path — PUPTAPS (via AlumniSystemClientInterface) is the
        // source of truth. This contract is documented to never throw —
        // it returns null on both "not found" and "system unavailable",
        // so there is nothing here for the queue's retry/backoff to act
        // on; a null result is written as 'failed' directly rather than
        // being retried.
        if ($actor->alumniProfile) {
            $alumni = $alumniSystemClient->tryLookupAlumniByEmail($actor->email);

            if ($alumni === null) {
                return [null, 'alumni_system', 'failed', CashierFailureDiagnosisService::SOURCE_FAILURE_ALUMNI];
            }

            return [
                [
                    'first_name'  => $alumni->firstName,
                    'middle_name' => $alumni->middleName,
                    'last_name'   => $alumni->lastName,
                    'suffix'      => $alumni->suffix,
                    'stud_number' => $alumni->studNumber,
                ],
                'alumni_system',
                'complete',
                null,
            ];
        }

        // Defensive fallback — the controller only dispatches this job
        // after confirming a profile exists on the OR-verification path,
        // so this should be unreachable in practice.
        return [null, null, 'failed', CashierFailureDiagnosisService::SOURCE_FAILURE_NO_PROFILE];
    }

    /**
     * All 3 attempts exhausted on a genuinely transient error (OGOS
     * unreachable for the whole backoff window). Write the 'failed'
     * state explicitly so this shows up in the audit trail as visibly
     * incomplete instead of just vanishing — see Phase 4c.
     */
    public function failed(Throwable $exception): void
    {
        $actor = SystemUser::find($this->actorUserId);

        if (!$actor) {
            return;
        }

        app(AuditLogger::class)->logForSystem(
            user:      $actor,
            action:    AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED,
            metadata:  [
                'source_audit_log_id' => $this->sourceAuditLogId,
                'or_number'           => $this->orNumber,
                'source_system'       => $actor->studentProfile ? 'ogos' : 'alumni_system',
                'on_file_snapshot'    => null,
                'enrichment_status'   => 'failed',
                'failure_reason'      => CashierFailureDiagnosisService::SOURCE_FAILURE_OGOS_UNREACHABLE,
                'diagnosis_codes'     => $this->diagnosisCodes(
                    app(CashierFailureDiagnosisService::class),
                    $actor,
                    null,
                    CashierFailureDiagnosisService::SOURCE_FAILURE_OGOS_UNREACHABLE,
                ),
            ],
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
        );
    }
}