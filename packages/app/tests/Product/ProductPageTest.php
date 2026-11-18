<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Product;

use MaxServ\App\Import\DummyJsonProductMapper;
use MaxServ\App\Pagination\PageRequest;
use MaxServ\App\Product\Dimensions;
use MaxServ\App\Product\Product;
use MaxServ\App\Product\ProductListRequest;
use MaxServ\App\Product\ProductPage;
use MaxServ\App\Tests\Fake\DummyJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductPage::class)]
#[UsesClass(PageRequest::class)]
#[UsesClass(ProductListRequest::class)]
#[UsesClass(DummyJsonProductMapper::class)]
#[UsesClass(Product::class)]
#[UsesClass(Dimensions::class)]
final class ProductPageTest extends TestCase
{
    #[Test]
    public function first_page_has_no_previous_page(): void
    {
        $page = new ProductPage([], new ProductListRequest(new PageRequest(1, 10)), 30);

        self::assertNull($page->previousPage());
        self::assertSame(2, $page->nextPage());
    }

    #[Test]
    public function middle_page_has_previous_and_next_pages(): void
    {
        $page = new ProductPage([], new ProductListRequest(new PageRequest(2, 10)), 30);

        self::assertSame(1, $page->previousPage());
        self::assertSame(3, $page->nextPage());
    }

    #[Test]
    public function last_page_has_no_next_page(): void
    {
        $page = new ProductPage([], new ProductListRequest(new PageRequest(3, 10)), 30);

        self::assertSame(2, $page->previousPage());
        self::assertNull($page->nextPage());
        self::assertSame(3, $page->lastPage);
    }

    #[Test]
    public function empty_list_has_one_page_without_neighbours(): void
    {
        $page = new ProductPage([], new ProductListRequest(new PageRequest()), 0);

        self::assertSame(1, $page->lastPage);
        self::assertNull($page->previousPage());
        self::assertNull($page->nextPage());
    }

    #[Test]
    public function positions_count_from_the_first_product_of_the_page(): void
    {
        $page = new ProductPage(self::products(10), new ProductListRequest(new PageRequest(2, 10)), 25);

        self::assertSame(11, $page->firstPosition());
        self::assertSame(20, $page->lastPosition());
    }

    #[Test]
    public function positions_on_a_short_last_page_end_at_the_last_product(): void
    {
        $page = new ProductPage(self::products(5), new ProductListRequest(new PageRequest(3, 10)), 25);

        self::assertSame(21, $page->firstPosition());
        self::assertSame(25, $page->lastPosition());
    }

    #[Test]
    public function positions_of_an_empty_page_are_zero(): void
    {
        $page = new ProductPage([], new ProductListRequest(), 0);

        self::assertSame(0, $page->firstPosition());
        self::assertSame(0, $page->lastPosition());
    }

    /**
     * Lists of products and current pages, with the page links each should show.
     *
     * @return iterable<string, array{int, int, list<int|null>}>
     */
    public static function pageLinkCases(): iterable
    {
        yield 'one page' => [5, 1, [1]];
        yield 'few pages' => [50, 3, [1, 2, 3, 4, 5]];
        yield 'one-page gap shows the page' => [40, 1, [1, 2, 3, 4]];
        yield 'current at the start' => [100, 1, [1, 2, null, 10]];
        yield 'current near the start' => [100, 3, [1, 2, 3, 4, null, 10]];
        yield 'current in the middle' => [100, 5, [1, null, 4, 5, 6, null, 10]];
        yield 'current near the end' => [100, 8, [1, null, 7, 8, 9, 10]];
        yield 'current at the end' => [100, 10, [1, null, 9, 10]];
    }

    /**
     * @param list<int|null> $expectedLinks
     */
    #[Test]
    #[DataProvider('pageLinkCases')]
    public function page_links_show_the_first_last_and_neighbouring_pages(int $totalProducts, int $currentPage, array $expectedLinks): void
    {
        $page = new ProductPage([], new ProductListRequest(new PageRequest($currentPage, 10)), $totalProducts);

        self::assertSame($expectedLinks, $page->pageLinks());
    }

    /**
     * @return list<Product>
     */
    private static function products(int $count): array
    {
        return array_map(new DummyJsonProductMapper()->map(...), DummyJson::products(1, $count));
    }
}
