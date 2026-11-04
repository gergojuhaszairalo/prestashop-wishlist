<?php

declare(strict_types=1);

namespace Summit\Wishlist\Service;

use Context;
use Db;
use PrestaShopLogger;
use Summit\Wishlist\Cart\PrestaShopCartGateway;
use Summit\Wishlist\Cart\PurchasePolicy;
use Summit\Wishlist\Catalog\PrestaShopProductCatalog;
use Summit\Wishlist\Owner\OwnerResolver;
use Summit\Wishlist\Owner\WishlistOwner;
use Summit\Wishlist\Presenter\WishlistPresenter;
use Summit\Wishlist\Repository\DbWishlistRepository;
use Summit\Wishlist\Security\CsrfTokenManager;

/**
 * Explicit wiring for Stage 1. Works in front office, back office and install code
 * without a Symfony cache rebuild; replace with config/common.yml when the API needs DI.
 */
class ServiceFactory
{
    /** @var Context */
    private $context;
    /** @var array<string, object> */
    private $instances = [];
    /** @var WishlistOwner|null */
    private $owner;
    /** @var int */
    private $claimed = 0;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function owner(): WishlistOwner
    {
        if ($this->owner === null) {
            $resolver = new OwnerResolver($this->context);
            $this->owner = $resolver->resolve();
            $this->claimPendingGuestList($resolver, $this->owner);
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
     * Lazy claim: the first request after any login path moves the guest list into the
     * account. Idempotent: the guest token is forgotten afterwards; on failure it is kept
     * so the next request retries, and the page still renders.
     */
    private function claimPendingGuestList(OwnerResolver $resolver, WishlistOwner $owner): void
    {
        $guest = $resolver->pendingGuestOwner();
        if ($guest === null) {
            return;
        }
        try {
            $this->claimed = $this->wishlistService()->claimGuestList($guest, $owner);
            $resolver->forgetGuest();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('summitwishlist: guest list claim failed: ' . $e->getMessage(), 2, null, 'Customer', (int) $owner->getCustomerId());
        }
    }

    public function repository(): DbWishlistRepository
    {
        return $this->instances['repository'] ??= new DbWishlistRepository(Db::getInstance());
    }

    public function catalog(): PrestaShopProductCatalog
    {
        return $this->instances['catalog'] ??= new PrestaShopProductCatalog($this->context);
    }

    public function csrf(): CsrfTokenManager
    {
        return $this->instances['csrf'] ??= new CsrfTokenManager($this->context->cookie, (string) _COOKIE_KEY_);
    }

    public function wishlistService(): WishlistService
    {
        return $this->instances['service'] ??= new WishlistService(
            $this->repository(),
            new WishlistAccessPolicy(),
            $this->catalog(),
            new PurchasePolicy($this->context, $this->catalog()),
            new PrestaShopCartGateway($this->context),
            $this->context->getTranslator()->trans('My wishlist', [], 'Modules.Summitwishlist.Shop')
        );
    }

    public function presenter(): WishlistPresenter
    {
        return $this->instances['presenter'] ??= new WishlistPresenter($this->context, $this->wishlistService(), $this->catalog());
    }
}
