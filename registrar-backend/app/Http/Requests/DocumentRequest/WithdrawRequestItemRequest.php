<?php

namespace App\Http\Requests\DocumentRequest;

use Illuminate\Support\Arr;

/**
 * POST /document-requests/{documentRequest}/documents|certificates/{item}/withdraw
 *
 * Same policy (DocumentRequestPolicy::withdraw), reason enum and
 * "other requires detail" rule as withdrawing a whole request, inherited
 * from WithdrawDocumentRequestRequest so the two can never drift. Only
 * superseded_by_request_id is dropped: a duplicate-request pointer belongs
 * to the request, not to one item.
 */
class WithdrawRequestItemRequest extends WithdrawDocumentRequestRequest
{
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['withdrawal_reason', 'withdrawal_detail']);
    }
}
