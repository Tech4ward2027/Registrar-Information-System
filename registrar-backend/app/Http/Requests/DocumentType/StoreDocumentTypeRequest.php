<?php

namespace App\Http\Requests\DocumentType;

use App\Rules\CashierPatternsAreConflictFree;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentTypeRequest extends FormRequest
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
            'document_name'               => 'required|string|max:100',
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

            // Lets an admin seed expected cashier receipt labels for this
            // type at creation time, instead of only ever discovering them
            // reactively via the Unmatched Cashier Items queue (see
            // CashierDocumentSuggester's docblock). Same column, same
            // normalisation, same matcher/suggester — this is just a
            // second, proactive way to populate it, not a parallel list.
            //
            // 'max:50' is a sanity ceiling, not a real-world expectation —
            // no type should plausibly need more than a handful of label
            // variants; this just bounds worst-case validation/query cost.
            // 'max:255' per entry matches unmatched_cashier_items.raw_label's
            // column width, so a pattern seeded here and a pattern
            // attached later via the resolve() flow are held to the same limit.
            'cashier_document_patterns'   => [
                'sometimes', 'nullable', 'array', 'max:50',
                new CashierPatternsAreConflictFree('document'),
            ],
            'cashier_document_patterns.*' => ['string', 'max:255'],
        ];
    }
}