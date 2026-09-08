<?php

namespace App\Http\Requests\DocumentRequest;

use App\Enums\WithdrawalReasonEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rules\Enum;

/**
 * Deficiency Notice & Withdrawn Status — Phase 1.
 *
 * Validates the payload for POST /document-requests/{documentRequest}/withdraw.
 *
 * {documentRequest} is already resolved to a DocumentRequest model by
 * Laravel's implicit route-model binding before this runs (same ordering
 * note as UpdateDocumentRequestRequest::authorize()), so authorize() can
 * safely check it here.
 *
 * withdrawal_reason must be one of WithdrawalReasonEnum's cases —
 * enforced with Laravel's built-in Enum validation rule, the same
 * type-safe approach RequestChannelEnum/NotificationAudienceEnum exist
 * to support elsewhere in this codebase. withdrawal_detail is required
 * only when withdrawal_reason = 'other' (see withValidator() below) —
 * matches the "other requires detail" rule Phase 3's Deficiency Notice
 * feature uses for request_remarks.detail.
 */
class WithdrawDocumentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('withdraw', $this->route('documentRequest'));
    }

    public function rules(): array
    {
        return [
            'withdrawal_reason' => ['required', 'string', new Enum(WithdrawalReasonEnum::class)],
            'withdrawal_detail' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'superseded_by_request_id' => [
                'sometimes',
                'nullable',
                'integer',
                // Deliberately no exists:document_request rule here —
                // ExcludeArchivedScope would reject a superseding request
                // that is itself archived, even though that's a
                // legitimate historical pointer (see withdraw()'s own
                // withArchived() lookup). The service layer performs the
                // real existence check against the unscoped table.
            ],
        ];
    }

    /**
     * other requires withdrawal_detail — mirrors ClaimDocumentRequestRequest's
     * use of withValidator() for a cross-field rule that Laravel's
     * declarative rules() array can't express on its own (required_if
     * would work for a simple string match, but keeping the comparison
     * against the enum's own backing value here, rather than duplicating
     * the literal 'other' string, keeps this in sync with
     * WithdrawalReasonEnum automatically if that enum ever changes).
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $reason = $this->input('withdrawal_reason');

            if ($reason === WithdrawalReasonEnum::Other->value && !filled($this->input('withdrawal_detail'))) {
                $validator->errors()->add(
                    'withdrawal_detail',
                    'A detail explanation is required when withdrawal_reason is "other".'
                );
            }
        });
    }
}
