<?php

namespace App\Enums;

enum RequestStatusEnum: int
{
    case Processing  = 1;
    case ReadyToClaim = 2;
    case Completed   = 3;
    case Forfeited   = 4;

    /**
     * @deprecated Cancelled is being retired as a status going forward.
     * The case is kept (rather than removed) because RequestStatusEnum::from()
     * is called on status_id values read from existing document_request rows
     * (e.g. in notifyOwnerOfStatusChange()) — removing the case would throw a
     * ValueError the first time the app reads a historical row that was
     * already cancelled before this change. allowedTransitions() below no
     * longer permits transitioning INTO Cancelled, so no new row can reach
     * this status; it now only exists to keep old data readable.
     */
    case Cancelled   = 5;

    /**
     * A request whose registrar-side work is done but which cannot move to
     * ReadyToClaim yet because it is waiting on a signature from an office
     * outside the Registrar's control (department head, dean, etc.).
     *
     * status_id = 6 is intentionally reused from a prior, abandoned attempt
     * at a "Pending" status — see the pre-flight check and full history in
     * migration 2026_08_15_000000_add_pending_signature_status and the
     * matching comment in DatabaseSeeder::seedRequestStatus(). This one is
     * named "Pending Signature" (not "Pending"), which does not collide
     * with the frontend's exact-match "pending" lookup.
     *
     * Introduced so the registrar's own SLA clock can stop the moment
     * their part is done, instead of either (a) keeping "Processing"
     * active while waiting on someone else — which unfairly counts
     * against the registrar in performance reports — or (b) marking it
     * "Ready to Claim" early, which tells the user to come pick up a
     * document that isn't actually ready yet. See
     * DocumentRequestService::recordStatusHistory() and
     * BusinessCalendarService for how the two SLA clocks (registrar time
     * vs. signature-office time) are measured separately once a request
     * passes through this status.
     */
    case PendingSignature = 6;

    /**
     * A request that cannot begin registrar processing yet because the
     * client must first hand over a physical source document — the CTC /
     * "Authentication Fee" case (see document_type.requires_source_
     * submission and the 2026_08_29 logbook_category/CTC reconciliation
     * migrations). Unlike PendingSignature, this is not a mid-processing
     * wait: it is the request's STARTING status, assigned at creation
     * time by DocumentRequestService::createRequest() whenever any
     * requested document/certificate type has requires_source_submission
     * = true, instead of the usual Processing.
     *
     * status_id = 12 — the next free id after Draft (11); see
     * migration 2026_08_29_000004_add_awaiting_submission_status and the
     * matching comment in DatabaseSeeder::seedRequestStatus() for the
     * same "don't collide with an exact-match lookup" caution that
     * governed PendingSignature's id choice. "Awaiting Submission"
     * lowercases to "awaiting submission", which is not "pending" and
     * does not collide with the frontend's exact-match "pending" lookup
     * (see the long-form note on that landmine in
     * DatabaseSeeder::seedRequestStatus()).
     *
     * The registrar's SLA clock does not start until staff confirm the
     * source document has actually been received and move the request to
     * Processing — the same "stop the clock while waiting on someone
     * outside the Registrar's control" reasoning as PendingSignature,
     * just applied at the front of the workflow instead of the middle.
     */
    case AwaitingSubmission = 12;

    /**
     * A terminal status for a request that will never be fulfilled —
     * distinct from Forfeited (which means "was ready, never claimed in
     * time") and from the deprecated Cancelled (which had no reason,
     * no audit trail, and could be entered from anywhere). Withdrawn is
     * staff-mediated only: it always carries a required
     * document_request.withdrawal_reason (see WithdrawalReasonEnum) and
     * optionally a superseded_by_request_id pointing at whichever
     * request actually proceeds when this one is being closed out
     * because it was a mistake or a duplicate.
     *
     * status_id = 13 — the next free id after AwaitingSubmission (12).
     * Confirmed free against the production dump per the Phase 0
     * pre-flight check (see app/Console/Commands/
     * PreflightCheckWithdrawnStatus.php and migration
     * 2026_09_05_000000_add_withdrawn_status). "Withdrawn" lowercases to
     * "withdrawn", which is not "pending" and does not collide with the
     * frontend's exact-match "pending" lookup (staffDashboardUtils.js) —
     * same non-collision check every prior status addition in this file
     * has documented.
     *
     * Deliberately reachable from AwaitingSubmission, Processing, AND
     * PendingSignature — a request can turn out to be a mistake at any
     * point before it's actually ready for pickup. NOT reachable from
     * ReadyToClaim: once a document is physically ready to hand over,
     * closing the request out goes through the existing claim/forfeit
     * resolution instead (see DocumentRequestService::claimRequest() and
     * ShredExpiredRequests), not Withdrawn — see
     * DocumentRequestService::withdraw() for the enforcement of this.
     */
    case Withdrawn = 13;

