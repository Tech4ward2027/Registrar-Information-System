<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CashierLabelNormalizer;

/**
 * Deterministic, synthetic "drift" variants of a cashier label, used ONLY by
 * the evaluation command to ask: "if the cashier system re-worded this known
 * label, would the suggester still find the right type?"
 *
 * Pure, no I/O. Variants are synthetic and are always reported separately
 * from real (leave-one-out) results.
 */
final class LabelDriftVariants
{
    /** @var list<array{0:string,1:string}> */
    private const ABBREVIATIONS = [
        ['Informative', 'Info.'],
        ['Certificate', 'Cert.'],
        ['Certification', 'Cert.'],
        ['Transcript of Records', 'TOR'],
        ['Certified True Copy', 'CTC'],
        ['Graduation', 'Grad.'],
    ];

    /**
     * @return array<string,string>  kind => variant (only variants whose
     *         normalised form differs from the original)
     */
    public static function for(string $label): array
    {
        $out = [];

        foreach (self::ABBREVIATIONS as [$long, $short]) {
            if (mb_stripos($label, $long) !== false) {
                $variant = (string) preg_replace('/' . preg_quote($long, '/') . '/iu', $short, $label);
                if (self::differs($variant, $label)) {
                    $out['abbrev'] = $variant;
                    break;
                }
            }
        }

        if (preg_match('/^(?:Certification|Authentication) Fee\s*-\s*(.+)$/iu', $label, $m) === 1) {
            $out['drop_prefix'] = $m[1];
        } else {
            $out['add_prefix'] = 'Certification Fee - ' . $label;
        }

        $parts = preg_split('/\s+-\s*|\s*\|\s*/u', $label) ?: [];
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $out['reorder'] = $parts[1] . ' - ' . $parts[0];
        }

        $longest = '';
        foreach (preg_split('/\s+/u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 6 && mb_strlen($word) > mb_strlen($longest)) {
                $longest = $word;
            }
        }
        if ($longest !== '') {
            $i = intdiv(mb_strlen($longest), 2);
            $chars = mb_str_split($longest);

            $deleted = $chars;
            unset($deleted[$i]);
            $out['typo_del'] = (string) preg_replace('/' . preg_quote($longest, '/') . '/u', implode('', $deleted), $label, 1);

            $swapped = $chars;
            [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
            $out['typo_swap'] = (string) preg_replace('/' . preg_quote($longest, '/') . '/u', implode('', $swapped), $label, 1);
        }

        return array_filter($out, static fn (string $v) => self::differs($v, $label));
    }

    private static function differs(string $variant, string $label): bool
    {
        $nv = CashierLabelNormalizer::normalize($variant);

        return $nv !== '' && $nv !== CashierLabelNormalizer::normalize($label);
    }
}
