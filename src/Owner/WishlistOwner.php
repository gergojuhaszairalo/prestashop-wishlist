<?php

declare(strict_types=1);

namespace Summit\Wishlist\Owner;

/**
 * Who owns a wishlist: a logged-in customer or a guest browser.
 * Built only by OwnerResolver from the server-side context.
 */
final class WishlistOwner
{
    /** @var int */
    private $shopId;
    /** @var string */
    private $ownerKey;
    /** @var int|null */
    private $customerId;

    public function __construct(int $shopId, string $ownerKey, ?int $customerId)
    {
        $this->shopId = $shopId;
        $this->ownerKey = $ownerKey;
        $this->customerId = $customerId;
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
