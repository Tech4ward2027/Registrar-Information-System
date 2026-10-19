<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Orchestrates label suggestions: rules first, LLM re-rank only when the
 * rules are ambiguous and the layer is available. Every LLM failure path
 * (reranker returns null) leaves the rule-based result untouched.
 */
final class LabelSuggestionService
{
    public function __construct(
        private CashierLabelSuggester $suggester,
        private LabelReranker $reranker,
    ) {}

    /**
     * @return array{suggestions:list<array<string,mixed>>, source:'rules'|'llm'}
     */
    public function generate(string $rawLabel): array
    {
        $result      = $this->suggester->suggest($rawLabel);
        $suggestions = $result['suggestions'];

        if (!$result['is_ambiguous'] || $result['candidates'] === [] || !$this->reranker->isAvailable()) {
            return ['suggestions' => $suggestions, 'source' => 'rules'];
        }

        $pick = $this->reranker->rerank($rawLabel, $result['candidates']);
        if ($pick === null) {
            return ['suggestions' => $suggestions, 'source' => 'rules'];
        }

        $top = null;
        foreach ($result['candidates'] as $c) {
            if ($c['key'] === $pick['choice']) {
                unset($c['patterns']);
                $top = $c + ['ai_reason' => $pick['reason']];
                break;
            }
        }
        if ($top === null) {
            return ['suggestions' => $suggestions, 'source' => 'rules'];
        }

        $rest = array_values(array_filter($suggestions, static fn (array $s) => $s['key'] !== $top['key']));
        $max  = (int) config('label_suggestions.top_n', 3);

        return [
            'suggestions' => array_slice(array_merge([$top], $rest), 0, $max),
            'source'      => 'llm',
        ];
    }
}
