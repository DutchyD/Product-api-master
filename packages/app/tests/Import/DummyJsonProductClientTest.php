<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Import;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use MaxServ\App\Import\DummyJsonPage;
use MaxServ\App\Import\DummyJsonProductClient;
use MaxServ\App\Import\ImportFailed;
use MaxServ\App\Tests\Fake\DummyJson;
use MaxServ\App\Tests\Fake\FakeClientException;
use MaxServ\App\Tests\Fake\FakeHttpClient;
use MaxServ\Core\Tests\Fake\SteppingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DummyJsonProductClient::class)]
#[CoversClass(DummyJsonPage::class)]
#[CoversClass(ImportFailed::class)]
final class DummyJsonProductClientTest extends TestCase
{
    #[Test]
    public function requestAsksForThePageAndTheStoredFieldsOnly(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, []));

        (void) self::client($httpClient)->fetchPage(50, 150);

        $request = $httpClient->requests()[0];
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('dummyjson.com', $request->getUri()->getHost());
        self::assertSame('/products', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('50', $query['limit']);
        self::assertSame('150', $query['skip']);
        self::assertStringNotContainsString('reviews', $query['select']);
        self::assertContains('thumbnail', explode(',', $query['select']));
    }

    #[Test]
    public function pageHoldsTheTotalAndRawProducts(): void
    {
        $products = DummyJson::products(1, 2);
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, $products));

        $page = self::client($httpClient)->fetchPage(2, 0);

        self::assertSame(194, $page->total);
        self::assertSame($products, $page->products);
    }

    #[Test]
    public function entriesThatAreNotObjectsArePassedOnForTheImporterToSkip(): void
    {
        $httpClient = new FakeHttpClient(new Response(200, [], '{"total": 3, "products": [{"id": 1}, null, 7]}'));

        $page = self::client($httpClient)->fetchPage(3, 0);

        self::assertSame([['id' => 1], null, 7], $page->products);
    }

    #[Test]
    public function transportFailureFailsTheFetch(): void
    {
        $httpClient = new FakeHttpClient(new FakeClientException('Connection timed out.'));

        $this->expectException(ImportFailed::class);

        (void) self::client($httpClient)->fetchPage(10, 0);
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function invalidResponses(): iterable
    {
        yield 'server error' => [new Response(500, [], '{}')];
        yield 'redirect' => [new Response(301, ['Location' => 'https://example.com/'])];
        yield 'not JSON' => [new Response(200, [], '<html></html>')];
        yield 'JSON too deep' => [new Response(200, [], str_repeat('[', 20) . str_repeat(']', 20))];
        yield 'no products' => [new Response(200, [], '{"total": 5}')];
        yield 'no total' => [new Response(200, [], '{"products": []}')];
        yield 'total as text' => [new Response(200, [], '{"total": "5", "products": []}')];
        yield 'negative total' => [new Response(200, [], '{"total": -1, "products": []}')];
        yield 'products not a list' => [new Response(200, [], '{"total": 1, "products": {"a": {}}}')];
        yield 'body too large' => [new Response(
            200,
            [],
            '{"total": 0, "products": [], "padding": "' . str_repeat('a', 2 * 1024 * 1024) . '"}',
        )];
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function invalidResponseFailsTheFetch(Response $response): void
    {
        $this->expectException(ImportFailed::class);

        (void) self::client(new FakeHttpClient($response))->fetchPage(10, 0);
    }

    #[Test]
    public function failureWhileReadingTheBodyFailsTheFetchAndClosesTheBody(): void
    {
        $closed = false;
        $body = FnStream::decorate(new HttpFactory()->createStream('{"total": 1, '), [
            'read' => static fn (): never => throw new RuntimeException('Unable to read from stream: timed out'),
            'close' => static function () use (&$closed): void {
                $closed = true;
            },
        ]);
        $httpClient = new FakeHttpClient(new Response(200, [], $body));

        try {
            (void) self::client($httpClient)->fetchPage(10, 0);
            self::fail('The fetch should fail.');
        } catch (ImportFailed $exception) {
            self::assertSame('The dummyjson.com response could not be read.', $exception->getMessage());
            self::assertTrue($closed);
        }
    }

    #[Test]
    public function errorStatusClosesTheBody(): void
    {
        $closed = false;
        $body = FnStream::decorate(new HttpFactory()->createStream(''), [
            'close' => static function () use (&$closed): void {
                $closed = true;
            },
        ]);
        $httpClient = new FakeHttpClient(new Response(503, [], $body));

        try {
            (void) self::client($httpClient)->fetchPage(10, 0);
            self::fail('The fetch should fail.');
        } catch (ImportFailed) {
            self::assertTrue($closed);
        }
    }

    #[Test]
    public function bodyThatTakesLongerThanTheTimeLimitFailsTheFetch(): void
    {
        $halfTheLimit = DummyJsonProductClient::FETCH_TIME_LIMIT_SECONDS * 1_000_000_000 / 2;
        $largeBody = '{"total": 0, "products": [], "padding": "' . str_repeat('a', 100_000) . '"}';
        $httpClient = new FakeHttpClient(new Response(200, [], $largeBody));
        $client = new DummyJsonProductClient(
            $httpClient,
            new HttpFactory(),
            new SteppingClock((int) $halfTheLimit + 1),
        );

        $this->expectException(ImportFailed::class);
        $this->expectExceptionMessage('The dummyjson.com response took longer than 8 seconds.');

        (void) $client->fetchPage(10, 0);
    }

    private static function client(FakeHttpClient $httpClient): DummyJsonProductClient
    {
        return new DummyJsonProductClient($httpClient, new HttpFactory(), new SteppingClock());
    }
}
