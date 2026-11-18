<?php

declare(strict_types=1);

namespace MaxServ\Core\Database;

use RuntimeException;

/**
 * A database migration could not be applied.
 */
final class MigrationFailed extends RuntimeException
{
}
