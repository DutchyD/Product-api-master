<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

use MaxServ\App\Pagination\PageRequest;

/**
 * Which products the list shows, in which order, and which page of them.
 */
final readonly class ProductListRequest
{
    public const int MAX_CATEGORY_LENGTH = 100;
    public const int MAX_BRAND_LENGTH = 255;

    private const string CATEGORY = 'category';
    private const string BRAND = 'brand';
    private const string SORT = 'sort';
    private const string DIRECTION = 'direction';
    private const string PAGE = 'page';
    private const string PER_PAGE = 'perPage';

    /**
     * Creates the list request.
     *
     * @param PageRequest $pageRequest The page to show.
     * @param string|null $category Show only products in this category, or null for every category.
     * @param string|null $brand Show only products of this brand, or null for every brand.
     * @param SortColumn|null $sortColumn The column to sort on, or null to sort by id.
     * @param SortDirection $sortDirection The direction of the sorted column.
     */
    public function __construct(
        public PageRequest $pageRequest = new PageRequest(),
        public ?string $category = null,
        public ?string $brand = null,
        public ?SortColumn $sortColumn = null,
        public SortDirection $sortDirection = SortDirection::Ascending,
    ) {
    }

    /**
     * Reads the list request from the query parameters; a missing or invalid value, including a filter that is not
     * valid UTF-8, uses its default.
     *
     * @param array<string, mixed> $query The query parameters category, brand, sort, direction, page and perPage.
     *
     * @return self The list request.
     */
    public static function fromQuery(array $query): self
    {
        $sortColumn = is_string($query[self::SORT] ?? null) ? SortColumn::tryFrom($query[self::SORT]) : null;
        $sortDirection = $sortColumn === null
            ? SortDirection::Ascending
            : self::readDirection($query[self::DIRECTION] ?? null);

        return new self(
            PageRequest::fromQuery($query),
            self::readFilter($query[self::CATEGORY] ?? null, self::MAX_CATEGORY_LENGTH),
            self::readFilter($query[self::BRAND] ?? null, self::MAX_BRAND_LENGTH),
            $sortColumn,
            $sortDirection,
        );
    }

    /**
     * Returns a copy of this request for another page.
     *
     * @param PageRequest $pageRequest The page to show.
     *
     * @return self The copy.
     */
    #[\NoDiscard]
    public function withPageRequest(PageRequest $pageRequest): self
    {
        return clone($this, ['pageRequest' => $pageRequest]);
    }

    /**
     * Tells whether a category or brand filter is set.
     *
     * @return bool True when the list shows only some of the products.
     */
    #[\NoDiscard]
    public function isFiltered(): bool
    {
        return $this->category !== null || $this->brand !== null;
    }

    /**
     * Returns the direction of a column when the list is sorted on it.
     *
     * @param SortColumn $column The column.
     *
     * @return SortDirection|null The direction, or null when the list is not sorted on this column.
     */
    #[\NoDiscard]
    public function directionOf(SortColumn $column): ?SortDirection
    {
        return $this->sortColumn === $column ? $this->sortDirection : null;
    }

    /**
     * Returns the query for the header link of a column: ascending for another column, reversed for the sorted one.
     *
     * The link keeps the filters and the page size and starts at page 1.
     *
     * @param SortColumn $column The column of the header.
     *
     * @return array<string, string|int> The query parameters, without default values.
     */
    #[\NoDiscard]
    public function sortQueryFor(SortColumn $column): array
    {
        $direction = $this->directionOf($column)?->opposite() ?? SortDirection::Ascending;

        return $this->queryWith([self::SORT => $column->value, self::DIRECTION => $direction->value]);
    }

    /**
     * Returns the query parameters of this request with some values changed, for a link.
     *
     * A change to anything but the page starts again at page 1. Default values are left out, so links stay short.
     *
     * @param array<string, string|int|null> $changes The parameters to change; null or an empty string removes one.
     *
     * @return array<string, string|int> The query parameters.
     */
    #[\NoDiscard]
    public function queryWith(array $changes): array
    {
        $query = [
            self::CATEGORY => $this->category,
            self::BRAND => $this->brand,
            self::SORT => $this->sortColumn?->value,
            self::DIRECTION => $this->sortDirection->value,
            self::PAGE => $this->pageRequest->page,
            self::PER_PAGE => $this->pageRequest->perPage,
        ];

        if (array_diff_key($changes, [self::PAGE => true]) !== []) {
            $query[self::PAGE] = PageRequest::DEFAULT_PAGE;
        }

        $query = [...$query, ...$changes];
        $defaults = [
            self::DIRECTION => SortDirection::Ascending->value,
            self::PAGE => PageRequest::DEFAULT_PAGE,
            self::PER_PAGE => PageRequest::DEFAULT_PER_PAGE,
        ];

        return array_filter(
            $query,
            static fn (string|int|null $value, string $key): bool => $value !== null && $value !== ''
                && (!array_key_exists($key, $defaults) || $defaults[$key] !== $value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function readDirection(mixed $value): SortDirection
    {
        return (is_string($value) ? SortDirection::tryFrom($value) : null) ?? SortDirection::Ascending;
    }

    private static function readFilter(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || $value === '' || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            return null;
        }

        return $value;
    }
}
