import { expect, test } from '@playwright/test';
import { newCustomer, register } from './support/accounts';
import { env } from './support/env';
import { msg, Storefront } from './support/storefront';

/**
 * Review 2, P1 "Bind guest CSRF protection to the guest session" and the ownership rules
 * from the plan (§2.4). Requests are sent directly to the module endpoints, as an attacker would.
 */

test.describe('CSRF token is bound to the visitor', () => {
  test('a token from guest A is rejected in guest B’s session', async ({ page, browser }) => {
    const a = new Storefront(page);
    await a.gotoCategory(env.categoryPath);
    const productId = await a.firstCardProductId();
    await a.addFromCard(productId);
    const tokenA = await a.csrfToken();

    const contextB = await browser.newContext();
    const b = new Storefront(await contextB.newPage());
    await b.gotoCategory(env.categoryPath); // B now has its own guest identity and token
    const result = await b.postModule('item', {
      op: 'add',
      id_product: String(productId),
      swl_id_product_attribute: '0',
      swl_token: tokenA,
    });

    expect(result.success).toBe(false);
    expect(result.messages.join(' ')).toMatch(msg.invalidToken);
    await b.gotoWishlist();
    await expect(b.items()).toHaveCount(0);
    await contextB.close();
  });

  test('missing or wrong tokens change neither the wishlist nor the cart', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());
    await shop.gotoWishlist();
    const itemId = (await shop.items().first().locator('input[name="ids[]"]').getAttribute('value'))!;

    const attempts = [
      shop.postModule('item', { op: 'remove', id_item: itemId }),
      shop.postModule('item', { op: 'remove', id_item: itemId, swl_token: '0'.repeat(64) }),
      shop.postModule('cart', { single: itemId }),
      shop.postModule('cart', { single: itemId, swl_token: 'not-a-token' }),
    ];
    for (const result of await Promise.all(attempts)) {
      expect(result.success).toBe(false);
      expect(result.messages.join(' ')).toMatch(msg.invalidToken);
    }

    await page.reload();
    await expect(shop.items()).toHaveCount(1);
    expect(await shop.cartLines()).toHaveLength(0);
  });

  test('the token rotates when the identity changes (guest → customer) @account', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const guestToken = await shop.csrfToken();

    await register(page, newCustomer());
    await shop.gotoCategory(env.categoryPath);
    const customerToken = await shop.csrfToken();
    expect(customerToken).not.toBe(guestToken);

    const result = await shop.postModule('item', {
      op: 'add',
      id_product: String(await shop.firstCardProductId()),
      swl_id_product_attribute: '0',
      swl_token: guestToken,
    });
    expect(result.success).toBe(false);
    expect(result.messages.join(' ')).toMatch(msg.invalidToken);
  });
});

test.describe('Ownership', () => {
  test('a visitor cannot remove or buy another visitor’s items, even with a valid token of their own', async ({ page, browser }) => {
    const a = new Storefront(page);
    await a.gotoCategory(env.categoryPath);
    await a.addFromCard(await a.firstCardProductId());
    await a.gotoWishlist();
    const itemIdA = (await a.items().first().locator('input[name="ids[]"]').getAttribute('value'))!;

    const contextB = await browser.newContext();
    const b = new Storefront(await contextB.newPage());
    await b.gotoCategory(env.categoryPath);
    await b.addFromCard(await b.firstCardProductId()); // B has a list of its own
    const tokenB = await b.csrfToken();

    const removed = await b.postModule('item', { op: 'remove', id_item: itemIdA, swl_token: tokenB });
    expect(removed.success).toBe(false);
    const bought = await b.postModule('cart', { 'ids[]': [itemIdA], swl_token: tokenB });
    expect(bought.success).toBe(false);
    expect(await b.cartLines()).toHaveLength(0);
    await contextB.close();

    await page.reload();
    await expect(a.items()).toHaveCount(1);
  });

  test('module endpoints reject GET (no state change through links)', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const response = await page.request.get(
      `${await shop.moduleUrl('item')}${(await shop.moduleUrl('item')).includes('?') ? '&' : '?'}op=add&id_product=${await shop.firstCardProductId()}`,
      { maxRedirects: 0 }
    );
    expect([301, 302, 303]).toContain(response.status());
    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(0);
  });
});
