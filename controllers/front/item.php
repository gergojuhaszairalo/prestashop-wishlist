<?php

declare(strict_types=1);

use Summit\Wishlist\Controller\RespondsToShopper;
use Summit\Wishlist\Security\CsrfTokenManager;

/**
 * POST op=add|toggle|remove|remove_selected|clear. Guests are allowed; ownership comes
 * from the server-side context.
 *
 * @property Summitwishlist $module
 */
class SummitwishlistItemModuleFrontController extends ModuleFrontController
{
    use RespondsToShopper;

    public function postProcess(): void
    {
        if (!$this->isPostRequest()) {
            Tools::redirect($this->module->getWishlistUrl());
        }

        $services = $this->module->getServices();
        $owner = $services->owner();
        if (!$services->csrf()->isValid($owner, Tools::getValue(CsrfTokenManager::FIELD))) {
            $this->respond(false, [$this->module->messageFor('invalid_token')]);
        }

        $service = $services->wishlistService();
        $op = Tools::getValue('op');
        switch ($op) {
            case 'add': // idempotent: re-adding reports "already in your wishlist"
            case 'toggle': // hearts and the PDP button: removes when already saved
                $wishlist = $service->getDefaultWishlist($owner, true);
                $groups = Tools::getValue('group');
                $args = [
                    $owner,
                    (int) $wishlist['id_wishlist'],
                    (int) Tools::getValue('id_product'),
                    (int) Tools::getValue('swl_id_product_attribute'),
                    is_array($groups) ? array_values($groups) : [],
                ];
                $result = $op === 'toggle' ? $service->toggleItem(...$args) : $service->addItem(...$args);
                $saved = $result['saved'] ?? in_array($result['code'], ['added', 'already_present'], true);
                $this->respond(
                    in_array($result['code'], ['added', 'already_present', 'removed'], true),
                    [$this->module->messageFor($result['code'], $result['product_name'])],
                    [
                        'code' => $result['code'],
                        'saved' => $saved,
                        'count' => $service->countItems($owner),
                        // The variant actually affected (resolved server-side), so the UI can update every matching trigger.
                        'id_product' => $result['id_product'] ?? null,
                        'id_product_attribute' => $result['id_product_attribute'] ?? null,
                    ]
                );
                break;

            case 'remove_selected': // "also remove from wishlist" after a cart add
            case 'clear': // "Empty wishlist"
                $wishlist = $service->getDefaultWishlist($owner);
                $selected = Tools::getValue('ids');
                $removed = !$wishlist ? null : ($op === 'clear'
                    ? $service->clear($owner, (int) $wishlist['id_wishlist'])
                    : $service->removeItems($owner, (int) $wishlist['id_wishlist'], is_array($selected) ? array_map('intval', $selected) : []));
                $this->respond(
                    $removed !== null && $removed > 0,
                    [$this->module->messageFor($removed ? ($op === 'clear' ? 'cleared' : 'removed_many') : 'not_found', '', (int) $removed)],
                    ['code' => $op, 'removed' => (int) $removed, 'count' => $service->countItems($owner)]
                );
                break;

            case 'remove':
                $wishlist = $service->getDefaultWishlist($owner);
                $result = $wishlist
                    ? $service->removeItem($owner, (int) $wishlist['id_wishlist'], (int) Tools::getValue('id_item'))
                    : ['code' => 'not_found', 'product_name' => ''];
                $this->respond(
                    $result['code'] === 'removed',
                    [$this->module->messageFor($result['code'], $result['product_name'])],
                    ['code' => $result['code'], 'count' => $service->countItems($owner)]
                );
                break;
        }

        $this->respond(false, [$this->module->messageFor('failed')]);
    }
}
