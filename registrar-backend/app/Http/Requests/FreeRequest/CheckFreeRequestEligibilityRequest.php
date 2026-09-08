<?php

namespace App\Http\Requests\FreeRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Validates POST /free-requests/eligibility — the read-only "show staff
 * the eligibility indicator before they commit to filing" check backed
 * by FreeRequestService::checkEligibility() / FreeRequestEligibilityService::
 * checkMany().
 *
 * POST rather than GET (unlike the original spec's "GET /graduates/{id}/
 * eligibility"): the real FreeRequestEligibilityService::checkMany() takes
 * a full 'documents'/'certificates' line-item array per call — the same
 * shape StoreDocumentRequestRequest already validates for a normal
 * self-service filing — not a single graduate id. That doesn't fit
 * cleanly into query-string params once more than one or two items are
 * selected, so this follows the same "structured array body on a
 * logically read-only action" precedent already set elsewhere in this
 * codebase (e.g. AiQueryRequest). No DB writes happen here or in the
 * controller action it backs.
 *
 * Route sits behind 'role:3,4' + 'module:free_requests,View' in
 * routes/api.php — authorize() only needs to confirm shape, not
 * re-check module access.
 *
 * Phase 7 — Security Hardening: 'documents'/'certificates' are capped at
 * max:20 items, same reasoning as StoreFreeDocumentRequestRequest's own
 * cap — see that class's docblock. No row lock is held on this
 * read-only path, but the cap is kept consistent between both endpoints
 * so the pre-check and the actual filing never disagree on what's a
 * valid-shaped request.
 */
class CheckFreeRequestEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_user_id' => 'required|integer|exists:users,user_id',

            'documents'                     => 'nullable|array|max:20',
            'documents.*.document_type_id' => 'required_with:documents|integer|exists:document_type,document_type_id',
            // Phase 7 — Security Hardening: optional here (unlike the
            // Store request, where it's required for documents) since
            // this is a pre-check that may run before staff have picked
            // a quantity yet. Defaults to 1 inside
            // FreeRequestEligibilityService::checkMany() when omitted —
            // same default DocumentRequestService already applies to
            // certificates. Feeds check()'s Rule 5 (quantity vs.
            // remaining) so the indicator staff see before filing
            // matches what fileFreeRequest() will actually enforce.
            'documents.*.number_of_copies' => 'nullable|integer|min:1|max:10',

            'certificates'                       => 'nullable|array|max:20',
            'certificates.*.certificate_type_id' => 'required_with:certificates|integer|exists:certificate_type,certificate_type_id',
            'certificates.*.number_of_copies'    => 'nullable|integer|min:1|max:10',
        ];
    }

    // -------------------------------------------------------------------
    // NOTE, mirroring StoreDocumentRequestRequest's own note: "at least
    // one document or certificate" and "is target_user_id actually a
    // student/alumni account" are cross-field / DB-state business rules,
    // not shape/type rules, and stay in the controller — see
    // FreeRequestController::eligibility().
    // -------------------------------------------------------------------
}