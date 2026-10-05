<?php

use App\Services\CashierFailureDiagnosisService as Dx;

/*
|--------------------------------------------------------------------------
| CashierFailureDiagnosisService — pure, table-driven (Phase 2b)
|--------------------------------------------------------------------------
| No database, no HTTP: the service has no I/O, so these are plain input →
| output assertions. The name cases (ñ, "JR." in the surname, hyphens, a
| missing middle name, all-caps) double as the evaluation cases for the
| diagnosis-code agreement study in Phase 7.
*/

function dxProfile(array $o = []): array
{
    return array_merge([
        'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'suffix' => '',
    ], $o);
}

function dxSnapshot(array $o = []): array
{
    return array_merge(dxProfile(), $o);
}

const DX_NOT_FOUND = [['name' => 'DELA CRUZ, JUAN S.', 'valid' => false, 'reason' => 'NOT_FOUND']];

function dxRun(array $profile, ?array $snapshot = null, ?string $sourceFailure = null, array $attempts = DX_NOT_FOUND): array
{
    return (new Dx())->diagnose($profile, $snapshot, $sourceFailure, $attempts);
}

// ── format flags ─────────────────────────────────────────────────────────

dataset('format cases', [
    'ñ in the surname'            => [dxProfile(['last_name' => 'Peña']),            Dx::FMT_NON_ASCII],
    'ñ in the first name'         => [dxProfile(['first_name' => 'Niño']),           Dx::FMT_NON_ASCII],
    'JR. in the surname'          => [dxProfile(['last_name' => 'Santos JR.']),      Dx::FMT_SUFFIX_IN_SURNAME],
    'Jr after a comma'            => [dxProfile(['last_name' => 'Reyes, Jr']),       Dx::FMT_SUFFIX_IN_SURNAME],
    'roman numeral III'           => [dxProfile(['last_name' => 'Garcia III']),      Dx::FMT_SUFFIX_IN_SURNAME],
    'hyphenated surname'          => [dxProfile(['last_name' => 'Reyes-Santos']),    Dx::FMT_HYPHEN_OR_SPACING],
    'hyphenated first name'       => [dxProfile(['first_name' => 'Mary-Ann']),       Dx::FMT_HYPHEN_OR_SPACING],
    'multi-word surname'          => [dxProfile(['last_name' => 'De la Cruz']),      Dx::FMT_HYPHEN_OR_SPACING],
    'doubled internal space'      => [dxProfile(['first_name' => 'Juan  Carlos']),   Dx::FMT_HYPHEN_OR_SPACING],
    'trailing whitespace'         => [dxProfile(['first_name' => 'Juan ']),          Dx::FMT_HYPHEN_OR_SPACING],
    'all-caps surname'            => [dxProfile(['last_name' => 'DELA CRUZ']),       Dx::FMT_CASE_INCONSISTENT],
    'all-lowercase first name'    => [dxProfile(['first_name' => 'juan']),           Dx::FMT_CASE_INCONSISTENT],
]);

it('flags the expected format factor', function (array $profile, string $expected) {
    expect(dxRun($profile))->toContain($expected);
})->with('format cases');

it('does not flag a clean, single-word, mixed-case ASCII name', function () {
    $codes = dxRun(dxProfile(['last_name' => 'Santos']));

    expect($codes)->not->toContain(Dx::FMT_NON_ASCII)
        ->not->toContain(Dx::FMT_SUFFIX_IN_SURNAME)
        ->not->toContain(Dx::FMT_HYPHEN_OR_SPACING)
        ->not->toContain(Dx::FMT_CASE_INCONSISTENT);
});

it('does not treat a surname that merely ends in a suffix-like letter sequence as a suffix', function () {
    // "Vivi", "Ivy" must not match the roman-numeral suffix pattern.
    expect(dxRun(dxProfile(['last_name' => 'Vivi'])))->not->toContain(Dx::FMT_SUFFIX_IN_SURNAME)
        ->and(dxRun(dxProfile(['last_name' => 'Ivy'])))->not->toContain(Dx::FMT_SUFFIX_IN_SURNAME);
});

it('does not call a two-letter-or-shorter single letter a case problem', function () {
    // Middle initial "S" alone is not "all caps" evidence.
    expect(dxRun(dxProfile(['middle_name' => 'S'])))->not->toContain(Dx::FMT_CASE_INCONSISTENT);
});

// ── FMT_MISSING_MIDDLE and PROFILE_DRIFT need a snapshot ──────────────────

it('flags a missing middle name only when OGOS has one and the local profile does not', function () {
    expect(dxRun(dxProfile(['middle_name' => '']), dxSnapshot(['middle_name' => 'Santos'])))
        ->toContain(Dx::FMT_MISSING_MIDDLE);

    // Both lack it: nothing to say.
    expect(dxRun(dxProfile(['middle_name' => '']), dxSnapshot(['middle_name' => ''])))
        ->not->toContain(Dx::FMT_MISSING_MIDDLE);

    // No snapshot: cannot know, so never guess.
    expect(dxRun(dxProfile(['middle_name' => ''])))->not->toContain(Dx::FMT_MISSING_MIDDLE);
});

