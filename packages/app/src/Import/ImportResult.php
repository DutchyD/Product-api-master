<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

/**
 * What an import stored and skipped.
 */
final readonly class ImportResult
{
    /**
     * Creates the result.
     *
     * @param int $importedCount The number of products inserted or updated.
     * @param list<string> $skippedReasons Why each skipped product was skipped, one entry per product.
     * @param int $sourceTotal The number of products dummyjson.com has in total.
     * @param bool $offsetPastTotal True when the offset was at or past the last dummyjson.com product.
     */
    public function __construct(
        public int $importedCount,
        public array $skippedReasons,
        public int $sourceTotal,
        public bool $offsetPastTotal,
    ) {
    }

    /**
     * Returns the number of skipped products.
     *
     * @return int The number of products with missing or invalid data.
     */
    #[\NoDiscard]
    public function skippedCount(): int
    {
        return count($this->skippedReasons);
    }
}
