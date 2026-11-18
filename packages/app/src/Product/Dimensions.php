<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * The size of a product.
 */
final readonly class Dimensions
{
    /**
     * Creates the dimensions.
     *
     * @param float $width The width.
     * @param float $height The height.
     * @param float $depth The depth.
     */
    public function __construct(
        public float $width,
        public float $height,
        public float $depth,
    ) {
    }
}
