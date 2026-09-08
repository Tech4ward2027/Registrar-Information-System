<?php

namespace App\Http\Requests\DocumentType;

use App\Rules\CashierPatternsAreConflictFree;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already sits behind the 'role:3' middleware group in
        // routes/api.php — no per-request Policy check needed here.
        return true;
    }

    /**
     * The document_type_id being edited, from the route — {id} in
     * PUT /document-types/{id} (see routes/api.php). Used so the conflict
     * check below excludes this row's own existing patterns instead of
     * flagging them as conflicting with themselves.
     */
    private function currentTypeId(): ?int
    {
        $id = $this->route('id');

        return $id !== null ? (int) $id : null;
    }

    public function rules(): array
    {
        return [
            'document_name'               => 'sometimes|string|max:100',
            'document_description'        => 'nullable|string',
            'document_requirements'       => 'nullable|string',
            'document_process_period'     => 'nullable|string|max:100',
            'access_id'                   => 'nullable|integer|exists:access_type,access_id',
            'logbook_category_id'         => 'nullable|integer|exists:logbook_category,logbook_category_id',
            // FIXED (gap found while wiring up admin UI for Phase 3 —
            // fulfillment_track_id was added to the schema/model in
            // migration 2026_08_29_000008 but never validated here, so it
            // was silently stripped from every store/update request
            // regardless of what the client sent).
            'fulfillment_track_id'        => 'nullable|integer|exists:fulfillment_track,fulfillment_track_id',
            'requires_source_submission'  => 'nullable|boolean',

            // See StoreDocumentTypeRequest for the full rationale. On
            // update, the conflict check excludes THIS type's own row —
            // otherwise every existing pattern would "conflict" with itself
            // the moment the admin re-saves the form.
            'cashier_document_patterns'   => [
                'sometimes', 'nullable', 'array', 'max:50',
                new CashierPatternsAreConflictFree('document', $this->currentTypeId()),
            ],
            'cashier_document_patterns.*' => ['string', 'max:255'],
        ];
    }
}