<?php

declare(strict_types=1);

use Summit\Wishlist\Controller\RespondsToShopper;
use Summit\Wishlist\Security\CsrfTokenManager;
use Summit\Wishlist\Service\WishlistNameValidator;

/**
 * The wishlist section, available to guests and customers.
 *
 * GET (read-only, never creates data):
 * - `/wishlist`              one list → that list (Stage 1 page); 2+ → overview; none → zero-list state
 * - `/wishlist?view=all`     overview of all lists
 * - `/wishlist?list=ID`      one list; foreign/unknown/deleted → the same "not available" state (404)
 * - `&dialog=create|rename|delete`, `&move=ITEM` open that dialog server-side (no-JS path)
 *
 * POST op=create|rename|delete|set_default (CSRF): success → redirect with a notice;
 * failure → the same page with the dialog open, the inline error and the typed value.
 *
 * @property Summitwishlist $module
 */
class SummitwishlistWishlistModuleFrontController extends ModuleFrontController
{
    use RespondsToShopper;

    /** @var array{dialog: string, list: int, field_error: string, value: string}|null */
    private $failedForm;
    /** @var string|null */
    private $pageTitle;

    public function postProcess(): void
    {
        if (!$this->isPostRequest() || !Tools::getIsset('op')) {
            return;
        }
        $services = $this->module->getServices();
        $owner = $services->owner();
        if (!$services->csrf()->isValid($owner, Tools::getValue(CsrfTokenManager::FIELD))) {
            $this->respond(false, [$this->module->messageFor('invalid_token')], ['code' => 'invalid_token']);
        }
        $lists = $services->listManager();
        $listId = (int) Tools::getValue('id_wishlist');
        $name = (string) Tools::getValue('name');

        switch ((string) Tools::getValue('op')) {
            case 'create':
                $result = $lists->create($owner, $name);
                if ($result['code'] === 'created') {
                    $this->succeed('created', $result['name'], $this->returnUrl() ?: $this->module->getWishlistUrl(['list' => $result['id']]), ['id' => $result['id']]);
                }
                $this->fail('create', 0, $result['code'], $result['name']);
                break;

            case 'rename':
                $result = $lists->rename($owner, $listId, $name);
                if ($result['code'] === 'renamed') {
                    $this->succeed('renamed', $result['name'], $this->module->getWishlistUrl(['list' => $listId]));
                }
                $this->fail('rename', $listId, $result['code'], $result['name']);
                break;

            case 'set_default':
                $code = $lists->setDefault($owner, $listId);
                if ($code === 'default_set') {
                    $list = $lists->findList($owner, $listId);
                    $this->succeed('default_set', (string) $list['name'], $this->returnUrl() ?: $this->module->getWishlistUrl(['list' => $listId]));
                }
                $this->fail('', 0, 'list_not_found', '');
                break;

            case 'delete':
                $result = $lists->delete($owner, $listId);
                if ($result['code'] === 'deleted') {
                    $this->succeed('deleted', $result['name'], $this->module->getWishlistUrl());
                }
                $this->fail('', 0, 'list_not_found', '');
                break;
        }
    }

