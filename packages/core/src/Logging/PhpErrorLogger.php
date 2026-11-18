<?php

declare(strict_types=1);

namespace MaxServ\Core\Logging;

/**
 * Writes errors to the PHP error log, which the web server's log collects.
 */
final readonly class PhpErrorLogger implements Logger
{
    /**
     * Writes an error message to the PHP error log.
     *
     * @param string $message The message; it must not contain secrets or personal data.
     *
     * @return void
     */
    #[\Override]
    public function error(string $message): void
    {
        error_log($message);
    }
}
