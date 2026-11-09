<?php

declare(strict_types=1);

namespace Summit\Wishlist\Service;

use Summit\Wishlist\Cart\CartAdditionResult;
use Summit\Wishlist\Cart\CartGatewayInterface;
use Summit\Wishlist\Cart\PurchasePolicy;
use Summit\Wishlist\Catalog\ProductCatalogInterface;
use Summit\Wishlist\Owner\WishlistOwner;
use Summit\Wishlist\Repository\WishlistRepositoryInterface;

/**
 * Application service used by controllers, hooks and, later, API endpoints.
 * Every operation takes an explicit wishlist ID and checks access first.
 */
class WishlistService
{
    /** @var array<string, array<string, true>> */
    private array $savedCache = [];

    public function __construct(
        private readonly WishlistRepositoryInterface $repository,
        private readonly WishlistAccessPolicy $accessPolicy,
        private readonly ProductCatalogInterface $catalog,
        private readonly PurchasePolicy $purchasePolicy,
        private readonly CartGatewayInterface $cartGateway,
        private readonly string $defaultName,
    ) {
    }

    /** Container factory (config/common.yml): the default list name is translated per request. */
    public static function fromContext(
        WishlistRepositoryInterface $repository,
        WishlistAccessPolicy $accessPolicy,
        ProductCatalogInterface $catalog,
        PurchasePolicy $purchasePolicy,
        CartGatewayInterface $cartGateway,
        \Context $context
    ): self {
        return new self(
            $repository,
            $accessPolicy,
            $catalog,
            $purchasePolicy,
            $cartGateway,
            $context->getTranslator()->trans('My wishlist', [], 'Modules.Summitwishlist.Shop')
        );
    }

    public function getDefaultWishlist(WishlistOwner $owner, bool $create = false): ?array
    {
        $wishlist = $this->repository->findDefault($owner->getShopId(), $owner->getOwnerKey());
        if ($wishlist === null && $create) {
            $wishlist = $this->repository->createDefault(
                $owner->getShopId(),
                $owner->getOwnerKey(),
                $owner->getCustomerId(),
                $this->defaultName
            );
        }

        return $wishlist;
    }

