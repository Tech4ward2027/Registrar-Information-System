<?php

namespace App\Services\AcademicRecords;

use App\DTOs\AcademicRecords\ResolvedAcademicRecord;
use App\Enums\AcademicRecordStatusEnum;
use App\Enums\RequesterTypeEnum;
use App\Enums\UndergradRequestorVerificationStatusEnum;
use App\Models\AlumniAcademicRecord;
use App\Models\DocumentRequest;
use App\Models\StudentAcademicRecord;
use App\Models\UndergradRequestorProfile;

/**
 * Resolves ONE student number to ONE academic record, searching in a fixed
 * priority order:
 *
 *   1. Enrolled student records   (student_academic_record)
 *   2. Alumni records             (alumni_academic_record)
 *   3. Undergraduate requesters   (undergrad_requestor_profiles, Approved only)
 *
 * The first tier that matches wins, so a number can never resolve to two
 * different people. Always a single-row, keyed lookup — never a list — so
 * callers cannot use it to enumerate institutional records.
 *
 * Undergraduate requesters are only resolvable once an Admin has Approved
 * them: their data is self-declared, so a Pending/Rejected profile must not
 * be able to produce certificate data.
 */
class AcademicRecordResolver
{
    public function resolve(string $studentNumber): ?ResolvedAcademicRecord
    {
        return $this->resolveEnrolled($studentNumber)
            ?? $this->resolveAlumni($studentNumber)
            ?? $this->resolveUndergraduateRequester($studentNumber);
    }

    /**
     * Most recent Official Receipt number on this account's active
     * (non-archived, non-deleted) requests, or null. DocumentRequest's
     * global scopes already exclude archived/soft-deleted rows.
     */
    public function latestOrNumber(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $or = DocumentRequest::query()
            ->where('user_id', $userId)
            ->whereNotNull('or_number')
            ->where('or_number', '!=', '')
            ->orderByDesc('requested_at')
            ->orderByDesc('request_id')
            ->value('or_number');

        return $or !== null ? (string) $or : null;
    }

    private function resolveEnrolled(string $studentNumber): ?ResolvedAcademicRecord
    {
        $record = StudentAcademicRecord::query()
            ->with('studentProfile:student_profile_id,user_id')
            ->where('student_number', $studentNumber)
            ->first();

        if (!$record) {
            return null;
        }

        return new ResolvedAcademicRecord(
            studentNumber:  (string) $record->student_number,
            course:         $record->course,
            graduationDate: null,
            status:         AcademicRecordStatusEnum::Enrolled,
            requesterType:  RequesterTypeEnum::Student,
            userId:         $record->studentProfile?->user_id,
        );
    }

    private function resolveAlumni(string $studentNumber): ?ResolvedAcademicRecord
    {
        $record = AlumniAcademicRecord::query()
            ->with('alumniProfile.alumni:alumni_id,user_id')
            ->where('student_number', $studentNumber)
            ->orderByDesc('alumni_academic_id')
            ->first();

        if (!$record) {
            return null;
        }

        return new ResolvedAcademicRecord(
            studentNumber:  (string) $record->student_number,
            course:         $record->course,
            graduationDate: $record->year_of_graduation !== null ? (string) $record->year_of_graduation : null,
            status:         AcademicRecordStatusEnum::Alumni,
            requesterType:  RequesterTypeEnum::Alumni,
            userId:         $record->alumniProfile?->alumni?->user_id,
        );
    }

    private function resolveUndergraduateRequester(string $studentNumber): ?ResolvedAcademicRecord
    {
        $profile = UndergradRequestorProfile::query()
            ->where('student_number', $studentNumber)
            ->whereHas('verification', fn ($q) => $q->where('status', UndergradRequestorVerificationStatusEnum::Approved))
            ->orderByDesc('undergrad_requestor_profile_id')
            ->first();

        if (!$profile) {
            return null;
        }

        return new ResolvedAcademicRecord(
            studentNumber:  (string) $profile->student_number,
            course:         $profile->program,
            graduationDate: null,
            status:         AcademicRecordStatusEnum::Undergraduate,
            requesterType:  RequesterTypeEnum::UndergraduateRequester,
            userId:         (int) $profile->user_id,
        );
    }
}
