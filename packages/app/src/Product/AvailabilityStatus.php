<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * Whether a product can be ordered from stock.
 */
enum AvailabilityStatus: string
{
    case InStock = 'In Stock';
    case LowStock = 'Low Stock';
    case OutOfStock = 'Out of Stock';
}
