<?php

declare(strict_types=1);

use Summit\Wishlist\Controller\RespondsToShopper;
use Summit\Wishlist\Security\CsrfTokenManager;

/**
 * POST single=<item id> or ids[]=<item ids> with bulk=1. Adds through the core cart,
 * reports each item, and never changes the wishlist.
 *
 * @property Summitwishlist $module
 */
class SummitwishlistCartModuleFrontController extends ModuleFrontController
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

        $single = (int) Tools::getValue('single');
        $selected = Tools::getValue('ids');
        $itemIds = $single > 0 ? [$single] : (is_array($selected) ? array_map('intval', $selected) : []);
        if (!$itemIds) {
            $this->respond(false, [$this->module->messageFor('nothing_selected')]);
        }

        $service = $services->wishlistService();
        $wishlist = $service->getDefaultWishlist($owner);
        $results = $wishlist ? $service->addItemsToCart($owner, (int) $wishlist['id_wishlist'], $itemIds) : null;
        if (!$results) {
            $this->respond(false, [$this->module->messageFor('not_found')]);
        }

        $added = [];
        $failed = [];
        $addedItemIds = [];
        foreach ($results as $result) {
            if ($result->isAdded()) {
                $added[] = $this->module->messageFor('cart_added', $result->productName, $result->addedQuantity);
                $addedItemIds[] = $result->itemId;
            } else {
                $failed[] = $this->module->messageFor($result->code, $result->productName);
            }
        }

        if ($this->wantsJson()) {
            $this->respond(!$failed, array_merge($added, $failed), [
                'added' => count($added),
                'failed' => count($failed),
                'added_item_ids' => $addedItemIds,
            ]);
        }

        // Partial success: show what worked and what didn't, per item. The wishlist page then
        // asks whether the added items should also leave the wishlist (opt-in; default keeps them).
        // Only item IDs travel in the URL; the page re-checks them against the visitor's own list.
        $this->success = $added;
        $this->errors = $failed;
        $url = $this->module->getWishlistUrl();
        if ($addedItemIds) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'swl_added=' . implode(',', $addedItemIds);
        }
        $this->redirectWithNotifications($url);
    }
}
