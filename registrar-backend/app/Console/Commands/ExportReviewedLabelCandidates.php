<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\UnmatchedCashierItem;
use App\Services\CashierLabelNormalizer;
use App\Services\LabelPrivacyFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * cashier:export-reviewed-label-candidates
 *
 * Builds the raw material for a registrar-reviewed evaluation set from labels
 * that admins have ALREADY resolved on the Unmatched Cashier Items screen.
 *
 * Read-only: nothing in the database is changed and nothing is sent anywhere.
 *
 * Where the target comes from
 * ---------------------------
 * unmatched_cashier_items does not store which type the admin chose. Resolving
 * appends the raw label to that type's cashier_document_patterns, and the
 * conflict checker guarantees one owner per label. So the target is recovered
 * as "the type whose patterns contain this label (normalised)". Items with no
 * owner (dismissed, or the pattern was later removed) and items with more than
 * one owner are skipped and counted, never guessed.
 *
 * Privacy
 * -------
 * The raw label is NEVER written. Each label is passed through
 * LabelPrivacyFilter::scrub() against the whole active catalogue vocabulary, so
 * names, student numbers, amounts, emails and URLs are dropped. Rows where the
 * scrub removed anything are flagged label_lossy=1; the registrar should treat
 * those as unusable for evaluation or fix the label by hand. Rows that scrub to
 * nothing are omitted. Policy basis: AI System Policy 3.3 (zero personal data
 * leaves RIS). Residual risk is the same as the filter's documented limit.
 *
 * Splitting
 * ---------
 * Each row gets split=dev or split=test from a hash of the normalised label, so
 * the assignment is stable across re-exports and cannot be reshuffled after
 * seeing results. Tune on dev; run test once.
 *
 * Caveat for reviewers
 * --------------------
 * An admin's choice is human-confirmed but not independent: if the admin
 * accepted a suggestion (suggestion_accepted=1) they may have been anchored by
 * it. The registrar_* columns are left blank for the independent review.
 */
class ExportReviewedLabelCandidates extends Command
{
    protected $signature = 'cashier:export-reviewed-label-candidates
        {--output= : Relative path under the local storage disk (default eval/reviewed-candidates-<timestamp>.csv)}';

    protected $description = 'Export scrubbed, resolved unmatched labels with their admin-chosen target for registrar review (read-only)';

    private const HEADER = [
        'item_id', 'label_scrubbed', 'label_lossy', 'occurrence_count',
        'admin_target_key', 'admin_target_name', 'target_archived',
        'suggestion_source', 'suggestion_accepted', 'split',
        'registrar_ok', 'registrar_correct_key', 'registrar_notes',
    ];

    public function handle(): int
    {
        $output = $this->resolveOutputPath();
        if ($output === null) {
            $this->error('--output must be a relative path without "..".');

            return self::INVALID;
        }

        [$owners, $vocabulary] = $this->loadCatalogue();
        if ($owners === [] && $vocabulary === []) {
            $this->error('No document/certificate types found.');

            return self::FAILURE;
        }

        $counts = ['seen' => 0, 'exported' => 0, 'lossy' => 0, 'no_owner' => 0, 'multi_owner' => 0, 'empty_after_scrub' => 0];
        $rows   = [self::HEADER];

        UnmatchedCashierItem::query()
            ->whereNotNull('resolved_at')
            ->chunkById(200, function ($items) use (&$rows, &$counts, $owners, $vocabulary): void {
                foreach ($items as $item) {
                    $counts['seen']++;

                    $normalised = CashierLabelNormalizer::normalize((string) $item->raw_label);
                    $matches    = $owners[$normalised] ?? [];

                    if ($matches === []) {
                        $counts['no_owner']++;
                        continue;
                    }
                    if (count($matches) > 1) {
                        $counts['multi_owner']++;
                        continue;
                    }

                    $scrubbed = LabelPrivacyFilter::scrub((string) $item->raw_label, $vocabulary);
                    if ($scrubbed === '') {
                        $counts['empty_after_scrub']++;
                        continue;
                    }

                    $lossy = CashierLabelNormalizer::normalize($scrubbed) !== $normalised;
                    if ($lossy) {
                        $counts['lossy']++;
                    }

                    $target = $matches[0];
                    $accept = $item->suggestion_accepted;

                    $rows[] = [
                        (string) $item->getKey(),
                        $this->safe($scrubbed),
                        $lossy ? '1' : '0',
                        (string) (int) $item->occurrence_count,
                        $target['key'],
                        $this->safe($target['name']),
                        $target['archived'] ? '1' : '0',
                        (string) ($item->suggestion_source ?? ''),
                        $accept === null ? '' : ($accept ? '1' : '0'),
                        (crc32($normalised) % 2) === 0 ? 'dev' : 'test',
                        '', '', '',
                    ];
                    $counts['exported']++;
                }
            }, 'unmatched_cashier_item_id');

        Storage::disk('local')->put($output, $this->toCsv($rows));

        $this->line(sprintf(
            'Resolved items seen: %d | exported: %d (lossy: %d) | skipped: no owner %d, multiple owners %d, empty after scrub %d',
            $counts['seen'], $counts['exported'], $counts['lossy'],
            $counts['no_owner'], $counts['multi_owner'], $counts['empty_after_scrub'],
        ));
        $this->line('File: storage/app/private/' . $output);
        $this->line('Registrar: fill registrar_ok (1/0) and registrar_correct_key where the admin choice is wrong. Ignore lossy rows or correct them by hand.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, list<array{key:string,name:string,archived:bool}>>, 1: list<string>}
     *         [normalised pattern => owners, vocabulary from active type names]
     */
    private function loadCatalogue(): array
    {
        $owners      = [];
        $activeNames = [];

        $collect = function (iterable $types, string $prefix, string $idCol, string $nameCol) use (&$owners, &$activeNames): void {
            foreach ($types as $type) {
                $name     = (string) $type->{$nameCol};
                $archived = (bool) $type->is_archived;
                $entry    = ['key' => $prefix . $type->{$idCol}, 'name' => $name, 'archived' => $archived];

                if (!$archived) {
                    $activeNames[] = $name;
                }

                $patterns = $type->cashier_document_patterns;
                if (is_string($patterns)) {
                    $patterns = json_decode($patterns, true);
                }
                foreach ((array) $patterns as $pattern) {
                    $norm = CashierLabelNormalizer::normalize((string) $pattern);
                    if ($norm === '') {
                        continue;
                    }
                    // One entry per type per label, even if a type lists it twice.
                    $owners[$norm][$entry['key']] = $entry;
                }
            }
        };

        $collect(DocumentType::query()->get(['document_type_id', 'document_name', 'cashier_document_patterns', 'is_archived']), 'd', 'document_type_id', 'document_name');
        $collect(CertificationType::query()->get(['certificate_type_id', 'certificate_name', 'cashier_document_patterns', 'is_archived']), 'c', 'certificate_type_id', 'certificate_name');

        foreach ($owners as $norm => $byKey) {
            $owners[$norm] = array_values($byKey);
        }

        return [$owners, LabelPrivacyFilter::vocabulary($activeNames)];
    }

    private function resolveOutputPath(): ?string
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return 'eval/reviewed-candidates-' . now()->format('Ymd-His') . '.csv';
        }
        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }

    /** Neutralise spreadsheet formula injection (leading = + - @ tab CR). */
    private function safe(string $v): string
    {
        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
    }

    /** @param list<list<string>> $rows */
    private function toCsv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($h, $r, ',', '"', '\\');
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }
}
