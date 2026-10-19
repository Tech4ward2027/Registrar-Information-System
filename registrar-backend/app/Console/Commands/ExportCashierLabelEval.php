<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\UnmatchedCashierItem;
use App\Services\CashierLabelNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * cashier:export-label-eval
 *
 * Builds the labeled ground-truth CSV (label -> correct type) that every
 * later measurement of the label-suggestion feature is computed from. It is
 * read-only: it never writes to the database and never calls any external
 * service.
 *
 * Sources (the `source` column)
 * -----------------------------
 *   resolved   Labels an admin actually attached to a type through the
 *              Unmatched Cashier Items screen. Ground truth is the
 *              `unmatched_cashier_item_resolved` audit entry (what the admin
 *              chose at the time), not whatever the pattern list looks like
 *              today. These are REAL drift examples and the most valuable
 *              rows. Dismissals are excluded (no target type).
 *   pattern    Every entry in cashier_document_patterns of a non-archived
 *              document/certificate type that is NOT already covered by a
 *              `resolved` row. (Resolving an item appends its label to the
 *              type's pattern list, so without this de-duplication the same
 *              label would be counted twice and inflate n.)
 *   synthetic  Reserved. Synthetic drift variants are generated in the
 *              evaluation phase and merged into the same CSV shape; this
 *              command intentionally emits none, so reported n for
 *              real-world sources is never mixed with generated data.
 *
 * Privacy: receipt line-item labels only (e.g. "Informative Copy of
 * Grades"). No names, OR numbers, amounts, emails or user ids are read or
 * written. The resolving admin's identity is deliberately not exported.
 *
 * CSV safety: any cell that starts with = + - @ TAB or CR is prefixed with
 * a single quote so the file is safe to open in a spreadsheet (CSV/formula
 * injection, OWASP). Consumers should strip one leading apostrophe when
 * reading the `label` column programmatically.
 */
class ExportCashierLabelEval extends Command
{
    public const SOURCE_PATTERN   = 'pattern';
    public const SOURCE_RESOLVED  = 'resolved';
    public const SOURCE_SYNTHETIC = 'synthetic';

    protected $signature = 'cashier:export-label-eval
        {--output= : Relative path on the local (private) storage disk. Default: eval/cashier-label-eval-<timestamp>.csv}';

    protected $description = 'Export a labeled CSV (label -> correct type) for evaluating cashier label suggestions';

    private const HEADER = [
        'source', 'label', 'normalised_label', 'target_type', 'target_id', 'target_name',
    ];

    public function handle(): int
    {
        // target_type|target_id => display name, for ACTIVE (non-archived) types only.
        $targets = $this->activeTargets();

        $rows        = [];           // keyed by "<normalised>|<type>|<id>" => row (dedupe)
        $skipped     = ['inactive_target' => 0, 'malformed' => 0];
        $labelOwners = [];           // normalised label => set of "<type>|<id>" (conflict detection)

        // ── (b) resolved: real admin decisions, from the audit trail ──────────
        AuditLog::query()
            ->where('action', AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED)
            ->orderBy('id')
            ->lazyById(500, 'id')
            ->each(function (AuditLog $log) use (&$rows, &$skipped, &$labelOwners, $targets) {
                $meta  = is_array($log->metadata) ? $log->metadata : [];
                $label = trim((string) ($meta['raw_label'] ?? ''));
                $type  = (string) ($meta['attached_to_type'] ?? '');
                $id    = (int) ($meta['attached_to_id'] ?? 0);

                if ($label === '' || !in_array($type, ['document', 'certificate'], true) || $id <= 0) {
                    $skipped['malformed']++;
                    return;
                }

                $key = "{$type}|{$id}";
                if (!isset($targets[$key])) {
                    // Type since archived/deleted: not a valid "correct answer"
                    // for a suggester that only ranks active types.
                    $skipped['inactive_target']++;
                    return;
                }

                $this->addRow($rows, $labelOwners, self::SOURCE_RESOLVED, $label, $type, $id, $targets[$key]);
            });

        // ── (a) pattern: every other stored pattern on an active type ─────────
        foreach ($this->patternSources() as [$type, $id, $name, $patterns]) {
            foreach ($patterns as $pattern) {
                $label = trim((string) $pattern);
                if ($label === '') {
                    continue;
                }
                $this->addRow($rows, $labelOwners, self::SOURCE_PATTERN, $label, $type, $id, $name);
            }
        }

        // A label owned by more than one type breaks the "one label -> one
        // type" invariant (CashierPatternConflictChecker). Surface it: it
        // would make a "correct type" ambiguous in the evaluation.
        $conflicting = array_filter($labelOwners, fn (array $owners) => count($owners) > 1);

        $path = $this->writeCsv(array_values($rows));

        $this->report($rows, $skipped, count($conflicting), $path);

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>  "<type>|<id>" => display name
     */
    private function activeTargets(): array
    {
        $targets = [];

        DocumentType::query()->where('is_archived', false)
            ->get(['document_type_id', 'document_name'])
            ->each(function ($t) use (&$targets) {
                $targets["document|{$t->document_type_id}"] = (string) $t->document_name;
            });

        CertificationType::query()->where('is_archived', false)
            ->get(['certificate_type_id', 'certificate_name'])
            ->each(function ($t) use (&$targets) {
                $targets["certificate|{$t->certificate_type_id}"] = (string) $t->certificate_name;
            });

        return $targets;
    }

    /**
     * @return \Generator<int, array{0:string,1:int,2:string,3:array}>
     */
    private function patternSources(): \Generator
    {
        $docs = DocumentType::query()->where('is_archived', false)
            ->get(['document_type_id', 'document_name', 'cashier_document_patterns']);
        foreach ($docs as $t) {
            yield ['document', (int) $t->document_type_id, (string) $t->document_name, $this->decodePatterns($t->cashier_document_patterns)];
        }

        $certs = CertificationType::query()->where('is_archived', false)
            ->get(['certificate_type_id', 'certificate_name', 'cashier_document_patterns']);
        foreach ($certs as $t) {
            yield ['certificate', (int) $t->certificate_type_id, (string) $t->certificate_name, $this->decodePatterns($t->cashier_document_patterns)];
        }
    }

    /**
     * Add a row unless the same (normalised label, target) pair is already
     * present. First writer wins; `resolved` rows are added before `pattern`
     * rows, so resolved takes precedence on overlap.
     */
    private function addRow(array &$rows, array &$labelOwners, string $source, string $label, string $type, int $id, string $name): void
    {
        $normalised = CashierLabelNormalizer::normalize($label);
        if ($normalised === '') {
            return;
        }

        $labelOwners[$normalised]["{$type}|{$id}"] = true;

        $key = "{$normalised}|{$type}|{$id}";
        if (isset($rows[$key])) {
            return;
        }

        $rows[$key] = [
            'source'           => $source,
            'label'            => $label,
            'normalised_label' => $normalised,
            'target_type'      => $type,
            'target_id'        => $id,
            'target_name'      => $name,
        ];
    }

    private function decodePatterns(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return string  Path written, relative to the local storage disk root.
     */
    private function writeCsv(array $rows): string
    {
        $path = $this->resolveOutputPath();

        $stream = fopen('php://temp', 'w+b');
        // PHP 8.4 deprecates the default $escape; pass it explicitly ('' = RFC 4180 behaviour).
        fputcsv($stream, self::HEADER, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map([$this, 'neutraliseCell'], array_values($row)), ',', '"', '');
        }
        rewind($stream);

        Storage::disk('local')->put($path, $stream);
        fclose($stream);

        return $path;
    }

    private function resolveOutputPath(): string
    {
        $option = trim((string) $this->option('output'));

        if ($option === '') {
            return 'eval/cashier-label-eval-' . now()->format('Ymd-His') . '.csv';
        }

        // Stay inside the storage disk: reject traversal and absolute paths.
        if (str_contains($option, '..') || str_starts_with($option, '/') || str_starts_with($option, '\\') || preg_match('/^[A-Za-z]:/', $option)) {
            throw new \InvalidArgumentException('--output must be a relative path inside the local storage disk (no "..", no absolute paths).');
        }

        return $option;
    }

    private function neutraliseCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    private function report(array $rows, array $skipped, int $conflictingLabels, string $path): void
    {
        $counts = [self::SOURCE_RESOLVED => 0, self::SOURCE_PATTERN => 0, self::SOURCE_SYNTHETIC => 0];
        foreach ($rows as $row) {
            $counts[$row['source']]++;
        }

        $this->info('Wrote ' . count($rows) . ' row(s) to ' . Storage::disk('local')->path($path));
        $this->table(['source', 'n'], [
            [self::SOURCE_RESOLVED,  $counts[self::SOURCE_RESOLVED]],
            [self::SOURCE_PATTERN,   $counts[self::SOURCE_PATTERN]],
            [self::SOURCE_SYNTHETIC, $counts[self::SOURCE_SYNTHETIC] . ' (generated in the evaluation phase)'],
        ]);

        // Phase 0 fact-check #1 in the plan: how much real history exists?
        $queueTotal    = UnmatchedCashierItem::query()->count();
        $queueResolved = UnmatchedCashierItem::query()->whereNotNull('resolved_at')->count();
        $dismissed     = AuditLog::query()->where('action', AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED)->count();
        $this->line("Queue: {$queueTotal} item(s); {$queueResolved} with resolved_at set (includes {$dismissed} dismissal audit entries, which carry no target and are not exported).");

        if ($skipped['inactive_target'] || $skipped['malformed']) {
            $this->warn("Skipped resolved audit entries: {$skipped['inactive_target']} pointing at archived/deleted types, {$skipped['malformed']} with missing/invalid metadata.");
        }
        if ($conflictingLabels > 0) {
            $this->warn("{$conflictingLabels} normalised label(s) map to more than one active type (violates 'one label -> one type'); review before trusting those rows.");
        }
    }
}
