<?php

declare(strict_types=1);

namespace Summit\Wishlist\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Back-office wishlist grid (Stage 3): one row per list, read-only. The only row action is View;
 * there are no bulk actions, no edit/delete and no positions, by design.
 */
class WishlistGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    public const GRID_ID = 'summitwishlist';

    private const DOMAIN = 'Modules.Summitwishlist.Admin';

    protected function getId()
    {
        return self::GRID_ID;
    }

    protected function getName()
    {
        return $this->trans('Wishlists', [], self::DOMAIN);
    }

    protected function getColumns()
    {
        $columns = (new ColumnCollection())
            ->add(
                (new DataColumn('name'))
                    ->setName($this->trans('Wishlist', [], self::DOMAIN))
                    ->setOptions(['field' => 'name'])
            )
            ->add(
                (new DataColumn('owner_name'))
                    ->setName($this->trans('Owner', [], self::DOMAIN))
                    ->setOptions(['field' => 'owner_name'])
            )
            ->add(
                (new DataColumn('email'))
                    ->setName($this->trans('Email', [], 'Admin.Global'))
                    ->setOptions(['field' => 'email', 'sortable' => false])
            )
            ->add(
                (new DataColumn('product_count'))
                    ->setName($this->trans('Products', [], self::DOMAIN))
                    ->setOptions(['field' => 'product_count'])
            )
            ->add(
                (new ActionColumn('actions'))
                    ->setName($this->trans('Actions', [], 'Admin.Global'))
                    ->setOptions([
                        'actions' => (new RowActionCollection())
                            ->add(
                                (new LinkRowAction('view'))
                                    ->setName($this->trans('View', [], 'Admin.Actions'))
                                    ->setIcon('zoom_in')
                                    ->setOptions([
                                        'route' => 'summitwishlist_admin_view',
                                        'route_param_name' => 'wishlistId',
                                        'route_param_field' => 'id_wishlist',
                                        'clickable_row' => true,
                                    ])
                            ),
                    ])
            );

        if (\Shop::isFeatureActive()) {
            $columns->addAfter(
                'product_count',
                (new DataColumn('shop_name'))
                    ->setName($this->trans('Shop', [], 'Admin.Global'))
                    ->setOptions(['field' => 'shop_name'])
            );
        }

        return $columns;
    }

    protected function getFilters()
    {
        return (new FilterCollection())
            ->add(
                (new Filter('name', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Wishlist name', [], self::DOMAIN)],
                    ])
                    ->setAssociatedColumn('name')
            )
            ->add(
                // One field for "search by customer": first name, last name, full name or email.
                (new Filter('customer', TextType::class))
                    ->setTypeOptions([
                        'required' => false,
                        'attr' => ['placeholder' => $this->trans('Customer name or email', [], self::DOMAIN)],
                    ])
                    ->setAssociatedColumn('owner_name')
            )
            ->add(
                (new Filter('actions', SearchAndResetType::class))
                    ->setTypeOptions([
                        'reset_route' => 'admin_common_reset_search_by_filter_id',
                        'reset_route_params' => ['filterId' => self::GRID_ID],
                        'redirect_route' => 'summitwishlist_admin_index',
                    ])
                    ->setAssociatedColumn('actions')
            );
    }
}
