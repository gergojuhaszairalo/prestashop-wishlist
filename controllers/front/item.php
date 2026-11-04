<?php

declare(strict_types=1);

use Summit\Wishlist\Controller\RespondsToShopper;
use Summit\Wishlist\Security\CsrfTokenManager;

/**
 * POST op=add|toggle|remove|remove_selected|clear|move|save_memberships.
 * Guests are allowed; ownership comes from the server-side context.
 *
 * List targeting: an explicit `id_wishlist` must be one of the visitor's lists, otherwise the
 * request fails as not found (no silent fallback). Without `id_wishlist`, the default list is
 * used: the Stage 1 contract, unchanged for existing pages and integrations.
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
        // No-JS: actions on a given list return to that list (not dependent on the Referer header).
        if (Tools::getIsset('id_wishlist') && $op !== 'add' && $op !== 'toggle') {
            $this->returnTo = $this->module->getWishlistUrl(['list' => (int) Tools::getValue('id_wishlist')]);
        }
        switch ($op) {
            case 'add': // idempotent: re-adding reports "already in your wishlist"
            case 'toggle': // hearts and the PDP button: removes when already saved
                $groups = Tools::getValue('group');
                $groups = is_array($groups) ? array_map('intval', array_values($groups)) : [];
                // With several lists a heart can't know the destination: open the picker instead.
                if ($op === 'toggle' && !Tools::getIsset('id_wishlist') && count($services->listManager()->getLists($owner)) >= 2) {
                    $this->needsPicker((int) Tools::getValue('id_product'), (int) Tools::getValue('swl_id_product_attribute'), $groups);
                }
                $wishlist = $this->targetList(true);
                if ($wishlist === null) {
                    $this->respondListNotFound();
                }
                $args = [
                    $owner,
                    (int) $wishlist['id_wishlist'],
                    (int) Tools::getValue('id_product'),
                    (int) Tools::getValue('swl_id_product_attribute'),
                    $groups,
                ];
                $result = $op === 'toggle' ? $service->toggleItem(...$args) : $service->addItem(...$args);
                $saved = isset($result['id_product'])
                    ? $service->isSaved($owner, (int) $result['id_product'], (int) $result['id_product_attribute'])
                    : false;
                $this->respond(
                    in_array($result['code'], ['added', 'already_present', 'removed'], true),
                    [$this->module->messageFor($result['code'], $result['product_name'])],
                    [
                        'code' => $result['code'],
                        // Filled heart = saved in at least one list (with one list: the Stage 1 meaning).
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
                $wishlist = $this->targetList(false);
                if ($wishlist === null && Tools::getIsset('id_wishlist')) {
                    $this->respondListNotFound();
                }
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
                $wishlist = $this->targetList(false);
                $result = $wishlist
                    ? $service->removeItem($owner, (int) $wishlist['id_wishlist'], (int) Tools::getValue('id_item'))
                    : ['code' => 'not_found', 'product_name' => ''];
                $this->respond(
                    $result['code'] === 'removed',
                    [$this->module->messageFor($result['code'], $result['product_name'])],
                    ['code' => $result['code'], 'count' => $service->countItems($owner)]
                );
                break;

            case 'move':
                $result = $services->listManager()->move(
                    $owner,
                    (int) Tools::getValue('id_wishlist'),
                    (int) Tools::getValue('id_item'),
                    (int) Tools::getValue('target_wishlist')
                );
                $code = $result['code'] === 'not_found' ? 'list_not_found' : $result['code'];
                $this->respond(
                    in_array($result['code'], ['moved', 'already_in_target'], true),
                    [$this->module->messageFor($code, '', 1, $result['target_name'])],
                    ['code' => $result['code'], 'count' => $service->countItems($owner)]
                );
                break;

            case 'save_memberships':
                $this->saveMemberships();
                break;
        }

        $this->respond(false, [$this->module->messageFor('failed')]);
    }

    /**
     * Picker Save: applies the checkbox edits relative to the baseline the picker was opened with.
     */
    private function saveMemberships(): void
    {
        $services = $this->module->getServices();
        $owner = $services->owner();
        $productId = (int) Tools::getValue('id_product');
        $attributeId = (int) Tools::getValue('id_product_attribute');

        $rawBaseline = Tools::getValue('baseline');
        $checked = Tools::getValue('lists');
        $checked = is_array($checked) ? array_flip(array_map('intval', $checked)) : [];
        $baseline = [];
        $desired = [];
        foreach (is_array($rawBaseline) ? $rawBaseline : [] as $listId => $was) {
            $baseline[(int) $listId] = (string) $was === '1';
            $desired[(int) $listId] = isset($checked[(int) $listId]);
        }

        $result = $services->listManager()->saveMembership($owner, $productId, $attributeId, $baseline, $desired);
        $services->wishlistService()->forgetSavedState();
        $membership = $services->listManager()->membership($owner, $productId, $attributeId);

        $messages = $this->membershipMessages($result, $membership);
        $success = in_array($result['code'], ['saved', 'unchanged'], true);
        $data = [
            'code' => $result['code'],
            'saved' => $services->wishlistService()->isSaved($owner, $productId, $attributeId),
            'count' => $services->wishlistService()->countItems($owner),
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'membership' => array_map(static function (array $list) {
                return ['id' => (int) $list['id_wishlist'], 'in_list' => (bool) $list['in_list'], 'item_count' => (int) $list['item_count']];
            }, $membership['lists'] ?? []),
        ];

        // No-JS: a stale or failed save returns to the picker page, which shows the current state.
        if (!$this->wantsJson() && !$success && $result['code'] !== 'invalid') {
            $this->errors = $messages;
            $this->redirectWithNotifications($this->pickerUrl($productId, $attributeId, []));
        }
        $this->respond($success, $messages, $data);
    }

    /**
     * Outcome text that matches what actually happened (added to / removed from which lists).
     *
     * @return string[]
     */
    private function membershipMessages(array $result, array $membership): array
    {
        if ($result['code'] === 'not_found') {
            return [$this->module->messageFor('list_not_found')];
        }
        if ($result['code'] !== 'saved') {
            return [$this->module->messageFor($result['code'])];
        }
        $names = [];
        foreach ($membership['lists'] ?? [] as $list) {
            $names[(int) $list['id_wishlist']] = (string) $list['name'];
        }
        $pick = static function (array $ids) use ($names) {
            return implode(', ', array_map(static function (int $id) use ($names) {
                return '"' . ($names[$id] ?? '') . '"';
            }, $ids));
        };
        $product = (string) ($membership['product_name'] ?? '');
        $anyLeft = false;
        foreach ($membership['lists'] ?? [] as $list) {
            $anyLeft = $anyLeft || (bool) $list['in_list'];
        }
        if (!$result['added'] && !$anyLeft) {
            return [$this->module->messageFor('saved_removed_all', $product)];
        }
        $messages = [];
        $domain = Summitwishlist::TRANSLATION_DOMAIN;
        if ($result['added']) {
            $messages[] = $this->trans('Saved to %lists%.', ['%lists%' => $pick($result['added'])], $domain);
        }
        if ($result['removed']) {
            $messages[] = $this->trans('Removed from %lists%.', ['%lists%' => $pick($result['removed'])], $domain);
        }

        return $messages;
    }

    /**
     * Explicit id_wishlist → that list if it is the visitor's, else null (never a fallback).
     * No id_wishlist → the default list; created only for an explicit save (POST), never on reads.
     */
    private function targetList(bool $createDefault): ?array
    {
        $services = $this->module->getServices();
        $owner = $services->owner();
        if (Tools::getIsset('id_wishlist')) {
            return $services->listManager()->findList($owner, (int) Tools::getValue('id_wishlist'));
        }

        return $services->wishlistService()->getDefaultWishlist($owner, $createDefault);
    }

    private function respondListNotFound(): void
    {
        $this->respond(false, [$this->module->messageFor('list_not_found')], ['code' => 'list_not_found']);
    }

    /**
     * @param int[] $groups
     */
    private function needsPicker(int $productId, int $attributeId, array $groups): void
    {
        $url = $this->pickerUrl($productId, $attributeId, $groups);
        if ($this->wantsJson()) {
            $this->context->cookie->write();
            header('Content-Type: application/json');
            $this->ajaxRender(json_encode(['success' => false, 'code' => 'needs_picker', 'picker_url' => $url, 'messages' => []]));
            exit;
        }
        Tools::redirect($url);
    }

    /**
     * @param int[] $groups
     */
    private function pickerUrl(int $productId, int $attributeId, array $groups): string
    {
        $params = ['id_product' => $productId, 'id_product_attribute' => $attributeId];
        foreach ($groups as $groupId => $attribute) {
            $params['group[' . (int) $groupId . ']'] = (int) $attribute;
        }

        return $this->context->link->getModuleLink($this->module->name, 'picker', $params);
    }
}
