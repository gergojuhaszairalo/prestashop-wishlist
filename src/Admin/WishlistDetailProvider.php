<?php

declare(strict_types=1);

namespace Summit\Wishlist\Admin;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Context\LanguageContext;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use Summit\Wishlist\Grid\Data\WishlistGridDataFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read model for the back-office wishlist detail page. Entries are loaded in batches (names,
 * variant labels, images: one query each), never one query per row for catalog data.
 *
 * Price context (Stage 3 decision 9, pending product sign-off): the list's shop, that shop's
 * default currency, tax included, as an unidentified storefront visitor would see it. It is not
 * the owner's personalised price, and the page says so.
 */
class WishlistDetailProvider
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
        private readonly ShopContext $shopContext,
        private readonly LanguageContext $languageContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array|null null when the list does not exist or is outside the back-office shop context
     */
    public function getDetail(int $wishlistId): ?array
    {
        $list = $this->connection->createQueryBuilder()
            ->select('w.`id_wishlist`, w.`id_shop`, w.`name`, w.`owner_key`, w.`id_customer`')
            ->addSelect('c.`id_customer` AS `customer_found`, c.`email`')
            ->addSelect("CONCAT(c.`firstname`, ' ', c.`lastname`) AS `owner_name`")
            ->addSelect('s.`name` AS `shop_name`')
            ->from($this->dbPrefix . 'summitwishlist', 'w')
            ->leftJoin('w', $this->dbPrefix . 'customer', 'c', 'c.`id_customer` = w.`id_customer`')
            ->leftJoin('w', $this->dbPrefix . 'shop', 's', 's.`id_shop` = w.`id_shop`')
            ->where('w.`id_wishlist` = :id')
            ->andWhere('w.`id_shop` IN (:shop_ids)')
            ->setParameter('id', $wishlistId)
            ->setParameter('shop_ids', $this->shopContext->getAssociatedShopIds(), ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        if (!$list) {
            return null;
        }

        $detail = WishlistGridDataFactory::presentOwner($list, $this->translator);
        $detail['items'] = $this->getItems((int) $list['id_wishlist'], (int) $list['id_shop']);
        $detail['product_count'] = count($detail['items']);
        $detail['currency_iso'] = $this->currencyIso((int) $list['id_shop']);
        $detail['multistore'] = $this->shopContext->isMultiShopUsed();

        return $detail;
    }

    private function getItems(int $wishlistId, int $shopId): array
    {
        $langId = $this->languageContext->getId();
        $rows = $this->connection->createQueryBuilder()
            ->select('wp.`id_wishlist_product`, wp.`id_product`, wp.`id_product_attribute`')
            ->addSelect('p.`id_product` AS `product_found`, ps.`id_product` AS `in_shop`, ps.`active`')
            ->addSelect('pl.`name`, pl.`link_rewrite`, pa.`id_product_attribute` AS `combination_found`')
            ->from($this->dbPrefix . 'summitwishlist_product', 'wp')
            ->leftJoin('wp', $this->dbPrefix . 'product', 'p', 'p.`id_product` = wp.`id_product`')
            ->leftJoin('wp', $this->dbPrefix . 'product_shop', 'ps', 'ps.`id_product` = wp.`id_product` AND ps.`id_shop` = :shop')
            ->leftJoin('wp', $this->dbPrefix . 'product_lang', 'pl', 'pl.`id_product` = wp.`id_product` AND pl.`id_lang` = :lang AND pl.`id_shop` = :shop')
            ->leftJoin('wp', $this->dbPrefix . 'product_attribute', 'pa', 'pa.`id_product_attribute` = wp.`id_product_attribute` AND pa.`id_product` = wp.`id_product`')
            ->where('wp.`id_wishlist` = :id')
            ->orderBy('wp.`id_wishlist_product`', 'ASC')
            ->setParameter('id', $wishlistId)
            ->setParameter('shop', $shopId)
            ->setParameter('lang', $langId)
            ->executeQuery()
            ->fetchAllAssociative();

        $attributeIds = array_values(array_filter(array_map(static fn (array $r): int => (int) $r['combination_found'], $rows)));
        $productIds = array_values(array_filter(array_map(static fn (array $r): int => (int) $r['in_shop'], $rows)));
        $variantLabels = $this->variantLabels($attributeIds, $langId);
        $images = $this->images($productIds, $attributeIds, $shopId);
        $priceContext = $this->priceContext($shopId);

        $items = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            $attributeId = (int) $row['id_product_attribute'];
            $productAvailable = !empty($row['product_found']) && !empty($row['in_shop']);
            $combinationAvailable = $attributeId === 0 || !empty($row['combination_found']);
            $available = $productAvailable && $combinationAvailable;

            $imageId = $images['combination'][$attributeId] ?? $images['cover'][$productId] ?? null;
            $items[] = [
                'available' => $productAvailable,
                'active' => $productAvailable && (bool) $row['active'],
                'name' => $productAvailable ? (string) $row['name'] : '',
                'variant' => $attributeId === 0 ? '' : ($variantLabels[$attributeId] ?? ''),
                'combination_missing' => $productAvailable && !$combinationAvailable,
                'image_url' => $productAvailable && $imageId
                    ? \Context::getContext()->link->getImageLink((string) $row['link_rewrite'], (string) $imageId, \ImageType::getFormattedName('small'))
                    : null,
                'price' => $available ? $this->formattedPrice($productId, $attributeId, $priceContext) : null,
            ];
        }

        return $items;
    }

    /**
     * @param int[] $attributeIds
     *
     * @return array<int, string> "Size - M, Color - White" per combination
     */
    private function variantLabels(array $attributeIds, int $langId): array
    {
        if ($attributeIds === []) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('pac.`id_product_attribute`, agl.`name` AS `group_name`, al.`name` AS `value_name`')
            ->from($this->dbPrefix . 'product_attribute_combination', 'pac')
            ->innerJoin('pac', $this->dbPrefix . 'attribute', 'a', 'a.`id_attribute` = pac.`id_attribute`')
            ->innerJoin('a', $this->dbPrefix . 'attribute_group', 'ag', 'ag.`id_attribute_group` = a.`id_attribute_group`')
            ->innerJoin('a', $this->dbPrefix . 'attribute_group_lang', 'agl', 'agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = :lang')
            ->innerJoin('a', $this->dbPrefix . 'attribute_lang', 'al', 'al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = :lang')
            ->where('pac.`id_product_attribute` IN (:ids)')
            ->orderBy('ag.`position`', 'ASC')
            ->setParameter('ids', $attributeIds, ArrayParameterType::INTEGER)
            ->setParameter('lang', $langId)
            ->executeQuery()
            ->fetchAllAssociative();

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['id_product_attribute']][] = $row['group_name'] . ' - ' . $row['value_name'];
        }

        return array_map(static fn (array $parts): string => implode(', ', $parts), $labels);
    }

    /**
     * @return array{combination: array<int, int>, cover: array<int, int>}
     */
    private function images(array $productIds, array $attributeIds, int $shopId): array
    {
        $result = ['combination' => [], 'cover' => []];
        if ($attributeIds !== []) {
            $rows = $this->connection->createQueryBuilder()
                ->select('pai.`id_product_attribute`, MIN(pai.`id_image`) AS `id_image`')
                ->from($this->dbPrefix . 'product_attribute_image', 'pai')
                ->where('pai.`id_product_attribute` IN (:ids)')
                ->andWhere('pai.`id_image` > 0')
                ->groupBy('pai.`id_product_attribute`')
                ->setParameter('ids', $attributeIds, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $result['combination'][(int) $row['id_product_attribute']] = (int) $row['id_image'];
            }
        }
        if ($productIds !== []) {
            $rows = $this->connection->createQueryBuilder()
                ->select('i.`id_product`, i.`id_image`')
                ->from($this->dbPrefix . 'image_shop', 'i')
                ->where('i.`id_product` IN (:ids)')
                ->andWhere('i.`cover` = 1')
                ->andWhere('i.`id_shop` = :shop')
                ->setParameter('ids', array_values(array_unique($productIds)), ArrayParameterType::INTEGER)
                ->setParameter('shop', $shopId)
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $result['cover'][(int) $row['id_product']] = (int) $row['id_image'];
            }
        }

        return $result;
    }

    private function priceContext(int $shopId): \Context
    {
        $context = \Context::getContext()->cloneContext();
        $context->shop = new \Shop($shopId);
        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId));
        $context->customer = new \Customer();
        $context->cart = null;

        return $context;
    }

    private function currencyIso(int $shopId): string
    {
        return (string) (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId)))->iso_code;
    }

    private function formattedPrice(int $productId, int $attributeId, \Context $context): ?string
    {
        try {
            $specificPrice = null;
            $price = \Product::getPriceStatic(
                $productId, true, $attributeId ?: null, 6, null, false, true, 1, false, null, null, null,
                $specificPrice, true, true, $context
            );

            return \Context::getContext()->getCurrentLocale()->formatPrice((float) $price, (string) $context->currency->iso_code);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
