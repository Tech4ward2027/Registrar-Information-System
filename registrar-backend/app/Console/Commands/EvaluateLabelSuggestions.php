<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashierLabelNormalizer;
use App\Services\CashierLabelSuggester;
use App\Services\LabelReranker;
use App\Support\LabelDriftVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * cashier:eval-label-suggestions
 *
 * Scores CashierLabelSuggester (and optionally the LLM re-ranker) against the
 * live document/certificate catalogue. Read-only: nothing in the database is
 * changed, and only catalogue labels (never personal data) are ever sent to
 * the LLM, and only with --with-llm.
 *
 *  - leave-one-out : each stored pattern is held out of the catalogue and the
 *    suggester must still find its type from what remains. Real data.
 *  - drift         : synthetic re-wordings of known labels. Reported
 *    separately; never mixed with real data.
 *
 * Reports n and a 95% Wilson interval for every proportion.
 */
class EvaluateLabelSuggestions extends Command
{
    protected $signature = 'cashier:eval-label-suggestions
        {--with-llm : Also run the LLM re-ranker on ambiguous rows (needs ANTHROPIC_API_KEY; costs tokens)}
        {--limit=0 : Max rows sent to the LLM (0 = all ambiguous rows)}
        {--output= : Relative path under the local storage disk (default eval/label-suggestions-<timestamp>.csv)}';

    protected $description = 'Evaluate the cashier label suggester (rules, and optionally rules + LLM) against the live catalogue';

    public function handle(CashierLabelSuggester $suggester, LabelReranker $reranker): int
    {
        $output = $this->resolveOutputPath();
        if ($output === null) {
            $this->error('--output must be a relative path without "..".');

            return self::INVALID;
        }

        $withLlm = (bool) $this->option('with-llm');
        if ($withLlm) {
            if (!filled(config('services.anthropic.api_key'))) {
                $this->error('--with-llm needs ANTHROPIC_API_KEY.');

                return self::FAILURE;
            }
            // In-process only: lets the reranker run for this command.
            config(['features.ai_label_suggestions' => true]);
        }

        $catalogue = $suggester->catalogue();
        if ($catalogue === []) {
            $this->error('No active document/certificate types found.');

            return self::FAILURE;
        }

        [$rows, $skipped] = $this->buildRows($catalogue);
        $limit = max(0, (int) $this->option('limit'));
        $llmSent = 0;

        $stats = [];
        $csv = [['source', 'label', 'truth_key', 'rules_top1', 'rules_top3', 'ambiguous', 'llm_top1']];

        foreach ($rows as [$source, $label, $truth, $cat]) {
            $res        = $suggester->suggest($label, $cat);
            $keys       = array_column($res['candidates'], 'key');
            $top1       = ($keys[0] ?? null) === $truth;
            $top3       = in_array($truth, array_slice($keys, 0, 3), true);
            $ambiguous  = $res['is_ambiguous'];
            $llmTop1    = $top1;

            if ($withLlm && $ambiguous && $res['candidates'] !== [] && ($limit === 0 || $llmSent < $limit)) {
                $llmSent++;
                $pick    = $reranker->rerank($label, $res['candidates']);
                $llmTop1 = $pick !== null ? $pick['choice'] === $truth : $top1;
            }

            // Buckets: the family ("leave-one-out" or "drift") and, for
            // synthetic rows, the specific variant kind ("drift:abbrev", ...).
            $buckets = [str_starts_with($source, 'drift') ? 'drift' : $source];
            if (str_starts_with($source, 'drift:')) {
                $buckets[] = $source;
            }

            foreach ($buckets as $bucket) {
                $s = $stats[$bucket] ?? ['n' => 0, 't1' => 0, 't3' => 0, 'amb' => 0, 'amb_t1' => 0, 'amb_llm' => 0, 'non' => 0, 'non_t1' => 0];
                $s['n']++;
                $s['t1'] += (int) $top1;
                $s['t3'] += (int) $top3;
                if ($ambiguous) {
                    $s['amb']++;
                    $s['amb_t1']  += (int) $top1;
                    $s['amb_llm'] += (int) $llmTop1;
                } else {
                    $s['non']++;
                    $s['non_t1'] += (int) $top1;
                }
                $stats[$bucket] = $s;
            }

            $csv[] = [$source, $this->safe($label), $truth, (int) $top1, (int) $top3, (int) $ambiguous, (int) $llmTop1];
        }

        $this->report($stats, $withLlm, $llmSent, $skipped);

        Storage::disk('local')->put($output, $this->toCsv($csv));
        $this->info('Row-level results: storage/app/private/' . $output);

        return self::SUCCESS;
    }

