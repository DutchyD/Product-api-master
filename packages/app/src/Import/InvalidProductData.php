<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use InvalidArgumentException;

/**
 * A dummyjson.com product has missing or invalid data.
 */
final class InvalidProductData extends InvalidArgumentException
{
}
