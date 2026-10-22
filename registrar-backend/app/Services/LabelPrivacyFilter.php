<?php

declare(strict_types=1);

namespace App\Services;

/**
 * LabelPrivacyFilter — the single place that decides what text may leave RIS
 * inside an AI request.
 *
 * Policy basis (AI System Policy & Office Guidelines v1.0, section 3.3):
 *   3.3.1  Zero personal data leaves RIS.
 *   3.3.2  The AI receives only the sanitised receipt label and a short list
 *          of possible document names.
 *   3.3.3  Names and maiden names, student numbers, reference numbers,
 *          payment amounts, emails and URLs are removed automatically.
 *   3.3.4  Instruction-like text on a receipt is plain data only.
 *
 * Why an allowlist
 * ----------------
 * Emails, URLs, amounts and identifiers have a recognisable shape, so they
 * are removed by pattern (redact()). A person's name has no reliable shape:
 * no regular expression can tell "Juan Dela Cruz" from "Transcript of
 * Records". Instead of trying to recognise what to remove, scrub() keeps
 * only words that belong to document vocabulary and drops the rest. It fails
 * closed: an unrecognised word is never sent.
 *
 * What counts as document vocabulary (see vocabulary()):
 *   - the configured abbreviations (keys and expansions) and stopwords,
 *   - label_suggestions.privacy.allowed_terms (extra approved terms),
 *   - the words of the candidate document names in the same request.
 * A label word is also kept when it is a truncation of a vocabulary word
 * ("Enrol" -> "Enrollment") or a small typo of one ("Transcrpit"), so the
 * AI still sees the messy labels it exists to resolve.
 *
 * Known limit: a requestor whose name is also an ordinary document word
 * (for example someone surnamed "Records") cannot be told apart from the
 * word itself. That residual risk is inherent to any non-NER approach.
 *
 * Pure and stateless: no database, no network, no caching. Every method is
 * deterministic for a given configuration.
 */
final class LabelPrivacyFilter
{
    /**
     * Currency amounts: "PHP 1,250.00", "P500", "₱ 1,250", "Pesos 500",
     * and bare decimal / thousands-separated figures ("1,250.00", "12,000").
     */
    private const AMOUNT = '/(?:₱|\b(?:php|pesos?)\b\.?|\bp(?=\s*\d))\s*\d[\d,]*(?:\.\d+)?'
        . '|\b\d[\d,]*\.\d{2}\b'
        . '|\b\d{1,3}(?:,\d{3})+\b/iu';

    /**
     * Pattern-based removal for text that is already trusted vocabulary
     * (candidate names, and the first stage of scrub()). Removes control
     * characters, URLs, emails, amounts, angle brackets, and every word that
     * contains a digit unless it is a short plain number (so "Form 137" and
     * "(1 page)" survive; "2021-00123", "REF-45", "OR#998877" do not).
     */
    public static function redact(string $text): string
    {
        return self::cap(implode(' ', self::strip($text)));
    }

    /**
     * redact() plus the allowlist: for untrusted free text (receipt labels
     * and stored receipt patterns). Any word that is not document vocabulary
     * is dropped, which removes names, maiden names and instruction text.
     *
     * @param  list<string>  $vocabulary  From vocabulary().
     */
    public static function scrub(string $text, array $vocabulary): string
    {
        $kept       = [];
        $hasContent = false;

        foreach (self::strip($text) as $word) {
            $parts = preg_split('/[^\p{L}\p{N}]+/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            // Punctuation-only words ("-", "&") carry no data; keep them only
            // as separators between real words (see the check below).
            if ($parts === []) {
                $kept[] = $word;
                continue;
            }

            foreach ($parts as $part) {
                if (!self::isAllowed($part, $vocabulary)) {
                    continue 2;
                }
            }

            $kept[]     = $word;
            $hasContent = true;
        }

        // Nothing but punctuation left: there is nothing to send.
        return $hasContent ? self::cap(implode(' ', $kept)) : '';
    }

    /**
     * Lower-cased unique terms that may appear in an outbound label.
     *
     * @param  list<string>  $candidateNames
     * @return list<string>
     */
    public static function vocabulary(array $candidateNames): array
    {
        $abbreviations = (array) config('label_suggestions.abbreviations', []);

        $sources = array_merge(
            array_map('strval', array_keys($abbreviations)),
            array_map('strval', array_values($abbreviations)),
            array_map('strval', (array) config('label_suggestions.stopwords', [])),
            array_map('strval', (array) config('label_suggestions.privacy.allowed_terms', [])),
            array_map('strval', $candidateNames),
        );

        $terms = [];
        foreach ($sources as $source) {
            $pieces = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($source), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($pieces as $piece) {
                $terms[$piece] = true;
            }
        }

        // Numeric-looking keys are converted to int by PHP; restore strings.
        return array_map('strval', array_keys($terms));
    }

    /**
     * Stage one: pattern-based removal, returned as whitespace-separated words.
     *
     * @return list<string>
     */
    private static function strip(string $text): array
    {
        // A null from preg_replace (e.g. invalid UTF-8) becomes '' — fail closed.
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('~https?://\S+|www\.\S+~i', ' ', $text) ?? '';
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', ' ', $text) ?? '';
        $text = preg_replace(self::AMOUNT, ' ', $text) ?? '';
        $text = str_replace(['<', '>'], ' ', $text);

        $maxDigits = max(1, (int) config('label_suggestions.privacy.max_numeric_digits', 3));
        $kept      = [];

        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $core = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word) ?? '';

            $hasDigit   = preg_match('/\d/u', $core) === 1;
            $shortPlain = preg_match('/^\d{1,' . $maxDigits . '}$/', $core) === 1;

            if ($hasDigit && !$shortPlain) {
                continue; // student number, reference number, mixed identifier
            }

            $kept[] = $word;
        }

        return $kept;
    }

    /** @param list<string> $vocabulary */
    private static function isAllowed(string $part, array $vocabulary): bool
    {
        $term = mb_strtolower($part);

        if (in_array($term, $vocabulary, true)) {
            return true;
        }

        // Numbers are never inferred: only exact vocabulary numbers ("137").
        if (ctype_digit($term)) {
            return false;
        }

        $length = mb_strlen($term);

        // Truncation of a longer vocabulary word: "enrol" -> "enrollment".
        $minPrefix = max(1, (int) config('label_suggestions.privacy.min_prefix_length', 3));
        if ($length >= $minPrefix) {
            foreach ($vocabulary as $known) {
                if ($length < mb_strlen($known) && str_starts_with($known, $term)) {
                    return true;
                }
            }
        }

        // Small typo of a vocabulary word. ASCII letters only: levenshtein()
        // is byte-based, so multibyte text is matched exactly or not at all.
        if (preg_match('/^[a-z]{4,}$/', $term) === 1) {
            $tolerance = $length >= 8 ? 2 : 1;

            foreach ($vocabulary as $known) {
                if (preg_match('/^[a-z]+$/', $known) === 1
                    && abs(strlen($known) - $length) <= $tolerance
                    && levenshtein($term, $known) <= $tolerance) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function cap(string $text): string
    {
        return trim(mb_substr(trim($text), 0, (int) config('label_suggestions.max_label_length', 120)));
    }
}
