<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

/**
 * A column of the product list that the list can be sorted on.
 */
enum SortColumn: string
{
    case Title = 'title';
    case Price = 'price';
    case Brand = 'brand';
    case Category = 'category';
}
