<?php

namespace App\Exceptions;

use RuntimeException;

/** One or more REQUIRED merge fields had no value at render time. */
class MissingMergeFieldsException extends RuntimeException
{
    /** @param list<string> $fields */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Missing required certificate fields: ' . implode(', ', $fields));
    }
}
