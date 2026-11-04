<?php

declare(strict_types=1);

/**
 * Summit Wishlist: guest and customer wishlists for PrestaShop 9.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

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

use Summit\Wishlist\Install\Installer;
use Summit\Wishlist\Service\ServiceFactory;

class Summitwishlist extends Module
{
    public const TRANSLATION_DOMAIN = 'Modules.Summitwishlist.Shop';

    /** @var ServiceFactory|null */
    private $services;

    public function __construct()
    {
        $this->name = 'summitwishlist';
        $this->tab = 'front_office_features';
        $this->version = '1.0.1';
        $this->author = 'PS Summit Hackathon team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Summit Wishlist', [], 'Modules.Summitwishlist.Admin');
        $this->description = $this->trans('Lets guests and customers save products to a wishlist and add them to the cart later.', [], 'Modules.Summitwishlist.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && (new Installer())->install()
            && $this->registerHook($this->getHooks());
    }

    public function uninstall(): bool
    {
        return (new Installer())->uninstall() && parent::uninstall();
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
            'actionFrontControllerSetMedia',
            'actionObjectCustomerDeleteAfter',
            'moduleRoutes',
        ];
    }

    public function getServices(): ServiceFactory
    {
        if ($this->services === null) {
            $this->services = new ServiceFactory($this->context);
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

    public function hookActionFrontControllerSetMedia(): void
    {
        // Runs before initContent and before any output, which matters twice:
        // - the guest-list claim happens early enough for its notice to show on this page;
        // - the guest and CSRF tokens exist before core writes the cookie (once, at the start
        //   of smartyOutputContent). Tokens first created while hooks render would be lost.
        $services = $this->getServices();
        $services->csrf()->getToken($services->owner());
        if ($services->claimedCount() > 0) {
            $this->context->controller->success[] = $this->messageFor('claimed', '', $services->claimedCount());
        }

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

    public function getWishlistUrl(): string
    {
        return $this->context->link->getModuleLink($this->name, 'wishlist');
    }

    /**
     * Shopper-facing text for a service result code.
     */
    public function messageFor(string $code, string $productName = '', int $quantity = 1): string
    {
        $domain = self::TRANSLATION_DOMAIN;
        if ($productName === '') {
            $productName = $this->trans('Unavailable product', [], $domain);
        }
        $params = ['%product%' => $productName, '%quantity%' => $quantity];

        switch ($code) {
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
        $this->context->smarty->assign([
            'swl_url' => $this->getWishlistUrl(),
            'swl_count' => $this->getServices()->wishlistService()->countItems($this->getServices()->owner()),
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
            'swl_action_url' => $this->context->link->getModuleLink($this->name, 'item'),
            'swl_token' => $services->csrf()->getToken($services->owner()),
        ]);

        return $this->fetch('module:summitwishlist/views/templates/hook/button-' . ($context === 'pdp' ? 'pdp' : 'card') . '.tpl');
    }
}
