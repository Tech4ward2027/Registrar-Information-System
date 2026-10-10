<?php

/**
 * Cashier-label suggestion tuning (Phase 4).
 *
 * Thresholds are PROVISIONAL until the Phase 0 eval harness
 * (php artisan cashier:export-label-eval) has been scored against them.
 * All are env-tunable so they can be changed without a deploy.
 */
return [

    // Suggestions kept per label (shown as chips in the resolve modal).
    'top_n' => (int) env('LABEL_SUGGEST_TOP_N', 3),

    // Candidates sent to the LLM when a label is ambiguous.
    'rerank_candidates' => (int) env('LABEL_SUGGEST_RERANK_CANDIDATES', 5),

    // Candidates scoring below this are never suggested at all.
    'min_score' => (float) env('LABEL_SUGGEST_MIN_SCORE', 0.35),

    // Ambiguous when the best score is below this ...
    'low_threshold' => (float) env('LABEL_SUGGEST_LOW_THRESHOLD', 0.75),

    // ... or the #1 / #2 gap is below this.
    'gap_threshold' => (float) env('LABEL_SUGGEST_GAP_THRESHOLD', 0.08),

    // Weighting of the two rule-based signals (should sum to 1.0).
    'token_weight'  => 0.6,
    'string_weight' => 0.4,

    // Max chars of a label ever considered / sent to the LLM.
    'max_label_length' => 120,

    // Abbreviation expansion applied per token after normalisation.
    // Keys are lowercase tokens (punctuation already stripped).
    'abbreviations' => [
        'info'    => 'informative',
        'inf'     => 'informative',
        'cert'    => 'certificate',
        'certif'  => 'certificate',
        'tor'     => 'transcript of records',
        'ctc'     => 'certified true copy',
        'cav'     => 'certification authentication verification',
        'regn'    => 'registration',
        'reg'     => 'registration',
        'grad'    => 'graduation',
        'gwa'     => 'general weighted average',
        'docs'    => 'documents',
        'doc'     => 'document',
        'req'     => 'requirement',
    ],

    // Words that carry no signal for matching ("fee - diploma" vs "diploma").
    'stopwords' => ['fee', 'fees', 'of', 'the', 'for', 'and', 'a', 'an', '-'],
];
