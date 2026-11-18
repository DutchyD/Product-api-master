<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use RuntimeException;

/**
 * Products could not be fetched from dummyjson.com or could not be stored.
 */
final class ImportFailed extends RuntimeException
{
}
