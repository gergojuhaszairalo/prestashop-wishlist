<?php

declare(strict_types=1);

namespace Summit\Wishlist\Cart;

use Cart;

interface CartGatewayInterface
{
    /**
     * The visitor's cart; it may not be persisted yet.
     */
    public function getCart(): Cart;

    /**
     * Adds a quantity through the core cart. Returns added, min_quantity, max_quantity or failed.
     */
    public function add(int $productId, int $productAttributeId, int $quantity): string;
}
