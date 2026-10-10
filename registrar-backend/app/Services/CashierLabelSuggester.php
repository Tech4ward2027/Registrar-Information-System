<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessType;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Support\StringSimilarity;

/**
 * CashierLabelSuggester — rule-based layer of the label-suggestion feature.
 *
 * Given a receipt label that matched no type, ranks active document /
 * certificate types by similarity of the label to each type's name and to
 * every stored cashier pattern. Deterministic, no I/O beyond reading the
 * (small) type catalogue, and never writes anything. It only RANKS — an
 * admin still confirms every resolution, and CashierPatternConflictChecker
 * still guards the write.
 *
 * Candidate scope mirrors CashierDocumentSuggester: non-archived types
 * visible to self-service students/alumni.
 */
final class CashierLabelSuggester
{
    public function __construct(private CashierPatternConflictChecker $conflictChecker) {}

    /**
     * @param  list<array<string,mixed>>|null  $catalogue  Optional override, shape of catalogue().
     * @return array{
     *     suggestions: list<array{key:string,type:'document'|'certificate',id:int,name:string,score:float,matched_on:string}>,
     *     candidates:  list<array{key:string,type:'document'|'certificate',id:int,name:string,score:float,matched_on:string,patterns:list<string>}>,
     *     is_ambiguous: bool,
     * }
     *   suggestions = top N (what the admin sees); candidates = wider list
     *   handed to the LLM re-ranker when is_ambiguous is true.
     */
    public function suggest(string $rawLabel, ?array $catalogue = null): array
    {
        // $catalogue is only passed by the evaluation command (to hold one
        // label out of the catalogue). Normal use reads the live catalogue
        // and applies the one-label-one-type ownership rule below.
        $live = $catalogue === null;
        $cfg   = config('label_suggestions');
        $label = mb_substr(trim($rawLabel), 0, (int) $cfg['max_label_length']);
        $norm  = CashierLabelNormalizer::normalize($label);

        if ($norm === '') {
            return ['suggestions' => [], 'candidates' => [], 'is_ambiguous' => true];
        }

        $labelTokens = $this->tokens($norm);
        $labelString = implode(' ', $labelTokens);

        $scored = [];
        foreach (($catalogue ?? $this->catalogue()) as $type) {
            $best = null;
            foreach ($type['strings'] as $source => $strings) {
                foreach ($strings as $candidateString) {
                    $score = $this->score($labelTokens, $labelString, $candidateString, $cfg);
                    if ($best === null || $score > $best['score']) {
                        $best = ['score' => $score, 'matched_on' => $source];
                    }
                }
            }
            if ($best !== null && $best['score'] >= (float) $cfg['min_score']) {
                $scored[] = [
                    'key'        => $type['key'],
                    'type'       => $type['type'],
                    'id'         => $type['id'],
                    'name'       => $type['name'],
                    'score'      => round($best['score'], 4),
                    'matched_on' => $best['matched_on'],
                    'patterns'   => array_slice($type['strings']['pattern'], 0, 8),
                ];
            }
        }

        // "One label -> one type": if another type already owns this exact
        // normalised label, only that owner is a valid target.
        $owners = $live ? $this->conflictChecker->findConflicts([$norm]) : [];
        if ($owners !== []) {
            $owner  = reset($owners);
            $scored = array_values(array_filter($scored, static fn (array $c) => $c['name'] === $owner));
        }

        usort($scored, static fn (array $x, array $y) => $y['score'] <=> $x['score'] ?: $x['id'] <=> $y['id']);

        $candidates  = array_slice($scored, 0, (int) $cfg['rerank_candidates']);
        // Stored/displayed suggestions never carry the pattern lists.
        $suggestions = array_map(
            static function (array $c): array {
                unset($c['patterns']);

                return $c;
            },
            array_slice($scored, 0, (int) $cfg['top_n'])
        );

        return [
            'suggestions'  => $suggestions,
            'candidates'   => $candidates,
            'is_ambiguous' => $this->isAmbiguous($scored, $cfg),
        ];
    }

    /** @param list<array{score:float}> $sorted */
    private function isAmbiguous(array $sorted, array $cfg): bool
    {
        if ($sorted === []) {
            return true;
        }
        if ($sorted[0]['score'] < (float) $cfg['low_threshold']) {
            return true;
        }
        if (isset($sorted[1]) && ($sorted[0]['score'] - $sorted[1]['score']) < (float) $cfg['gap_threshold']) {
            return true;
        }

        return false;
    }

    /** @param list<string> $labelTokens */
    private function score(array $labelTokens, string $labelString, string $candidate, array $cfg): float
    {
        $candTokens = $this->tokens($candidate);
        if ($candTokens === []) {
            return 0.0;
        }

        $token  = StringSimilarity::tokenOverlap($labelTokens, $candTokens);
        $string = StringSimilarity::jaroWinkler($labelString, implode(' ', $candTokens));

        return ((float) $cfg['token_weight'] * $token) + ((float) $cfg['string_weight'] * $string);
    }

    /**
     * Normalise, strip punctuation, expand abbreviations, drop stopwords.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $norm = CashierLabelNormalizer::normalize($text);
        $norm = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $norm) ?? $norm;

        $abbr = (array) config('label_suggestions.abbreviations', []);
        $stop = (array) config('label_suggestions.stopwords', []);

        // Expand abbreviations FIRST, then drop stopwords, so that e.g.
        // "TOR" -> "transcript of records" -> [transcript, records] matches
        // the type "Transcript of Records" exactly.
        $out = [];
        foreach (preg_split('/\s+/u', trim($norm), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $expanded = $abbr[$token] ?? $token;
            foreach (explode(' ', $expanded) as $part) {
                if ($part !== '' && !in_array($part, $stop, true)) {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    /**
     * Active, self-service-visible types with their comparable strings.
     *
     * @return list<array{key:string,type:'document'|'certificate',id:int,name:string,strings:array{name:list<string>,pattern:list<string>}}>
     */
    public function catalogue(): array
    {
        $accessIds = AccessType::selfServiceVisibleIds();
        $types     = [];

        $docs = DocumentType::where('is_archived', false)
            ->whereIn('access_id', $accessIds)
            ->get(['document_type_id', 'document_name', 'cashier_document_patterns']);
        foreach ($docs as $d) {
            $types[] = $this->entry('document', (int) $d->document_type_id, (string) $d->document_name, $d->cashier_document_patterns);
        }

        $certs = CertificationType::where('is_archived', false)
            ->whereIn('access_id', $accessIds)
            ->get(['certificate_type_id', 'certificate_name', 'cashier_document_patterns']);
        foreach ($certs as $c) {
            $types[] = $this->entry('certificate', (int) $c->certificate_type_id, (string) $c->certificate_name, $c->cashier_document_patterns);
        }

        return $types;
    }

    private function entry(string $type, int $id, string $name, mixed $patterns): array
    {
        $patterns = is_array($patterns) ? $patterns : (json_decode((string) $patterns, true) ?: []);

        return [
            'key'     => ($type === 'document' ? 'd' : 'c') . $id,
            'type'    => $type,
            'id'      => $id,
            'name'    => $name,
            'strings' => [
                'name'    => [$name],
                'pattern' => array_values(array_filter(array_map('strval', $patterns), static fn ($p) => trim($p) !== '')),
            ],
        ];
    }
}