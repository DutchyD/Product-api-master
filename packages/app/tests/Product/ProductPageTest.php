<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Product;

use MaxServ\App\Pagination\PageRequest;
use MaxServ\App\Product\ProductListRequest;
use MaxServ\App\Product\ProductPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductPage::class)]
#[UsesClass(PageRequest::class)]
#[UsesClass(ProductListRequest::class)]
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
}
