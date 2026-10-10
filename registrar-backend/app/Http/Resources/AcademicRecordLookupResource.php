<?php

namespace App\Http\Resources;

use App\DTOs\AcademicRecords\ResolvedAcademicRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only shape the by-student lookup ever returns. An explicit allow-list:
 * adding a field to ResolvedAcademicRecord never leaks it to the client.
 *
 * @property ResolvedAcademicRecord $resource
 */
class AcademicRecordLookupResource extends JsonResource
{
    public function __construct(ResolvedAcademicRecord $resource, private readonly ?string $orNumber)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'student_number'  => $this->resource->studentNumber,
            'course'          => $this->resource->course,
            'graduation_date' => $this->resource->graduationDate,
            'status'          => $this->resource->status->value,
            'requester_type'  => $this->resource->requesterType->value,
            'or_number'       => $this->orNumber,
            'current_date'    => now(config('app.display_timezone'))->toDateString(),
        ];
    }
}
