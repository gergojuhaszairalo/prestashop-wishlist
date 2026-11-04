<?php

declare(strict_types=1);

namespace Summit\Wishlist\Install;

use Doctrine\DBAL\Connection;

/**
 * Creates and drops the module schema (example-modules convention: demodoctrine's installer,
 * built by hand in the module during install because module services aren't registered yet).
 * Install never drops existing tables: a reinstall over kept data stays non-destructive.
 */
class Installer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function install(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $prefix = $this->dbPrefix;

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

        $this->connection->executeStatement($wishlist);
        $this->connection->executeStatement($items);

        return true;
    }

    /**
     * Destructive: drops all wishlist data. Upgrades never go through here.
     */
    public function uninstall(): bool
    {
        $prefix = $this->dbPrefix;
        $this->connection->executeStatement("DROP TABLE IF EXISTS `{$prefix}summitwishlist_product`, `{$prefix}summitwishlist`");

        return true;
    }
}
