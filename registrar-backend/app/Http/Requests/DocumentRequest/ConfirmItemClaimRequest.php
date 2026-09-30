<?php

namespace App\Http\Requests\DocumentRequest;

use App\Models\DocumentRequest;
use App\Models\SystemUser;
use App\Services\ItemClaimService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /document-requests/claim/confirm
 *
 * Exactly one of uuid / claim_code (the credential that was looked up),
 * plus item_uuids: the documents staff saw on the checklist and chose to
 * release. item_uuids may be omitted only when the credential is one
 * item's own code — ItemClaimService enforces that, since only it knows
 * what the credential resolves to.
 */
class ConfirmItemClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof SystemUser && $actor->can('claim', DocumentRequest::class);
    }

    public function rules(): array
    {
        return [
            'uuid'          => 'required_without:claim_code|nullable|uuid',
            'claim_code'    => 'required_without:uuid|nullable|string|size:6',
            'item_uuids'    => 'nullable|array|max:' . ItemClaimService::MAX_ITEMS_PER_CONFIRM,
            'item_uuids.*'  => 'required|uuid|distinct',
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v) {
            if ($this->filled('uuid') && $this->filled('claim_code')) {
                $v->errors()->add('claim_code', 'Provide either a QR uuid or a claim code, not both.');
            }
        });
    }

    /** @return array{uuid?: string, claim_code?: string} */
    public function credential(): array
    {
        return array_filter([
            'uuid'       => $this->validated('uuid'),
            'claim_code' => $this->validated('claim_code'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function itemUuids(): ?array
    {
        $uuids = $this->validated('item_uuids');

        return is_array($uuids) ? $uuids : null;
    }
}
