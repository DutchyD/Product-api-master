<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * One page of the products that match a list request, with what is needed to link to the other pages.
 */
final readonly class ProductPage
{
    /**
     * The number of the last page, from 1.
     */
    public int $lastPage;

    /**
     * Creates the page.
     *
     * @param list<Product> $products The products on this page, in order.
     * @param ProductListRequest $listRequest The request shown, with its page clamped to the last page.
     * @param int $totalProducts The number of products that match the filters of the request.
     */
    public function __construct(
        public array $products,
        public ProductListRequest $listRequest,
        public int $totalProducts,
    ) {
        $this->lastPage = $listRequest->pageRequest->lastPageFor($totalProducts);
    }

    /**
     * Returns the number of the previous page.
     *
     * @return int|null The previous page, or null on the first page.
     */
    #[\NoDiscard]
    public function previousPage(): ?int
    {
        $page = $this->listRequest->pageRequest->page;

        return $page > 1 ? $page - 1 : null;
    }

    /**
     * Returns the number of the next page.
     *
     * @return int|null The next page, or null on the last page.
     */
    #[\NoDiscard]
    public function nextPage(): ?int
    {
        $page = $this->listRequest->pageRequest->page;

        return $page < $this->lastPage ? $page + 1 : null;
    }
}
