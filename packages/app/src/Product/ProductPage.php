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

    /**
     * Returns the position of the first product on this page among all matching products.
     *
     * @return int The 1-based position, or 0 when no product matches.
     */
    #[\NoDiscard]
    public function firstPosition(): int
    {
        return $this->products === [] ? 0 : $this->listRequest->pageRequest->offset() + 1;
    }

    /**
     * Returns the position of the last product on this page among all matching products.
     *
     * @return int The 1-based position, or 0 when no product matches.
     */
    #[\NoDiscard]
    public function lastPosition(): int
    {
        return $this->products === [] ? 0 : $this->listRequest->pageRequest->offset() + count($this->products);
    }

    /**
     * Returns the page numbers to link to: the first page, the last page and the pages next to the current one.
     *
     * A gap of one page shows that page; a longer gap is a null entry, shown as an ellipsis.
     *
     * @return list<int|null> The page numbers in order, with null for each longer gap.
     */
    #[\NoDiscard]
    public function pageLinks(): array
    {
        $current = $this->listRequest->pageRequest->page;
        $pages = array_unique([1, $current - 1, $current, $current + 1, $this->lastPage]);
        $pages = array_values(array_filter($pages, fn (int $page): bool => $page >= 1 && $page <= $this->lastPage));
        sort($pages);

        $links = [];
        $previous = 0;
        foreach ($pages as $page) {
            if ($page - $previous === 2) {
                $links[] = $page - 1;
            } elseif ($page - $previous > 2) {
                $links[] = null;
            }

            $links[] = $page;
            $previous = $page;
        }

        return $links;
    }
}
