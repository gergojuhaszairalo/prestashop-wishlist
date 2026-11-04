import { expect, test } from '@playwright/test';
import { login, logout, newCustomer, register } from './support/accounts';
import { env } from './support/env';
import { msg, Storefront } from './support/storefront';

/**
 * "Guest wishlist lost on login / registration" (1.0.1): the guest list is claimed into
 * the account on the first logged-in request, merged without duplicates, then forgotten.
 * Numbering follows the tab's test list. Case 5 (password-reset link) needs mail access
 * and case 6 (simultaneous logins in two tabs) needs request-level timing control;
 * both are covered by design (cookie-based lazy claim, row lock + unique keys), not here.
 */

test.describe('Guest list claimed on login @account', () => {
  test('1/3: registering moves the guest items into the new account, with a notice', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const a = await shop.firstCardProductId();
    const b = await shop.firstCardProductId([a]);
    await shop.addFromCard(a);
    await shop.addFromCard(b);
    await shop.gotoWishlist();
    const guestItems = await shop.itemNames();

    await register(page, newCustomer());
    await expect(shop.notifications()).toContainText(msg.claimed);

    await shop.gotoWishlist();
    expect((await shop.itemNames()).sort()).toEqual(guestItems.sort());
  });

  test('2: logging in merges into an existing account list, duplicates once', async ({ page }) => {
    const shop = new Storefront(page);
    const customer = newCustomer();

    // Account already holds B and C.
    await register(page, customer);
    await shop.gotoCategory(env.categoryPath);
    const ids = await page
      .locator('.sw-card-heart input[name="id_product"]')
      .evaluateAll((inputs) => [...new Set(inputs.map((i) => Number((i as HTMLInputElement).value)))]);
    test.skip(ids.length < 3, 'Needs a listing with at least three products');
    const [a, b, c] = ids;
    await shop.addFromCard(b);
    await shop.addFromCard(c);
    await logout(page);

    // As a guest, add A and B.
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(a);
    await shop.addFromCard(b);

    await login(page, customer);
    await expect(shop.notifications()).toContainText(msg.claimed); // only A is new

    await shop.gotoWishlist();
    const products = await page
      .locator('.sw-item')
      .evaluateAll((rows) => rows.length);
    expect(products).toBe(3);
    expect(await shop.headerCount()).toBe(3);
  });

  test('4: after the claim, logging out starts an empty guest list (no ghost items)', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());
    await register(page, newCustomer());

    await logout(page);
    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(0);
    await expect(page.locator('.sw-notice')).toBeVisible();
  });

  test('a visitor with no guest items logs in without a notice', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoHome();
    await register(page, newCustomer());
    await expect(page.locator('#notifications .alert', { hasText: msg.claimed })).toHaveCount(0);
  });
});
