<?php

namespace App\Http\Requests\DeficiencyNotice;

use App\Enums\DeficiencyItemEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rules\Enum;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Validates the payload for POST /document-requests/{documentRequest}/
 * deficiency-notices.
 *
 * {documentRequest} is already resolved to a DocumentRequest model by
 * Laravel's implicit route-model binding before this runs (same
 * ordering note as WithdrawDocumentRequestRequest::authorize()), so
 * authorize() can safely check it here.
 *
 * item_key must be one of DeficiencyItemEnum's cases — same type-safe
 * Enum validation rule WithdrawDocumentRequestRequest already uses for
 * withdrawal_reason. detail is required only when item_key = 'other' —
 * identical "other requires detail" rule, deliberately kept in sync
 * with WithdrawDocumentRequestRequest's pattern for consistency across
 * this feature's two staff-facing forms.
 *
 * item_label is NOT accepted from the client: DeficiencyNoticeService::
 * issue() derives it from DeficiencyItemEnum::label() at write time
 * (see that migration's docblock for why the column is denormalized
 * rather than resolved on every read). Accepting a client-supplied
 * label would let staff enter text inconsistent with item_key, and
 * would let a normally machine-driven column be typed freely by
 * mistake — the same reason RequestChannelEnum/WithdrawalReasonEnum
 * values are never taken as free text either.
 */
class IssueDeficiencyNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('issueDeficiencyNotice', $this->route('documentRequest'));
    }

    public function rules(): array
    {
        return [
            'item_key' => ['required', 'string', new Enum(DeficiencyItemEnum::class)],
            'detail'   => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * other requires detail — mirrors WithdrawDocumentRequestRequest::
     * withValidator() exactly, comparing against the enum's own backing
     * value rather than duplicating the literal 'other' string so this
     * stays in sync automatically if DeficiencyItemEnum ever changes.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $itemKey = $this->input('item_key');

            if ($itemKey === DeficiencyItemEnum::Other->value && !filled($this->input('detail'))) {
                $validator->errors()->add(
                    'detail',
                    'A detail explanation is required when item_key is "other".'
                );
            }
        });
    }
}
