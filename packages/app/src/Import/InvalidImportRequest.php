<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use InvalidArgumentException;

/**
 * The import count or offset is not allowed.
 */
final class InvalidImportRequest extends InvalidArgumentException
{
}
