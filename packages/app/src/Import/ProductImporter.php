<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use MaxServ\App\Product\Product;
use MaxServ\App\Product\ProductRepository;
use PDOException;

/**
 * Imports products from dummyjson.com into the product repository.
 */
final readonly class ProductImporter
{
    public const int BATCH_SIZE = 100;

    /**
     * Creates the importer.
     *
     * @param DummyJsonProductClient $client Fetches the products.
     * @param DummyJsonProductMapper $mapper Validates and converts each product.
     * @param ProductRepository $repository Stores the products.
     */
    public function __construct(
        private DummyJsonProductClient $client,
        private DummyJsonProductMapper $mapper,
        private ProductRepository $repository,
    ) {
    }

    /**
     * Fetches the requested products in batches and stores each batch; invalid products are skipped and counted.
     *
     * Batches stored before a failure stay stored.
     *
     * @param ImportRequest $request How many products to import, and how many to skip first.
     *
     * @return ImportResult What was stored and skipped.
     * @throws ImportFailed dummyjson.com could not be reached or answered invalidly, or a batch could not be stored.
     */
    #[\NoDiscard]
    public function import(ImportRequest $request): ImportResult
    {
        $remaining = $request->count;
        $skip = $request->offset;
        $importedCount = 0;
        $skippedReasons = [];
        $sourceTotal = 0;

        while ($remaining > 0) {
            $limit = min(self::BATCH_SIZE, $remaining);
            $page = $this->client->fetchPage($limit, $skip);
            $sourceTotal = $page->total;
            $rawProducts = array_slice($page->products, 0, $limit);

            [$products, $batchSkippedReasons] = $this->mapAll($rawProducts);
            $this->store($products);

            $importedCount += count($products);
            $skippedReasons = [...$skippedReasons, ...$batchSkippedReasons];
            $remaining -= count($rawProducts);
            $skip += count($rawProducts);

            if (count($rawProducts) < $limit || $skip >= $sourceTotal) {
                break;
            }
        }

        return new ImportResult($importedCount, $skippedReasons, $sourceTotal, $request->offset >= $sourceTotal);
    }

    /**
     * @param list<mixed> $rawProducts
     *
     * @return array{list<Product>, list<string>} The valid products, and the reason for each invalid one.
     */
    private function mapAll(array $rawProducts): array
    {
        $products = [];
        $skippedReasons = [];

        foreach ($rawProducts as $rawProduct) {
            try {
                $products[] = $this->mapper->map($rawProduct);
            } catch (InvalidProductData $exception) {
                $skippedReasons[] = $exception->getMessage();
            }
        }

        return [$products, $skippedReasons];
    }

    /**
     * @param list<Product> $products
     */
    private function store(array $products): void
    {
        try {
            $this->repository->save($products);
        } catch (PDOException $exception) {
            throw new ImportFailed('The imported products could not be stored.', previous: $exception);
        }
    }
}
