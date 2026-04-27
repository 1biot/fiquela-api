<?php

namespace Api\Exceptions;

class LintValidationException extends \RuntimeException
{
    /**
     * @param list<array{severity: string, rule: string, message: string, line: ?int, column: ?int, offset: ?int}> $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('Query lint validation failed');
    }
}