it('flags PROFILE_DRIFT when OGOS holds a different name, ignoring case, spacing and periods', function () {
    expect(dxRun(dxProfile(), dxSnapshot(['last_name' => 'Dela Cruz-Reyes'])))->toContain(Dx::PROFILE_DRIFT);
    expect(dxRun(dxProfile(), dxSnapshot(['suffix' => 'Jr.'])))->toContain(Dx::PROFILE_DRIFT);

    // Cosmetic-only differences are NOT drift.
    expect(dxRun(dxProfile(), dxSnapshot(['last_name' => 'DELA  CRUZ'])))->not->toContain(Dx::PROFILE_DRIFT);
    expect(dxRun(dxProfile(['suffix' => 'Jr']), dxSnapshot(['suffix' => 'JR.'])))->not->toContain(Dx::PROFILE_DRIFT);

    // No snapshot: no drift verdict.
    expect(dxRun(dxProfile()))->not->toContain(Dx::PROFILE_DRIFT);
});

it('flags a case-only difference from OGOS as a case factor, not as drift', function () {
    $codes = dxRun(dxProfile(['last_name' => 'Dela Cruz']), dxSnapshot(['last_name' => 'dela cruz']));

    expect($codes)->toContain(Dx::FMT_CASE_INCONSISTENT)->not->toContain(Dx::PROFILE_DRIFT);
});

// ── source codes ─────────────────────────────────────────────────────────

dataset('source failures', [
    'OGOS 404'          => [Dx::SOURCE_FAILURE_OGOS_NOT_FOUND,   Dx::OGOS_NOT_FOUND],
    'OGOS retries out'  => [Dx::SOURCE_FAILURE_OGOS_UNREACHABLE, Dx::OGOS_UNREACHABLE],
    'alumni lookup'     => [Dx::SOURCE_FAILURE_ALUMNI,           Dx::ALUMNI_LOOKUP_FAILED],
    'no profile'        => [Dx::SOURCE_FAILURE_NO_PROFILE,       Dx::NO_SNAPSHOT],
    'unknown reason'    => ['SOMETHING_NEW',                     Dx::NO_SNAPSHOT],
    'null reason'       => [null,                                Dx::NO_SNAPSHOT],
]);

it('maps a missing snapshot to exactly one source code', function (?string $failure, string $expected) {
    $codes = dxRun(dxProfile(), null, $failure);

    $source = array_intersect($codes, [
        Dx::OGOS_NOT_FOUND, Dx::OGOS_UNREACHABLE, Dx::ALUMNI_LOOKUP_FAILED, Dx::NO_SNAPSHOT,
    ]);

    expect(array_values($source))->toBe([$expected]);
})->with('source failures');

it('emits no source code when a snapshot was obtained', function () {
    $codes = dxRun(dxProfile(), dxSnapshot());

    expect($codes)->not->toContain(Dx::NO_SNAPSHOT)
        ->not->toContain(Dx::OGOS_NOT_FOUND)
        ->not->toContain(Dx::OGOS_UNREACHABLE)
        ->not->toContain(Dx::ALUMNI_LOOKUP_FAILED);
});

// ── ALL_CANDIDATES_EXHAUSTED ─────────────────────────────────────────────

it('flags ALL_CANDIDATES_EXHAUSTED only when every attempt was a clean NOT_FOUND', function () {
    $notFound = ['valid' => false, 'reason' => 'NOT_FOUND'];

    expect(dxRun(dxProfile(), null, null, [$notFound, $notFound, $notFound]))->toContain(Dx::ALL_CANDIDATES_EXHAUSTED);

    // One API_ERROR means the outage, not the name, may be to blame.
    expect(dxRun(dxProfile(), null, null, [$notFound, ['valid' => false, 'reason' => 'API_ERROR']]))
        ->not->toContain(Dx::ALL_CANDIDATES_EXHAUSTED);

    // A valid attempt is not a failure to explain.
    expect(dxRun(dxProfile(), null, null, [$notFound, ['valid' => true, 'reason' => null]]))
        ->not->toContain(Dx::ALL_CANDIDATES_EXHAUSTED);

    // No attempts recorded: no claim.
    expect(dxRun(dxProfile(), null, null, []))->not->toContain(Dx::ALL_CANDIDATES_EXHAUSTED);
});

// ── contract ─────────────────────────────────────────────────────────────

it('is deterministic, returns unique codes, and orders source then data then format', function () {
    $profile  = dxProfile(['last_name' => 'PEÑA-SANTOS JR.']);
    $snapshot = dxSnapshot(['last_name' => 'Peña-Santos', 'suffix' => 'Jr.']);

    $first  = dxRun($profile, $snapshot);
    $second = dxRun($profile, $snapshot);

    expect($first)->toBe($second)->and($first)->toBe(array_values(array_unique($first)));

    $rank = fn (string $c) => match (true) {
        in_array($c, [Dx::OGOS_NOT_FOUND, Dx::OGOS_UNREACHABLE, Dx::ALUMNI_LOOKUP_FAILED, Dx::NO_SNAPSHOT], true) => 0,
        in_array($c, [Dx::PROFILE_DRIFT, Dx::ALL_CANDIDATES_EXHAUSTED], true) => 1,
        default => 2,
    };
    $ranks = array_map($rank, $first);
    $sorted = $ranks;
    sort($sorted);

    expect($ranks)->toBe($sorted);
});

it('tolerates null and missing name parts', function () {
    expect(fn () => dxRun(['first_name' => null, 'last_name' => null]))->not->toThrow(Throwable::class);
    expect(fn () => dxRun([]))->not->toThrow(Throwable::class);
});
