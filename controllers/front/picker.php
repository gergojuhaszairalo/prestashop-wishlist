<?php

declare(strict_types=1);

use Summit\Wishlist\Service\WishlistNameValidator;

/**
 * "Save to wishlists" membership editor for one exact product/combination (GET, read-only).
 * - Full page: the no-JS path (the heart/PDP form redirects here when the visitor has 2+ lists).
 * - `fragment=1`: the same form only, loaded into the module's dialog by wishlist.js.
 * Saving goes to the item controller (op=save_memberships) with the baseline rendered here.
 *
 * @property Summitwishlist $module
 */
class SummitwishlistPickerModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $services = $this->module->getServices();
        $owner = $services->owner();
        $productId = (int) Tools::getValue('id_product');
        $attributeId = (int) Tools::getValue('id_product_attribute');
        $groups = Tools::getValue('group');
        $groups = is_array($groups) ? array_map('intval', array_values($groups)) : [];

        $membership = $services->listManager()->membership($owner, $productId, $attributeId, $groups);
        $valid = $membership['code'] === 'ok';
        $productUrl = $valid
            ? $this->context->link->getProductLink($membership['id_product'], null, null, null, null, null, $membership['id_product_attribute'])
            : $this->module->getWishlistUrl();
        $error = (string) Tools::getValue('swl_error');

        $this->context->smarty->assign([
            'swl_valid' => $valid,
            'swl_product' => $valid ? [
                'id' => $membership['id_product'],
                'id_product_attribute' => $membership['id_product_attribute'],
                'name' => $membership['product_name'],
                'combination' => $membership['combination'],
            ] : null,
            'swl_picker_lists' => $valid ? array_map(static function (array $list) {
                return [
                    'id' => (int) $list['id_wishlist'],
                    'name' => (string) $list['name'],
                    'count' => (int) $list['item_count'],
                    'is_default' => (int) $list['default_marker'] === 1,
                    'in_list' => (bool) $list['in_list'],
                ];
            }, $membership['lists']) : [],
            'swl_token' => $services->csrf()->getToken($owner),
            'swl_item_url' => $this->context->link->getModuleLink($this->module->name, 'item'),
            'swl_manage_url' => $this->module->getWishlistUrl(),
            'swl_cancel_url' => $productUrl,
            'swl_picker_self_url' => $valid ? $this->context->link->getModuleLink($this->module->name, 'picker', [
                'id_product' => $membership['id_product'],
                'id_product_attribute' => $membership['id_product_attribute'],
            ]) : '',
            'swl_create_error' => $error !== '' ? $this->module->messageFor($error, '', WishlistNameValidator::MAX_LENGTH) : '',
            'swl_create_value' => (string) Tools::getValue('swl_name'),
            'swl_name_max' => WishlistNameValidator::MAX_LENGTH,
        ]);

        if (Tools::getValue('fragment')) {
            $this->context->cookie->write();
            $this->ajaxRender($this->context->smarty->fetch('module:summitwishlist/views/templates/_partials/picker-form.tpl'));
            exit;
        }
        $this->setTemplate('module:summitwishlist/views/templates/front/picker.tpl');
    }

    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['title'] = $this->trans('Save to wishlists', [], Summitwishlist::TRANSLATION_DOMAIN);
        $page['meta']['robots'] = 'noindex';

        return $page;
    }
}
