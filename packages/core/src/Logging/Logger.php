<?php

declare(strict_types=1);

namespace MaxServ\Core\Logging;

/**
 * Records errors for the operators of the application.
 */
interface Logger
{
    /**
     * Records an error message.
     *
     * @param string $message The message; it must not contain secrets or personal data.
     *
     * @return void
     */
    public function error(string $message): void;
}
