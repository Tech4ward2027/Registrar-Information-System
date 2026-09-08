<?php

namespace App\Http\Requests\CertificationType;

use App\Rules\CashierPatternsAreConflictFree;
use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already sits behind the 'role:3' middleware group in
        // routes/api.php — no per-request Policy check needed here.
        return true;
    }

    public function rules(): array
    {
        return [
            'certificate_name'            => 'required|string|max:255',
            'certificate_requirements'    => 'nullable|string',
            'certificate_process_period'  => 'nullable|string|max:100',
            'access_id'                   => 'nullable|integer',
            'logbook_category_id'         => 'nullable|integer|exists:logbook_category,logbook_category_id',
            // FIXED (gap found while wiring up admin UI for Phase 3 —
            // fulfillment_track_id was added to the schema/model in
            // migration 2026_08_29_000008 but never validated here, so it
            // was silently stripped from every store/update request
            // regardless of what the client sent).
            'fulfillment_track_id'        => 'nullable|integer|exists:fulfillment_track,fulfillment_track_id',
            'requires_source_submission'  => 'nullable|boolean',

            // See StoreDocumentTypeRequest (same field, same rule, mirrored
            // on the certificate side of the catalog).
            'cashier_document_patterns'   => [
                'sometimes', 'nullable', 'array', 'max:50',
                new CashierPatternsAreConflictFree('certificate'),
            ],
            'cashier_document_patterns.*' => ['string', 'max:255'],
        ];
    }
}