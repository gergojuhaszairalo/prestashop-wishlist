<?php

declare(strict_types=1);

namespace Summit\Wishlist\Repository;

interface WishlistRepositoryInterface
{
    public function findDefault(int $shopId, string $ownerKey): ?array;

    /**
     * Creates the owner's default list if missing and returns it. Race-safe via the unique key.
     */
    public function createDefault(int $shopId, string $ownerKey, ?int $customerId, string $name): array;

    public function find(int $wishlistId): ?array;

    /**
     * @return array[] items ordered by insertion
     */
    public function getItems(int $wishlistId): array;

    /**
     * @param int[] $itemIds
     *
     * @return array[] only items that belong to the wishlist
     */
    public function getItemsByIds(int $wishlistId, array $itemIds): array;

    public function countItems(int $wishlistId): int;

    public function findItemByVariant(int $wishlistId, int $productId, int $productAttributeId): ?array;

    /**
     * @return bool true when a new row was inserted, false when it already existed
     */
    public function addItem(int $wishlistId, int $productId, int $productAttributeId, string $fallbackName): bool;

    public function removeItem(int $wishlistId, int $itemId): bool;

    /**
     * @param int[] $itemIds only items of this wishlist are touched
     *
     * @return int number of items removed
     */
    public function removeItems(int $wishlistId, array $itemIds): int;

    /**
     * @return int number of items removed
     */
    public function clearItems(int $wishlistId): int;

    public function deleteByCustomer(int $customerId): void;

    /**
     * Owner-scoped serialization for list-level invariants (unique names, capacity, one default,
     * membership revisions). Works for owners with zero lists, unlike row locks.
     *
     * @template T
     *
     * @param callable(): T $work runs inside the lock and a transaction; an exception rolls back
     *
     * @return T
     */
    public function withOwnerLock(int $shopId, string $ownerKey, callable $work);

    /**
     * @return array[] the owner's lists with an `item_count`, default first, then oldest first
     */
    public function findAllByOwner(int $shopId, string $ownerKey): array;

    public function createList(int $shopId, string $ownerKey, ?int $customerId, string $name, bool $isDefault): int;

    public function renameList(int $wishlistId, string $name): void;

    /**
     * Deletes the list's entries and the list row. Catalogue products are never touched.
     */
    public function deleteList(int $wishlistId): void;

    /**
     * Clears the owner's default marker, then sets it on the target (the unique key needs that order).
     */
    public function setDefault(int $shopId, string $ownerKey, int $wishlistId): void;

    /**
     * If the owner has lists but no default, the oldest one (lowest ID) becomes default.
     */
    public function ensureDefault(int $shopId, string $ownerKey): void;

    /**
     * @return int[] IDs of the owner's lists that contain this exact variant
     */
    public function listIdsContaining(int $shopId, string $ownerKey, int $productId, int $productAttributeId): array;

    /**
     * @return array<string, true> "id_product-id_product_attribute" keys saved in any of the owner's lists
     */
    public function savedVariantKeys(int $shopId, string $ownerKey): array;

    /**
     * @return array<string, int[]> "id_product-id_product_attribute" => IDs of the owner's lists holding it
     */
    public function variantMemberships(int $shopId, string $ownerKey): array;

    public function removeVariant(int $wishlistId, int $productId, int $productAttributeId): bool;

    /**
     * Moves one entry to another list in one transaction. If the target already holds the
     * variant, the target keeps its single entry and only the source entry is removed.
     *
     * @return string moved, already_in_target, not_found
     */
    public function moveItem(int $fromWishlistId, int $itemId, int $toWishlistId): string;

    /**
     * Moves every guest list to the customer (see WishlistService::claimGuestLists()).
     *
     * @return array{items: int, renamed: int}
     */
    public function claimGuestLists(int $shopId, string $guestKey, string $customerKey, int $customerId, callable $uniqueName): array;
}
