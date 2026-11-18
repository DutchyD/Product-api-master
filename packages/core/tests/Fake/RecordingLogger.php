<?php

declare(strict_types=1);

namespace MaxServ\Core\Tests\Fake;

use MaxServ\Core\Logging\Logger;

/**
 * Keeps every logged error in memory so tests can assert on it.
 */
final class RecordingLogger implements Logger
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * Records the error message in memory.
     *
     * @param string $message The message.
     *
     * @return void
     */
    #[\Override]
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Returns the logged error messages.
     *
     * @return list<string> The messages, in order.
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
