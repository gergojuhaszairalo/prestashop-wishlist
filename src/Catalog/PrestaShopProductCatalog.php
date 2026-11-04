<?php

declare(strict_types=1);

namespace Summit\Wishlist\Catalog;

use Combination;
use Context;
use Product;
use Validate;

class PrestaShopProductCatalog implements ProductCatalogInterface
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function findSavableProduct(int $productId): ?Product
    {
        if ($productId <= 0) {
            return null;
        }
        $product = new Product($productId, false, (int) $this->context->language->id, (int) $this->context->shop->id);
        if (!Validate::isLoadedObject($product) || !$product->active || !$product->isAssociatedToShop((int) $this->context->shop->id)) {
            return null;
        }

        return $product;
    }

    public function resolveCombination(Product $product, int $productAttributeId, array $selectedAttributeIds = []): ?int
    {
        if (!$product->hasAttributes()) {
            return 0;
        }
        if ($productAttributeId > 0) {
            return $this->combinationBelongsTo($product, $productAttributeId) ? $productAttributeId : null;
        }

        $selectedAttributeIds = array_values(array_filter(array_map('intval', $selectedAttributeIds)));
        if ($selectedAttributeIds) {
            try {
                $fromGroups = (int) Product::getIdProductAttributeByIdAttributes((int) $product->id, $selectedAttributeIds);
            } catch (\PrestaShopException $e) {
                return null;
            }

            return $fromGroups > 0 ? $fromGroups : null;
        }

        $default = (int) Product::getDefaultAttribute((int) $product->id);

        return $default > 0 ? $default : null;
    }

    public function combinationBelongsTo(Product $product, int $productAttributeId): bool
    {
        if ($productAttributeId === 0) {
            return !$product->hasAttributes();
        }
        $combination = new Combination($productAttributeId);

        return Validate::isLoadedObject($combination) && (int) $combination->id_product === (int) $product->id;
    }

    public function combinationLabel(int $productId, int $productAttributeId): string
    {
        $parts = [];
        foreach (Product::getAttributesParams($productId, $productAttributeId) as $attribute) {
            $parts[] = ($attribute['public_group'] ?: $attribute['group']) . ': ' . $attribute['name'];
        }

        return implode(', ', $parts);
    }
}