    public function initContent(): void
    {
        parent::initContent();

        $services = $this->module->getServices();
        $owner = $services->owner();
        $manager = $services->listManager();
        $lists = $manager->getLists($owner);
        $requested = Tools::getIsset('list') ? (int) Tools::getValue('list') : null;

        if ($requested !== null) {
            $current = $manager->findList($owner, $requested);
            $mode = $current ? 'detail' : 'not_found';
        } elseif (Tools::getValue('view') === 'all' && $lists) {
            $mode = 'overview';
            $current = null;
        } elseif (count($lists) === 1) {
            // One list: the Stage 1 page, unchanged.
            $mode = 'detail';
            $current = $manager->findList($owner, (int) $lists[0]['id_wishlist']);
        } else {
            $mode = $lists ? 'overview' : 'zero';
            $current = null;
        }
        if ($mode === 'not_found') {
            header('HTTP/1.1 404 Not Found');
        }

        $presented = [];
        $itemLists = [];
        if ($current) {
            $items = $services->wishlistService()->getItems($owner, (int) $current['id_wishlist']);
            $presented = $services->presenter()->present($items);
            // item ID => [list ID => true], so templates can test with isset().
            $itemLists = array_map('array_flip', $manager->itemMemberships($owner, $items));
        }

        // Items just added to the cart (from the redirect), limited to this list's own rows.
        $justAdded = array_map('intval', explode(',', (string) Tools::getValue('swl_added')));
        $addedItems = array_values(array_filter($presented, static function (array $item) use ($justAdded) {
            return in_array($item['id'], $justAdded, true);
        }));

        $currentId = $current ? (int) $current['id_wishlist'] : 0;
        $listsView = array_map(function (array $list) use ($currentId) {
            $id = (int) $list['id_wishlist'];

            return [
                'id' => $id,
                'name' => (string) $list['name'],
                'count' => (int) $list['item_count'],
                'is_default' => (int) $list['default_marker'] === 1,
                'is_current' => $id === $currentId,
                'url' => $this->module->getWishlistUrl(['list' => $id]),
            ];
        }, $lists);

        $this->pageTitle = $current
            ? (string) $current['name']
            : $this->trans($mode === 'not_found' ? 'Wishlist not available' : 'My wishlists', [], Summitwishlist::TRANSLATION_DOMAIN);

        $selfUrl = $current ? $this->module->getWishlistUrl(['list' => $currentId]) : $this->module->getWishlistUrl(['view' => 'all']);
        $this->context->smarty->assign([
            'swl_mode' => $mode,
            'swl_lists' => $listsView,
            'swl_list' => $current ? [
                'id' => $currentId,
                'name' => (string) $current['name'],
                'is_default' => (int) $current['default_marker'] === 1,
                'count' => count($presented),
            ] : null,
            'swl_items' => $presented,
            'swl_item_lists' => $itemLists,
            'swl_added_items' => $addedItems,
            'swl_open_dialog' => $this->failedForm['dialog'] ?? (string) Tools::getValue('dialog'),
            'swl_dialog_list' => $this->failedForm['list'] ?? (int) Tools::getValue('target'),
            'swl_move_item' => (int) Tools::getValue('move'),
            'swl_form_error' => $this->failedForm ? $this->module->messageFor($this->failedForm['field_error'], '', WishlistNameValidator::MAX_LENGTH) : '',
            'swl_form_value' => $this->failedForm['value'] ?? '',
            'swl_name_max' => WishlistNameValidator::MAX_LENGTH,
            'swl_self_url' => $selfUrl,
            'swl_overview_url' => $this->module->getWishlistUrl(['view' => 'all']),
            'swl_wishlist_url' => $current ? $this->module->getWishlistUrl(['list' => $currentId]) : $this->module->getWishlistUrl(),
            'swl_manage_url' => $this->module->getWishlistUrl(),
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
        $page['meta']['title'] = $this->pageTitle ?? $this->trans('My wishlist', [], 'Modules.Summitwishlist.Shop');
        $page['meta']['robots'] = 'noindex';

        return $page;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function succeed(string $code, string $listName, string $url, array $data = []): void
    {
        $message = $this->module->messageFor($code, '', 1, $listName);
        if ($this->wantsJson()) {
            $this->respond(true, [$message], ['code' => $code] + $data);
        }
        $this->success[] = $message;
        $this->redirectWithNotifications($url);
    }

    /**
     * Keeps the dialog open with the inline error and the typed value (page re-render, no redirect).
     */
    private function fail(string $dialog, int $listId, string $code, string $value): void
    {
        if ($code === 'not_found') {
            $code = 'list_not_found';
            $dialog = '';
        }
        if ($this->wantsJson()) {
            $this->respond(false, [$this->module->messageFor($code, '', WishlistNameValidator::MAX_LENGTH)], ['code' => $code, 'name' => $value]);
        }
        $returnUrl = $this->returnUrl();
        if ($returnUrl !== '') {
            // No-JS picker page: carry the code and the typed name back so it can show them.
            $sep = strpos($returnUrl, '?') === false ? '?' : '&';
            Tools::redirect($returnUrl . $sep . http_build_query(['swl_error' => $code, 'swl_name' => $value]));
        }
        if ($dialog === '') {
            $this->errors[] = $this->module->messageFor($code);

            return;
        }
        $this->failedForm = ['dialog' => $dialog, 'list' => $listId, 'field_error' => $code, 'value' => $value];
    }

    /**
     * Optional same-shop return URL (the no-JS picker page creates lists through here).
     */
    private function returnUrl(): string
    {
        $url = (string) Tools::getValue('return');
        if ($url === '') {
            return '';
        }
        // Only module pages of this shop (also behind proxies/tunnels where urlBelongsToShop can't
        // match the host): compare against our own generated base URLs.
        foreach ([$this->module->getWishlistUrl(), $this->context->link->getModuleLink($this->module->name, 'picker')] as $base) {
            $base = (string) preg_replace('/\?.*$/', '', $base);
            if (strpos($url, $base) === 0) {
                return $url;
            }
        }

        return Tools::urlBelongsToShop($url) ? $url : '';
    }
}
