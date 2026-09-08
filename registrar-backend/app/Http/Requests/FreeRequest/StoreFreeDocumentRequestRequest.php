<?php

namespace App\Http\Requests\FreeRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Validates POST /free-requests. Shape mirrors StoreDocumentRequestRequest's
 * 'documents'/'certificates' arrays exactly (FreeRequestService::
 * fileFreeRequest() passes them straight through to
 * DocumentRequestService::createRequest()), plus three fields specific to
 * the admin-filed free flow: who this is being filed on behalf of, and
 * the optional override/verification confirmations.
 *
 * Route sits behind 'role:3,4' + 'module:free_requests,File' in
 * routes/api.php — every admin who can reach this endpoint can file an
 * ordinary eligible free request. The narrower Verify/Override
 * capabilities are NOT re-validated here: FreeRequestService::
 * fileFreeRequest()'s assertCapability() is what actually enforces them
 * (against the live, lockable state of the actor's policy at the moment
 * of filing) — this class only confirms the override/verification
 * payload has the right SHAPE when present, same "shape here, business
 * rule in the service" split StoreCashierOrOverrideRequest's docblock
 * already documents for this codebase.
 *
 * Deliberately does NOT check target_user_id's role (student/alumni) or
 * "at least one item requested" here — both are DB-state/cross-field
 * business rules that FreeRequestService::fileFreeRequest() already
 * enforces (via abort(422)) at the point where they actually matter,
 * same reasoning StoreDocumentRequestRequest's docblock gives for
 * leaving equivalent checks out of its own rules().
 */
class StoreFreeDocumentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_user_id' => 'required|integer|exists:users,user_id',

            'request_purpose_id' => 'required|integer|exists:request_purpose,request_purpose_id',

            // Phase 7 — Security Hardening: cap the number of line items
            // per filing. This feature has exactly three in-scope types
            // (COG, TOR, LOA) today, so 20 is already generous headroom
            // — the real purpose is closing a resource-exhaustion vector:
            // fileFreeRequest() holds a row lock on the TARGET user
            // (SystemUser::lockForUpdate()) for the entire duration of
            // FreeRequestEligibilityService::checkMany()'s loop, so an
            // uncapped array lets one malicious/misbehaving request hold
            // that lock open proportionally to array size, starving every
            // other free-request filing for that same graduate. The same
            // cap is applied on the read-only eligibility pre-check for
            // consistency, even though no lock is held there.
            'documents'                          => 'nullable|array|max:20',
            'documents.*.document_type_id'       => 'required_with:documents|integer|exists:document_type,document_type_id',
            'documents.*.number_of_copies'       => 'required_with:documents|integer|min:1|max:10',

            'certificates'                       => 'nullable|array|max:20',
            'certificates.*.certificate_type_id' => 'required_with:certificates|integer|exists:certificate_type,certificate_type_id',
            'certificates.*.number_of_copies'    => 'nullable|integer|min:1|max:10',

            // Eligibility override — staff have independently determined
            // an ineligible item should be filed anyway. Minimum length
            // mirrors StoreCashierOrOverrideRequest's own 'reason' rule:
            // nudges away from a rubber-stamp one-word justification for
            // bypassing a policy-configured eligibility gate.
            'override'        => 'nullable|boolean',
            'override_reason' => 'required_if:override,true|nullable|string|min:10|max:1000',

            // Graduate verification confirmation — required in practice
            // only when the filing includes a COG/TOR item (checked by
            // the service, not here; see this class's docblock). Both
            // flags must be explicitly true, not merely present — a
            // staff member unchecking a box after loading the form
            // should not silently pass 'true' through stale form state.
            'verification'                       => 'nullable|array',
            'verification.credentials_verified'  => 'nullable|boolean',
            'verification.records_checked'       => 'nullable|boolean',
        ];
    }
}