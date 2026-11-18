<?php

declare(strict_types=1);

namespace MaxServ\App\Pagination;

use InvalidArgumentException;

/**
 * Which page of a list to show, and how many items a page holds.
 */
final readonly class PageRequest
{
    public const int DEFAULT_PAGE = 1;
    public const int DEFAULT_PER_PAGE = 20;
    public const array PER_PAGE_OPTIONS = [10, 20, 50];
    private const int MAX_PAGE_DIGITS = 6;

    /**
     * Creates the page request.
     *
     * @param int $page The page number, from 1.
     * @param int $perPage The number of items per page, one of PER_PAGE_OPTIONS.
     *
     * @throws InvalidArgumentException The page is below 1 or the number per page is not allowed.
     */
    public function __construct(
        public int $page = self::DEFAULT_PAGE,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('The page must be 1 or higher.');
        }

        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            throw new InvalidArgumentException('The number per page must be one of ' . implode(', ', self::PER_PAGE_OPTIONS) . '.');
        }
    }

    /**
     * Reads the page request from the query parameters page and perPage; a missing or invalid value uses its default.
     *
     * @param array<string, mixed> $query The query parameters.
     *
     * @return self The page request.
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            self::readPage($query['page'] ?? null),
            self::readPerPage($query['perPage'] ?? null),
        );
    }

    /**
     * Returns a copy of this request for another page.
     *
     * @param int $page The page number, from 1.
     *
     * @return self The copy.
     * @throws InvalidArgumentException The page is below 1.
     */
    #[\NoDiscard]
    public function withPage(int $page): self
    {
        if ($page < 1) {
            throw new InvalidArgumentException('The page must be 1 or higher.');
        }

        return clone($this, ['page' => $page]);
    }

    /**
     * Returns the number of the last page for a number of items; an empty list has one page.
     *
     * @param int $totalItems The number of items in the whole list.
     *
     * @return int The last page number, from 1.
     */
    #[\NoDiscard]
    public function lastPageFor(int $totalItems): int
    {
        return max(1, intdiv($totalItems + $this->perPage - 1, $this->perPage));
    }

    /**
     * Returns this request, or a request for the last page when this page is past it.
     *
     * @param int $totalItems The number of items in the whole list.
     *
     * @return self The request for an existing page.
     */
    #[\NoDiscard]
    public function clampedTo(int $totalItems): self
    {
        $lastPage = $this->lastPageFor($totalItems);

        return $this->page > $lastPage ? $this->withPage($lastPage) : $this;
    }

    /**
     * Returns the number of items before this page.
     *
     * @return int The offset.
     */
    #[\NoDiscard]
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private static function readPage(mixed $value): int
    {
        $page = self::readPositiveInteger($value);

        return $page ?? self::DEFAULT_PAGE;
    }

    private static function readPerPage(mixed $value): int
    {
        $perPage = self::readPositiveInteger($value);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    private static function readPositiveInteger(mixed $value): ?int
    {
        if (!is_string($value) || strlen($value) > self::MAX_PAGE_DIGITS || !ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= 1 ? $number : null;
    }
}
