<?php

declare(strict_types=1);

namespace Summit\Wishlist\Cart;

use Cart;
use Context;
use Validate;

/**
 * Thin adapter over the core cart. Never writes to cart tables directly
 * and only ever adds, so unrelated cart lines are left untouched.
 */
class PrestaShopCartGateway implements CartGatewayInterface
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function getCart(): Cart
    {
        return $this->context->cart;
    }

    public function add(int $productId, int $productAttributeId, int $quantity): string
    {
        $cart = $this->context->cart;
        if (!$cart->id) {
            $cart->add();
            if (!Validate::isLoadedObject($cart)) {
                return 'failed';
            }
            $this->context->cookie->id_cart = (int) $cart->id;
        }

        // Same arguments as the core CartController, except that availability is not skipped:
        // PurchasePolicy already checked stock, and this keeps the core check as a second guard.
        $result = $cart->updateQty($quantity, $productId, $productAttributeId, 0, 'up', 0, null, true, false);

        // updateQty returns a negative int when the minimal quantity is not met,
        // false/0 when no more can be added, true on success.
        if (is_int($result) && $result < 0) {
            return 'min_quantity';
        }
        if (!$result) {
            return 'max_quantity';
        }

        return 'added';
    }
}
