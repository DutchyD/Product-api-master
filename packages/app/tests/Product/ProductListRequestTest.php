<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Product;

use MaxServ\App\Pagination\PageRequest;
use MaxServ\App\Product\ProductListRequest;
use MaxServ\App\Product\SortColumn;
use MaxServ\App\Product\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductListRequest::class)]
#[CoversClass(SortDirection::class)]
#[UsesClass(PageRequest::class)]
final class ProductListRequestTest extends TestCase
{
    #[Test]
    public function missingQueryShowsEveryProductSortedById(): void
    {
        $request = ProductListRequest::fromQuery([]);

        self::assertNull($request->category);
        self::assertNull($request->brand);
        self::assertNull($request->sortColumn);
        self::assertSame(SortDirection::Ascending, $request->sortDirection);
        self::assertFalse($request->isFiltered());
    }

    #[Test]
    public function validQueryValuesAreUsed(): void
    {
        $request = ProductListRequest::fromQuery([
            'category' => 'smartphones',
            'brand' => 'Apple',
            'sort' => 'price',
            'direction' => 'desc',
            'page' => '2',
            'perPage' => '10',
        ]);

        self::assertSame('smartphones', $request->category);
        self::assertSame('Apple', $request->brand);
        self::assertSame(SortColumn::Price, $request->sortColumn);
        self::assertSame(SortDirection::Descending, $request->sortDirection);
        self::assertSame(2, $request->pageRequest->page);
        self::assertSame(10, $request->pageRequest->perPage);
        self::assertTrue($request->isFiltered());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'unknown sort column' => [['sort' => 'drop']];
        yield 'sort column in other case' => [['sort' => 'Price']];
        yield 'sort as array' => [['sort' => ['price']]];
        yield 'empty category' => [['category' => '']];
        yield 'category too long' => [['category' => str_repeat('a', 101)]];
        yield 'brand too long' => [['brand' => str_repeat('a', 256)]];
        yield 'brand as array' => [['brand' => ['Apple']]];
        yield 'category not valid UTF-8' => [['category' => "\xFF"]];
        yield 'brand with a cut-off UTF-8 character' => [['brand' => "Nestl\xC3"]];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[Test]
    #[DataProvider('invalidQueries')]
    public function invalidValuesFallBackToNoFilterAndNoSorting(array $query): void
    {
        $request = ProductListRequest::fromQuery($query);

        self::assertNull($request->sortColumn);
        self::assertFalse($request->isFiltered());
    }

    #[Test]
    public function unknownDirectionFallsBackToAscending(): void
    {
        $request = ProductListRequest::fromQuery(['sort' => 'title', 'direction' => 'up']);

        self::assertSame(SortDirection::Ascending, $request->sortDirection);
    }

    #[Test]
    public function directionWithoutASortColumnIsIgnored(): void
    {
        $request = ProductListRequest::fromQuery(['direction' => 'desc']);

        self::assertSame(SortDirection::Ascending, $request->sortDirection);
    }

    #[Test]
    public function filtersAtTheirMaximumLengthAreAccepted(): void
    {
        $request = ProductListRequest::fromQuery(['category' => str_repeat('é', 100), 'brand' => str_repeat('b', 255)]);

        self::assertSame(str_repeat('é', 100), $request->category);
        self::assertSame(str_repeat('b', 255), $request->brand);
    }

    #[Test]
    public function queryLeavesOutDefaultValues(): void
    {
        $request = new ProductListRequest();

        self::assertSame([], $request->queryWith([]));
    }

    #[Test]
    public function pageChangeKeepsTheFiltersSortingAndPageSize(): void
    {
        $request = self::filteredAndSorted();

        $query = $request->queryWith(['page' => 3]);

        self::assertSame(
            [
                'category' => 'smartphones',
                'brand' => 'Apple',
                'sort' => 'price',
                'direction' => 'desc',
                'page' => 3,
                'perPage' => 10,
            ],
            $query,
        );
    }

    #[Test]
    public function otherChangesStartAgainAtPageOne(): void
    {
        $request = self::filteredAndSorted();

        $query = $request->queryWith(['perPage' => 50]);

        self::assertArrayNotHasKey('page', $query);
        self::assertSame(50, $query['perPage']);
    }

    #[Test]
    public function clearingTheFiltersKeepsTheSorting(): void
    {
        $request = self::filteredAndSorted();

        $query = $request->queryWith(['category' => null, 'brand' => null]);

        self::assertSame(['sort' => 'price', 'direction' => 'desc', 'perPage' => 10], $query);
    }

    #[Test]
    public function headerOfAnotherColumnSortsItAscending(): void
    {
        $request = self::filteredAndSorted();

        $query = $request->sortQueryFor(SortColumn::Title);

        self::assertSame(['category' => 'smartphones', 'brand' => 'Apple', 'sort' => 'title', 'perPage' => 10], $query);
    }

    #[Test]
    public function headerOfTheSortedColumnReversesIt(): void
    {
        $descending = self::filteredAndSorted();
        $ascending = new ProductListRequest(sortColumn: SortColumn::Price);

        self::assertArrayNotHasKey('direction', $descending->sortQueryFor(SortColumn::Price));
        self::assertSame('desc', $ascending->sortQueryFor(SortColumn::Price)['direction']);
    }

    #[Test]
    public function onlyTheSortedColumnHasADirection(): void
    {
        $request = self::filteredAndSorted();

        self::assertSame(SortDirection::Descending, $request->directionOf(SortColumn::Price));
        self::assertNull($request->directionOf(SortColumn::Brand));
    }

    #[Test]
    public function oppositeDirectionReverses(): void
    {
        self::assertSame(SortDirection::Descending, SortDirection::Ascending->opposite());
        self::assertSame(SortDirection::Ascending, SortDirection::Descending->opposite());
    }

    #[Test]
    public function withPageRequestKeepsEverythingElse(): void
    {
        $request = self::filteredAndSorted();

        $copy = $request->withPageRequest(new PageRequest(5, 10));

        self::assertSame(5, $copy->pageRequest->page);
        self::assertSame('smartphones', $copy->category);
        self::assertSame(SortColumn::Price, $copy->sortColumn);
        self::assertSame(2, $request->pageRequest->page);
    }

    private static function filteredAndSorted(): ProductListRequest
    {
        return new ProductListRequest(
            new PageRequest(2, 10),
            'smartphones',
            'Apple',
            SortColumn::Price,
            SortDirection::Descending,
        );
    }
}
