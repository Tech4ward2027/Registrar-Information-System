<?php

use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function p0DocType(string $name, array $patterns, bool $archived = false): DocumentType
{
    return DocumentType::create([
        'document_name'             => $name,
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => $patterns,
        'is_archived'               => $archived,
    ]);
}

function p0CertType(string $name, array $patterns): CertificationType
{
    return CertificationType::create([
        'certificate_name'           => $name,
        'certificate_requirements'   => '',
        'certificate_process_period' => '3 days',
        'access_id'                  => 1,
        'cashier_document_patterns'  => $patterns,
    ]);
}

function p0ResolvedAudit(string $label, string $type, int $id, string $action = AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED): AuditLog
{
    return AuditLog::create([
        'user_id'    => null,
        'email'      => 'system@ris.local',
        'role_name'  => 'admin',
        'action'     => $action,
        'created_at' => now(),
        'metadata'   => [
            'raw_label'        => $label,
            'attached_to_type' => $type,
            'attached_to_id'   => $id,
        ],
    ]);
}

/** @return list<array<string,string>> */
function p0ReadCsv(string $path): array
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, Storage::disk('local')->get($path));
    rewind($stream);

    $header = fgetcsv($stream, 0, ',', '"', '');
    $rows   = [];
    while (($r = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = array_combine($header, $r);
    }
    fclose($stream);

    return $rows;
}

/**
 * @param  list<array<string,string>> $rows
 * @return list<array<string,string>>
 */
function p0RowsForTarget(array $rows, string $type, int $id): array
{
    return array_values(array_filter(
        $rows,
        fn (array $r) => $r['target_type'] === $type && (int) $r['target_id'] === $id
    ));
}

test('exports patterns of active types and skips archived types', function () {
    p0DocType('Transcript of Records', ['TOR', 'Transcript of Records']);
    p0DocType('Old Archived Type', ['Legacy Label'], archived: true);
    p0CertType('Good Moral Certificate', ['Good Moral']);

    $this->artisan('cashier:export-label-eval', ['--output' => 'eval/test.csv'])->assertSuccessful();

    $rows   = p0ReadCsv('eval/test.csv');
    $labels = array_column($rows, 'label');

    expect($labels)->toContain('TOR', 'Transcript of Records', 'Good Moral')
        ->and($labels)->not->toContain('Legacy Label')
        ->and(array_unique(array_column($rows, 'source')))->toBe(['pattern']);
});

test('resolved audit entries are exported as source=resolved and not double counted as patterns', function () {
    // Resolving an item appends its label to the type's pattern list, so the
    // same label exists in BOTH places; it must appear exactly once.
    // NOTE: migrations seed real catalog rows, so the CSV is never empty in
    // tests. Assert only on the rows that belong to the type created here.
    $doc = p0DocType('Informative Copy of Grades', ['Info. Copy of Grades']);
    p0ResolvedAudit('Info. Copy of Grades', 'document', $doc->document_type_id);

    $this->artisan('cashier:export-label-eval', ['--output' => 'eval/test.csv'])->assertSuccessful();

    $mine = p0RowsForTarget(p0ReadCsv('eval/test.csv'), 'document', $doc->document_type_id);

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['source'])->toBe('resolved')
        ->and($mine[0]['label'])->toBe('Info. Copy of Grades')
        ->and($mine[0]['target_name'])->toBe('Informative Copy of Grades');
});

test('dismissals and resolutions pointing at archived or missing types are not exported', function () {
    $archived = p0DocType('Archived Type', [], archived: true);
    $active   = p0DocType('Active Type', []);

    p0ResolvedAudit('Dismissed Label', 'document', $active->document_type_id, AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED);
    p0ResolvedAudit('To Archived', 'document', $archived->document_type_id);
    p0ResolvedAudit('To Missing', 'certificate', 999999);

    $this->artisan('cashier:export-label-eval', ['--output' => 'eval/test.csv'])->assertSuccessful();

    $labels = array_column(p0ReadCsv('eval/test.csv'), 'label');

    expect($labels)->not->toContain('Dismissed Label', 'To Archived', 'To Missing');
});

test('spreadsheet formula characters in labels are neutralised', function () {
    p0DocType('Some Type', ['=HYPERLINK("http://evil.example")', '@SUM(A1)']);

    $this->artisan('cashier:export-label-eval', ['--output' => 'eval/test.csv'])->assertSuccessful();

    $labels = array_column(p0ReadCsv('eval/test.csv'), 'label');

    expect($labels)->toContain("'=HYPERLINK(\"http://evil.example\")", "'@SUM(A1)");
});

test('output path outside the storage disk is rejected', function () {
    foreach (['../escape.csv', '/etc/passwd', 'C:\\temp\\x.csv'] as $bad) {
        expect(fn () => Artisan::call('cashier:export-label-eval', ['--output' => $bad]))
            ->toThrow(InvalidArgumentException::class);
    }
});

test('default output path is written under eval/ and exports no personal-data columns', function () {
    p0DocType('Some Type', ['Some Label']);

    $this->artisan('cashier:export-label-eval')->assertSuccessful();

    $files = Storage::disk('local')->files('eval');
    expect($files)->toHaveCount(1);

    $header = array_keys(p0ReadCsv($files[0])[0]);
    expect($header)->toBe(['source', 'label', 'normalised_label', 'target_type', 'target_id', 'target_name']);
});