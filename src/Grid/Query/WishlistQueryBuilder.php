<?php

declare(strict_types=1);

namespace Summit\Wishlist\Grid\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * One paginated query for the grid: lists in the back-office shop context, their owner (if a
 * customer) and a grouped product count, so empty lists show 0 and there is no query per row.
 */
class WishlistQueryBuilder extends AbstractDoctrineQueryBuilder
{
    /** Sortable grid fields mapped to SQL; anything else falls back to the default order. */
    private const SORTABLE = [
        'id_wishlist' => 'w.`id_wishlist`',
        'name' => 'w.`name`',
        'owner_name' => 'owner_name',
        'product_count' => 'product_count',
        'shop_name' => 's.`name`',
    ];

    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $searchCriteriaApplicator,
        private readonly ShopContext $shopContext,
    ) {
        parent::__construct($connection, $dbPrefix);
    }

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getQueryBuilder($searchCriteria->getFilters())
            ->select('w.`id_wishlist`, w.`name`, w.`owner_key`, w.`id_customer`')
            ->addSelect('c.`id_customer` AS `customer_found`, c.`email`')
            ->addSelect("CONCAT(c.`firstname`, ' ', c.`lastname`) AS `owner_name`")
            ->addSelect('COALESCE(pc.`product_count`, 0) AS `product_count`')
            ->addSelect('s.`name` AS `shop_name`')
            ->leftJoin(
                'w',
                '(SELECT `id_wishlist`, COUNT(*) AS `product_count` FROM `' . $this->dbPrefix . 'summitwishlist_product` GROUP BY `id_wishlist`)',
                'pc',
                'pc.`id_wishlist` = w.`id_wishlist`'
            )
            ->leftJoin('w', $this->dbPrefix . 'shop', 's', 's.`id_shop` = w.`id_shop`');

        $orderBy = self::SORTABLE[$searchCriteria->getOrderBy() ?? ''] ?? self::SORTABLE['id_wishlist'];
        $orderWay = strtolower((string) $searchCriteria->getOrderWay()) === 'asc' ? 'ASC' : 'DESC';
        $qb->orderBy($orderBy, $orderWay);
        if ($orderBy !== self::SORTABLE['id_wishlist']) {
            $qb->addOrderBy('w.`id_wishlist`', 'DESC');
        }
        $this->searchCriteriaApplicator->applyPagination($searchCriteria, $qb);

        return $qb;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        return $this->getQueryBuilder($searchCriteria->getFilters())->select('COUNT(w.`id_wishlist`)');
    }

    private function getQueryBuilder(array $filterValues): QueryBuilder
    {
        $qb = $this->connection
            ->createQueryBuilder()
            ->from($this->dbPrefix . 'summitwishlist', 'w')
            ->leftJoin('w', $this->dbPrefix . 'customer', 'c', 'c.`id_customer` = w.`id_customer`')
            ->where('w.`id_shop` IN (:shop_ids)')
            ->setParameter('shop_ids', $this->shopContext->getAssociatedShopIds(), ArrayParameterType::INTEGER);

        foreach ($filterValues as $filterName => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $like = '%' . addcslashes($value, '%_\\') . '%';

            if ($filterName === 'name') {
                $qb->andWhere('w.`name` LIKE :name')->setParameter('name', $like);
            } elseif ($filterName === 'customer') {
                $qb->andWhere(
                    "c.`firstname` LIKE :customer OR c.`lastname` LIKE :customer
                    OR CONCAT(c.`firstname`, ' ', c.`lastname`) LIKE :customer OR c.`email` LIKE :customer"
                )->setParameter('customer', $like);
            }
        }

        return $qb;
    }
}
