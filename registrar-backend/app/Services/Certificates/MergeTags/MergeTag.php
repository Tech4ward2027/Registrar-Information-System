<?php

namespace App\Services\Certificates\MergeTags;

use Closure;

/**
 * One supported merge tag. Immutable definition; the registry owns the list.
 *
 * source:
 *   backend  — resolved by the server from trusted records (never client input)
 *   manual   — typed by the Registrar when generating (validated, length-capped)
 *   computed — derived from other values by `$compute`
 */
final class MergeTag
{
    public const SOURCE_BACKEND  = 'backend';
    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_COMPUTED = 'computed';

    /** @param Closure(array<string, mixed>): ?string|null $compute */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $source,
        public readonly string $description,
        public readonly ?Closure $compute = null,
        public readonly string $example = '',
    ) {}

    public function isComputed(): bool
    {
        return $this->source === self::SOURCE_COMPUTED;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'tag'         => '{{ ' . $this->name . ' }}',
            'label'       => $this->label,
            'source'      => $this->source,
            'description' => $this->description,
            'example'     => $this->example,
        ];
    }
}
