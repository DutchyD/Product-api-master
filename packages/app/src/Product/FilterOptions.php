<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * The values the product list can be filtered on: every stored category and brand.
 */
final readonly class FilterOptions
{
    /**
     * Creates the filter options.
     *
     * @param list<string> $categories The distinct stored categories, in alphabetical order.
     * @param list<string> $brands The distinct stored brands, in alphabetical order.
     */
    public function __construct(
        public array $categories,
        public array $brands,
    ) {
    }
}
