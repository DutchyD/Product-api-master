<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * The order in which a sorted column runs.
 */
enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';

    /**
     * Returns the other direction.
     *
     * @return self Descending for ascending, and ascending for descending.
     */
    #[\NoDiscard]
    public function opposite(): self
    {
        return match ($this) {
            self::Ascending => self::Descending,
            self::Descending => self::Ascending,
        };
    }
}
