<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Pagination;

use InvalidArgumentException;
use MaxServ\App\Pagination\PageRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageRequest::class)]
final class PageRequestTest extends TestCase
{
    #[Test]
    public function missing_query_uses_the_defaults(): void
    {
        $request = PageRequest::fromQuery([]);

        self::assertSame(1, $request->page);
        self::assertSame(20, $request->perPage);
    }

    #[Test]
    public function valid_query_values_are_used(): void
    {
        $request = PageRequest::fromQuery(['page' => '3', 'perPage' => '50']);

        self::assertSame(3, $request->page);
        self::assertSame(50, $request->perPage);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPageValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['1.5'];
        yield 'empty' => [''];
        yield 'more than six digits' => ['1000000'];
        yield 'array' => [['2']];
        yield 'leading space' => [' 2'];
    }

    #[Test]
    #[DataProvider('invalidPageValues')]
    public function invalid_page_falls_back_to_page_one(mixed $page): void
    {
        $request = PageRequest::fromQuery(['page' => $page]);

        self::assertSame(1, $request->page);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPerPageValues(): iterable
    {
        yield 'not an option' => ['7'];
        yield 'zero' => ['0'];
        yield 'too large' => ['1000'];
        yield 'not a number' => ['all'];
        yield 'array' => [['10']];
    }

    #[Test]
    #[DataProvider('invalidPerPageValues')]
    public function invalid_per_page_falls_back_to_twenty(mixed $perPage): void
    {
        $request = PageRequest::fromQuery(['perPage' => $perPage]);

        self::assertSame(20, $request->perPage);
    }

    #[Test]
    public function offset_counts_the_items_on_earlier_pages(): void
    {
        $request = new PageRequest(3, 10);

        self::assertSame(20, $request->offset());
    }

    #[Test]
    public function with_page_returns_a_copy_for_the_other_page(): void
    {
        $request = new PageRequest(3, 50);

        $copy = $request->withPage(1);

        self::assertSame(1, $copy->page);
        self::assertSame(50, $copy->perPage);
        self::assertSame(3, $request->page);
    }

    #[Test]
    public function with_page_rejects_page_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) new PageRequest()->withPage(0);
    }

    #[Test]
    public function constructor_rejects_a_per_page_value_that_is_not_an_option(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageRequest(1, 7);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function lastPageCases(): iterable
    {
        yield 'empty list has one page' => [0, 20, 1];
        yield 'exactly one page' => [20, 20, 1];
        yield 'one item over a page' => [21, 20, 2];
        yield 'all dummyjson products' => [194, 50, 4];
    }

    #[Test]
    #[DataProvider('lastPageCases')]
    public function last_page_covers_every_item(int $totalItems, int $perPage, int $expectedLastPage): void
    {
        $request = new PageRequest(1, $perPage);

        self::assertSame($expectedLastPage, $request->lastPageFor($totalItems));
    }

    #[Test]
    public function page_past_the_last_page_is_clamped_to_the_last_page(): void
    {
        $request = new PageRequest(999_999, 20);

        self::assertSame(2, $request->clampedTo(30)->page);
    }

    #[Test]
    public function page_within_the_list_is_not_clamped(): void
    {
        $request = new PageRequest(2, 20);

        self::assertSame($request, $request->clampedTo(30));
    }
}
