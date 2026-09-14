<?php

use App\DTOs\Alumni\AlumniDTO;

// ═════════════════════════════════════════════════════════════════════════════
// Regression guard: PUPTAPS's Non-SIS support returns stud_number: null for
// self-registered alumni still pending (or already past) Registrar Admin
// verification — on /alumni/lookup, /alumni/{id}, and /alumni alike, per
// ALUMNI_API.md's "Non-SIS Alumni Notes" section. Before this fix,
// AlumniDTO::fromArray() assigned $data['stud_number'] straight into a
// non-nullable `string $studNumber` constructor property with no null
// coalesce, so a real PUPTAPS response for a Non-SIS alumnus threw a
// TypeError and the whole lookup/provisioning call failed outright instead
// of degrading gracefully.
// ═════════════════════════════════════════════════════════════════════════════

function puptapsAlumniPayload(array $overrides = []): array
{
    return array_merge([
        'alumni_id'      => 22,
        'stud_number'    => null,
        'last_name'      => 'Dela Cruz',
        'first_name'     => 'Juan',
        'middle_name'    => null,
        'suffix'         => null,
        'course_id'      => 'BSIT',
        'course_desc'    => 'Bachelor of Science in Information Technology',
        'batch'          => 1998,
        'year_graduated' => '1998-01-01',
        'sex'            => null,
        'birthday'       => null,
        'email'          => 'juan.delacruz@example.com',
        'number'         => null,
        'profile_status' => 'Complete',
    ], $overrides);
}

test('fromArray does not throw when PUPTAPS returns stud_number: null for a Non-SIS alumnus', function () {
    $dto = AlumniDTO::fromArray(puptapsAlumniPayload());

    expect($dto)->toBeInstanceOf(AlumniDTO::class);
    expect($dto->studNumber)->toBe('');
});

test('fromArray still preserves a real stud_number for a SIS alumnus', function () {
    $dto = AlumniDTO::fromArray(puptapsAlumniPayload(['stud_number' => '2018-00123-MN-0']));

    expect($dto->studNumber)->toBe('2018-00123-MN-0');
});

test('fromArray handles the /alumni and /alumni/{id} nested course shape alongside a null stud_number', function () {
    $payload = puptapsAlumniPayload();
    unset($payload['course_desc']);
    $payload['course'] = ['course_desc' => 'Bachelor of Science in Information Technology'];

    $dto = AlumniDTO::fromArray($payload);

    expect($dto->studNumber)->toBe('');
    expect($dto->courseDesc)->toBe('Bachelor of Science in Information Technology');
});

test('toArray round-trips the empty-string stud_number sentinel, matching FakeAlumniSystemClient convention', function () {
    $dto = AlumniDTO::fromArray(puptapsAlumniPayload());

    expect($dto->toArray()['stud_number'])->toBe('');
});
