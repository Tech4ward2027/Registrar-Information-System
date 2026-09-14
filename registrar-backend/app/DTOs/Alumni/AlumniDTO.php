<?php

namespace App\DTOs\Alumni;

class AlumniDTO
{
    public function __construct(
        public readonly int     $alumniId,
        public readonly string  $studNumber,
        public readonly string  $lastName,
        public readonly string  $firstName,
        public readonly ?string $middleName,
        public readonly ?string $suffix,
        public readonly string  $courseId,
        public readonly ?string $courseDesc,
        public readonly int     $batch,
        public readonly ?string $yearGraduated,
        public readonly ?string $sex,
        public readonly ?string $birthday,
        public readonly ?string $email,
        public readonly ?string $number,
        public readonly string  $profileStatus,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            alumniId:      $data['alumni_id'],
            // Non-SIS alumni (self-registered, pending/awaiting a
            // Registrar Admin's manual verification against legacy
            // records) have no student number by design — PUPTAPS
            // returns stud_number: null for them on every endpoint,
            // not just /lookup. Coalescing to '' here matches the
            // existing convention already used by
            // FakeAlumniSystemClient::toDto() (empty string = "no
            // student number"), so every downstream consumer already
            // treats this correctly (nullable DB column, falsy checks
            // in the frontend) without needing its own null handling.
            studNumber:    $data['stud_number'] ?? '',
            lastName:      $data['last_name'],
            firstName:     $data['first_name'],
            middleName:    $data['middle_name'] ?? null,
            suffix:        $data['suffix'] ?? null,
            courseId:      $data['course_id'],
            // /alumni and /alumni/{id} (full detail) nest this under a
            // 'course' relation object; /alumni/lookup (added for the
            // RIS login-check flow) returns it as a flat key instead.
            // Support both so this DTO works against either shape.
            courseDesc:    $data['course']['course_desc'] ?? $data['course_desc'] ?? null,
            batch:         $data['batch'],
            yearGraduated: $data['year_graduated'] ?? null,
            sex:           $data['sex'] ?? null,
            birthday:      $data['birthday'] ?? null,
            email:         $data['email'] ?? null,
            number:        $data['number'] ?? null,
            profileStatus: $data['profile_status'],
        );
    }

    public static function collectionFromArray(array $items): array
    {
        return array_map(fn(array $item) => self::fromArray($item), $items);
    }

    public function toArray(): array
    {
        return [
            'alumni_id'      => $this->alumniId,
            'stud_number'    => $this->studNumber,
            'last_name'      => $this->lastName,
            'first_name'     => $this->firstName,
            'middle_name'    => $this->middleName,
            'suffix'         => $this->suffix,
            'course_id'      => $this->courseId,
            'course_desc'    => $this->courseDesc,
            'batch'          => $this->batch,
            'year_graduated' => $this->yearGraduated,
            'sex'            => $this->sex,
            'birthday'       => $this->birthday,
            'email'          => $this->email,
            'number'         => $this->number,
            'profile_status' => $this->profileStatus,
        ];
    }
}