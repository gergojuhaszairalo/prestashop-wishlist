<?php

declare(strict_types=1);

namespace Summit\Wishlist\Cart;

/**
 * Outcome of adding one wishlist item to the cart.
 * Codes: added, unavailable, no_access, needs_options, out_of_stock, min_quantity, max_quantity, failed.
 */
final class CartAdditionResult
{
    /** @var int */
    public $itemId;
    /** @var string */
    public $code;
    /** @var string */
    public $productName;
    /** @var int */
    public $requestedQuantity;
    /** @var int */
    public $addedQuantity;

    public function __construct(int $itemId, string $code, string $productName, int $requestedQuantity, int $addedQuantity)
    {
        $this->itemId = $itemId;
        $this->code = $code;
        $this->productName = $productName;
        $this->requestedQuantity = $requestedQuantity;
        $this->addedQuantity = $addedQuantity;
    }

    public function isAdded(): bool
    {
        return $this->code === 'added';
    }
}
