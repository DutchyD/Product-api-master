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
    public function missingQueryUsesTheDefaults(): void
    {
        $request = PageRequest::fromQuery([]);

        self::assertSame(1, $request->page);
        self::assertSame(20, $request->perPage);
    }

    #[Test]
    public function validQueryValuesAreUsed(): void
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
    public function invalidPageFallsBackToPageOne(mixed $page): void
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
    public function invalidPerPageFallsBackToTwenty(mixed $perPage): void
    {
        $request = PageRequest::fromQuery(['perPage' => $perPage]);

        self::assertSame(20, $request->perPage);
    }

    #[Test]
    public function offsetCountsTheItemsOnEarlierPages(): void
    {
        $request = new PageRequest(3, 10);

        self::assertSame(20, $request->offset());
    }

    #[Test]
    public function withPageReturnsACopyForTheOtherPage(): void
    {
        $request = new PageRequest(3, 50);

        $copy = $request->withPage(1);

        self::assertSame(1, $copy->page);
        self::assertSame(50, $copy->perPage);
        self::assertSame(3, $request->page);
    }

    #[Test]
    public function withPageRejectsPageZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) new PageRequest()->withPage(0);
    }

    #[Test]
    public function constructorRejectsAPerPageValueThatIsNotAnOption(): void
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
    public function lastPageCoversEveryItem(int $totalItems, int $perPage, int $expectedLastPage): void
    {
        $request = new PageRequest(1, $perPage);

        self::assertSame($expectedLastPage, $request->lastPageFor($totalItems));
    }

    #[Test]
    public function pagePastTheLastPageIsClampedToTheLastPage(): void
    {
        $request = new PageRequest(999_999, 20);

        self::assertSame(2, $request->clampedTo(30)->page);
    }

    #[Test]
    public function pageWithinTheListIsNotClamped(): void
    {
        $request = new PageRequest(2, 20);

        self::assertSame($request, $request->clampedTo(30));
    }
}
