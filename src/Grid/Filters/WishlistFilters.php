<?php

declare(strict_types=1);

namespace Summit\Wishlist\Grid\Filters;

use PrestaShop\PrestaShop\Core\Search\Filters;
use Summit\Wishlist\Grid\Definition\Factory\WishlistGridDefinitionFactory;

class WishlistFilters extends Filters
{
    protected $filterId = WishlistGridDefinitionFactory::GRID_ID;

    public static function getDefaults()
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'orderBy' => 'id_wishlist',
            'sortOrder' => 'desc',
            'filters' => [],
        ];
    }
}
