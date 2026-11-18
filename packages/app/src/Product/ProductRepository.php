<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * Stores and finds products.
 */
interface ProductRepository
{
    /**
     * Inserts the products, or updates them with their tags and images when they already exist, in one transaction.
     *
     * @param list<Product> $products The products to store.
     *
     * @return void
     * @throws \PDOException The products could not be stored; none of them are stored.
     */
    public function save(array $products): void;

    /**
     * Finds a product by its id.
     *
     * @param int $id The product id.
     *
     * @return Product|null The product, or null when no product has this id.
     * @throws \PDOException The products could not be read.
     */
    public function findById(int $id): ?Product;

    /**
     * Finds one page of the products that match the filters, in the requested order; a page past the last page
     * returns the last page.
     *
     * @param ProductListRequest $listRequest The filters, sorting and page to find.
     *
     * @return ProductPage The page.
     * @throws \PDOException The products could not be read.
     */
    public function findPage(ProductListRequest $listRequest): ProductPage;

    /**
     * Finds every stored category and brand, for the filters of the product list.
     *
     * @return FilterOptions The distinct categories and brands, in alphabetical order.
     * @throws \PDOException The products could not be read.
     */
    public function findFilterOptions(): FilterOptions;
}
