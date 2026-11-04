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
     * Moves a guest's default list into a customer's default list, in one transaction:
     * re-assigns the guest list when the customer has none, otherwise merges (duplicates
     * dropped by the unique key) and deletes the guest list.
     *
     * @return int number of items that are new in the customer's list
     */
    public function claimGuestList(int $shopId, string $guestKey, string $customerKey, int $customerId): int;
}
