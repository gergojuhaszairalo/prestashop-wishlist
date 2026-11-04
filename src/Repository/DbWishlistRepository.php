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

    public function withOwnerLock(int $shopId, string $ownerKey, callable $work)
    {
        // A named lock serializes everything one owner does, including when the owner has no
        // rows yet (row locks can't cover "create the first list" or "no duplicate name").
        $lock = "'summitwishlist_" . md5($shopId . '|' . $ownerKey) . "'";
        if ((int) $this->db->getValue('SELECT GET_LOCK(' . $lock . ', 10)', false) !== 1) {
            throw new RuntimeException('Could not acquire the wishlist owner lock.');
        }
        $this->db->execute('START TRANSACTION');
        try {
            $result = $work();
            $this->db->execute('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        } finally {
            $this->db->getValue('SELECT RELEASE_LOCK(' . $lock . ')', false);
        }
    }

    public function findAllByOwner(int $shopId, string $ownerKey): array
    {
        return $this->db->executeS(
            'SELECT w.*, COUNT(i.`id_wishlist_product`) AS `item_count`
            FROM `' . _DB_PREFIX_ . self::LISTS . '` w
            LEFT JOIN `' . _DB_PREFIX_ . self::ITEMS . '` i ON i.`id_wishlist` = w.`id_wishlist`
            WHERE w.`id_shop` = ' . $shopId . " AND w.`owner_key` = '" . pSQL($ownerKey) . "'
            GROUP BY w.`id_wishlist`
            ORDER BY (w.`default_marker` IS NULL) ASC, w.`id_wishlist` ASC",
            true,
            false
        ) ?: [];
    }

    public function createList(int $shopId, string $ownerKey, ?int $customerId, string $name, bool $isDefault): int
    {
        $now = date('Y-m-d H:i:s');
        $ok = $this->db->insert(self::LISTS, [
            'id_shop' => $shopId,
            'owner_key' => pSQL($ownerKey),
            'id_customer' => $customerId,
            'name' => pSQL($name),
            'default_marker' => $isDefault ? 1 : null,
            'date_add' => $now,
            'date_upd' => $now,
        ], true, false);
        if (!$ok) {
            throw new RuntimeException('Could not create the wishlist.');
        }

        return (int) $this->db->Insert_ID();
    }

    public function renameList(int $wishlistId, string $name): void
    {
        $this->db->update(self::LISTS, [
            'name' => pSQL($name),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_wishlist` = ' . $wishlistId);
    }

    public function deleteList(int $wishlistId): void
    {
        $this->db->delete(self::ITEMS, '`id_wishlist` = ' . $wishlistId);
        $this->db->delete(self::LISTS, '`id_wishlist` = ' . $wishlistId);
    }

    public function setDefault(int $shopId, string $ownerKey, int $wishlistId): void
    {
        $owner = '`id_shop` = ' . $shopId . " AND `owner_key` = '" . pSQL($ownerKey) . "'";
        // Clear first: UNIQUE (id_shop, owner_key, default_marker) allows one 1 and any number of NULLs.
        $this->db->update(self::LISTS, ['default_marker' => null], $owner . ' AND `default_marker` = 1', 0, true);
        $this->db->update(self::LISTS, ['default_marker' => 1], $owner . ' AND `id_wishlist` = ' . $wishlistId, 0, true);
    }

    public function ensureDefault(int $shopId, string $ownerKey): void
    {
        $owner = '`id_shop` = ' . $shopId . " AND `owner_key` = '" . pSQL($ownerKey) . "'";
        $hasDefault = (bool) $this->db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::LISTS . '` WHERE ' . $owner . ' AND `default_marker` = 1',
            false
        );
        if ($hasDefault) {
            return;
        }
        // "First created is the default": the oldest list, deterministic by ID.
        $oldest = (int) $this->db->getValue(
            'SELECT MIN(`id_wishlist`) FROM `' . _DB_PREFIX_ . self::LISTS . '` WHERE ' . $owner,
            false
        );
        if ($oldest > 0) {
            $this->db->update(self::LISTS, ['default_marker' => 1], '`id_wishlist` = ' . $oldest);
        }
    }

    public function listIdsContaining(int $shopId, string $ownerKey, int $productId, int $productAttributeId): array
    {
        $rows = $this->db->executeS(
            'SELECT w.`id_wishlist` FROM `' . _DB_PREFIX_ . self::LISTS . '` w
            INNER JOIN `' . _DB_PREFIX_ . self::ITEMS . '` i ON i.`id_wishlist` = w.`id_wishlist`
            WHERE w.`id_shop` = ' . $shopId . " AND w.`owner_key` = '" . pSQL($ownerKey) . "'
              AND i.`id_product` = " . $productId . ' AND i.`id_product_attribute` = ' . $productAttributeId,
            true,
            false
        ) ?: [];

        return array_map('intval', array_column($rows, 'id_wishlist'));
    }

    public function savedVariantKeys(int $shopId, string $ownerKey): array
    {
        $rows = $this->db->executeS(
            'SELECT DISTINCT i.`id_product`, i.`id_product_attribute` FROM `' . _DB_PREFIX_ . self::ITEMS . '` i
            INNER JOIN `' . _DB_PREFIX_ . self::LISTS . '` w ON w.`id_wishlist` = i.`id_wishlist`
            WHERE w.`id_shop` = ' . $shopId . " AND w.`owner_key` = '" . pSQL($ownerKey) . "'",
            true,
            false
        ) ?: [];
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['id_product'] . '-' . $row['id_product_attribute']] = true;
        }

        return $keys;
    }

    public function variantMemberships(int $shopId, string $ownerKey): array
    {
        $rows = $this->db->executeS(
            'SELECT i.`id_product`, i.`id_product_attribute`, i.`id_wishlist` FROM `' . _DB_PREFIX_ . self::ITEMS . '` i
            INNER JOIN `' . _DB_PREFIX_ . self::LISTS . '` w ON w.`id_wishlist` = i.`id_wishlist`
            WHERE w.`id_shop` = ' . $shopId . " AND w.`owner_key` = '" . pSQL($ownerKey) . "'",
            true,
            false
        ) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[$row['id_product'] . '-' . $row['id_product_attribute']][] = (int) $row['id_wishlist'];
        }

        return $map;
    }

    public function removeVariant(int $wishlistId, int $productId, int $productAttributeId): bool
    {
        $this->db->delete(
            self::ITEMS,
            '`id_wishlist` = ' . $wishlistId . ' AND `id_product` = ' . $productId . ' AND `id_product_attribute` = ' . $productAttributeId
        );
        $removed = $this->db->Affected_Rows() > 0;
        if ($removed) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function moveItem(int $fromWishlistId, int $itemId, int $toWishlistId): string
    {
        $items = _DB_PREFIX_ . self::ITEMS;
        $exists = (bool) $this->db->getValue(
            'SELECT 1 FROM `' . $items . '` WHERE `id_wishlist` = ' . $fromWishlistId . ' AND `id_wishlist_product` = ' . $itemId,
            false
        );
        if (!$exists) {
            return 'not_found';
        }
        // Copies the saved entry as-is (fallback name, date): moving organises existing entries
        // and must not re-run "can this be saved" checks, so unavailable entries can move too.
        $this->db->execute(
            'INSERT IGNORE INTO `' . $items . '` (`id_wishlist`, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`)
            SELECT ' . $toWishlistId . ', `id_product`, `id_product_attribute`, `fallback_name`, `date_add`
            FROM `' . $items . '` WHERE `id_wishlist_product` = ' . $itemId
        );
        $inserted = $this->db->Affected_Rows() > 0;
        $this->db->delete(self::ITEMS, '`id_wishlist` = ' . $fromWishlistId . ' AND `id_wishlist_product` = ' . $itemId);
        $this->touch($fromWishlistId);
        $this->touch($toWishlistId);

        return $inserted ? 'moved' : 'already_in_target';
    }

    public function claimGuestLists(int $shopId, string $guestKey, string $customerKey, int $customerId, callable $uniqueName): array
    {
        $lists = _DB_PREFIX_ . self::LISTS;
        $items = _DB_PREFIX_ . self::ITEMS;
        $guestLists = $this->findAllByOwner($shopId, $guestKey);
        if (!$guestLists) {
            return ['items' => 0, 'renamed' => 0];
        }

        $customerLists = $this->findAllByOwner($shopId, $customerKey);
        $customerDefault = null;
        $takenNames = [];
        foreach ($customerLists as $list) {
            if ((int) $list['default_marker'] === 1) {
                $customerDefault = (int) $list['id_wishlist'];
            }
            $takenNames[] = (string) $list['name'];
        }

        $itemsMoved = 0;
        $renamed = 0;
        foreach ($guestLists as $list) {
            $guestId = (int) $list['id_wishlist'];
            $isGuestDefault = (int) $list['default_marker'] === 1;

            if ($isGuestDefault && $customerDefault !== null) {
                // Default meets default (1.0.1 behaviour): merge entries, duplicates dropped by the unique key.
                $this->db->execute(
                    'INSERT IGNORE INTO `' . $items . '` (`id_wishlist`, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`)
                    SELECT ' . $customerDefault . ', `id_product`, `id_product_attribute`, `fallback_name`, `date_add`
                    FROM `' . $items . '` WHERE `id_wishlist` = ' . $guestId
                );
                $itemsMoved += (int) $this->db->Affected_Rows();
                $this->deleteList($guestId);
                $this->touch($customerDefault);
                continue;
            }

            // Every other list is re-assigned in place (same ID, same entries), like the core cart.
            // Names are not identity: a clash is renamed visibly, never merged.
            $name = (string) $list['name'];
            $newName = $uniqueName($name, $takenNames);
            if ($newName !== $name) {
                ++$renamed;
            }
            $takenNames[] = $newName;
            $this->db->update(self::LISTS, [
                'owner_key' => pSQL($customerKey),
                'id_customer' => $customerId,
                'name' => pSQL($newName),
                // Keeps the guest default as the account default only when the account had none.
                'default_marker' => ($isGuestDefault && $customerDefault === null) ? 1 : null,
                'date_upd' => date('Y-m-d H:i:s'),
            ], '`id_wishlist` = ' . $guestId, 0, true);
            if ($isGuestDefault && $customerDefault === null) {
                $customerDefault = $guestId;
            }
            $itemsMoved += (int) $list['item_count'];
        }
        $this->ensureDefault($shopId, $customerKey);

        return ['items' => $itemsMoved, 'renamed' => $renamed];
    }

    private function touch(int $wishlistId): void
    {
        $this->db->update(self::LISTS, ['date_upd' => date('Y-m-d H:i:s')], '`id_wishlist` = ' . $wishlistId);
    }
}