    /**
     * A terminal status for a request whose open Deficiency Notice can
     * never be complied with because the requestor is deceased or
     * otherwise permanently unable to respond — see the Data Retention
     * & Disposal Policy §3.4 ("Requests That Can Never Be Resolved").
     * Distinct from Withdrawn: Withdrawn is an ordinary administrative
     * correction (wrong item paid, duplicate, no longer needed) that
     * any staff member can invoke without proof of anything beyond
     * their own judgment call. ClosedUnableToProcess is the policy's
     * "worst-case scenario" outcome — it always carries a required
     * document_request.closure_reason (see ClosureReasonEnum) AND a
     * required closure_proof_reference describing the proof the
     * Registrar Admin verified (e.g. a death certificate) before
     * closing the case — see DocumentRequestService::
     * closeUnableToProcess() and CloseRequestUnableToProcessRequest.
     *
     * status_id = 14 — the next free id after Withdrawn (13) at the
     * time this was added; CONFIRM AGAINST THE PRODUCTION DUMP before
     * deploying (see the add_closed_unable_to_process_status migration
     * docblock for the exact queries to run) — same caution every
     * status addition in this file has required. "Closed - Unable to
     * Process" lowercases to "closed - unable to process", which is
     * not "pending" and does not collide with the frontend's
     * exact-match "pending" lookup (staffDashboardUtils.js).
     *
     * Reachable from the same set of statuses as Withdrawn
     * (AwaitingSubmission, Processing, PendingSignature) and NOT from
     * ReadyToClaim, for the identical reasoning documented on Withdrawn
     * above. Additionally guarded at the service layer (not just here)
     * to require that the request currently has an OPEN Deficiency
     * Notice — this status only makes sense as the resolution of an
     * unresolvable notice, never as a closure invoked out of nowhere.
     */
    case ClosedUnableToProcess = 14;

    /**
     * Returns the set of statuses that this status may legally transition to.
     * Used by DocumentRequestService::updateRequest() to reject illegal moves.
     *
     * Transition map:
     *   AwaitingSubmission → Processing  | Withdrawn
     *   Processing         → ReadyToClaim | PendingSignature | Withdrawn
     *   PendingSignature   → ReadyToClaim | Withdrawn
     *   ReadyToClaim       → Completed    | Forfeited
     *   Completed          → (terminal)
     *   Forfeited          → (terminal)
     *   Withdrawn          → (terminal — staff-mediated only, see
     *                         DocumentRequestService::withdraw())
     *   ClosedUnableToProcess → (terminal — staff-mediated only, see
     *                         DocumentRequestService::closeUnableToProcess())
     *   Cancelled          → (terminal, and unreachable from any other status — see
     *                         the @deprecated note on the Cancelled case above)
     *
     * Note: the automated shredder (ShredExpiredRequests) transitions
     * ReadyToClaim → Forfeited by writing directly to the DB, so it
     * bypasses this guard intentionally.
     *
     * PendingSignature is reachable only from Processing, and can only
     * ever move forward to ReadyToClaim or Withdrawn — a document either
     * comes back signed (→ ReadyToClaim), the request turns out to be a
     * mistake (→ Withdrawn), or it doesn't yet, in which case it simply
     * stays in PendingSignature. There is deliberately no PendingSignature
     * → Processing "undo": if staff need to correct a mistaken transition,
     * that's a data-correction operation, not a normal workflow move, and
     * should not be modeled as one more state the SLA clock has to reason
     * about.
     *
     * AwaitingSubmission follows the same one-way principle: it is only
     * ever the request's initial status (never entered via updateRequest())
     * and can only move forward to Processing (once staff confirm the
     * source document is in hand) or Withdrawn. There is no
     * AwaitingSubmission ← Processing "undo" for the same reason
     * PendingSignature has none.
     *
     * Withdrawn is deliberately NOT reachable from ReadyToClaim: once a
     * document is physically ready for pickup, closing the request out
     * goes through the existing claim/forfeit resolution instead — see
     * DocumentRequestService::withdraw(), which enforces this same rule
     * as an explicit guard (defense in depth, since allowedTransitions()
     * already makes ReadyToClaim → Withdrawn structurally impossible to
     * reach via this array alone).
     *
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AwaitingSubmission   => [self::Processing, self::Withdrawn, self::ClosedUnableToProcess],
            self::Processing           => [self::ReadyToClaim, self::PendingSignature, self::Withdrawn, self::ClosedUnableToProcess],
            self::PendingSignature     => [self::ReadyToClaim, self::Withdrawn, self::ClosedUnableToProcess],
            self::ReadyToClaim         => [self::Completed, self::Forfeited],
            self::Completed            => [],
            self::Forfeited            => [],
            self::Withdrawn            => [],
            self::ClosedUnableToProcess => [],
            self::Cancelled            => [],
        };
    }

    /** Notification trigger slug for each terminal/transitional status. */
    public function notificationTrigger(): ?string
    {
        return match ($this) {
            self::AwaitingSubmission   => 'awaiting_submission',
            self::Processing           => 'request_processing',
            self::PendingSignature     => 'pending_signature',
            self::ReadyToClaim         => 'ready_to_claim',
            self::Completed            => 'request_completed',
            self::Forfeited            => 'request_forfeited',
            self::Withdrawn            => 'request_withdrawn',
            self::ClosedUnableToProcess => 'request_closed_unable_to_process',
            self::Cancelled            => null,
        };
    }
}