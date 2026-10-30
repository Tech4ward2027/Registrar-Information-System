<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Caps the number of links in a piece of text. Links in a message are the
 * commonest carrier of spam, so public submissions allow very few
 * (config('tickets.content.max_links_per_message')) and names and subjects none.
 *
 * Counts http(s):// and www. addresses.
 */
class MaxLinks implements ValidationRule
{
    public function __construct(private readonly int $max) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match_all('~(?:https?://|www\.)\S+~i', $value) > $this->max) {
            $fail($this->max === 0
                ? 'The :attribute may not contain links.'
                : "The :attribute may contain at most {$this->max} link" . ($this->max === 1 ? '' : 's') . '.');
        }
    }
}
