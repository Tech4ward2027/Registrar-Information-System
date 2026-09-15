<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Enums\UndergradRequestorVerificationStatusEnum;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| PurgeRejectedUndergradRequestorPii
| (php artisan undergrad-requestors:purge-rejected-pii)
|--------------------------------------------------------------------------
| D9's second retention sweep. Disposes of the self-declared personal
| data behind a REJECTED Undergrad Requestor submission once it has aged
| past config('undergrad_requestor.rejected_retention_days') — 90 days,
| the same window SECURITY_EVENTS_RETENTION_DAYS already established as
| this codebase's standard, rather than a new number with no precedent.
|
| ── What is disposed of, and what is deliberately kept ────────────────
|
| DELETED  undergrad_requestor_profiles row. It is pure self-declared
|          PII — name, student number, date of birth, home address,
|          phone, reason for non-enrollment — with no residual value once
|          the application has been refused and the appeal window has
|          long closed.
|
| CLEARED  undergrad_requestor_verifications.rejection_reason. Free text
|          authored by staff; it can and often will name specifics about
|          a person. The reason itself survives permanently in the
|          audit_logs entry written at decision time.
|
| KEPT     The verification row's status/reviewed_by/reviewed_at, plus a
|          new pii_purged_at stamp. This is what lets the Registrar
|          answer "was this person refused, by whom, when, and was their
|          data disposed of on schedule" without retaining the data
|          itself — the disposal half of the Data Privacy Act story.
|
| KEPT     audit_logs, entirely and always. It is the permanent,
|          tamper-evident record that a rejection occurred; a retention
|          job that could edit it would defeat the hash chain's entire
|          purpose. This command writes TO the audit log and never
|          deletes from it.
|
| PSEUDONYMIZED  users.email. The row itself is kept (the verification
|          record FKs to it, and its cascadeOnDelete would take the
|          verification record with it). The address is replaced with a
|          non-routable placeholder, which has a second, deliberate
|          effect: it frees the person's real email address, so someone
|          refused 90+ days ago can submit a fresh application rather
|          than being permanently locked out by a unique-constraint
|          collision with their own purged record.
|
| ── Idempotency & safety ──────────────────────────────────────────────
| Keyed off pii_purged_at IS NULL, so re-running is a no-op and a run
| interrupted halfway resumes cleanly. Chunked, with each record's purge
| in its own transaction: one malformed row can never take a whole
| night's sweep down with it, and a partial purge (profile deleted, email
| still live) is impossible.
|
| Logged to job_run_logs via LogsJobRun, same as every other scheduled
| command here, so the SuperAdmin "Scheduled Jobs Health" panel can prove
| the retention policy is actually running — an unmonitored retention job
| is a compliance claim nobody can evidence.
|--------------------------------------------------------------------------
*/
class PurgeRejectedUndergradRequestorPii extends Command
{
    use LogsJobRun;

    protected $signature = 'undergrad-requestors:purge-rejected-pii
                            {--dry-run : Report what would be purged without writing anything}';

    protected $description = 'Purge self-declared PII from Undergrad Requestor submissions rejected past the retention window (D9)';

