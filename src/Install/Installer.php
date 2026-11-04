<?php

declare(strict_types=1);

namespace Summit\Wishlist\Install;

use Db;

/**
 * Creates and drops the module schema. Uses Db directly so it works
 * during install/upgrade, before any module service is available.
 */
class Installer
{
    public function install(): bool
    {
        $db = Db::getInstance();
        $engine = _MYSQL_ENGINE_;
        $prefix = _DB_PREFIX_;

        // default_marker is 1 for an owner's default list and NULL otherwise, so the
        // unique key allows one default per owner while permitting more lists later.
        $wishlist = "CREATE TABLE IF NOT EXISTS `{$prefix}summitwishlist` (
            `id_wishlist` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `owner_key` VARCHAR(80) NOT NULL,
            `id_customer` INT UNSIGNED NULL,
            `name` VARCHAR(128) NOT NULL,
            `default_marker` TINYINT(1) UNSIGNED NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_wishlist`),
            UNIQUE KEY `owner_default` (`id_shop`, `owner_key`, `default_marker`),
            KEY `owner` (`id_shop`, `owner_key`),
            KEY `customer` (`id_customer`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4";

        $items = "CREATE TABLE IF NOT EXISTS `{$prefix}summitwishlist_product` (
            `id_wishlist_product` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_wishlist` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `fallback_name` VARCHAR(255) NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_wishlist_product`),
            UNIQUE KEY `wishlist_variant` (`id_wishlist`, `id_product`, `id_product_attribute`),
            KEY `wishlist_order` (`id_wishlist`, `id_wishlist_product`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4";

        return $db->execute($wishlist) && $db->execute($items);
    }

    /**
     * Destructive: drops all wishlist data. Upgrades never go through here.
     */
    public function uninstall(): bool
    {
        $prefix = _DB_PREFIX_;

        return Db::getInstance()->execute(
            "DROP TABLE IF EXISTS `{$prefix}summitwishlist_product`, `{$prefix}summitwishlist`"
        );
    }
}
