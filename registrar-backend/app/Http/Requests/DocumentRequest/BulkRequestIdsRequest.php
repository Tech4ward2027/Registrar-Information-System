<?php

namespace App\Http\Requests\DocumentRequest;

use App\Models\DocumentRequest;
use App\Models\SystemUser;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Shared by the bulk endpoints (archive-bulk, restore-bulk, bulk-ready,
 * bulk-done) — they take the same input shape and the same "is this actor
 * staff?" check, so one FormRequest covers all four.
 *
 * `request_ids` holds request references: the public_id (ULID) the client
 * now sends, or — while PUBLIC_IDS_ACCEPT_LEGACY_NUMERIC is on — the legacy
 * integer id. Controllers call requestIds() to get the internal integer ids
 * the services work with, so nothing below the controller changes.
 */
class BulkRequestIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Not a per-instance policy (there's no single DocumentRequest to
        // check against — it's a list), so this stays role-based.
        $actor = $this->user();

        return $actor instanceof SystemUser && $actor->isStaff();
    }

    public function rules(): array
    {
        return [
            'request_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'request_ids.*' => [
                'required',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Same acceptance rule as route binding: a ULID, or an
                    // all-digit id while the legacy switch is on.
                    if (DocumentRequest::publicKeyColumnFor($value) === null) {
                        $fail('Each request id must be a valid request reference.');
                    }
                },
            ],
        ];
    }

    /**
     * Resolve the validated references to internal integer request_ids,
     * de-duplicated (an integer id and the public_id of the same request
     * count once).
     *
     * Integer ids pass through untouched, so the services keep reporting an
     * unknown id as skipped exactly as before. A public_id that matches no
     * request cannot be turned into an id and is dropped; if nothing
     * resolves at all the call fails validation rather than running an
     * empty batch.
     *
     * Archived requests are included: restore-bulk needs them.
     *
     * @return int[]
     */
    public function requestIds(): array
    {
        $numeric = [];
        $ulids   = [];

        foreach ($this->validated()['request_ids'] as $ref) {
            $ref = (string) $ref;

            if (DocumentRequest::publicKeyColumnFor($ref) === 'public_id') {
                $ulids[] = strtolower($ref);
            } else {
                $numeric[] = (int) $ref;
            }
        }

        $resolved = $ulids === []
            ? []
            : DocumentRequest::withArchived()
                ->whereIn('public_id', $ulids)
                ->pluck('request_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $ids = array_values(array_unique(array_merge($numeric, $resolved)));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'request_ids' => ['None of the supplied requests could be found.'],
            ]);
        }

        return $ids;
    }
}