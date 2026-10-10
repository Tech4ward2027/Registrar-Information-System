<?php

namespace App\DTOs\AcademicRecords;

use App\Enums\AcademicRecordStatusEnum;
use App\Enums\RequesterTypeEnum;

/**
 * Immutable result of AcademicRecordResolver::resolve().
 *
 * `userId` is the owning system user and is for INTERNAL use only (later
 * phases resolve the student server-side from it). It is intentionally not
 * part of the API response — see AcademicRecordLookupResource, the single
 * place that decides what is exposed.
 */
final readonly class ResolvedAcademicRecord
{
    public function __construct(
        public string $studentNumber,
        public ?string $course,
        /** Alumni: the year of graduation (YYYY) — the schema stores no exact date. */
        public ?string $graduationDate,
        public AcademicRecordStatusEnum $status,
        public RequesterTypeEnum $requesterType,
        public ?int $userId,
    ) {}
}
