<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Fake;

use MaxServ\App\Product\FilterOptions;
use MaxServ\App\Product\Product;
use MaxServ\App\Product\ProductListRequest;
use MaxServ\App\Product\ProductPage;
use MaxServ\App\Product\ProductRepository;
use MaxServ\App\Product\SortColumn;
use MaxServ\App\Product\SortDirection;
use PDOException;

/**
 * Keeps products in memory, and can fail a chosen save call.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<int, Product> */
    private array $products = [];

    private int $saveCalls = 0;

    /**
     * Creates the repository.
     *
     * @param int|null $failOnSaveCall The 1-based save call that throws a PDOException, or null to never fail.
     */
    public function __construct(private readonly ?int $failOnSaveCall = null)
    {
    }

    /**
     * Stores the products by id, or throws on the configured save call.
     *
     * @param list<Product> $products The products to store.
     *
     * @return void
     * @throws PDOException This is the save call configured to fail.
     */
    #[\Override]
    public function save(array $products): void
    {
        $this->saveCalls++;
        if ($this->saveCalls === $this->failOnSaveCall) {
            throw new PDOException('Simulated database failure.');
        }

        foreach ($products as $product) {
            $this->products[$product->id] = $product;
        }
    }

    /**
     * Finds a stored product by its id.
     *
     * @param int $id The product id.
     *
     * @return Product|null The product, or null when none has this id.
     */
    #[\Override]
    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    /**
     * Returns one page of the stored products that match the filters, in the requested order, clamped to the last
     * page. Filters match exactly; text is ordered without regard to case, which matches a database collation for
     * ASCII text only.
     *
     * @param ProductListRequest $listRequest The filters, sorting and page to find.
     *
     * @return ProductPage The page.
     */
    #[\Override]
    public function findPage(ProductListRequest $listRequest): ProductPage
    {
        $matching = array_values(array_filter(
            $this->products,
            static fn (Product $product): bool =>
                ($listRequest->category === null || $product->category === $listRequest->category)
                && ($listRequest->brand === null || $product->brand === $listRequest->brand),
        ));
        usort($matching, static fn (Product $a, Product $b): int => self::compare($a, $b, $listRequest));

        $pageRequest = $listRequest->pageRequest->clampedTo(count($matching));
        $products = array_slice($matching, $pageRequest->offset(), $pageRequest->perPage);

        return new ProductPage($products, $listRequest->withPageRequest($pageRequest), count($matching));
    }

    /**
     * Returns the distinct stored categories and brands in alphabetical order.
     *
     * @return FilterOptions The filter options.
     */
    #[\Override]
    public function findFilterOptions(): FilterOptions
    {
        $categories = array_values(array_unique(array_map(
            static fn (Product $product): string => $product->category,
            $this->products,
        )));
        $brands = array_values(array_unique(array_filter(
            array_map(static fn (Product $product): ?string => $product->brand, $this->products),
            static fn (?string $brand): bool => $brand !== null,
        )));
        sort($categories, SORT_STRING | SORT_FLAG_CASE);
        sort($brands, SORT_STRING | SORT_FLAG_CASE);

        return new FilterOptions($categories, $brands);
    }

    private static function compare(Product $a, Product $b, ProductListRequest $listRequest): int
    {
        $sign = $listRequest->sortDirection === SortDirection::Ascending ? 1 : -1;

        $result = match ($listRequest->sortColumn) {
            null => 0,
            SortColumn::Title => $sign * strcasecmp($a->title, $b->title),
            SortColumn::Price => $sign * ($a->discountedPriceInCents() <=> $b->discountedPriceInCents()),
            SortColumn::Brand => ($a->brand === null) <=> ($b->brand === null)
                ?: $sign * strcasecmp((string) $a->brand, (string) $b->brand),
            SortColumn::Category => $sign * strcasecmp($a->category, $b->category),
        };

        return $result ?: $a->id <=> $b->id;
    }

    /**
     * Returns the ids of the stored products in ascending order.
     *
     * @return list<int> The ids.
     */
    public function storedIds(): array
    {
        $ids = array_keys($this->products);
        sort($ids);

        return $ids;
    }

    /**
     * Returns how often save was called.
     *
     * @return int The number of save calls.
     */
    public function saveCalls(): int
    {
        return $this->saveCalls;
    }
}
