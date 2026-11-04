<?php

declare(strict_types=1);

/**
 * The wishlist page, available to guests and customers.
 *
 * @property Summitwishlist $module
 */
class SummitwishlistWishlistModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $services = $this->module->getServices();
        $owner = $services->owner();
        $service = $services->wishlistService();
        $wishlist = $service->getDefaultWishlist($owner);
        $items = $wishlist ? $service->getItems($owner, (int) $wishlist['id_wishlist']) : [];

        $presented = $services->presenter()->present($items);

        // Items just added to the cart (from the redirect), limited to this visitor's own rows.
        $justAdded = array_map('intval', explode(',', (string) Tools::getValue('swl_added')));
        $addedItems = array_values(array_filter($presented, static function (array $item) use ($justAdded) {
            return in_array($item['id'], $justAdded, true);
        }));

        $this->context->smarty->assign([
            'swl_items' => $presented,
            'swl_added_items' => $addedItems,
            'swl_wishlist_url' => $this->module->getWishlistUrl(),
            'swl_available_count' => count(array_filter($presented, static function (array $item) {
                return $item['status'] === 'ok';
            })),
            'swl_is_guest' => $owner->isGuest(),
            'swl_login_url' => $this->context->link->getPageLink('authentication', null, null, [
                'back' => $this->module->getWishlistUrl(),
            ]),
            'swl_token' => $services->csrf()->getToken($owner),
            'swl_cart_url' => $this->context->link->getModuleLink($this->module->name, 'cart'),
            'swl_item_url' => $this->context->link->getModuleLink($this->module->name, 'item'),
            'swl_continue_url' => $this->context->link->getPageLink('index'),
        ]);

        $this->setTemplate('module:summitwishlist/views/templates/front/wishlist.tpl');
    }

    public function getBreadcrumbLinks(): array
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = [
            'title' => $this->trans('My wishlist', [], 'Modules.Summitwishlist.Shop'),
            'url' => $this->module->getWishlistUrl(),
        ];

        return $breadcrumb;
    }

    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = $this->trans('My wishlist', [], 'Modules.Summitwishlist.Shop');
        $page['meta']['robots'] = 'noindex';

        return $page;
    }
}
