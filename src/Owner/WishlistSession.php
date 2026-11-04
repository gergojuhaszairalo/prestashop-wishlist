<?php

declare(strict_types=1);

namespace Summit\Wishlist\Owner;

use PrestaShopLogger;
use Summit\Wishlist\Service\WishlistListManager;
use Summit\Wishlist\Service\WishlistService;

/**
 * The visitor's wishlist owner for this request, resolved once. A shared container service,
 * so every hook and controller of one request sees the same owner and the same claim result.
 */
class WishlistSession
{
    private ?WishlistOwner $owner = null;
    private int $claimed = 0;
    private int $claimRenamed = 0;

    public function __construct(
        private readonly OwnerResolver $resolver,
        private readonly WishlistListManager $listManager,
        private readonly WishlistService $wishlistService,
    ) {
    }

    public function owner(): WishlistOwner
    {
        if ($this->owner === null) {
            $this->owner = $this->resolver->resolve();
            $this->claimPendingGuestList($this->owner);
        }

        return $this->owner;
    }

    /**
     * Items moved from the guest list on this request (0 when nothing was claimed).
     */
    public function claimedCount(): int
    {
        return $this->claimed;
    }

    /**
     * Claimed guest lists renamed on this request because the account had a list of that name.
     */
    public function claimRenamedCount(): int
    {
        return $this->claimRenamed;
    }

    /**
     * Lazy claim, modelled on the core cart: the first request after any login path moves the
     * guest's lists into the account. Idempotent: the guest token is forgotten only after the
     * claim committed; on failure it is kept so the next request retries, and the page renders.
     */
    private function claimPendingGuestList(WishlistOwner $owner): void
    {
        $guest = $this->resolver->pendingGuestOwner();
        if ($guest === null) {
            return;
        }
        try {
            $result = $this->listManager->claimGuestLists($guest, $owner);
            $this->claimed = $result['items'];
            $this->claimRenamed = $result['renamed'];
            $this->wishlistService->forgetSavedState();
            $this->resolver->forgetGuest();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('summitwishlist: guest list claim failed: ' . $e->getMessage(), 2, null, 'Customer', (int) $owner->getCustomerId());
        }
    }
}
