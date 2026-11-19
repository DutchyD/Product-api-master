<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

/**
 * One page of raw products from dummyjson.com.
 */
final readonly class DummyJsonPage
{
    /**
     * Creates the page.
     *
     * @param int $total The number of products dummyjson.com has in total.
     * @param list<mixed> $products The raw products on this page, not validated yet;
     *                              an entry may not even be an object.
     */
    public function __construct(
        public int $total,
        public array $products,
    ) {
    }
}
