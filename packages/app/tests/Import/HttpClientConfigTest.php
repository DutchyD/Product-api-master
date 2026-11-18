<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Import;

use MaxServ\App\Import\DummyJsonProductClient;
use MaxServ\App\Import\ImportRequest;
use MaxServ\App\Import\ProductImporter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the HTTP client settings that the response size and time limits of DummyJsonProductClient rely on.
 */
#[CoversNothing]
final class HttpClientConfigTest extends TestCase
{
    private const string SERVICES_FILE = __DIR__ . '/../../config/services.yaml';
    /**
     * The wall time one import may spend once each response has started; PHP's execution time limit does not
     * count network waits on Linux, so the client limits are what bound it.
     */
    private const int IMPORT_WALL_TIME_BUDGET_SECONDS = 30;

    #[Test]
    public function client_streams_responses_so_the_size_limit_stops_the_download(): void
    {
        $options = self::clientOptions();

        self::assertTrue($options['stream']);
    }

    #[Test]
    public function client_does_not_follow_redirects_away_from_dummyjson(): void
    {
        $options = self::clientOptions();

        self::assertFalse($options['allow_redirects']);
    }

    #[Test]
    public function slowest_import_stays_within_the_wall_time_budget(): void
    {
        $options = self::clientOptions();
        $slowestFetch = DummyJsonProductClient::FETCH_TIME_LIMIT_SECONDS + $options['read_timeout'];
        $fetchesPerImport = intdiv(ImportRequest::MAX_COUNT + ProductImporter::BATCH_SIZE - 1, ProductImporter::BATCH_SIZE);

        self::assertLessThanOrEqual(self::IMPORT_WALL_TIME_BUDGET_SECONDS, $fetchesPerImport * $slowestFetch);
    }

    /**
     * @return array<string, mixed>
     */
    private static function clientOptions(): array
    {
        $services = Yaml::parseFile(self::SERVICES_FILE)['services'];

        return $services['dummyjson.http_client']['arguments'][0];
    }
}
