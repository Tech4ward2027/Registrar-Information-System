<?php

namespace App\Services\Certificates;

use App\DTOs\AcademicRecords\ResolvedAcademicRecord;
use App\Enums\RequesterTypeEnum;
use App\Models\Alumni;
use App\Models\Signatory;
use App\Models\StudentProfile;
use App\Models\UndergradRequestorProfile;
use App\Services\Certificates\MergeTags\MergeTagRegistry;
use Carbon\CarbonImmutable;

/**
 * Builds the merge-data array a template is rendered with.
 *
 * Trust boundary: everything identifying (name, student number, course,
 * graduation date, OR number, signee, issue date, control number) comes from
 * the server. The Registrar's request may only supply the registry's MANUAL
 * tags; anything else is dropped here as a second line of defence behind the
 * request validation, and server values are applied last so they always win.
 */
class CertificateDataResolver
{
    public function __construct(private readonly MergeTagRegistry $registry) {}

    /**
     * @param array<string, mixed> $manual Registrar-entered values (already validated)
     * @return array<string, string|null>
     */
    public function build(
        ResolvedAcademicRecord $record,
        ?string $orNumber,
        ?Signatory $signatory,
        array $manual,
        CarbonImmutable $now,
    ): array {
        $manualValues = [];
        foreach ($this->registry->manualNames() as $name) {
            if (isset($manual[$name]) && is_scalar($manual[$name])) {
                $manualValues[$name] = trim((string) $manual[$name]);
            }
        }

        $server = [
            'full_name'               => $this->fullName($record),
            'student_number'          => $record->studentNumber,
            'course'                  => $record->course,
            'date_graduated'          => $record->graduationDate,
            'official_receipt_number' => $orNumber,
            'issue_date'              => $now->toDateString(),
            'signee_name'             => $signatory?->name,
            'signee_position'         => $signatory?->position,
        ];

        return array_merge($manualValues, $server);
    }

    /** The account holder's name from their own profile, never from input. */
    public function fullName(ResolvedAcademicRecord $record): ?string
    {
        if ($record->userId === null) {
            return null;
        }

        $profile = match ($record->requesterType) {
            RequesterTypeEnum::Student => StudentProfile::query()->where('user_id', $record->userId)->first(),
            RequesterTypeEnum::Alumni  => Alumni::query()->with('profile')->where('user_id', $record->userId)->first()?->profile,
            RequesterTypeEnum::UndergraduateRequester => UndergradRequestorProfile::query()
                ->where('user_id', $record->userId)
                ->orderByDesc('undergrad_requestor_profile_id')
                ->first(),
        };

        if ($profile === null) {
            return null;
        }

        $parts = array_filter(
            array_map(
                fn ($part) => is_string($part) ? trim($part) : '',
                [$profile->first_name, $profile->middle_name, $profile->last_name, $profile->suffix]
            ),
            fn (string $part) => $part !== ''
        );

        return $parts === [] ? null : implode(' ', $parts);
    }
}
