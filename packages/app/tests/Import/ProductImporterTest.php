<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Import;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use MaxServ\App\Import\DummyJsonPage;
use MaxServ\App\Import\DummyJsonProductClient;
use MaxServ\App\Import\DummyJsonProductMapper;
use MaxServ\App\Import\ImportFailed;
use MaxServ\App\Import\ImportRequest;
use MaxServ\App\Import\ImportResult;
use MaxServ\App\Import\InvalidProductData;
use MaxServ\App\Import\ProductImporter;
use MaxServ\App\Pagination\PageRequest;
use MaxServ\App\Product\Dimensions;
use MaxServ\App\Product\Product;
use MaxServ\App\Product\ProductPage;
use MaxServ\App\Tests\Fake\DummyJson;
use MaxServ\App\Tests\Fake\FakeClientException;
use MaxServ\App\Tests\Fake\FakeHttpClient;
use MaxServ\App\Tests\Fake\InMemoryProductRepository;
use MaxServ\Core\Tests\Fake\SteppingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(ProductImporter::class)]
#[CoversClass(ImportResult::class)]
#[UsesClass(DummyJsonProductClient::class)]
#[UsesClass(DummyJsonProductMapper::class)]
#[UsesClass(DummyJsonPage::class)]
#[UsesClass(ImportRequest::class)]
#[UsesClass(ImportFailed::class)]
#[UsesClass(InvalidProductData::class)]
#[UsesClass(Product::class)]
#[UsesClass(Dimensions::class)]
#[UsesClass(PageRequest::class)]
#[UsesClass(ProductPage::class)]
final class ProductImporterTest extends TestCase
{
    #[Test]
    public function count_above_the_batch_size_is_fetched_in_batches(): void
    {
        $httpClient = new FakeHttpClient(
            DummyJson::pageResponse(194, DummyJson::products(1, 100)),
            DummyJson::pageResponse(194, DummyJson::products(101, 94)),
        );
        $repository = new InMemoryProductRepository();

        $result = self::importer($httpClient, $repository)->import(new ImportRequest(200, 0));

        self::assertSame(194, $result->importedCount);
        self::assertSame(0, $result->skippedCount());
        self::assertSame(range(1, 194), $repository->storedIds());
        self::assertSame(['limit=100&skip=0', 'limit=100&skip=100'], self::pageQueries($httpClient));
    }

    #[Test]
    public function last_batch_asks_only_for_the_remaining_products(): void
    {
        $httpClient = new FakeHttpClient(
            DummyJson::pageResponse(194, DummyJson::products(11, 100)),
            DummyJson::pageResponse(194, DummyJson::products(111, 50)),
        );

        $result = self::importer($httpClient, new InMemoryProductRepository())->import(new ImportRequest(150, 10));

        self::assertSame(150, $result->importedCount);
        self::assertSame(['limit=100&skip=10', 'limit=50&skip=110'], self::pageQueries($httpClient));
    }

    #[Test]
    public function import_stops_when_the_source_runs_out(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, DummyJson::products(151, 44)));
        $repository = new InMemoryProductRepository();

        $result = self::importer($httpClient, $repository)->import(new ImportRequest(100, 150));

        self::assertSame(44, $result->importedCount);
        self::assertCount(1, $httpClient->requests());
        self::assertFalse($result->offsetPastTotal);
    }

    #[Test]
    public function import_stops_without_another_request_when_the_source_ends_on_a_batch_boundary(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, DummyJson::products(95, 100)));

        $result = self::importer($httpClient, new InMemoryProductRepository())->import(new ImportRequest(200, 94));

        self::assertSame(100, $result->importedCount);
        self::assertCount(1, $httpClient->requests());
    }

    #[Test]
    public function offset_past_the_last_product_imports_nothing(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, []));
        $repository = new InMemoryProductRepository();

        $result = self::importer($httpClient, $repository)->import(new ImportRequest(10, 10_000));

        self::assertSame(0, $result->importedCount);
        self::assertTrue($result->offsetPastTotal);
        self::assertSame(194, $result->sourceTotal);
        self::assertSame([], $repository->storedIds());
    }

    #[Test]
    public function invalid_products_are_skipped_and_valid_ones_stored(): void
    {
        $products = DummyJson::products(1, 3);
        $products[1]['price'] = 'free';
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, $products));
        $repository = new InMemoryProductRepository();

        $result = self::importer($httpClient, $repository)->import(new ImportRequest(3, 0));

        self::assertSame(2, $result->importedCount);
        self::assertSame(1, $result->skippedCount());
        self::assertStringStartsWith('Product 2: price', $result->skippedReasons[0]);
        self::assertSame([1, 3], $repository->storedIds());
    }

    #[Test]
    public function entry_that_is_not_an_object_is_skipped_and_the_rest_stored(): void
    {
        $products = DummyJson::products(1, 3);
        $products[1] = null;
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, $products));
        $repository = new InMemoryProductRepository();

        $result = self::importer($httpClient, $repository)->import(new ImportRequest(3, 0));

        self::assertSame(2, $result->importedCount);
        self::assertSame(['product must be an object.'], $result->skippedReasons);
        self::assertSame([1, 3], $repository->storedIds());
    }

    #[Test]
    public function more_products_than_asked_are_ignored(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, DummyJson::products(1, 5)));
        $repository = new InMemoryProductRepository();

        $result = self::importer($httpClient, $repository)->import(new ImportRequest(3, 0));

        self::assertSame(3, $result->importedCount);
        self::assertSame([1, 2, 3], $repository->storedIds());
    }

    #[Test]
    public function failed_fetch_keeps_the_batches_stored_before_it(): void
    {
        $httpClient = new FakeHttpClient(
            DummyJson::pageResponse(194, DummyJson::products(1, 100)),
            new FakeClientException('Connection reset.'),
        );
        $repository = new InMemoryProductRepository();

        try {
            (void) self::importer($httpClient, $repository)->import(new ImportRequest(200, 0));
            self::fail('The import should fail.');
        } catch (ImportFailed) {
            self::assertSame(range(1, 100), $repository->storedIds());
        }
    }

    #[Test]
    public function invalid_response_fails_the_import(): void
    {
        $httpClient = new FakeHttpClient(new Response(503));

        $this->expectException(ImportFailed::class);

        (void) self::importer($httpClient, new InMemoryProductRepository())->import(new ImportRequest(10, 0));
    }

    #[Test]
    public function database_failure_fails_the_import(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, DummyJson::products(1, 10)));

        $this->expectException(ImportFailed::class);
        $this->expectExceptionMessage('The imported products could not be stored.');

        (void) self::importer($httpClient, new InMemoryProductRepository(failOnSaveCall: 1))->import(new ImportRequest(10, 0));
    }

    private static function importer(FakeHttpClient $httpClient, InMemoryProductRepository $repository): ProductImporter
    {
        return new ProductImporter(
            new DummyJsonProductClient($httpClient, new HttpFactory(), new SteppingClock()),
            new DummyJsonProductMapper(),
            $repository,
        );
    }

    /**
     * @return list<string> The limit and skip of each request, as "limit=…&skip=…".
     */
    private static function pageQueries(FakeHttpClient $httpClient): array
    {
        return array_map(static function (RequestInterface $request): string {
            parse_str($request->getUri()->getQuery(), $query);

            return "limit={$query['limit']}&skip={$query['skip']}";
        }, $httpClient->requests());
    }
}
