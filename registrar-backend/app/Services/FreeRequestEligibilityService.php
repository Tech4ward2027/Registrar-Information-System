<?php

namespace App\Services;

use App\DTOs\FreeRequest\FreeRequestEligibilityResult;
use App\Enums\AccessType;
use App\Enums\FreeRequestItemKindEnum;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\SystemUser;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Answers exactly one question — "may $targetUser receive $typeId for
 * free, right now, through the admin_filed_free channel?" — for a
 * single (kind, typeId) pair. Deliberately free of side effects (no
 * writes, no locking, no Auth:: calls): FreeRequestService::
 * fileFreeRequest() is what wraps a call to check() inside a row-locked
 * transaction at the moment of filing (see that class's docblock for
 * why the lock lives there and not here). Keeping this class pure is
 * also exactly what the Phase 6 test plan calls for — every case in
 * that plan (eligible graduate, ineligible document type, non-graduate,
 * already-claimed graduate, filed-then-forfeited graduate, LOA with
 * prior claimed LOA) is a plain input/output assertion against check()
 * with no database transaction or mocking required beyond factory rows.
 *
 * ── Rule 1 — is_free_eligible ──────────────────────────────────────────
 * The type must have is_free_eligible = true. This is the opt-in gate:
 * a type with no configured free_issuance_limit is NOT "unlimited by
 * omission" — it simply isn't part of this feature at all (see the
 * Phase 1 migration's docblock).
 *
 * ── Rule 2 — role visibility ────────────────────────────────────────────
 * The type's access_id must already be visible to the TARGET account's
 * actual role (App\Enums\AccessType::studentVisibleIds() /
 * alumniVisibleIds() — the same single source of truth
 * CashierDocumentSuggester already uses for the self-service forms).
 * Deliberately reads $targetUser->role_id directly rather than
 * SystemUser::isStudent()/isAlumni() — those resolve the CURRENTLY
 * AUTHENTICATED SESSION's assumed role (see SystemUser::
 * assumedRoleId()'s docblock), which is meaningless here: the target
 * account being looked up by a staff member is never the session
 * making this call.
 *
 * ── Rule 3 — graduate-scoped types require the Alumni role ─────────────
 * A type with a finite free_issuance_limit (i.e. NOT NULL) is, by
 * construction, a First-Copy-Free-Issuance-for-Graduates type — TOR and
 * COG are seeded with free_issuance_limit = 1 specifically because that
 * policy's scope (§2) is graduates only, one-time. A type with
 * free_issuance_limit = NULL (LOA) is instead governed by the base Free
 * Documents/Certificates Request Policy alone, which has no graduate
 * restriction — LOA is visible to (and requestable by) currently
 * enrolled students per its own access_id = 1.
 *
 * This couples "has a finite limit" to "is graduate-scoped" rather than
 * hardcoding document_type_id/certificate_type_id checks for COG/TOR
 * specifically, so a future type added to this feature is correctly
 * classified purely by how its two Phase-1 columns are configured — no
 * code change needed. ASSUMPTION FLAGGED FOR CONFIRMATION: this
 * couples two concepts (issuance limit vs. graduate-only restriction)
 * that happen to align for every type in scope today, but are not
 * logically identical — if a future policy ever needs a capped-but-not-
 * graduate-restricted type, this rule must be revisited (e.g. an
 * explicit requires_graduate_verification column). Flagged explicitly
 * rather than silently assumed.
 *
 * ── Rule 4 — consumption count vs. limit ────────────────────────────────
 * Counts this target's own prior Completed admin_filed_free requests
 * for this exact type (see DocumentRequest::scopeAdminFiledFree() and
 * RequestStatusEnum::Completed — the real "successfully claimed"
 * terminal state; there is no separate "claimed" status). A prior
 * request that was Forfeited (expired, never claimed) or is still
 * Processing/ReadyToClaim does NOT count against the limit — this is
 * the revised "consumed upon claim, not upon filing" rule, and is what
 * lets a graduate whose first attempt lapsed unclaimed file again
 * without being permanently blocked. free_issuance_limit = NULL (LOA)
 * skips this count entirely — always eligible on this rule.
 *
 * ── Rule 5 — requested quantity vs. remaining (Phase 7 hardening) ──────
 * $quantity is the number_of_copies actually being requested for this
 * line item, not just "is the type eligible at all". Rule 4 above only
 * asks "has the limit already been fully consumed" (remaining <= 0);
 * without this rule, a SINGLE filing with e.g. remaining = 1 but
 * number_of_copies = 5 would sail through as "eligible" and
 * DocumentRequestService::createRequest() would fulfil all 5 copies
 * under one admin_filed_free request — bypassing the First Copy Free
 * Issuance Policy's one-time cap in a single API call, since
 * priorClaimedCount() counts CLAIMED REQUESTS containing the type, not
 * individual copies issued. A type with free_issuance_limit = NULL
 * (LOA) has no such cap by policy, so $quantity is never restricted for
 * unlimited types — only checked once $remaining has been computed for
 * a finite-limit type. Defaults to 1 (every existing caller/test that
 * doesn't pass a quantity keeps behaving exactly as before).
 */
class FreeRequestEligibilityService
{
    public function check(SystemUser $targetUser, FreeRequestItemKindEnum $kind, int $typeId, int $quantity = 1): FreeRequestEligibilityResult
    {
        $type = $this->resolveType($kind, $typeId);

        if (!$type) {
            return FreeRequestEligibilityResult::ineligible(
                kind: $kind,
                typeId: $typeId,
                typeLabel: null,
                reasonCode: FreeRequestEligibilityResult::REASON_TYPE_NOT_FOUND,
                reason: 'This document/certificate type does not exist.',
            );
        }

        $typeLabel = $this->labelFor($kind, $type);
        $requiresGraduateVerification = $type->free_issuance_limit !== null;

        if (!$type->is_free_eligible) {
            return FreeRequestEligibilityResult::ineligible(
                kind: $kind,
                typeId: $typeId,
                typeLabel: $typeLabel,
                reasonCode: FreeRequestEligibilityResult::REASON_NOT_FREE_ELIGIBLE,
                reason: "{$typeLabel} is not configured for free issuance.",
                requiresGraduateVerification: $requiresGraduateVerification,
                freeIssuanceLimit: $type->free_issuance_limit,
            );
        }

        $allowedAccessIds = match ((int) $targetUser->role_id) {
            SystemUser::ROLE_STUDENT => AccessType::studentVisibleIds(),
            SystemUser::ROLE_ALUMNI  => AccessType::alumniVisibleIds(),
            default                  => null,
        };

        if ($allowedAccessIds === null) {
            return FreeRequestEligibilityResult::ineligible(
                kind: $kind,
                typeId: $typeId,
                typeLabel: $typeLabel,
                reasonCode: FreeRequestEligibilityResult::REASON_INVALID_TARGET_ROLE,
                reason: 'Free requests may only be filed on behalf of a student or alumni account.',
                requiresGraduateVerification: $requiresGraduateVerification,
                freeIssuanceLimit: $type->free_issuance_limit,
            );
        }

        if (!in_array((int) $type->access_id, $allowedAccessIds, true)) {
            return FreeRequestEligibilityResult::ineligible(
                kind: $kind,
                typeId: $typeId,
                typeLabel: $typeLabel,
                reasonCode: FreeRequestEligibilityResult::REASON_NOT_VISIBLE_TO_ROLE,
                reason: "{$typeLabel} is not available to this account's role.",
                requiresGraduateVerification: $requiresGraduateVerification,
                freeIssuanceLimit: $type->free_issuance_limit,
            );
        }

        if ($requiresGraduateVerification && (int) $targetUser->role_id !== SystemUser::ROLE_ALUMNI) {
            return FreeRequestEligibilityResult::ineligible(
                kind: $kind,
                typeId: $typeId,
                typeLabel: $typeLabel,
                reasonCode: FreeRequestEligibilityResult::REASON_NOT_GRADUATE,
                reason: "{$typeLabel} is a graduate-only first-copy benefit and this account is not an alumni/graduate account.",
                requiresGraduateVerification: true,
                freeIssuanceLimit: $type->free_issuance_limit,
            );
        }

        $remaining = null;

        if ($type->free_issuance_limit !== null) {
            $priorClaimedCount = $this->priorClaimedCount($targetUser, $kind, $typeId);
            $remaining = max(0, $type->free_issuance_limit - $priorClaimedCount);

            if ($remaining <= 0) {
                return FreeRequestEligibilityResult::ineligible(
                    kind: $kind,
                    typeId: $typeId,
                    typeLabel: $typeLabel,
                    reasonCode: FreeRequestEligibilityResult::REASON_LIMIT_REACHED,
                    reason: "This account has already claimed the free copy limit ({$type->free_issuance_limit}) for {$typeLabel}.",
                    requiresGraduateVerification: $requiresGraduateVerification,
                    freeIssuanceLimit: $type->free_issuance_limit,
                    remaining: 0,
                );
            }

            // Rule 5 (see class docblock) — copies remain, but not
            // enough to cover THIS filing's requested quantity. Only
            // reachable for finite-limit types; unlimited types (LOA,
            // free_issuance_limit === null) never enter this branch at
            // all, so no quantity cap is ever applied to them.
            if ($quantity > $remaining) {
                return FreeRequestEligibilityResult::ineligible(
                    kind: $kind,
                    typeId: $typeId,
                    typeLabel: $typeLabel,
                    reasonCode: FreeRequestEligibilityResult::REASON_QUANTITY_EXCEEDS_REMAINING,
                    reason: "Only {$remaining} free " . ($remaining === 1 ? 'copy' : 'copies') . " of {$typeLabel} remain for this account, but {$quantity} " . ($quantity === 1 ? 'was' : 'were') . " requested.",
                    requiresGraduateVerification: $requiresGraduateVerification,
                    freeIssuanceLimit: $type->free_issuance_limit,
                    remaining: $remaining,
                );
            }
        }

        return FreeRequestEligibilityResult::eligible(
            kind: $kind,
            typeId: $typeId,
            typeLabel: $typeLabel,
            requiresGraduateVerification: $requiresGraduateVerification,
            freeIssuanceLimit: $type->free_issuance_limit,
            remaining: $remaining,
        );
    }

    /**
     * Batch form of check() — every document/certificate line item a
     * staff member is about to file in one go, shaped exactly like
     * StoreDocumentRequestRequest's 'documents' / 'certificates' arrays
     * so FreeRequestService can pass validated input straight through.
     *
     * number_of_copies is read from each line item (defaulting to 1 when
     * absent, matching DocumentRequestService's own default for
     * certificates) and fed into check()'s Rule 5 quantity-vs-remaining
     * check — see that method's docblock.
     *
     * @param array<int,array{document_type_id:int,number_of_copies?:int}> $documents
     * @param array<int,array{certificate_type_id:int,number_of_copies?:int}> $certificates
     * @return FreeRequestEligibilityResult[]
     */
    public function checkMany(SystemUser $targetUser, array $documents, array $certificates): array
    {
        $results = [];

        foreach ($documents as $doc) {
            $results[] = $this->check(
                $targetUser,
                FreeRequestItemKindEnum::Document,
                (int) $doc['document_type_id'],
                (int) ($doc['number_of_copies'] ?? 1),
            );
        }

        foreach ($certificates as $cert) {
            $results[] = $this->check(
                $targetUser,
                FreeRequestItemKindEnum::Certificate,
                (int) $cert['certificate_type_id'],
                (int) ($cert['number_of_copies'] ?? 1),
            );
        }

        return $results;
    }

    /**
     * Whether ANY item in a batch requires the COG/TOR graduate
     * verification step — used by FreeRequestService to decide whether
     * a graduate_verifications row must be created for this filing.
     *
     * @param FreeRequestEligibilityResult[] $results
     */
    public function anyRequiresGraduateVerification(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->requiresGraduateVerification) {
                return true;
            }
        }

        return false;
    }

    private function resolveType(FreeRequestItemKindEnum $kind, int $typeId): DocumentType|CertificationType|null
    {
        return match ($kind) {
            FreeRequestItemKindEnum::Document    => DocumentType::find($typeId),
            FreeRequestItemKindEnum::Certificate => CertificationType::find($typeId),
        };
    }

    private function labelFor(FreeRequestItemKindEnum $kind, DocumentType|CertificationType $type): string
    {
        return match ($kind) {
            FreeRequestItemKindEnum::Document    => $type->document_name,
            FreeRequestItemKindEnum::Certificate => $type->certificate_name,
        };
    }

    /**
     * Count of this target's own Completed (i.e. actually claimed —
     * see RequestStatusEnum) admin_filed_free requests that included
     * this exact type, via the appropriate line-item relation for the
     * given kind.
     */
    private function priorClaimedCount(SystemUser $targetUser, FreeRequestItemKindEnum $kind, int $typeId): int
    {
        $query = DocumentRequest::query()
            ->where('user_id', $targetUser->user_id)
            ->adminFiledFree()
            ->where('status_id', \App\Enums\RequestStatusEnum::Completed->value);

        return match ($kind) {
            FreeRequestItemKindEnum::Document => $query
                ->whereHas('documents', fn ($q) => $q->where('document_type_id', $typeId))
                ->count(),
            FreeRequestItemKindEnum::Certificate => $query
                ->whereHas('certificates', fn ($q) => $q->where('certificate_type_id', $typeId))
                ->count(),
        };
    }
}