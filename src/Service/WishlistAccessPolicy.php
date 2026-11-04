<?php

declare(strict_types=1);

namespace Summit\Wishlist\Service;

use Summit\Wishlist\Owner\WishlistOwner;

/**
 * Single place for wishlist authorisation. Stage 1: private lists, owner and same shop only.
 * Sharing rules are added here later so every transport inherits them.
 */
class WishlistAccessPolicy
{
    public function canView(WishlistOwner $owner, array $wishlist): bool
    {
        return (int) $wishlist['id_shop'] === $owner->getShopId()
            && hash_equals((string) $wishlist['owner_key'], $owner->getOwnerKey());
    }

    public function canEdit(WishlistOwner $owner, array $wishlist): bool
    {
        return $this->canView($owner, $wishlist);
    }
}
