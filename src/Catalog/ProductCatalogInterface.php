<?php

declare(strict_types=1);

namespace Summit\Wishlist\Catalog;

use Product;

interface ProductCatalogInterface
{
    /**
     * Loads a product that may be saved to a wishlist in the current shop, or null.
     */
    public function findSavableProduct(int $productId): ?Product;

    /**
     * Normalises the variant to store: the requested combination if it belongs to the
     * product, else the one matching the selected attribute groups, else the default.
     * Returns 0 for products without combinations and null when nothing valid exists.
     *
     * @param int[] $selectedAttributeIds attribute IDs from the product page groups
     */
    public function resolveCombination(Product $product, int $productAttributeId, array $selectedAttributeIds = []): ?int;

    public function combinationBelongsTo(Product $product, int $productAttributeId): bool;

    /**
     * Human label of a combination, e.g. "Size: M, Color: White".
     */
    public function combinationLabel(int $productId, int $productAttributeId): string;
}
