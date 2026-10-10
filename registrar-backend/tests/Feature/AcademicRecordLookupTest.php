<?php

use App\Models\Alumni;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\DocumentRequest;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function lookupActAs(int $roleId = SystemUser::ROLE_SUPER_ADMIN): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

function lookupEnrolled(string $number, string $course = 'BS Computer Science'): StudentAcademicRecord
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);

    return StudentAcademicRecord::factory()->create([
        'student_profile_id' => $profile->student_profile_id,
        'student_number'     => $number,
        'course'             => $course,
    ]);
}

function lookupAlumni(string $number, int $year = 2019): SystemUser
{
    $user  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI, 'status' => 'Activated']);
    $alum  = Alumni::create(['user_id' => $user->user_id, 'alumni_type_id' => Alumni::TYPE_SIS]);
    $prof  = AlumniProfile::create([
        'alumni_id' => $alum->alumni_id, 'first_name' => 'Ana', 'last_name' => 'Cruz',
        'date_of_birth' => '1997-01-01', 'sex_at_birth' => 'Female',
    ]);
    AlumniAcademicRecord::create([
        'alumni_profile_id' => $prof->alumni_profile_id, 'student_number' => $number,
        'year_of_graduation' => $year, 'course' => 'BS Accountancy',
    ]);

    return $user;
}

function lookupUndergrad(string $number, bool $approved = true): SystemUser
{
    $user = SystemUser::factory()->undergradRequestor()->create();
    UndergradRequestorProfile::factory()->create([
        'user_id' => $user->user_id, 'student_number' => $number, 'program' => 'BS Information Technology',
    ]);
    UndergradRequestorVerification::factory()
        ->when($approved, fn ($f) => $f->approved())
        ->create(['user_id' => $user->user_id]);

    return $user;
}

test('resolves an enrolled student with the standard response shape', function () {
    lookupActAs();
    lookupEnrolled('2021-00001-MN-0');

    $this->getJson('/api/academic-records/by-student?student_num=2021-00001-MN-0')
        ->assertOk()
        ->assertJsonPath('data.student_number', '2021-00001-MN-0')
        ->assertJsonPath('data.course', 'BS Computer Science')
        ->assertJsonPath('data.graduation_date', null)
        ->assertJsonPath('data.status', 'Enrolled')
        ->assertJsonPath('data.requester_type', 'Student')
        ->assertJsonPath('data.or_number', null)
        ->assertJsonStructure(['data' => ['current_date']])
        ->assertJsonCount(7, 'data');
});

test('enrolled tier wins over alumni when a number exists in both', function () {
    lookupActAs();
    lookupEnrolled('2018-00002-MN-0');
    lookupAlumni('2018-00002-MN-0');

    $this->getJson('/api/academic-records/by-student?student_num=2018-00002-MN-0')
        ->assertOk()
        ->assertJsonPath('data.requester_type', 'Student');
});

test('falls through to alumni with year of graduation', function () {
    lookupActAs();
    lookupAlumni('2015-00003-MN-0', 2019);

    $this->getJson('/api/academic-records/by-student?student_num=2015-00003-MN-0')
        ->assertOk()
        ->assertJsonPath('data.status', 'Alumni')
        ->assertJsonPath('data.requester_type', 'Alumni')
        ->assertJsonPath('data.graduation_date', '2019')
        ->assertJsonPath('data.course', 'BS Accountancy');
});

test('falls through to an approved undergraduate requester', function () {
    lookupActAs();
    lookupUndergrad('2017-00004-MN-0');

    $this->getJson('/api/academic-records/by-student?student_num=2017-00004-MN-0')
        ->assertOk()
        ->assertJsonPath('data.status', 'Undergraduate')
        ->assertJsonPath('data.requester_type', 'Undergraduate Requester')
        ->assertJsonPath('data.course', 'BS Information Technology');
});

test('a pending undergraduate requester is not resolvable', function () {
    lookupActAs();
    lookupUndergrad('2017-00005-MN-0', approved: false);

    $this->getJson('/api/academic-records/by-student?student_num=2017-00005-MN-0')->assertNotFound();
});

test('returns the latest OR number for the resolved account', function () {
    lookupActAs();
    $record = lookupEnrolled('2021-00006-MN-0');
    $userId = $record->studentProfile->user_id;

    DocumentRequest::factory()->create(['user_id' => $userId, 'or_number' => '1111111', 'requested_at' => now()->subDays(3)]);
    DocumentRequest::factory()->create(['user_id' => $userId, 'or_number' => '2222222', 'requested_at' => now()->subDay()]);

    $this->getJson('/api/academic-records/by-student?student_num=2021-00006-MN-0')
        ->assertOk()
        ->assertJsonPath('data.or_number', '2222222');
});

test('unknown student number returns 404', function () {
    lookupActAs();

    $this->getJson('/api/academic-records/by-student?student_num=9999-99999')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('rejects a missing or malformed student number', function (?string $value) {
    lookupActAs();

    $url = '/api/academic-records/by-student' . ($value === null ? '' : '?student_num=' . urlencode($value));

    $this->getJson($url)->assertStatus(422)->assertJsonValidationErrors('student_num');
})->with([
    'missing'       => [null],
    'empty'         => [''],
    'sql chars'     => ["1' OR '1'='1"],
    'wildcard'      => ['2021-%'],
    'too long'      => [str_repeat('1', 51)],
]);

test('array input is rejected', function () {
    lookupActAs();

    $this->getJson('/api/academic-records/by-student?student_num[]=2021-00001')
        ->assertStatus(422);
});

test('requires authentication', function () {
    $this->getJson('/api/academic-records/by-student?student_num=2021-00001')->assertUnauthorized();
});

test('students cannot use the lookup', function () {
    lookupActAs(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/academic-records/by-student?student_num=2021-00001')->assertForbidden();
});

test('the list-all academic records endpoint is no longer exposed', function () {
    lookupActAs();

    $this->getJson('/api/academic-records')->assertStatus(405);
});