    /**
     * @return array[] raw item rows, or [] when the list is missing or not accessible
     */
    public function getItems(WishlistOwner $owner, int $wishlistId): array
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canView($owner, $wishlist)) {
            return [];
        }

        return $this->repository->getItems($wishlistId);
    }

    /**
     * Saved entries in the owner's **default** list: the header badge (Stage 1 meaning, kept in Stage 2).
     */
    public function countItems(WishlistOwner $owner): int
    {
        $wishlist = $this->getDefaultWishlist($owner);

        return $wishlist ? $this->repository->countItems((int) $wishlist['id_wishlist']) : 0;
    }

    /**
     * Whether this exact product/combination is in **any** of the owner's lists (drives the filled heart).
     * With one list this is the Stage 1 meaning.
     */
    public function isSaved(WishlistOwner $owner, int $productId, int $productAttributeId): bool
    {
        return isset($this->savedVariants($owner)[$productId . '-' . $productAttributeId]);
    }

    /**
     * Loaded once per request for an owner, so many product cards cost one query.
     * Reset after every mutation.
     *
     * @return array<string, true> keyed "id_product-id_product_attribute"
     */
    private function savedVariants(WishlistOwner $owner): array
    {
        $key = $owner->getShopId() . '|' . $owner->getOwnerKey();
        if (!isset($this->savedCache[$key])) {
            $this->savedCache[$key] = $this->repository->savedVariantKeys($owner->getShopId(), $owner->getOwnerKey());
        }

        return $this->savedCache[$key];
    }

    /**
     * Call after mutations made outside this service (list manager), so hearts re-read.
     */
    public function forgetSavedState(): void
    {
        $this->savedCache = [];
    }

    /**
     * @param int[] $selectedAttributeIds attribute IDs from the product page groups
     *
     * @return array{code: string, product_name: string, id_product?: int, id_product_attribute?: int}
     *               code: added, already_present, invalid, forbidden
     */
    public function addItem(WishlistOwner $owner, int $wishlistId, int $productId, int $productAttributeId, array $selectedAttributeIds = []): array
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canEdit($owner, $wishlist)) {
            return ['code' => 'forbidden', 'product_name' => ''];
        }

        $product = $this->catalog->findSavableProduct($productId);
        if ($product === null) {
            return ['code' => 'invalid', 'product_name' => ''];
        }
        $productAttributeId = $this->catalog->resolveCombination($product, $productAttributeId, $selectedAttributeIds);
        if ($productAttributeId === null) {
            return ['code' => 'invalid', 'product_name' => (string) $product->name];
        }

        $name = (string) $product->name;
        $label = $this->catalog->combinationLabel((int) $product->id, $productAttributeId);
        $fallbackName = $label !== '' ? $name . ' (' . $label . ')' : $name;

        $inserted = $this->repository->addItem($wishlistId, (int) $product->id, $productAttributeId, $fallbackName);
        $this->savedCache = [];

        return [
            'code' => $inserted ? 'added' : 'already_present',
            'product_name' => $fallbackName,
            'id_product' => (int) $product->id,
            'id_product_attribute' => $productAttributeId,
        ];
    }

    /**
     * Heart / PDP button behaviour: removes the exact variant when it is already saved,
     * otherwise adds it. add() stays idempotent for direct requests and the future API.
     *
     * @param int[] $selectedAttributeIds attribute IDs from the product page groups
     *
     * @return array{code: string, product_name: string, saved: bool, id_product?: int, id_product_attribute?: int}
     *               code: added, removed, invalid, forbidden
     */
    public function toggleItem(WishlistOwner $owner, int $wishlistId, int $productId, int $productAttributeId, array $selectedAttributeIds = []): array
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canEdit($owner, $wishlist)) {
            return ['code' => 'forbidden', 'product_name' => '', 'saved' => false];
        }
        $product = $this->catalog->findSavableProduct($productId);
        $resolved = $product ? $this->catalog->resolveCombination($product, $productAttributeId, $selectedAttributeIds) : null;
        if ($product === null || $resolved === null) {
            return ['code' => 'invalid', 'product_name' => $product ? (string) $product->name : '', 'saved' => false];
        }

        $existing = $this->repository->findItemByVariant($wishlistId, (int) $product->id, $resolved);
        if ($existing === null) {
            $result = $this->addItem($owner, $wishlistId, (int) $product->id, $resolved);

            return $result + ['saved' => in_array($result['code'], ['added', 'already_present'], true)];
        }

        $this->repository->removeItem($wishlistId, (int) $existing['id_wishlist_product']);
        $this->savedCache = [];

        return [
            'code' => 'removed',
            'product_name' => (string) $existing['fallback_name'],
            'saved' => false,
            'id_product' => (int) $product->id,
            'id_product_attribute' => $resolved,
        ];
    }

    /**
     * @return array{code: string, product_name: string} code: removed, not_found, forbidden
     */
    public function removeItem(WishlistOwner $owner, int $wishlistId, int $itemId): array
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canEdit($owner, $wishlist)) {
            return ['code' => 'forbidden', 'product_name' => ''];
        }

        $items = $this->repository->getItemsByIds($wishlistId, [$itemId]);
        if (!$items || !$this->repository->removeItem($wishlistId, $itemId)) {
            return ['code' => 'not_found', 'product_name' => ''];
        }
        $this->savedCache = [];

        $name = $this->catalog->canViewProduct((int) $items[0]['id_product'])
            ? (string) $items[0]['fallback_name']
            : '';

        return ['code' => 'removed', 'product_name' => $name];
    }

    /**
     * Removes several items at once (the "also remove from wishlist" choice after a cart add).
     *
     * @param int[] $itemIds items outside this list are ignored
     *
     * @return int|null removed count, null when the list is not accessible
     */
    public function removeItems(WishlistOwner $owner, int $wishlistId, array $itemIds): ?int
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canEdit($owner, $wishlist)) {
            return null;
        }
        $this->savedCache = [];

        return $this->repository->removeItems($wishlistId, $itemIds);
    }

    /**
     * Empties the list. The list itself (and its ID) is kept.
     *
     * @return int|null removed count, null when the list is not accessible
     */
    public function clear(WishlistOwner $owner, int $wishlistId): ?int
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canEdit($owner, $wishlist)) {
            return null;
        }
        $this->savedCache = [];

        return $this->repository->clearItems($wishlistId);
    }

    /**
     * Adds selected items to the visitor's cart. Items outside the list are ignored,
     * each item is rechecked now, and the wishlist itself is never changed.
     *
     * @param int[] $itemIds
     *
     * @return CartAdditionResult[]|null null when the list is not accessible
     */
    public function addItemsToCart(WishlistOwner $owner, int $wishlistId, array $itemIds): ?array
    {
        $wishlist = $this->repository->find($wishlistId);
        if ($wishlist === null || !$this->accessPolicy->canEdit($owner, $wishlist)) {
            return null;
        }

        $results = [];
        foreach ($this->repository->getItemsByIds($wishlistId, $itemIds) as $item) {
            $itemId = (int) $item['id_wishlist_product'];
            $productId = (int) $item['id_product'];
            $attributeId = (int) $item['id_product_attribute'];

            $check = $this->purchasePolicy->check($productId, $attributeId, $this->cartGateway->getCart());
            if ($check['product'] !== null && !$this->catalog->canViewProduct($productId)) {
                $results[] = new CartAdditionResult($itemId, 'no_access', '', 0, 0);
                continue;
            }
            $name = $check['product'] ? (string) $check['product']->name : (string) $item['fallback_name'];
            if ($check['code'] !== 'ok') {
                $results[] = new CartAdditionResult($itemId, $check['code'], $name, $check['quantity'], 0);
                continue;
            }

            $code = $this->cartGateway->add($productId, $attributeId, $check['quantity']);
            $added = $code === 'added' ? $check['quantity'] : 0;
            $results[] = new CartAdditionResult($itemId, $code, $name, $check['quantity'], $added);
        }

        return $results;
    }

    /**
     * Read-only availability for display, using the same rules as the cart action.
     *
     * @return string "ok" or a CartAdditionResult code
     */
    public function availability(int $productId, int $productAttributeId): string
    {
        return $this->purchasePolicy->check($productId, $productAttributeId, $this->cartGateway->getCart())['code'];
    }
}
