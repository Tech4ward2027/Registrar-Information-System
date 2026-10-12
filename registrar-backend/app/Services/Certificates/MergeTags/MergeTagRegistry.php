<?php

namespace App\Services\Certificates\MergeTags;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The ONLY merge tags a certificate template may use, and how each resolves.
 *
 * A template is never evaluated as code: tags are looked up here by exact
 * name, so an unknown tag is a validation error rather than something that
 * could reach a function or property. Adding a tag means adding it here.
 *
 * Sources (see MergeTag): backend values come from trusted records and can
 * never be overridden by request input; manual values are the Registrar's own
 * entries; computed values are derived below. This mirrors the fields the
 * legacy CERT_CONFIG templates read, so existing wording can be migrated 1:1.
 */
class MergeTagRegistry
{
    /** Controlled formatting: the only filters a tag may carry — {{ full_name|upper }}. */
    public const FILTERS = ['upper', 'lower', 'title'];

    private const MAX_VALUE_LENGTH = 500;

    /** @var array<string, MergeTag>|null */
    private ?array $tags = null;

    /** @return array<string, MergeTag> */
    public function all(): array
    {
        return $this->tags ??= $this->build();
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): ?MergeTag
    {
        return $this->all()[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** Names the Registrar may supply at generation time (nothing else is accepted from input). */
    public function manualNames(): array
    {
        return array_keys(array_filter($this->all(), fn (MergeTag $t) => $t->source === MergeTag::SOURCE_MANUAL));
    }

    /**
     * Resolve one tag to its display string, or null when it has no value.
     *
     * @param array<string, mixed> $data
     */
    public function value(string $name, array $data): ?string
    {
        $tag = $this->get($name);
        if ($tag === null) {
            return null;
        }

        try {
            $raw = $tag->compute ? ($tag->compute)($data) : ($data[$name] ?? null);
        } catch (Throwable) {
            return null; // a malformed input value must not break rendering of the rest
        }

        return $this->normalize($raw);
    }

    public function applyFilter(string $value, ?string $filter): string
    {
        return match ($filter) {
            'upper' => mb_strtoupper($value),
            'lower' => mb_strtolower($value),
            'title' => $this->titleCase($value),
            default => $value,
        };
    }

    /** @return list<array<string, string>> */
    public function toArray(): array
    {
        return array_values(array_map(fn (MergeTag $t) => $t->toArray(), $this->all()));
    }

    // ------------------------------------------------------------------

    private function normalize(mixed $raw): ?string
    {
        if ($raw === null || is_array($raw) || is_object($raw)) {
            return null;
        }
        $value = is_bool($raw) ? ($raw ? 'Yes' : 'No') : (string) $raw;
        // Strip control characters, collapse whitespace, cap the length.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, self::MAX_VALUE_LENGTH);
    }

    private function titleCase(string $value): string
    {
        return implode(' ', array_map(function (string $token) {
            if (preg_match('/^[IVXLCDM]+$/i', $token)) {
                return mb_strtoupper($token); // keep roman numerals (III, IV)
            }

            return preg_replace_callback(
                "/(^|[-'])(\p{L})/u",
                fn (array $m) => $m[1] . mb_strtoupper($m[2]),
                mb_strtolower($token)
            );
        }, preg_split('/\s+/u', trim($value)) ?: []));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, config('app.display_timezone', 'Asia/Manila'));
    }

    /** @return array<string, MergeTag> */
    private function build(): array
    {
        $B = MergeTag::SOURCE_BACKEND;
        $M = MergeTag::SOURCE_MANUAL;
        $C = MergeTag::SOURCE_COMPUTED;

        $defs = [
            // ── Resolved by the server (never from request input) ─────────────
            new MergeTag('full_name', 'Full name', $B, 'Requester full name from their profile.', null, 'Juan Santos Dela Cruz'),
            new MergeTag('student_number', 'Student number', $B, 'Student number from the academic record.', null, '2019-00123-TG-0'),
            new MergeTag('course', 'Course / program', $B, 'Course from the academic record.', null, 'Bachelor of Science in Information Technology'),
            new MergeTag('date_graduated', 'Date graduated', $B, 'Graduation date (the system stores the year only).', null, '2019'),
            new MergeTag('official_receipt_number', 'Official receipt number', $B, "The requester's latest OR number.", null, '0012345'),
            new MergeTag('issue_date', 'Issue date', $B, 'Date of issuance (ISO date).', null, '2026-03-28'),
            new MergeTag('control_number', 'Control number', $B, 'Unique certificate control number.', null, 'PUP-CERT-2026-0042'),
            new MergeTag('signee_name', 'Signee name', $B, 'Selected signatory name.', null, 'Juan Reyes'),
            new MergeTag('signee_position', 'Signee position', $B, 'Selected signatory position.', null, 'Campus Registrar'),

            // ── Entered by the Registrar at generation time ───────────────────
            new MergeTag('education_level', 'Education level', $M, 'e.g. graduate / undergraduate.', null, 'graduate'),
            new MergeTag('major', 'Major', $M, 'Major or specialization.', null, 'Information Technology'),
            new MergeTag('gwa', 'GWA', $M, 'General weighted average.', null, '1.4500'),
            new MergeTag('units', 'Units', $M, 'Number of academic units.', null, '162'),
            new MergeTag('latin_honors', 'Latin honors', $M, 'Latin honor, e.g. Magna Cum Laude.', null, 'Magna Cum Laude'),
            new MergeTag('semesters', 'Semester(s)', $M, 'Semester(s) covered.', null, 'First Semester'),
            new MergeTag('semesters_num', 'Years / semesters (number)', $M, 'Program length figure.', null, '4'),
            new MergeTag('last_semesters', 'Last semester(s)', $M, 'Last semester attended.', null, 'Second Semester'),
            new MergeTag('sy_admitted', 'School year admitted', $M, 'e.g. 2019-2020.', null, '2019-2020'),
            new MergeTag('last_sy', 'Last school year', $M, 'Last school year attended.', null, '2022-2023'),
            new MergeTag('diploma_num', 'Diploma number', $M, 'Diploma number.', null, 'D-001234'),
            new MergeTag('ladderized_degree', 'Ladderized degree', $M, 'Ladderized degree.', null, 'Associate in Computer Technology'),
            new MergeTag('student_status', 'Student status', $M, 'Student status.', null, 'Regular'),
            new MergeTag('eligibility_type', 'Eligibility type', $M, 'Eligibility type.', null, 'Honorable dismissal'),
            new MergeTag('cav_num', 'CAV number', $M, 'CAV number.', null, '2026-0042'),
            new MergeTag('cav_series', 'CAV series', $M, 'CAV series.', null, '2026'),
            new MergeTag('nstp_serial_num', 'NSTP serial number', $M, 'NSTP serial number.', null, 'NSTP-123'),
            new MergeTag('amount', 'Amount', $M, 'Amount paid.', null, '250.00'),

            // ── Computed ──────────────────────────────────────────────────────
            new MergeTag('issue_date_formal', 'Issue date (formal)', $C, 'Issue date as "March 28, 2026".',
                fn (array $d) => $this->date($d['issue_date'] ?? null)?->format('F j, Y'), 'March 28, 2026'),
            new MergeTag('issue_date_ordinal', 'Issue date (ordinal)', $C, 'Issue date as "28th day of March 2026".',
                fn (array $d) => ($x = $this->date($d['issue_date'] ?? null)) ? $x->format('jS') . ' day of ' . $x->format('F Y') : null,
                '28th day of March 2026'),
            new MergeTag('date_graduated_formal', 'Date graduated (formal)', $C, 'Full date when one is stored, otherwise the year.',
                function (array $d) {
                    $v = $d['date_graduated'] ?? null;
                    if (is_string($v) && preg_match('/^\d{4}$/', trim($v))) {
                        return trim($v);
                    }

                    return $this->date($v)?->format('F j, Y');
                }, '2019'),
            new MergeTag('batch_year', 'Batch year', $C, 'Year of graduation.',
                function (array $d) {
                    $v = $d['date_graduated'] ?? null;
                    if (is_string($v) && preg_match('/^(\d{4})/', trim($v), $m)) {
                        return $m[1];
                    }

                    return null;
                }, '2019'),
            new MergeTag('current_year', 'Current year', $C, 'The year of issuance.',
                fn (array $d) => ($this->date($d['issue_date'] ?? null) ?? CarbonImmutable::now(config('app.display_timezone', 'Asia/Manila')))->format('Y'),
                '2026'),
            new MergeTag('salutation_name', 'Salutation and last name', $C, 'e.g. "Mr./Ms. Dela Cruz", keeping a known title.',
                function (array $d) {
                    $name = trim((string) ($d['full_name'] ?? ''));
                    if ($name === '') {
                        return null;
                    }
                    $parts = preg_split('/\s+/u', $name) ?: [];
                    $last  = end($parts);
                    if (count($parts) === 1) {
                        return $parts[0];
                    }
                    $first = mb_strtolower(rtrim($parts[0], '.'));
                    if (in_array($first, ['mr', 'ms', 'mrs', 'mx', 'dr', 'prof'], true)) {
                        return $parts[0] . ' ' . $last;
                    }

                    return 'Mr./Ms. ' . $last;
                }, 'Mr./Ms. Cruz'),
            new MergeTag('honor_name', 'Honor name', $C, 'Latin honors without parentheses.',
                function (array $d) {
                    $v = trim(str_replace(['(', ')'], '', (string) ($d['latin_honors'] ?? '')));

                    return $v === '' ? null : $v;
                }, 'Magna Cum Laude'),
            new MergeTag('gwa_range', 'GWA range for the honor', $C, 'GWA band implied by the honor (or the GWA).',
                function (array $d) {
                    $honor = mb_strtolower(trim(str_replace(['(', ')'], '', (string) ($d['latin_honors'] ?? ''))));
                    if (str_contains($honor, 'summa')) {
                        return '1.0000 to 1.1500';
                    }
                    if (str_contains($honor, 'magna')) {
                        return '1.1501 to 1.3500';
                    }
                    if (str_contains($honor, 'cum laude')) {
                        return '1.3501 to 1.6000';
                    }
                    $gwa = $d['gwa'] ?? null;
                    if (is_numeric($gwa)) {
                        $g = (float) $gwa;
                        if ($g <= 1.15) {
                            return '1.0000 to 1.1500';
                        }
                        if ($g <= 1.35) {
                            return '1.1501 to 1.3500';
                        }
                        if ($g <= 1.60) {
                            return '1.3501 to 1.6000';
                        }
                    }

                    return '1.3501 to 1.6000';
                }, '1.1501 to 1.3500'),
        ];

        $map = [];
        foreach ($defs as $tag) {
            // A registry typo (duplicate / non snake_case name) must fail loudly in tests.
            assert(!isset($map[$tag->name]) && Str::snake($tag->name) === $tag->name);
            $map[$tag->name] = $tag;
        }

        return $map;
    }
}
