<?php
/**
 * Real PrestaShop integration regression for catalog permission enforcement.
 * Run only against a disposable test shop; see tests/README.md.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$prestashopRoot = getenv('PRESTASHOP_ROOT') ?: dirname(__DIR__, 4);
if (!is_file($prestashopRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "Set PRESTASHOP_ROOT to the PrestaShop installation directory.\n");
    exit(2);
}
require $prestashopRoot . '/config/config.inc.php';
require_once dirname(__DIR__, 2) . '/summitwishlist.php';

use Summit\Wishlist\Cart\PrestaShopCartGateway;
use Summit\Wishlist\Cart\PurchasePolicy;
use Summit\Wishlist\Catalog\PrestaShopProductCatalog;
use Summit\Wishlist\Owner\WishlistOwner;
use Summit\Wishlist\Presenter\WishlistPresenter;
use Summit\Wishlist\Repository\DbWishlistRepository;
use Summit\Wishlist\Service\WishlistAccessPolicy;
use Summit\Wishlist\Service\WishlistService;

function check(bool $condition, string $name): void
{
    global $checks;
    $checks[] = ['name' => $name, 'passed' => $condition];
}

function fixtureProduct(string $name, int $categoryId, int $shopId, array &$products): Product
{
    $product = new Product();
    $products[] = $product;
    foreach (Language::getIDs(false) as $langId) {
        $product->name[$langId] = $name;
        $product->link_rewrite[$langId] = Tools::str2url($name);
    }
    $product->id_shop_default = $shopId;
    $product->id_category_default = $categoryId;
    $product->price = 123.45;
    $product->active = true;
    $product->available_for_order = true;
    $product->show_price = true;
    $product->minimal_quantity = 1;
    if (!$product->add()) {
        throw new RuntimeException('Could not create fixture product.');
    }
    $product->updateCategories([$categoryId]);
    StockAvailable::setQuantity((int) $product->id, 0, 5, $shopId);

    return $product;
}

function service(Context $context, DbWishlistRepository $repository, PrestaShopProductCatalog $catalog): WishlistService
{
    return new WishlistService(
        $repository,
        new WishlistAccessPolicy(),
        $catalog,
        new PurchasePolicy($context, $catalog),
        new PrestaShopCartGateway($context),
        'Integration test wishlist'
    );
}

$checks = [];
$lists = [];
$products = [];
$category = null;
$customer = null;
$error = null;
$cleanup = [];

try {
    $shopId = (int) (getenv('PRESTASHOP_TEST_SHOP_ID') ?: 1);
    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
    $context = Context::getContext();
    $context->shop = new Shop($shopId);
    $context->controller = new FrontController();
    $context->controller->init();
    if (!Group::isFeatureActive()) {
        throw new RuntimeException('Group feature must already be active; test does not modify shop configuration.');
    }

    $suffix = bin2hex(random_bytes(6));
    $customerGroup = (int) Configuration::get('PS_CUSTOMER_GROUP');
    $visitorGroup = (int) Configuration::get('PS_UNIDENTIFIED_GROUP');
    if ($customerGroup === $visitorGroup) {
        throw new RuntimeException('Fixture requires distinct customer and visitor groups.');
    }
    $category = new Category();
    $category->id_parent = (int) $context->shop->getCategory();
    $category->active = true;
    foreach (Language::getIDs(false) as $langId) {
        $category->name[$langId] = 'Wishlist access test ' . $suffix;
        $category->link_rewrite[$langId] = 'wishlist-access-test-' . $suffix;
    }
    if (!$category->add()) {
        throw new RuntimeException('Could not create fixture category.');
    }
    $category->updateGroup([$customerGroup]);
    $savedName = 'Wishlist saved private ' . $suffix;
    $private = fixtureProduct($savedName, (int) $category->id, $shopId, $products);
    $public = fixtureProduct('Wishlist public control ' . $suffix, (int) $context->shop->getCategory(), $shopId, $products);

    $connection = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('doctrine.dbal.default_connection');
    $repository = new DbWishlistRepository($connection, _DB_PREFIX_);
    $context->customer = new Customer();
    $context->cart = new Cart();
    $catalog = new PrestaShopProductCatalog($context);
    $guestOwner = new WishlistOwner($shopId, 'guest:' . hash('sha256', $suffix), null);
    $guestService = service($context, $repository, $catalog);
    $guestList = $guestService->getDefaultWishlist($guestOwner, true);
    $lists[] = (int) $guestList['id_wishlist'];
    check(!$private->checkAccess(0), 'native product access denies the guest');
    check($catalog->findSavableProduct((int) $private->id) === null, 'guest cannot load private product for saving');
    $guestAdd = $guestService->addItem($guestOwner, $lists[0], (int) $private->id, 0);
    check($guestAdd['code'] === 'invalid' && $guestAdd['product_name'] === '', 'guest save rejects guessed private ID without its name');
    check($repository->countItems($lists[0]) === 0, 'rejected guest save creates no wishlist item');

    $customer = new Customer();
    $customer->firstname = 'Wishlist';
    $customer->lastname = 'Regression';
    $customer->email = 'wishlist-' . $suffix . '@example.invalid';
    $customer->passwd = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $customer->id_default_group = $customerGroup;
    $customer->active = true;
    if (!$customer->add()) {
        throw new RuntimeException('Could not create fixture customer.');
    }
    $customer->updateGroup([$customerGroup]);
    $context->customer = $customer;
    $context->cart = new Cart();
    $context->cart->id_customer = (int) $customer->id;
    $owner = new WishlistOwner($shopId, 'customer:' . (int) $customer->id, (int) $customer->id);
    $catalog = new PrestaShopProductCatalog($context);
    $service = service($context, $repository, $catalog);
    $list = $service->getDefaultWishlist($owner, true);
    $listId = (int) $list['id_wishlist'];
    $lists[] = $listId;
    check($private->checkAccess((int) $customer->id), 'native product access allows authorized customer');
    check($catalog->findSavableProduct((int) $private->id) !== null, 'authorized customer can load private product');
    check($service->addItem($owner, $listId, (int) $private->id, 0)['code'] === 'added', 'authorized customer can save private product');
    check($service->addItem($owner, $listId, (int) $public->id, 0)['code'] === 'added', 'public control product remains savable');
    $items = $service->getItems($owner, $listId);
    $privateItemId = (int) $items[0]['id_wishlist_product'];
    $presenter = new WishlistPresenter($context, $service, $catalog, $connection, _DB_PREFIX_);
    $allowedRows = $presenter->present($items);
    check($allowedRows[0]['name'] === $savedName && $allowedRows[0]['price'] !== null, 'authorized customer sees current catalog data');

    // Revoke only the fixture category grant; flush the core access cache to
    // simulate the following HTTP request, where a new request has no cached grant.
    $category->updateGroup([$visitorGroup]);
    $liveName = 'Wishlist newly private ' . $suffix;
    foreach (Language::getIDs(false) as $langId) {
        $private->name[$langId] = $liveName;
    }
    $private->price = 987.65;
    $private->update();
    Cache::clean('Product::checkAccess_*');
    check(!$private->checkAccess((int) $customer->id), 'native product access reflects revoked customer grant');
    check($catalog->findSavableProduct((int) $private->id) === null, 'saving rechecks revoked customer access');

    $rows = $presenter->present($service->getItems($owner, $listId));
    $denied = $rows[0];
    check($denied['id'] === $privateItemId && $denied['status'] === 'no_access', 'denied item retains its removable row and status');
    $genericName = $context->getTranslator()->trans('Unavailable product', [], 'Modules.Summitwishlist.Shop');
    check($denied['name'] === $genericName, 'denied item uses a generic label rather than stored or current private name');
    check($denied['price'] === null && $denied['image'] === null && $denied['url'] === null && $denied['combination'] === '', 'denied item redacts price image URL and combination');
    check(count($rows) === 2 && $rows[1]['name'] === $public->name[(int) $context->language->id], 'filtering private item preserves public row mapping');
    check($repository->countItems($listId) === 2, 'presentation does not delete unavailable items');

    $cartResults = $service->addItemsToCart($owner, $listId, [$privateItemId]);
    check(count($cartResults) === 1 && $cartResults[0]->code === 'no_access' && !$cartResults[0]->isAdded(), 'direct cart action refuses revoked product');
    check($cartResults[0]->productName === '', 'cart failure metadata does not expose private name');
    $module = new Summitwishlist();
    $cartMessage = $module->messageFor($cartResults[0]->code, $cartResults[0]->productName);
    check(strpos($cartMessage, $savedName) === false && strpos($cartMessage, $liveName) === false, 'cart feedback does not expose stored or live private name');
    check(!$context->cart->id, 'refused cart action does not persist a cart');
    $removed = $service->removeItem($owner, $listId, $privateItemId);
    check($removed['code'] === 'removed' && $repository->countItems($listId) === 1, 'revoked item remains removable');
    check($removed['product_name'] === '', 'removal result does not expose saved private name');
    $removeMessage = $module->messageFor($removed['code'], $removed['product_name']);
    check(strpos($removeMessage, $savedName) === false && strpos($removeMessage, $liveName) === false, 'removal feedback does not expose private name');

    $deletedItems = $service->getItems($owner, $listId);
    $publicFallback = $deletedItems[0]['fallback_name'];
    $public->delete();
    $deletedRows = $presenter->present($deletedItems);
    check($deletedRows[0]['status'] === 'unavailable' && $deletedRows[0]['name'] === $publicFallback, 'deleted product retains existing unavailable fallback behavior');
} catch (Throwable $exception) {
    $error = get_class($exception) . ': ' . $exception->getMessage();
} finally {
    foreach ($lists as $listId) {
        Db::getInstance()->delete('summitwishlist_product', 'id_wishlist = ' . $listId);
        $cleanup['list:' . $listId] = Db::getInstance()->delete('summitwishlist', 'id_wishlist = ' . $listId);
    }
    foreach ($products as $product) {
        if ($product->id) {
            $cleanup['product:' . $product->id] = !Product::existsInDatabase((int) $product->id, 'product') || $product->delete();
        }
    }
    if ($customer && $customer->id) {
        $cleanup['customer'] = $customer->delete();
    }
    if ($category && $category->id) {
        $cleanup['category'] = $category->delete();
    }
    Cache::clean('Product::checkAccess_*');
}

$failed = array_values(array_filter($checks, static function (array $check): bool { return !$check['passed']; }));
$cleanupFailed = array_filter($cleanup, static function ($result): bool { return !$result; });
echo json_encode(['checks' => $checks, 'passed' => count($checks) - count($failed), 'failed' => count($failed), 'error' => $error, 'cleanup' => $cleanup], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($error !== null || $failed || $cleanupFailed ? 1 : 0);
