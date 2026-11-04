<?php

declare(strict_types=1);

namespace Summit\Wishlist\Cart;

use Cart;
use Context;
use Customization;
use Product;
use ProductAttribute;
use Summit\Wishlist\Catalog\ProductCatalogInterface;
use Validate;

/**
 * Reproduces the checks the core CartController runs before Cart::updateQty()
 * (controllers/front/CartController.php in 9.2.0), because updateQty alone skips them.
 */
class PurchasePolicy
{
    /** @var Context */
    private $context;
    /** @var ProductCatalogInterface */
    private $catalog;

    public function __construct(Context $context, ProductCatalogInterface $catalog)
    {
        $this->context = $context;
        $this->catalog = $catalog;
    }

    /**
     * @return array{code: string, quantity: int, product: Product|null}
     *               code is "ok" when the item may be added, with the quantity to add
     */
    public function check(int $productId, int $productAttributeId, Cart $cart): array
    {
        $shopId = (int) $this->context->shop->id;
        $product = new Product($productId, true, (int) $this->context->language->id, $shopId);

        // 1. Exists, belongs to this shop, active and orderable.
        if (!Validate::isLoadedObject($product)) {
            return $this->result('unavailable', 0, null);
        }
        if (!$product->isAssociatedToShop($shopId) || !$product->active || !$product->available_for_order) {
            return $this->result('unavailable', 0, $product);
        }

        // 2. Customer-group access, checked live like core.
        $customerId = $cart->id ? (int) $cart->id_customer : (int) $this->context->customer->id;
        if (!$product->checkAccess($customerId)) {
            return $this->result('no_access', 0, $product);
        }

        // 3. The saved variant must still belong to the product; never substitute.
        if (!$this->catalog->combinationBelongsTo($product, $productAttributeId)) {
            return $this->result('unavailable', 0, $product);
        }

        // 4. Required customisation can only be filled in on the product page.
        if (Customization::isFeatureActive() && $product->customizable && $product->getRequiredCustomizableFields()) {
            return $this->result('needs_options', 0, $product);
        }

        // 5. Minimal quantity, counting what is already in the cart.
        $minimalQuantity = $productAttributeId
            ? (int) ProductAttribute::getAttributeMinimalQty($productAttributeId)
            : (int) $product->minimal_quantity;
        $inCart = $cart->id ? (int) $cart->getProductQuantity($productId, $productAttributeId)['quantity'] : 0;
        $quantity = max(1, $minimalQuantity - $inCart);

        // 6. Stock, same rule as CartController::shouldAvailabilityErrorBeRaised().
        if ($this->stockTooLow($product, $productAttributeId, $inCart + $quantity)) {
            return $this->result('out_of_stock', $quantity, $product);
        }

        return $this->result('ok', $quantity, $product);
    }

    private function stockTooLow(Product $product, int $productAttributeId, int $quantityToCheck): bool
    {
        if (Product::isAvailableWhenOutOfStock($product->out_of_stock)) {
            return false;
        }
        if ($productAttributeId) {
            return !ProductAttribute::checkAttributeQty($productAttributeId, $quantityToCheck);
        }

        return Product::getQuantity((int) $product->id, 0) < $quantityToCheck;
    }

    private function result(string $code, int $quantity, ?Product $product): array
    {
        return ['code' => $code, 'quantity' => $quantity, 'product' => $product];
    }
}
