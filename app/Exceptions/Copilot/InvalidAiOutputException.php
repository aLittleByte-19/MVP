<?php

namespace App\Exceptions\Copilot;

/**
 * The AI model returned a payload that does not respect the expected JSON
 * Schema. Carries only schema-level diagnostics (keywords and pointers),
 * never the raw model output, so it is safe to log and audit.
 */
class InvalidAiOutputException extends \RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        private readonly string $operation,
        private readonly array $errors,
    ) {
        parent::__construct("Output AI non conforme allo schema ({$operation}).");
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function summary(): string
    {
        return implode('; ', array_slice($this->errors, 0, 5));
    }
}
