<?php

declare(strict_types=1);

namespace Summit\Wishlist\Owner;

/**
 * Who owns a wishlist: a logged-in customer or a guest browser.
 * Built only by OwnerResolver from the server-side context.
 */
final class WishlistOwner
{
    public function __construct(
        private readonly int $shopId,
        private readonly string $ownerKey,
        private readonly ?int $customerId,
    ) {
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function isGuest(): bool
    {
        return $this->customerId === null;
    }
}
