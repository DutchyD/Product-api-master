<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use JsonException;
use MaxServ\Core\Time\MonotonicClock;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Uri\Rfc3986\Uri;

/**
 * Fetches pages of products from the dummyjson.com products API.
 */
final readonly class DummyJsonProductClient
{
    private const string PRODUCTS_URL = 'https://dummyjson.com/products';
    private const int HTTP_OK = 200;
    private const int MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const int READ_CHUNK_BYTES = 8192;
    private const int MAX_JSON_DEPTH = 16;
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    /**
     * How long one fetch may take in wall time, checked when the response headers have arrived and between body
     * reads. A read that is already waiting can run past it by at most the read timeout of the HTTP client.
     */
    public const int FETCH_TIME_LIMIT_SECONDS = 8;

    /**
     * The product fields to request. Reviews are left out on purpose: they are never stored.
     */
    private const array SELECTED_FIELDS = [
        'title', 'description', 'category', 'price', 'discountPercentage', 'rating', 'stock', 'tags', 'brand', 'sku',
        'weight', 'dimensions', 'warrantyInformation', 'shippingInformation', 'availabilityStatus', 'returnPolicy',
        'minimumOrderQuantity', 'meta', 'images', 'thumbnail',
    ];

    /**
     * Creates the client.
     *
     * @param ClientInterface $httpClient Sends the HTTP requests.
     * @param RequestFactoryInterface $requestFactory Creates the HTTP requests.
     * @param MonotonicClock $clock Measures how long a fetch takes.
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private MonotonicClock $clock,
    ) {
    }

    /**
     * Fetches one page of products.
     *
     * @param int $limit The largest number of products to return, from 1.
     * @param int $skip How many products to skip first, from 0.
     *
     * @return DummyJsonPage The total number of products and the raw products of the page, not validated yet.
     * @throws ImportFailed The request failed; the response body could not be read or took longer than
     *                      FETCH_TIME_LIMIT_SECONDS; or the response is not a 200 response of at most 2 MiB with a
     *                      total and a product list.
     */
    public function fetchPage(int $limit, int $skip): DummyJsonPage
    {
        $deadline = $this->clock->nanoseconds() + self::FETCH_TIME_LIMIT_SECONDS * self::NANOSECONDS_PER_SECOND;
        $request = $this->requestFactory
            ->createRequest('GET', $this->pageUrl($limit, $skip))
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ImportFailed('The dummyjson.com request failed.', previous: $exception);
        }

        $body = $response->getBody();

        try {
            if ($response->getStatusCode() !== self::HTTP_OK) {
                throw new ImportFailed("dummyjson.com answered with status {$response->getStatusCode()}.");
            }

            $this->checkDeadline($deadline);

            return $this->parsePage($this->readBody($body, $deadline));
        } finally {
            $body->close();
        }
    }

    private function pageUrl(int $limit, int $skip): string
    {
        $query = http_build_query(
            ['limit' => $limit, 'skip' => $skip, 'select' => implode(',', self::SELECTED_FIELDS)],
            encoding_type: PHP_QUERY_RFC3986,
        );

        return new Uri(self::PRODUCTS_URL)->withQuery($query)->toString();
    }

    private function readBody(StreamInterface $body, int $deadline): string
    {
        $contents = '';
        while (($chunk = $this->readChunk($body)) !== null) {
            $contents .= $chunk;
            if (strlen($contents) > self::MAX_RESPONSE_BYTES) {
                throw new ImportFailed(
                    'The dummyjson.com response is larger than ' . self::MAX_RESPONSE_BYTES . ' bytes.',
                );
            }

            $this->checkDeadline($deadline);
        }

        return $contents;
    }

    private function checkDeadline(int $deadline): void
    {
        if ($this->clock->nanoseconds() > $deadline) {
            throw new ImportFailed(
                'The dummyjson.com response took longer than ' . self::FETCH_TIME_LIMIT_SECONDS . ' seconds.',
            );
        }
    }

    /**
     * @return string|null The next chunk, or null at the end of the body.
     */
    private function readChunk(StreamInterface $body): ?string
    {
        try {
            return $body->eof() ? null : $body->read(self::READ_CHUNK_BYTES);
        } catch (RuntimeException $exception) {
            throw new ImportFailed('The dummyjson.com response could not be read.', previous: $exception);
        }
    }

    private function parsePage(string $json): DummyJsonPage
    {
        try {
            $data = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ImportFailed('The dummyjson.com response is not valid JSON.', previous: $exception);
        }

        $total = is_array($data) ? ($data['total'] ?? null) : null;
        $products = is_array($data) ? ($data['products'] ?? null) : null;

        if (!is_int($total) || $total < 0 || !is_array($products) || !array_is_list($products)) {
            throw new ImportFailed('The dummyjson.com response has no valid total and product list.');
        }

        return new DummyJsonPage($total, $products);
    }
}
