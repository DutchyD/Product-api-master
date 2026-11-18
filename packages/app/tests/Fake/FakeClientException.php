<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Fake;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A transport failure raised by the fake HTTP client.
 */
final class FakeClientException extends RuntimeException implements ClientExceptionInterface
{
}