    public function handle(AuditLogger $auditLogger): int
    {
        $this->startJobRun($this->getName());

        try {
            $retentionDays = (int) config('undergrad_requestor.rejected_retention_days', 90);
            $chunkSize     = (int) config('undergrad_requestor.purge_chunk_size', 200);
            $cutoff        = now()->subDays($retentionDays);
            $dryRun        = (bool) $this->option('dry-run');

            $purged = 0;

            UndergradRequestorVerification::query()
                ->where('status', UndergradRequestorVerificationStatusEnum::Rejected->value)
                ->whereNull('pii_purged_at')
                // reviewed_at, not created_at: the retention clock starts
                // when the Registrar made the decision, not when the
                // person applied. A submission that sat in the queue for
                // two months should still get its full 90 days after
                // refusal.
                ->whereNotNull('reviewed_at')
                ->where('reviewed_at', '<', $cutoff)
                ->orderBy('undergrad_requestor_verification_id')
                ->chunkById($chunkSize, function ($verifications) use (&$purged, $auditLogger, $dryRun) {
                    foreach ($verifications as $verification) {
                        if ($dryRun) {
                            $this->line("  would purge verification #{$verification->undergrad_requestor_verification_id} (user #{$verification->user_id})");
                            $purged++;
                            continue;
                        }

                        if ($this->purgeOne($verification, $auditLogger)) {
                            $purged++;
                        }
                    }
                }, 'undergrad_requestor_verification_id');

            $verb = $dryRun ? 'would purge' : 'purged';
            $this->info("undergrad-requestors:purge-rejected-pii — {$verb} {$purged} rejected record(s) older than {$retentionDays} day(s).");

            $this->finishJobRun(self::SUCCESS, $purged);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }

    /**
     * Purge a single record, atomically. Returns false (without
     * throwing) if the record could not be purged, so one bad row never
     * aborts the sweep — the row simply stays eligible and is retried on
     * the next run, which is the correct failure mode for a retention
     * job: under-deleting is recoverable, over-deleting is not.
     */
    private function purgeOne(UndergradRequestorVerification $verification, AuditLogger $auditLogger): bool
    {
        try {
            DB::transaction(function () use ($verification, $auditLogger) {
                /** @var SystemUser|null $user */
                $user = SystemUser::find($verification->user_id);

                // Captured BEFORE the write, for the audit entry — this
                // is the last moment the real address exists in the
                // operational tables. audit_logs is permanent by design
                // and is the intended final resting place for it.
                $originalEmail = $user?->email;

                UndergradRequestorProfile::where('user_id', $verification->user_id)->delete();

                if ($user) {
                    // A syntactically valid address on a reserved TLD
                    // (RFC 2606 .invalid) — guaranteed never to route to
                    // a real mailbox, while still satisfying the column's
                    // uniqueness and any downstream format expectation.
                    // Keyed on user_id so two purged rows can never
                    // collide with each other.
                    $user->forceFill([
                        'email' => "purged-undergrad-requestor-{$user->user_id}@purged.invalid",
                    ])->save();
                }

                $verification->forceFill([
                    'rejection_reason' => null,
                    'pii_purged_at'    => now(),
                ])->save();

                // System action with no human actor and no HTTP request —
                // logForSystem() is the entry point AuditLogger provides
                // for exactly this case (see its docblock), and it shares
                // the same hash chain as every interactive write, so a
                // retention action is as tamper-evident as a decision.
                // The purged account is passed as the nominal actor, the
                // same attribution convention ExpireStaleProvisioning
                // uses for its own sweep.
                if ($user) {
                    $auditLogger->logForSystem(
                        user:     $user,
                        action:   AuditLog::ACTION_UNDERGRAD_REQUESTOR_PII_PURGED,
                        metadata: [
                            'target_user_id' => $user->user_id,
                            'target_email'   => $originalEmail,
                            'undergrad_requestor_verification_id' => $verification->undergrad_requestor_verification_id,
                            'retention_days' => (int) config('undergrad_requestor.rejected_retention_days', 90),
                            'rejected_at'    => $verification->reviewed_at?->toIso8601String(),
                        ],
                    );
                }
            });

            return true;
        } catch (\Throwable $e) {
            // Warning, not error: the sweep is designed to tolerate this
            // and retry tomorrow. An error-level log here would page
            // someone for a condition that self-heals.
            Log::warning('[PurgeRejectedUndergradRequestorPii] record skipped', [
                'undergrad_requestor_verification_id' => $verification->undergrad_requestor_verification_id,
                'reason' => $e->getMessage(),
            ]);

            $this->warn("  skipped verification #{$verification->undergrad_requestor_verification_id}: {$e->getMessage()}");

            return false;
        }
    }
}
