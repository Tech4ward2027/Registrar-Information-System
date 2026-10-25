<?php

namespace App\Http\Resources;

use App\Models\RequestHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * One row of the request-history audit trail.
 *
 * The raw model serialised the whole parent DocumentRequest (including its
 * uuid and claim_code) and the whole changing account (including idp_user_id).
 * A history row only needs to point at them, so both are reduced.
 *
 * @property RequestHistory $resource
 */
class RequestHistoryResource extends JsonResource
{
    public static $wrap = null;

    public const REQUEST_FIELDS = ['request_id', 'public_id', 'status_id', 'requested_at'];

    public function toArray(Request $request): array
    {
        $data = $this->resource->toArray();

        if (isset($data['request']) && is_array($data['request'])) {
            $data['request'] = Arr::only($data['request'], self::REQUEST_FIELDS);
        }

        if (isset($data['changed_by']) && is_array($data['changed_by'])) {
            $data['changed_by'] = Arr::only($data['changed_by'], DocumentRequestDetailResource::USER_FIELDS);
        }

        return $data;
    }
}
