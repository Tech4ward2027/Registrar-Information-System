<?php

namespace App\Http\Requests\DocumentRequest;

use App\Enums\ClosureReasonEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rules\Enum;

/**
 * Data Retention & Disposal Policy — Section 3.4.
 *
 * Validates the payload for
 * POST /document-requests/{documentRequest}/close-unable-to-process.
 *
 * closure_reason must be one of ClosureReasonEnum's cases. closure_detail
 * is required only when closure_reason = 'other' (see withValidator()
 * below) — same "other requires detail" convention
 * WithdrawDocumentRequestRequest and IssueDeficiencyNoticeRequest
 * already use. closure_proof_reference is ALWAYS required (not
 * conditional on the reason) — per the policy's "Upon receipt of valid
 * proof" requirement, every closure under this status must record what
 * was verified, regardless of which specific reason applies.
 */
class CloseRequestUnableToProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('closeUnableToProcess', $this->route('documentRequest'));
    }

    public function rules(): array
    {
        return [
            'closure_reason'          => ['required', 'string', new Enum(ClosureReasonEnum::class)],
            'closure_detail'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Required regardless of reason — see class docblock. Kept
            // generous (500 chars) since this is a description of what
            // was verified (e.g. document type, submitter, verification
            // date), not the proof itself — no file is uploaded or
            // stored by this endpoint. See the
            // add_closed_unable_to_process_status migration's docblock
            // for why this is a text reference rather than a file
            // upload.
            'closure_proof_reference' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * other requires closure_detail — mirrors WithdrawDocumentRequestRequest
     * ::withValidator() exactly, comparing against the enum's own
     * backing value rather than duplicating the literal 'other' string.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $reason = $this->input('closure_reason');

            if ($reason === ClosureReasonEnum::Other->value && !filled($this->input('closure_detail'))) {
                $validator->errors()->add(
                    'closure_detail',
                    'A detail explanation is required when closure_reason is "other".'
                );
            }
        });
    }
}
