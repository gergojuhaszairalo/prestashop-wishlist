<?php

declare(strict_types=1);

namespace Summit\Wishlist\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Wishlist storage on the injected DBAL connection (example-modules convention: demodoctrine).
 * Same tables and SQL semantics as 1.1.0, which used the legacy Db class: values are bound
 * instead of escaped, and names keep legacy pSQL()'s tag stripping so stored data is identical.
 */
class DbWishlistRepository implements WishlistRepositoryInterface
{
    private readonly string $lists;
    private readonly string $items;

    public function __construct(
        private readonly Connection $connection,
        string $dbPrefix,
    ) {
        $this->lists = $dbPrefix . 'summitwishlist';
        $this->items = $dbPrefix . 'summitwishlist_product';
    }

    public function findDefault(int $shopId, string $ownerKey): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `' . $this->lists . '` WHERE `id_shop` = ? AND `owner_key` = ? AND `default_marker` = 1',
            [$shopId, $ownerKey]
        );

        return $row ?: null;
    }

    public function createDefault(int $shopId, string $ownerKey, ?int $customerId, string $name): array
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT IGNORE INTO `' . $this->lists . '`
                (`id_shop`, `owner_key`, `id_customer`, `name`, `default_marker`, `date_add`, `date_upd`)
            VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$shopId, $ownerKey, $customerId, self::clean($name), $now, $now]
        );

        // INSERT IGNORE also hides unrelated failures, so re-read and fail loudly.
        $row = $this->findDefault($shopId, $ownerKey);
        if ($row === null) {
            throw new RuntimeException('Could not create or find the default wishlist.');
        }

        return $row;
    }

    public function find(int $wishlistId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM `' . $this->lists . '` WHERE `id_wishlist` = ?', [$wishlistId]);

        return $row ?: null;
    }

    public function getItems(int $wishlistId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM `' . $this->items . '` WHERE `id_wishlist` = ? ORDER BY `id_wishlist_product` ASC',
            [$wishlistId]
        );
    }

    public function getItemsByIds(int $wishlistId, array $itemIds): array
    {
        $itemIds = self::ids($itemIds);
        if (!$itemIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT * FROM `' . $this->items . '`
            WHERE `id_wishlist` = ? AND `id_wishlist_product` IN (?)
            ORDER BY `id_wishlist_product` ASC',
            [$wishlistId, $itemIds],
            [\PDO::PARAM_INT, ArrayParameterType::INTEGER]
        );
    }

    public function countItems(int $wishlistId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `' . $this->items . '` WHERE `id_wishlist` = ?', [$wishlistId]);
    }

    public function findItemByVariant(int $wishlistId, int $productId, int $productAttributeId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `' . $this->items . '` WHERE `id_wishlist` = ? AND `id_product` = ? AND `id_product_attribute` = ?',
            [$wishlistId, $productId, $productAttributeId]
        );

        return $row ?: null;
    }

    public function addItem(int $wishlistId, int $productId, int $productAttributeId, string $fallbackName): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT IGNORE INTO `' . $this->items . '`
                (`id_wishlist`, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`)
            VALUES (?, ?, ?, ?, ?)',
            [$wishlistId, $productId, $productAttributeId, mb_substr(self::clean($fallbackName), 0, 255), date('Y-m-d H:i:s')]
        );

        if ($inserted > 0) {
            $this->touch($wishlistId);

            return true;
        }

        if ($this->findItemByVariant($wishlistId, $productId, $productAttributeId) === null) {
            throw new RuntimeException('Could not add the wishlist item.');
        }

        return false;
    }

    public function removeItem(int $wishlistId, int $itemId): bool
    {
        $removed = $this->connection->executeStatement(
            'DELETE FROM `' . $this->items . '` WHERE `id_wishlist` = ? AND `id_wishlist_product` = ?',
            [$wishlistId, $itemId]
        ) > 0;
        if ($removed) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function removeItems(int $wishlistId, array $itemIds): int
    {
        $itemIds = self::ids($itemIds);
        if (!$itemIds) {
            return 0;
        }
        $removed = (int) $this->connection->executeStatement(
            'DELETE FROM `' . $this->items . '` WHERE `id_wishlist` = ? AND `id_wishlist_product` IN (?)',
            [$wishlistId, $itemIds],
            [\PDO::PARAM_INT, ArrayParameterType::INTEGER]
        );
        if ($removed > 0) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function clearItems(int $wishlistId): int
    {
        $removed = (int) $this->connection->executeStatement('DELETE FROM `' . $this->items . '` WHERE `id_wishlist` = ?', [$wishlistId]);
        if ($removed > 0) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function deleteByCustomer(int $customerId): void
    {
        $this->connection->executeStatement(
            'DELETE i FROM `' . $this->items . '` i
            INNER JOIN `' . $this->lists . '` w ON w.`id_wishlist` = i.`id_wishlist`
            WHERE w.`id_customer` = ?',
            [$customerId]
        );
        $this->connection->executeStatement('DELETE FROM `' . $this->lists . '` WHERE `id_customer` = ?', [$customerId]);
    }

    public function withOwnerLock(int $shopId, string $ownerKey, callable $work)
    {
        // A named lock serializes everything one owner does, including when the owner has no
        // rows yet (row locks can't cover "create the first list" or "no duplicate name").
        $lock = 'summitwishlist_' . md5($shopId . '|' . $ownerKey);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            throw new RuntimeException('Could not acquire the wishlist owner lock.');
        }
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function findAllByOwner(int $shopId, string $ownerKey): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT w.*, COUNT(i.`id_wishlist_product`) AS `item_count`
            FROM `' . $this->lists . '` w
            LEFT JOIN `' . $this->items . '` i ON i.`id_wishlist` = w.`id_wishlist`
            WHERE w.`id_shop` = ? AND w.`owner_key` = ?
            GROUP BY w.`id_wishlist`
            ORDER BY (w.`default_marker` IS NULL) ASC, w.`id_wishlist` ASC',
            [$shopId, $ownerKey]
        );
    }

    public function createList(int $shopId, string $ownerKey, ?int $customerId, string $name, bool $isDefault): int
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->insert($this->lists, [
            'id_shop' => $shopId,
            'owner_key' => $ownerKey,
            'id_customer' => $customerId,
            'name' => self::clean($name),
            'default_marker' => $isDefault ? 1 : null,
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        $id = (int) $this->connection->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('Could not create the wishlist.');
        }

        return $id;
    }

    public function renameList(int $wishlistId, string $name): void
    {
        $this->connection->update(
            $this->lists,
            ['name' => self::clean($name), 'date_upd' => date('Y-m-d H:i:s')],
            ['id_wishlist' => $wishlistId]
        );
    }

    public function deleteList(int $wishlistId): void
    {
        $this->connection->delete($this->items, ['id_wishlist' => $wishlistId]);
        $this->connection->delete($this->lists, ['id_wishlist' => $wishlistId]);
    }

    public function setDefault(int $shopId, string $ownerKey, int $wishlistId): void
    {
        // Clear first: UNIQUE (id_shop, owner_key, default_marker) allows one 1 and any number of NULLs.
        $this->connection->executeStatement(
            'UPDATE `' . $this->lists . '` SET `default_marker` = NULL WHERE `id_shop` = ? AND `owner_key` = ? AND `default_marker` = 1',
            [$shopId, $ownerKey]
        );
        $this->connection->executeStatement(
            'UPDATE `' . $this->lists . '` SET `default_marker` = 1 WHERE `id_shop` = ? AND `owner_key` = ? AND `id_wishlist` = ?',
            [$shopId, $ownerKey, $wishlistId]
        );
    }

    public function ensureDefault(int $shopId, string $ownerKey): void
    {
        $hasDefault = (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `' . $this->lists . '` WHERE `id_shop` = ? AND `owner_key` = ? AND `default_marker` = 1',
            [$shopId, $ownerKey]
        );
        if ($hasDefault) {
            return;
        }
        // "First created is the default": the oldest list, deterministic by ID.
        $oldest = (int) $this->connection->fetchOne(
            'SELECT MIN(`id_wishlist`) FROM `' . $this->lists . '` WHERE `id_shop` = ? AND `owner_key` = ?',
            [$shopId, $ownerKey]
        );
        if ($oldest > 0) {
            $this->connection->update($this->lists, ['default_marker' => 1], ['id_wishlist' => $oldest]);
        }
    }

    public function listIdsContaining(int $shopId, string $ownerKey, int $productId, int $productAttributeId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT w.`id_wishlist` FROM `' . $this->lists . '` w
            INNER JOIN `' . $this->items . '` i ON i.`id_wishlist` = w.`id_wishlist`
            WHERE w.`id_shop` = ? AND w.`owner_key` = ? AND i.`id_product` = ? AND i.`id_product_attribute` = ?',
            [$shopId, $ownerKey, $productId, $productAttributeId]
        );

        return array_map('intval', $ids);
    }

    public function savedVariantKeys(int $shopId, string $ownerKey): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT i.`id_product`, i.`id_product_attribute` FROM `' . $this->items . '` i
            INNER JOIN `' . $this->lists . '` w ON w.`id_wishlist` = i.`id_wishlist`
            WHERE w.`id_shop` = ? AND w.`owner_key` = ?',
            [$shopId, $ownerKey]
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['id_product'] . '-' . $row['id_product_attribute']] = true;
        }

        return $keys;
    }

    public function variantMemberships(int $shopId, string $ownerKey): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.`id_product`, i.`id_product_attribute`, i.`id_wishlist` FROM `' . $this->items . '` i
            INNER JOIN `' . $this->lists . '` w ON w.`id_wishlist` = i.`id_wishlist`
            WHERE w.`id_shop` = ? AND w.`owner_key` = ?',
            [$shopId, $ownerKey]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['id_product'] . '-' . $row['id_product_attribute']][] = (int) $row['id_wishlist'];
        }

        return $map;
    }

    public function removeVariant(int $wishlistId, int $productId, int $productAttributeId): bool
    {
        $removed = $this->connection->executeStatement(
            'DELETE FROM `' . $this->items . '` WHERE `id_wishlist` = ? AND `id_product` = ? AND `id_product_attribute` = ?',
            [$wishlistId, $productId, $productAttributeId]
        ) > 0;
        if ($removed) {
            $this->touch($wishlistId);
        }

        return $removed;
    }

    public function moveItem(int $fromWishlistId, int $itemId, int $toWishlistId): string
    {
        $exists = (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `' . $this->items . '` WHERE `id_wishlist` = ? AND `id_wishlist_product` = ?',
            [$fromWishlistId, $itemId]
        );
        if (!$exists) {
            return 'not_found';
        }
        // Copies the saved entry as-is (fallback name, date): moving organises existing entries
        // and must not re-run "can this be saved" checks, so unavailable entries can move too.
        $inserted = $this->connection->executeStatement(
            'INSERT IGNORE INTO `' . $this->items . '` (`id_wishlist`, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`)
            SELECT ?, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`
            FROM `' . $this->items . '` WHERE `id_wishlist_product` = ?',
            [$toWishlistId, $itemId]
        ) > 0;
        $this->connection->delete($this->items, ['id_wishlist' => $fromWishlistId, 'id_wishlist_product' => $itemId]);
        $this->touch($fromWishlistId);
        $this->touch($toWishlistId);

        return $inserted ? 'moved' : 'already_in_target';
    }

    public function claimGuestLists(int $shopId, string $guestKey, string $customerKey, int $customerId, callable $uniqueName): array
    {
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
                $itemsMoved += (int) $this->connection->executeStatement(
                    'INSERT IGNORE INTO `' . $this->items . '` (`id_wishlist`, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`)
                    SELECT ?, `id_product`, `id_product_attribute`, `fallback_name`, `date_add`
                    FROM `' . $this->items . '` WHERE `id_wishlist` = ?',
                    [$customerDefault, $guestId]
                );
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
            $this->connection->update($this->lists, [
                'owner_key' => $customerKey,
                'id_customer' => $customerId,
                'name' => self::clean($newName),
                // Keeps the guest default as the account default only when the account had none.
                'default_marker' => ($isGuestDefault && $customerDefault === null) ? 1 : null,
                'date_upd' => date('Y-m-d H:i:s'),
            ], ['id_wishlist' => $guestId]);
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
        $this->connection->update($this->lists, ['date_upd' => date('Y-m-d H:i:s')], ['id_wishlist' => $wishlistId]);
    }

    /** Same stored value as legacy pSQL() (escape + strip_tags), minus the escaping now done by binding. */
    private static function clean(string $value): string
    {
        return strip_tags($value);
    }

    /**
     * @return int[]
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
