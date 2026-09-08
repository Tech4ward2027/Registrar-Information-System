<?php

namespace App\Http\Requests\DeficiencyNotice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Validates (and authorizes) POST /deficiency-notices/{deficiencyNotice}/
 * clear. Clearing takes no body — staff are only confirming the flagged
 * item was received — so this exists purely to carry authorize() in the
 * same shape as every other write route on this feature, rather than
 * calling $this->authorize() directly in the controller (matching this
 * codebase's established move away from ad-hoc controller-level
 * authorize() calls).
 *
 * {deficiencyNotice} is already resolved to a RequestRemark model by
 * Laravel's implicit route-model binding before this runs.
 */
class ClearDeficiencyNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('clear', $this->route('deficiencyNotice'));
    }

    public function rules(): array
    {
        return [];
    }
}
