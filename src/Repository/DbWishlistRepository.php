<?php

declare(strict_types=1);

namespace Summit\Wishlist\Repository;

use Db;
use RuntimeException;

class DbWishlistRepository implements WishlistRepositoryInterface
{
    private const LISTS = 'summitwishlist';
    private const ITEMS = 'summitwishlist_product';

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function findDefault(int $shopId, string $ownerKey): ?array
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::LISTS . '`
            WHERE `id_shop` = ' . $shopId . "
              AND `owner_key` = '" . pSQL($ownerKey) . "'
              AND `default_marker` = 1",
            false
        );

        return $row ?: null;
    }

    public function createDefault(int $shopId, string $ownerKey, ?int $customerId, string $name): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert(self::LISTS, [
            'id_shop' => $shopId,
            'owner_key' => pSQL($ownerKey),
            'id_customer' => $customerId,
            'name' => pSQL($name),
            'default_marker' => 1,
            'date_add' => $now,
            'date_upd' => $now,
        ], true, false, Db::INSERT_IGNORE);

        // INSERT IGNORE also hides unrelated failures, so re-read and fail loudly.
        $row = $this->findDefault($shopId, $ownerKey);
        if ($row === null) {
            throw new RuntimeException('Could not create or find the default wishlist.');
        }

        return $row;
    }

    public function find(int $wishlistId): ?array
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::LISTS . '` WHERE `id_wishlist` = ' . $wishlistId,
            false
        );

        return $row ?: null;
    }

    public function getItems(int $wishlistId): array
    {
        return $this->db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::ITEMS . '`
            WHERE `id_wishlist` = ' . $wishlistId . '
            ORDER BY `id_wishlist_product` ASC',
            true,
            false
        ) ?: [];
    }

    public function getItemsByIds(int $wishlistId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$itemIds) {
            return [];
        }

        return $this->db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::ITEMS . '`
            WHERE `id_wishlist` = ' . $wishlistId . '
              AND `id_wishlist_product` IN (' . implode(',', $itemIds) . ')
            ORDER BY `id_wishlist_product` ASC',
            true,
            false
        ) ?: [];
    }

    public function countItems(int $wishlistId): int
    {
        return (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::ITEMS . '` WHERE `id_wishlist` = ' . $wishlistId,
            false
        );
    }

    public function findItemByVariant(int $wishlistId, int $productId, int $productAttributeId): ?array
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::ITEMS . '`
            WHERE `id_wishlist` = ' . $wishlistId . '
              AND `id_product` = ' . $productId . '
              AND `id_product_attribute` = ' . $productAttributeId,
            false
        );

        return $row ?: null;
    }

    public function addItem(int $wishlistId, int $productId, int $productAttributeId, string $fallbackName): bool
    {
        $this->db->insert(self::ITEMS, [
            'id_wishlist' => $wishlistId,
            'id_product' => $productId,
            'id_product_attribute' => $productAttributeId,
            'fallback_name' => pSQL(mb_substr($fallbackName, 0, 255)),
            'date_add' => date('Y-m-d H:i:s'),
        ], false, false, Db::INSERT_IGNORE);

        if ($this->db->Affected_Rows() > 0) {
            $this->touch($wishlistId);

            return true;
        }

        $exists = (bool) $this->db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::ITEMS . '`
            WHERE `id_wishlist` = ' . $wishlistId . '
              AND `id_product` = ' . $productId . '
              AND `id_product_attribute` = ' . $productAttributeId,
            false
        );
        if (!$exists) {
            throw new RuntimeException('Could not add the wishlist item.');
        }

        return false;
    }

    public function removeItem(int $wishlistId, int $itemId): bool
    {
        $this->db->delete(self::ITEMS, '`id_wishlist` = ' . $wishlistId . ' AND `id_wishlist_product` = ' . $itemId);
        $removed = $this->db->Affected_Rows() > 0;
        if ($removed) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function removeItems(int $wishlistId, array $itemIds): int
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$itemIds) {
            return 0;
        }
        $this->db->delete(self::ITEMS, '`id_wishlist` = ' . $wishlistId . ' AND `id_wishlist_product` IN (' . implode(',', $itemIds) . ')');
        $removed = (int) $this->db->Affected_Rows();
        if ($removed > 0) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function clearItems(int $wishlistId): int
    {
        $this->db->delete(self::ITEMS, '`id_wishlist` = ' . $wishlistId);
        $removed = (int) $this->db->Affected_Rows();
        if ($removed > 0) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function deleteByCustomer(int $customerId): void
    {
        $this->db->execute(
            'DELETE i FROM `' . _DB_PREFIX_ . self::ITEMS . '` i
            INNER JOIN `' . _DB_PREFIX_ . self::LISTS . '` w ON w.`id_wishlist` = i.`id_wishlist`
            WHERE w.`id_customer` = ' . $customerId
        );
        $this->db->delete(self::LISTS, '`id_customer` = ' . $customerId);
    }

    public function claimGuestList(int $shopId, string $guestKey, string $customerKey, int $customerId): int
    {
        $lists = _DB_PREFIX_ . self::LISTS;
        $items = _DB_PREFIX_ . self::ITEMS;

        $this->db->execute('START TRANSACTION');
        try {
            // executeS, not getRow: getRow appends LIMIT 1 after FOR UPDATE, which is invalid SQL.
            $rows = $this->db->executeS(
                'SELECT * FROM `' . $lists . '` WHERE `id_shop` = ' . $shopId . "
                  AND `owner_key` = '" . pSQL($guestKey) . "' AND `default_marker` = 1 LIMIT 1 FOR UPDATE",
                true,
                false
            );
            $guest = $rows[0] ?? null;
            if (!$guest) {
                $this->db->execute('COMMIT');

                return 0;
            }
            $guestId = (int) $guest['id_wishlist'];
            $customerList = $this->findDefault($shopId, $customerKey);

            if ($customerList === null) {
                // Same as the core cart: the visitor's basket simply becomes the account's.
                $this->db->update(self::LISTS, [
                    'owner_key' => pSQL($customerKey),
                    'id_customer' => $customerId,
                    'date_upd' => date('Y-m-d H:i:s'),
                ], '`id_wishlist` = ' . $guestId);
                $moved = $this->countItems($guestId);
            } else {
                // Unlike the cart, never drop the account's existing list: merge instead.
                $targetId = (int) $customerList['id_wishlist'];
                $this->db->execute(
                    'INSERT IGNORE INTO `' . $items . '`
                        (`id_wishlist`, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`)
                    SELECT ' . $targetId . ', `id_product`, `id_product_attribute`, `fallback_name`, `date_add`
                    FROM `' . $items . '` WHERE `id_wishlist` = ' . $guestId
                );
                $moved = (int) $this->db->Affected_Rows();
                $this->db->delete(self::ITEMS, '`id_wishlist` = ' . $guestId);
                $this->db->delete(self::LISTS, '`id_wishlist` = ' . $guestId);
                if ($moved > 0) {
                    $this->touch($targetId);
                }
            }

            $this->db->execute('COMMIT');

            return $moved;
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }
    }

    private function touch(int $wishlistId): void
    {
        $this->db->update(self::LISTS, ['date_upd' => date('Y-m-d H:i:s')], '`id_wishlist` = ' . $wishlistId);
    }
}
