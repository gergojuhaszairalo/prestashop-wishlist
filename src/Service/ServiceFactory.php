<?php

declare(strict_types=1);

namespace Summit\Wishlist\Service;

use Context;
use Doctrine\DBAL\Connection;
use Summit\Wishlist\Cart\PrestaShopCartGateway;
use Summit\Wishlist\Cart\PurchasePolicy;
use Summit\Wishlist\Catalog\PrestaShopProductCatalog;
use Summit\Wishlist\Install\Installer;
use Summit\Wishlist\Owner\OwnerResolver;
use Summit\Wishlist\Owner\WishlistOwner;
use Summit\Wishlist\Owner\WishlistSession;
use Summit\Wishlist\Presenter\WishlistPresenter;
use Summit\Wishlist\Repository\DbWishlistRepository;
use Summit\Wishlist\Security\CsrfTokenManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service access for hooks and front controllers. Services are defined in config/common.yml
 * and taken from the container (example-modules convention). When the container does not
 * know them yet (during install, or right after the 1.2.0 files replace 1.1.0 and before
 * the cache is rebuilt) they are wired by hand the same way, as demodoctrine does for its
 * installer, so the storefront never breaks on a stale container.
 */
class ServiceFactory
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        private readonly Context $context,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function session(): WishlistSession
    {
        return $this->resolve(WishlistSession::class, fn () => new WishlistSession(
            new OwnerResolver($this->context),
            $this->listManager(),
            $this->wishlistService()
        ));
    }

    public function owner(): WishlistOwner
    {
        return $this->session()->owner();
    }

    /**
     * Items moved from the guest list on this request (0 when nothing was claimed).
     */
    public function claimedCount(): int
    {
        return $this->session()->claimedCount();
    }

    /**
     * Claimed guest lists renamed on this request because the account had a list of that name.
     */
    public function claimRenamedCount(): int
    {
        return $this->session()->claimRenamedCount();
    }

    public function installer(): Installer
    {
        return $this->resolve(Installer::class, fn () => new Installer($this->connection(), _DB_PREFIX_));
    }

    public function repository(): DbWishlistRepository
    {
        return $this->resolve(DbWishlistRepository::class, fn () => new DbWishlistRepository($this->connection(), _DB_PREFIX_));
    }

    public function catalog(): PrestaShopProductCatalog
    {
        return $this->resolve(PrestaShopProductCatalog::class, fn () => new PrestaShopProductCatalog($this->context));
    }

    public function csrf(): CsrfTokenManager
    {
        return $this->resolve(CsrfTokenManager::class, fn () => CsrfTokenManager::fromContext($this->context));
    }

    public function wishlistService(): WishlistService
    {
        return $this->resolve(WishlistService::class, fn () => WishlistService::fromContext(
            $this->repository(),
            new WishlistAccessPolicy(),
            $this->catalog(),
            new PurchasePolicy($this->context, $this->catalog()),
            new PrestaShopCartGateway($this->context),
            $this->context
        ));
    }

    public function listManager(): WishlistListManager
    {
        return $this->resolve(WishlistListManager::class, fn () => new WishlistListManager(
            $this->repository(),
            new WishlistAccessPolicy(),
            $this->catalog(),
            new WishlistNameValidator()
        ));
    }

    public function presenter(): WishlistPresenter
    {
        return $this->resolve(WishlistPresenter::class, fn () => new WishlistPresenter(
            $this->context,
            $this->wishlistService(),
            $this->catalog(),
            $this->connection(),
            _DB_PREFIX_
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param callable(): T $build
     *
     * @return T
     */
    private function resolve(string $id, callable $build): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if ($this->container !== null && $this->container->has($id)) {
            return $this->instances[$id] = $this->container->get($id);
        }

        return $this->instances[$id] = $build();
    }

    private function connection(): Connection
    {
        if ($this->container !== null && $this->container->has('doctrine.dbal.default_connection')) {
            return $this->container->get('doctrine.dbal.default_connection');
        }

        return \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('doctrine.dbal.default_connection');
    }
}