    /**
     * @return array{0:list<array{0:string,1:string,2:string,3:?array}>,1:int}
     */
    private function buildRows(array $catalogue): array
    {
        // normalised label => set of type keys that own it as a pattern.
        $owners = [];
        $existing = [];
        foreach ($catalogue as $t) {
            $existing[CashierLabelNormalizer::normalize($t['name'])] = true;
            foreach ($t['strings']['pattern'] as $p) {
                $n = CashierLabelNormalizer::normalize($p);
                $owners[$n][$t['key']] = true;
                $existing[$n] = true;
            }
        }
        $conflicting = array_keys(array_filter($owners, static fn ($set) => count($set) > 1));

        $rows    = [];
        $skipped = 0;
        $seen    = [];

        foreach ($catalogue as $idx => $t) {
            foreach ($t['strings']['pattern'] as $pattern) {
                $norm = CashierLabelNormalizer::normalize($pattern);
                if (in_array($norm, $conflicting, true)) {
                    $skipped++;
                    continue;
                }

                // Leave-one-out: hold the label out of this type's strings
                // (including its name when identical), keep everything else.
                $held = $catalogue;
                $held[$idx]['strings']['pattern'] = array_values(array_filter(
                    $t['strings']['pattern'],
                    static fn ($p) => CashierLabelNormalizer::normalize($p) !== $norm
                ));
                $held[$idx]['strings']['name'] = array_values(array_filter(
                    $t['strings']['name'],
                    static fn ($p) => CashierLabelNormalizer::normalize($p) !== $norm
                ));
                if ($held[$idx]['strings']['pattern'] === [] && $held[$idx]['strings']['name'] === []) {
                    $skipped++;
                    continue;
                }
                $rows[] = ['leave-one-out', $pattern, $t['key'], $held];
            }

            foreach (array_merge($t['strings']['name'], $t['strings']['pattern']) as $base) {
                if (in_array(CashierLabelNormalizer::normalize($base), $conflicting, true)) {
                    continue;
                }
                foreach (LabelDriftVariants::for($base) as $kind => $variant) {
                    $nv = CashierLabelNormalizer::normalize($variant);
                    // A variant that already matches a stored label would not be "unmatched".
                    if (isset($existing[$nv]) || isset($seen[$nv . '|' . $t['key']])) {
                        continue;
                    }
                    $seen[$nv . '|' . $t['key']] = true;
                    $rows[] = ['drift:' . $kind, $variant, $t['key'], null];
                }
            }
        }

        return [$rows, $skipped];
    }

    private function report(array $stats, bool $withLlm, int $llmSent, int $skipped): void
    {
        ksort($stats);
        $this->newLine();
        foreach ($stats as $bucket => $s) {
            $this->line(sprintf(
                '%-20s n=%-4d top1=%s  top3=%s  ambiguous=%d  non-ambiguous top1=%s',
                $bucket,
                $s['n'],
                $this->pct($s['t1'], $s['n']),
                $this->pct($s['t3'], $s['n']),
                $s['amb'],
                $this->pct($s['non_t1'], $s['non'])
            ));
            if ($s['amb'] > 0) {
                $line = sprintf('  ambiguous subset: rules top1=%s', $this->pct($s['amb_t1'], $s['amb']));
                if ($withLlm) {
                    $line .= sprintf(
                        ' | rules+LLM top1=%s | lift=%+.1f pp',
                        $this->pct($s['amb_llm'], $s['amb']),
                        100 * ($s['amb_llm'] - $s['amb_t1']) / $s['amb']
                    );
                }
                $this->line($line);
            }
        }
        $this->newLine();
        $this->line("Skipped (conflicting or no remaining strings): {$skipped}");
        if ($withLlm) {
            $this->line("Rows sent to the LLM: {$llmSent} (labels and catalogue names only)");
            $this->line('Decision rule: keep the LLM only if the ambiguous-subset lift is >= 10 pp on real (leave-one-out) data, with no loss elsewhere.');
        }
    }

    private function pct(int $k, int $n): string
    {
        if ($n === 0) {
            return 'n/a';
        }
        [$lo, $hi] = $this->wilson($k, $n);

        return sprintf('%.1f%% [%.1f-%.1f]', 100 * $k / $n, 100 * $lo, 100 * $hi);
    }

    /** @return array{0:float,1:float} */
    private function wilson(int $k, int $n, float $z = 1.96): array
    {
        $p = $k / $n;
        $d = 1 + ($z * $z) / $n;
        $c = ($p + ($z * $z) / (2 * $n)) / $d;
        $h = $z * sqrt(($p * (1 - $p)) / $n + ($z * $z) / (4 * $n * $n)) / $d;

        return [max(0.0, $c - $h), min(1.0, $c + $h)];
    }

    private function resolveOutputPath(): ?string
    {
        $path = (string) ($this->option('output') ?: 'eval/label-suggestions-' . now()->format('Ymd-His') . '.csv');

        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }

    /** Neutralise spreadsheet formula injection in labels. */
    private function safe(string $v): string
    {
        return $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v;
    }

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
