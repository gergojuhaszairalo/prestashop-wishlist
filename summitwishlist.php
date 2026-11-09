<?php

declare(strict_types=1);

/**
 * Summit Wishlist: guest and customer wishlists for PrestaShop 9.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Composer PSR-4 autoloader (example-modules convention). The kernel also includes it for
// active modules; requiring it here covers install and legacy entry points.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Safety net for a copy without vendor/: the same PSR-4 mapping, so the storefront keeps working.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Summit\\Wishlist\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

use Summit\Wishlist\Controller\Admin\WishlistController;
use Summit\Wishlist\Service\ServiceFactory;

class Summitwishlist extends Module
{
    public const TRANSLATION_DOMAIN = 'Modules.Summitwishlist.Shop';

    private ?ServiceFactory $services = null;
    /** @var array[]|null the visitor's lists, read once per request for header and triggers */
    private ?array $ownerLists = null;

    public function __construct()
    {
        $this->name = 'summitwishlist';
        $this->tab = 'front_office_features';
        $this->version = '1.2.0';
        $this->author = 'PS Summit Hackathon team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Summit Wishlist', [], 'Modules.Summitwishlist.Admin');
        $this->description = $this->trans('Lets guests and customers save products to a wishlist and add them to the cart later.', [], 'Modules.Summitwishlist.Admin');

        // Customers > Wishlists, registered by core on install (democontrollertabs convention).
        // Upgrades from 1.1.0 and earlier create it in upgrade/upgrade-1.2.0.php.
        $this->tabs = [self::adminTab($this)];
    }

    /**
     * The back-office tab definition, shared by $tabs (install) and the 1.2.0 upgrade.
     *
     * @return array<string, mixed>
     */
    public static function adminTab(Module $module): array
    {
        $names = [];
        foreach (Language::getLanguages(false) as $language) {
            $names[$language['locale']] = $module->getTranslator()->trans('Wishlists', [], 'Modules.Summitwishlist.Admin', $language['locale']);
        }

        return [
            'route_name' => 'summitwishlist_admin_index',
            'class_name' => WishlistController::TAB_CLASS_NAME,
            'visible' => true,
            'name' => $names,
            'parent_class_name' => 'AdminParentCustomer',
            'wording' => 'Wishlists',
            'wording_domain' => 'Modules.Summitwishlist.Admin',
        ];
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink(WishlistController::TAB_CLASS_NAME));
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && $this->getServices()->installer()->install()
            && $this->registerHook($this->getHooks());
    }

    public function uninstall(): bool
    {
        return $this->getServices()->installer()->uninstall() && parent::uninstall();
    }

    /**
     * @return string[]
     */
    public function getHooks(): array
    {
        return [
            'displayProductActions',
            'displayProductListReviews',
            'displayNav2',
            'displayTop',
            'displayCustomerAccount',
            'actionFrontControllerInitAfter',
            'actionFrontControllerSetMedia',
            'actionObjectCustomerDeleteAfter',
            'moduleRoutes',
        ];
    }

    public function getServices(): ServiceFactory
    {
        if ($this->services === null) {
            try {
                $container = $this->getContainer();
            } catch (Throwable $e) {
                // No container yet (some install paths): ServiceFactory wires services by hand.
                $container = null;
            }
            $this->services = new ServiceFactory($this->context, $container);
        }

        return $this->services;
    }

    public function hookModuleRoutes(): array
    {
        return [
            'module-summitwishlist-wishlist' => [
                'controller' => 'wishlist',
                'rule' => 'wishlist',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
        ];
    }

    /**
     * Like the core cart (FrontController::init reconciles the cart with the logged-in customer),
     * the guest-list claim runs right after init on every front request, so it covers every login
     * path and its notice is ready before notifications are assigned.
     */
    public function hookActionFrontControllerInitAfter(): void
    {
        $services = $this->getServices();
        $services->owner();
        if ($services->claimedCount() > 0) {
            $this->context->controller->success[] = $this->messageFor('claimed', '', $services->claimedCount());
        }
        if ($services->claimRenamedCount() > 0) {
            $this->context->controller->info[] = $this->messageFor('claim_renamed', '', $services->claimRenamedCount());
        }
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        // Runs before initContent and before any output: the guest and CSRF tokens must exist
        // before core writes the cookie (once, at the start of smartyOutputContent). Tokens first
        // created while hooks render would be lost. The claim already ran in InitAfter.
        $services = $this->getServices();
        $services->csrf()->getToken($services->owner());

        $this->context->controller->registerStylesheet(
            'summitwishlist-css',
            'modules/' . $this->name . '/views/css/wishlist.css',
            ['media' => 'all', 'priority' => 200]
        );
        $this->context->controller->registerJavascript(
            'summitwishlist-js',
            'modules/' . $this->name . '/views/js/wishlist.js',
            ['position' => 'bottom', 'priority' => 200]
        );
        Media::addJsDef(['summitWishlistI18n' => [
            'error' => $this->messageFor('failed'),
        ]]);
    }

    public function hookDisplayProductActions(array $params): string
    {
        return $this->renderButton($params['product'] ?? null, 'pdp');
    }

    public function hookDisplayProductListReviews(array $params): string
    {
        return $this->renderButton($params['product'] ?? null, 'miniature');
    }

    public function hookDisplayNav2(): string
    {
        return $this->renderNav('nav-desktop');
    }

    /**
     * displayNav2 is hidden below 768px in Hummingbird, so small screens get their own entry here.
     */
    public function hookDisplayTop(): string
    {
        return $this->renderNav('nav-mobile');
    }

    public function hookDisplayCustomerAccount(): string
    {
        $this->context->smarty->assign([
            'swl_url' => $this->getWishlistUrl(),
            'swl_active' => $this->context->controller instanceof SummitwishlistWishlistModuleFrontController,
        ]);

        return $this->fetch('module:summitwishlist/views/templates/hook/account-link.tpl');
    }

    public function hookActionObjectCustomerDeleteAfter(array $params): void
    {
        $customer = $params['object'] ?? null;
        if ($customer instanceof Customer && $customer->id) {
            $this->getServices()->repository()->deleteByCustomer((int) $customer->id);
        }
    }

    /**
     * The wishlist section. Without arguments: the Stage 1 URL (`/wishlist`), which shows the
     * default list for one-list owners and the overview otherwise.
     *
     * @param array<string, int|string> $params e.g. ['list' => 12] or ['view' => 'all']
     */
    public function getWishlistUrl(array $params = []): string
    {
        return $this->context->link->getModuleLink($this->name, 'wishlist', $params);
    }

    /**
     * The visitor's lists, read once per request (header entries and every product trigger).
     *
     * @return array[]
     */
    public function getOwnerLists(): array
    {
        if ($this->ownerLists === null) {
            $services = $this->getServices();
            $this->ownerLists = $services->listManager()->getLists($services->owner());
        }

        return $this->ownerLists;
    }

    /**
     * Shopper-facing text for a service result code.
     */
    public function messageFor(string $code, string $productName = '', int $quantity = 1, string $listName = ''): string
    {
        $domain = self::TRANSLATION_DOMAIN;
        if ($productName === '') {
            $productName = $this->trans('Unavailable product', [], $domain);
        }
        $params = ['%product%' => $productName, '%quantity%' => $quantity, '%list%' => $listName];

        switch ($code) {
            case 'created':
                return $this->trans('Wishlist "%list%" was created.', $params, $domain);
            case 'renamed':
                return $this->trans('Wishlist renamed to "%list%".', $params, $domain);
            case 'default_set':
                return $this->trans('"%list%" is now your default wishlist.', $params, $domain);
            case 'deleted':
                return $this->trans('Wishlist "%list%" was deleted. Your other wishlists and the products in the store are unchanged.', $params, $domain);
            case 'moved':
                return $this->trans('Moved to "%list%".', $params, $domain);
            case 'already_in_target':
                return $this->trans('Already in "%list%": kept the one entry there and removed it from this list.', $params, $domain);
            case 'same_list':
                return $this->trans('Choose a different wishlist to move to.', [], $domain);
            case 'list_not_found':
                return $this->trans('This wishlist isn\'t available.', [], $domain);
            case 'name_required':
                return $this->trans('Enter a wishlist name.', [], $domain);
            case 'name_taken':
                return $this->trans('You already have a wishlist with this name.', [], $domain);
            case 'name_too_long':
                return $this->trans('Use %quantity% characters or fewer.', $params, $domain);
            case 'name_invalid':
                return $this->trans('Remove special characters such as < > = { } from the name.', [], $domain);
            case 'too_many_lists':
                return $this->trans('You can have up to %quantity% wishlists. Delete one to create another.', $params, $domain);
            case 'saved':
                return $this->trans('Your wishlists were updated.', [], $domain);
            case 'saved_removed_all':
                return $this->trans('"%product%" was removed from all your wishlists.', $params, $domain);
            case 'unchanged':
                return $this->trans('No changes to save.', [], $domain);
            case 'stale':
                return $this->trans('Your wishlists changed in another window. Review the updated choices and save again.', [], $domain);
            case 'claim_renamed':
                return $this->trans('Some wishlists from your visit were renamed because you already had wishlists with the same names.', [], $domain);
            case 'added':
                return $this->trans('"%product%" was added to your wishlist.', $params, $domain);
            case 'already_present':
                return $this->trans('"%product%" is already in your wishlist.', $params, $domain);
            case 'removed':
                return $this->trans('"%product%" was removed from your wishlist.', $params, $domain);
            case 'not_found':
                return $this->trans('This item is no longer in your wishlist.', [], $domain);
            case 'cart_added':
                return $quantity > 1
                    ? $this->trans('%quantity% × "%product%" added to your cart (minimum purchase quantity).', $params, $domain)
                    : $this->trans('"%product%" was added to your cart.', $params, $domain);
            case 'unavailable':
                return $this->trans('"%product%" is no longer available.', $params, $domain);
            case 'no_access':
                return $this->trans('"%product%" is not available for your account.', $params, $domain);
            case 'needs_options':
                return $this->trans('"%product%" needs options to be chosen on its product page first.', $params, $domain);
            case 'out_of_stock':
                return $this->trans('"%product%" is out of stock.', $params, $domain);
            case 'min_quantity':
                return $this->trans('"%product%" must be bought in a larger quantity.', $params, $domain);
            case 'max_quantity':
                return $this->trans('You already have the maximum available quantity of "%product%" in your cart.', $params, $domain);
            case 'removed_many':
                return $quantity === 1
                    ? $this->trans('1 product was removed from your wishlist.', [], $domain)
                    : $this->trans('%quantity% products were removed from your wishlist.', $params, $domain);
            case 'cleared':
                return $this->trans('Your wishlist is now empty.', [], $domain);
            case 'claimed':
                return $quantity === 1
                    ? $this->trans('1 item from your visit was added to your wishlist.', [], $domain)
                    : $this->trans('%quantity% items from your visit were added to your wishlist.', $params, $domain);
            case 'nothing_selected':
                return $this->trans('Select at least one product first.', [], $domain);
            case 'invalid_token':
                return $this->trans('Your session has changed. Please reload the page and try again.', [], $domain);
            case 'invalid':
                return $this->trans('This product can\'t be added to your wishlist.', [], $domain);
            default:
                return $this->trans('Something went wrong. Please try again.', [], $domain);
        }
    }

    private function renderNav(string $template): string
    {
        // The header heart opens the default list and counts only its entries (Task 2 decision).
        $default = null;
        foreach ($this->getOwnerLists() as $list) {
            if ((int) $list['default_marker'] === 1) {
                $default = $list;
                break;
            }
        }
        $this->context->smarty->assign([
            'swl_url' => $default ? $this->getWishlistUrl(['list' => (int) $default['id_wishlist']]) : $this->getWishlistUrl(),
            'swl_count' => $default ? (int) $default['item_count'] : 0,
            'swl_default_name' => $default ? (string) $default['name'] : '',
        ]);

        return $this->fetch('module:summitwishlist/views/templates/hook/' . $template . '.tpl');
    }

    /**
     * @param array|\ArrayAccess|null $product
     */
    private function renderButton($product, string $context): string
    {
        if (!$product || empty($product['id_product'])) {
            return '';
        }
        $services = $this->getServices();
        $productId = (int) $product['id_product'];
        $attributeId = (int) ($product['id_product_attribute'] ?? 0);

        $this->context->smarty->assign([
            'swl_id_product' => $productId,
            'swl_id_product_attribute' => $attributeId,
            'swl_product_name' => (string) ($product['name'] ?? ''),
            'swl_saved' => $services->wishlistService()->isSaved($services->owner(), $productId, $attributeId),
            // 2+ lists: the trigger opens the membership picker instead of toggling.
            'swl_multi' => count($this->getOwnerLists()) >= 2,
            'swl_picker_url' => $this->context->link->getModuleLink($this->name, 'picker'),
            'swl_action_url' => $this->context->link->getModuleLink($this->name, 'item'),
            'swl_token' => $services->csrf()->getToken($services->owner()),
        ]);

        return $this->fetch('module:summitwishlist/views/templates/hook/button-' . ($context === 'pdp' ? 'pdp' : 'card') . '.tpl');
    }
}
