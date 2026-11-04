import { expect, test } from '@playwright/test';
import { env } from './support/env';
import { msg, Storefront } from './support/storefront';

/**
 * Review 2, P1 "Preserve cart-controller purchase checks". Each test needs a product prepared
 * in the back office (see tests/e2e/README.md) and is skipped when its fixture isn't configured.
 * Items are saved with a direct request so the rule under test is the cart-time check,
 * independent of whether the product page would still offer the button.
 */

async function saveAndOpenWishlist(shop: Storefront, productId: number): Promise<string> {
  await shop.gotoCategory(env.categoryPath); // any page with a token
  const saved = await shop.addByRequest(productId);
  expect(saved.success, saved.messages?.join(' ')).toBe(true);
  await shop.gotoWishlist();
  await expect(shop.items()).toHaveCount(1);
  return (await shop.items().first().locator('input[name="ids[]"]').getAttribute('value'))!;
}

test.describe('Minimal purchase quantity', () => {
  test.skip(!env.minQtyProductId, 'Set SWL_MIN_QTY_PRODUCT_ID (and SWL_MIN_QTY) to a product with minimal quantity > 1');

  test('adds the minimal quantity and says so', async ({ page }) => {
    const shop = new Storefront(page);
    await saveAndOpenWishlist(shop, env.minQtyProductId!);

    await shop.items().first().locator('button[name="single"]').click();

    await expect(shop.notifications()).toContainText(msg.minimumQuantity);
    const line = (await shop.cartLines()).find((l) => l.idProduct === env.minQtyProductId);
    expect(line?.quantity).toBe(env.minQty);
  });
});

test.describe('Required customisation', () => {
  test.skip(!env.customizableProductId, 'Set SWL_CUSTOMIZABLE_PRODUCT_ID to a product with a required customisation field');

  test('offers "Choose options" and refuses a direct cart request', async ({ page }) => {
    const shop = new Storefront(page);
    const itemId = await saveAndOpenWishlist(shop, env.customizableProductId!);
    const row = shop.items().first();

    await expect(row.locator('.summitwishlist-item__status')).toContainText(/options required/i);
    await expect(row.getByRole('link', { name: /choose options/i })).toBeVisible();
    await expect(row.locator('input[name="ids[]"]')).toBeDisabled();

    const result = await shop.postModule('cart', { single: itemId, swl_token: await shop.csrfToken() });
    expect(result.success).toBe(false);
    expect(result.messages.join(' ')).toMatch(msg.needsOptions);
    expect(await shop.cartLines()).toHaveLength(0);
  });
});

test.describe('Customer-group restriction', () => {
  test.skip(!env.restrictedProductId, 'Set SWL_RESTRICTED_PRODUCT_ID to an active product whose categories exclude the Visitor and Guest groups');

  test('a saved product the visitor may not buy is marked and refused', async ({ page }) => {
    const shop = new Storefront(page);
    const itemId = await saveAndOpenWishlist(shop, env.restrictedProductId!);

    await expect(shop.items().first().locator('.summitwishlist-item__status')).toContainText(/not available for your account/i);

    const result = await shop.postModule('cart', { single: itemId, swl_token: await shop.csrfToken() });
    expect(result.success).toBe(false);
    expect(result.messages.join(' ')).toMatch(msg.noAccess);
    expect(await shop.cartLines()).toHaveLength(0);
  });
});

test.describe('Out of stock without backorders', () => {
  test.skip(!env.outOfStockProductId, 'Set SWL_OUT_OF_STOCK_PRODUCT_ID to an active product with 0 stock and "deny orders"');

  test('is marked out of stock and refused', async ({ page }) => {
    const shop = new Storefront(page);
    const itemId = await saveAndOpenWishlist(shop, env.outOfStockProductId!);

    await expect(shop.items().first().locator('.summitwishlist-item__status')).toContainText(/out of stock/i);

    const result = await shop.postModule('cart', { single: itemId, swl_token: await shop.csrfToken() });
    expect(result.success).toBe(false);
    expect(result.messages.join(' ')).toMatch(msg.outOfStock);
  });

  test('a mixed selection adds the valid items and reports the rest per item', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const available = await shop.firstCardProductId([env.outOfStockProductId!]);
    await shop.addFromCard(available);
    await shop.addByRequest(env.outOfStockProductId!);
    await shop.gotoWishlist();

    const ids = await shop.items().locator('input[name="ids[]"]').evaluateAll((inputs) =>
      inputs.map((i) => (i as HTMLInputElement).value)
    );
    const result = await shop.postModule('cart', { 'ids[]': ids, swl_token: await shop.csrfToken() });

    expect(result.added).toBe(1);
    expect(result.failed).toBe(1);
    expect((await shop.cartLines()).map((l) => l.idProduct)).toEqual([available]);
  });
});
