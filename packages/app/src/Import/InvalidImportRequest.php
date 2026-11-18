<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use InvalidArgumentException;

/**
 * The import count or offset is not allowed.
 */
final class InvalidImportRequest extends InvalidArgumentException
{
    /**
     * Creates the exception.
     *
     * @param array<string, string> $fieldErrors The message for each field that is not allowed, keyed by field name.
     */
    public function __construct(private readonly array $fieldErrors)
    {
        parent::__construct(implode(' ', $fieldErrors));
    }

    /**
     * Returns the message for each field that is not allowed.
     *
     * @return array<string, string> The messages, keyed by field name, in form order.
     */
    #[\NoDiscard]
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
