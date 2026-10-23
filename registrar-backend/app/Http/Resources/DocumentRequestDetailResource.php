<?php

namespace App\Http\Resources;

use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Single-request payload (show, store, update, withdraw, close, archive,
 * restore ...). Replaces returning the raw model, which serialised every
 * eager-loaded relation in full.
 *
 * What it changes, and only this (everything else is the model's normal
 * toArray() output, so existing frontend reads keep working):
 *
 *  1. Embedded accounts are reduced to USER_FIELDS. The requester (`user`)
 *     and staff actors (`archived_by_user`, `issued_by_user`, ...) used to
 *     carry every SystemUser column, including idp_user_id and policy_id.
 *
 *  2. Staff actor accounts are removed entirely for a non-staff viewer. A
 *     student reading their own request has no need for the email of the
 *     admin who archived it or issued a deficiency notice.
 *
 *  3. `superseding_request` is reduced to a pointer. It was a complete
 *     DocumentRequest, which includes that request's uuid and claim_code
 *     (the claim credential).
 *
 * Deliberately NOT changed yet: the top-level request_id and user_id, and the
 * nested request_id / request_document_id keys. The frontend still reads them
 * (e.g. StudentDashboard filters the list on user_id; item actions use
 * request_document_id). They go in the contract phase, after the legacy-ID
 * log has gone quiet.
 *
 * @property DocumentRequest $resource
 */
class DocumentRequestDetailResource extends JsonResource
{
    /** Keep the bare model shape: no {"data": ...} envelope. */
    public static $wrap = null;

    /** Columns of an embedded account that may leave the server. */
    public const USER_FIELDS = ['user_id', 'email', 'role_id', 'status'];

    /** What an embedded superseding request is reduced to. */
    public const SUPERSEDING_FIELDS = ['request_id', 'public_id', 'status_id'];

    public function toArray(Request $request): array
    {
        $viewer  = $request->user();
        $isStaff = $viewer !== null && $viewer->isStaff();

        $data = $this->scrub($this->resource->toArray(), $isStaff);

        if (isset($data['superseding_request']) && is_array($data['superseding_request'])) {
            $data['superseding_request'] = Arr::only($data['superseding_request'], self::SUPERSEDING_FIELDS);
        }

        return $data;
    }

    /**
     * Walk the serialised tree. `user` is the requester; any key ending in
     * `_by_user` is a staff actor (archived_by_user, issued_by_user, ...).
     */
    private function scrub(array $node, bool $isStaff): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (is_string($key) && str_ends_with($key, '_by_user')) {
                if ($isStaff) {
                    $node[$key] = Arr::only($value, self::USER_FIELDS);
                } else {
                    unset($node[$key]);
                }

                continue;
            }

            if ($key === 'user') {
                $node[$key] = Arr::only($value, self::USER_FIELDS);

                continue;
            }

            $node[$key] = $this->scrub($value, $isStaff);
        }

        return $node;
    }
}
