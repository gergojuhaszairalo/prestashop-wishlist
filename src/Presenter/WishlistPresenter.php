<?php

declare(strict_types=1);

namespace Summit\Wishlist\Presenter;

use Context;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use ProductAssembler;
use ProductPresenterFactory;
use Summit\Wishlist\Catalog\ProductCatalogInterface;
use Summit\Wishlist\Service\WishlistService;

/**
 * Turns wishlist rows into template data with live name, image, price and availability
 * in the current shop, language, currency and customer context. Prices are never stored.
 */
class WishlistPresenter
{
    public function __construct(
        private readonly Context $context,
        private readonly WishlistService $service,
        private readonly ProductCatalogInterface $catalog,
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    /**
     * @param array[] $items rows from the wishlist item table
     *
     * @return array[]
     */
    public function present(array $items): array
    {
        if (!$items) {
            return [];
        }

        $existing = $this->existingProductIds(array_column($items, 'id_product'));
        $viewable = [];
        foreach (array_keys($existing) as $productId) {
            if ($this->catalog->canViewProduct((int) $productId)) {
                $viewable[$productId] = true;
            }
        }
        // Gate catalog assembly itself: a purchase status does not protect metadata.
        $presented = $this->presentProducts(array_filter($items, static function (array $item) use ($viewable) {
            return isset($viewable[(int) $item['id_product']]);
        }));

        $rows = [];
        foreach ($items as $item) {
            $itemId = (int) $item['id_wishlist_product'];
            $productId = (int) $item['id_product'];
            $attributeId = (int) $item['id_product_attribute'];
            if (isset($existing[$productId]) && !isset($viewable[$productId])) {
                $rows[] = [
                    'id' => $itemId,
                    'name' => $this->context->getTranslator()->trans('Unavailable product', [], 'Modules.Summitwishlist.Shop'),
                    'combination' => '',
                    'url' => null,
                    'image' => null,
                    'price' => null,
                    'status' => 'no_access',
                ];
                continue;
            }
            $product = $presented[$itemId] ?? null;

            if ($product === null) {
                $rows[] = [
                    'id' => $itemId,
                    'name' => $item['fallback_name'],
                    'combination' => '',
                    'url' => null,
                    'image' => null,
                    'price' => null,
                    'status' => 'unavailable',
                    'availability_label' => '',
                ];
                continue;
            }

            $rows[] = [
                'id' => $itemId,
                'name' => $product['name'],
                'combination' => $this->catalog->combinationLabel($productId, $attributeId),
                'url' => $product['url'],
                'image' => $this->imageUrl($product['cover'] ?? null),
                'price' => !empty($product['show_price']) ? $product['price'] : null,
                'status' => $this->service->availability($productId, $attributeId),
                // Core's localized stock message ("In stock", backorder text...) for purchasable rows.
                'availability_label' => (string) ($product['availability_message'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param int[] $productIds
     *
     * @return array<int, true> product IDs that still exist in the current shop
     */
    private function existingProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $ids = $this->connection->fetchFirstColumn(
            'SELECT `id_product` FROM `' . $this->dbPrefix . 'product_shop` WHERE `id_shop` = ? AND `id_product` IN (?)',
            [(int) $this->context->shop->id, $productIds],
            [\PDO::PARAM_INT, ArrayParameterType::INTEGER]
        );

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Batch-assembles and presents products the same way core listing modules do.
     *
     * @return array<int, mixed> presented product keyed by wishlist item ID
     */
    private function presentProducts(array $items): array
    {
        if (!$items) {
            return [];
        }

        $rawProducts = [];
        $itemIds = [];
        foreach ($items as $item) {
            $rawProducts[] = [
                'id_product' => (int) $item['id_product'],
                'id_product_attribute' => (int) $item['id_product_attribute'],
            ];
            $itemIds[] = (int) $item['id_wishlist_product'];
        }

        $assembler = new ProductAssembler($this->context);
        $settings = (new ProductPresenterFactory($this->context))->getPresentationSettings();
        $presenter = new ProductListingPresenter(
            new ImageRetriever($this->context->link),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator()
        );

        $presented = [];
        foreach ($assembler->assembleProducts($rawProducts) as $index => $rawProduct) {
            $presented[$itemIds[$index]] = $presenter->present($settings, $rawProduct, $this->context->language);
        }

        return $presented;
    }

    private function imageUrl($cover): ?string
    {
        if (!$cover) {
            return null;
        }

        return $cover['bySize']['home_default']['url'] ?? $cover['medium']['url'] ?? null;
    }
}
