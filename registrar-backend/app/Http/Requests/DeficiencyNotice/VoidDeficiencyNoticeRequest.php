<?php

namespace App\Http\Requests\DeficiencyNotice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Validates the payload for POST /deficiency-notices/{deficiencyNotice}/
 * void — the "never resolved" escalation outcome (student unreachable,
 * deceased, etc. — see the implementation plan's Phase 3 goal for
 * void()). void_reason is always required and is free text, not an
 * enum: unlike withdrawal_reason/item_key, there is no fixed list for
 * this field in the implementation plan — every void is a one-off
 * staff explanation, so validating it as a bounded string is the
 * correct (and only) rule here.
 *
 * {deficiencyNotice} is already resolved to a RequestRemark model by
 * Laravel's implicit route-model binding before this runs.
 */
class VoidDeficiencyNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('deficiencyNotice'));
    }

    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
