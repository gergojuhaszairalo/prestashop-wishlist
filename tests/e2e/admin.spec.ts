import { expect, Page, test } from '@playwright/test';
import { newCustomer, register } from './support/accounts';
import { BackOffice } from './support/admin';
import { env, hasAdmin, hasRestrictedAdmin } from './support/env';
import { Storefront } from './support/storefront';

/**
 * Task 3 (Stage 3), back-office wishlist overview, scenarios 1–10. Data is created through
 * the storefront in each test (a fresh guest, or a throwaway customer for @account tests),
 * then inspected in Customers > Wishlists. Needs SWL_ADMIN_URL/EMAIL/PASSWORD; skipped otherwise.
 */

test.skip(!hasAdmin(), 'Set SWL_ADMIN_URL, SWL_ADMIN_EMAIL and SWL_ADMIN_PASSWORD to run back-office tests.');

/** The visitor's current list ID, read from the storefront wishlist page. */
async function currentListId(page: Page): Promise<number> {
  const shop = new Storefront(page);
  await shop.gotoWishlist();
  return Number(await page.locator('input[name="id_wishlist"]').first().inputValue());
}

/** Creates a named list for the visitor through the storefront create form's request. */
async function createList(page: Page, name: string): Promise<void> {
  const shop = new Storefront(page);
  await shop.gotoWishlist();
  const response = await page.request.post('/index.php?fc=module&module=summitwishlist&controller=wishlist', {
    form: { op: 'create', name, swl_token: await shop.csrfToken() },
  });
  expect(response.ok()).toBe(true);
}

/** Detail URL for a list ID, built from a grid row's View link so it carries the back-office token. */
async function detailUrl(bo: BackOffice, listId: number): Promise<string> {
  await bo.gotoWishlists();
  const any = await bo.rowDetailUrl(bo.wishlistRows().first());
  return any.replace(/\/wishlists\/\d+/, `/wishlists/${listId}`);
}

test.describe('Stage 3: back-office wishlist overview', () => {
  test('Scenarios 1, 6, 7: a guest list is listed as "Guest", opens read-only, and shows its empty state', async ({ page, browser }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const productId = await shop.firstCardProductId();
    await shop.addFromCard(productId);
    const listId = await currentListId(page);

    const bo = await BackOffice.open(browser);
    try {
      await bo.gotoWishlists();
      await expect(bo.page.locator('#summitwishlist_grid_table thead')).toContainText(/Wishlist/);
      await expect(bo.page.locator('#summitwishlist_grid_table thead')).toContainText(/Owner/);
      await expect(bo.page.locator('#summitwishlist_grid_table thead')).toContainText(/Products/);

      await bo.page.goto(await detailUrl(bo, listId));
      const summary = bo.page.locator('.card').first();
      await expect(summary).toContainText('Guest');
      await expect(summary).not.toContainText('Email');
      await expect(summary).not.toContainText(/guest:|[0-9a-f]{64}/); // no owner key or hash
      await expect(summary.locator('dd').last()).toHaveText('1');
      await expect(bo.page.locator('.card table tbody tr')).toHaveCount(1);

      // The guest empties the list: the detail page keeps its metadata and shows the empty state.
      await shop.gotoWishlist();
      await shop.removeButton(shop.items().first()).click();
      await expect(shop.items()).toHaveCount(0);
      await bo.page.reload();
      await expect(bo.page.getByText('This wishlist contains no products.')).toBeVisible();
      await expect(summary.locator('dd').last()).toHaveText('0');
      await expect(bo.page.locator('.card table')).toHaveCount(0);
    } finally {
      await bo.close();
    }
  });

  test('Scenarios 2, 3, 4, 5, 8: one customer, three lists, searchable by email, with product details @account', async ({ page, browser }) => {
    const shop = new Storefront(page);
    const customer = newCustomer();
    await register(page, customer);
    await shop.gotoCategory(env.categoryPath);
    const first = await shop.firstCardProductId();
    await shop.addFromCard(first);
    await shop.addFromCard(await shop.firstCardProductId([first]));
    await createList(page, 'Birthday');
    await createList(page, 'Christmas');

    const bo = await BackOffice.open(browser);
    try {
      await bo.gotoWishlists();
      await bo.searchCustomer(customer.email);
      await expect(bo.wishlistRows()).toHaveCount(3);
      for (const row of await bo.wishlistRows().all()) {
        await expect(row.locator('td.column-owner_name')).toHaveText('Wishlist Tester');
        await expect(row.locator('td.column-email')).toHaveText(customer.email);
      }
      const names = await bo.wishlistRows().locator('td.column-name').allInnerTexts();
      expect(names.map((name) => name.trim()).sort()).toEqual(['Birthday', 'Christmas', 'My wishlist']);

      // Search by name works too and returns the same three lists.
      await bo.searchCustomer('Wishlist Tester');
      await expect(bo.wishlistRows().filter({ hasText: customer.email })).toHaveCount(3);

      await bo.searchCustomer(customer.email);
      const filled = bo.wishlistRows().filter({ has: bo.page.locator('td.column-name', { hasText: 'My wishlist' }) });
      await expect(filled.locator('td.column-product_count')).toHaveText('2');
      const empty = bo.wishlistRows().filter({ has: bo.page.locator('td.column-name', { hasText: 'Birthday' }) });
      await expect(empty.locator('td.column-product_count')).toHaveText('0');

      await bo.openRow(filled);
      const summary = bo.page.locator('.card').first();
      await expect(summary).toContainText('Wishlist Tester');
      await expect(summary).toContainText(customer.email);
      const products = bo.page.locator('.card table tbody tr');
      await expect(products).toHaveCount(2);
      for (const row of await products.all()) {
        await expect(row.locator('td').nth(1)).not.toBeEmpty();
        await expect(row.locator('td').nth(3)).toContainText(/\d/); // formatted current price
        const image = row.locator('img');
        if (await image.count()) {
          expect(await image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
        }
      }

      // Back to the grid keeps the filter (the employee's saved grid filters).
      await bo.page.getByRole('link', { name: /Back to wishlists/ }).click();
      await expect(bo.page.locator('input[name="summitwishlist[customer]"]')).toHaveValue(customer.email);
      await bo.resetFilters();
    } finally {
      await bo.close();
    }
  });

  test('Scenario 10: no way to change a wishlist from the back office', async ({ page, browser }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());
    const listId = await currentListId(page);

    const bo = await BackOffice.open(browser);
    try {
      await bo.gotoWishlists();
      await expect(bo.page.locator('#summitwishlist_grid_table .js-bulk-action-checkbox, .js-bulk-actions-btn')).toHaveCount(0);
      const url = await detailUrl(bo, listId);
      await bo.page.goto(url);
      const content = bo.page.locator('.card');
      await expect(content.locator('form')).toHaveCount(0);
      await expect(content.getByRole('button')).toHaveCount(0);
      await expect(content.getByRole('link', { name: /delete|edit|remove|move|cart/i })).toHaveCount(0);

      for (const method of ['post', 'put', 'delete'] as const) {
        const response = await bo.page.request[method](url, { maxRedirects: 0 });
        expect([404, 405], `${method.toUpperCase()} on the detail route`).toContain(response.status());
      }
      expect((await bo.page.request.get(url)).status()).toBe(200); // still there, unchanged
    } finally {
      await bo.close();
    }
  });

  test('unknown list IDs get a generic page without any list data', async ({ browser }) => {
    const bo = await BackOffice.open(browser);
    try {
      const url = await detailUrl(bo, 999_999_999);
      const response = await bo.page.goto(url);
      expect(response?.status()).toBe(404);
      await expect(bo.page.getByText('does not exist or is not available')).toBeVisible();
      await expect(bo.page.locator('.card table')).toHaveCount(0);
    } finally {
      await bo.close();
    }
  });

  test('Scenario 9: a storefront session cannot reach the back-office pages', async ({ page, browser }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());

    const bo = await BackOffice.open(browser);
    const url = await detailUrl(bo, 1).finally(() => bo.close());

    // A shopper (no employee session) following the same URL lands on the back-office login.
    const response = await page.request.get(url, { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toMatch(/login/i);
  });

  test('Scenario 9: an employee profile without the Wishlists permission is denied', async ({ browser }) => {
    test.skip(!hasRestrictedAdmin(), 'Set SWL_ADMIN_RESTRICTED_EMAIL/PASSWORD (an employee whose profile has no Wishlists read right).');
    const admin = await BackOffice.open(browser);
    const url = await detailUrl(admin, 1).finally(() => admin.close());

    const restricted = await BackOffice.open(browser, env.adminRestrictedEmail, env.adminRestrictedPassword);
    try {
      await expect(restricted.page.locator('a[href*="/modules/summitwishlist/wishlists"]')).toHaveCount(0);
      await restricted.page.goto(url.replace(/_token=[^&]+/, ''));
      await expect(restricted.page.locator('body')).not.toContainText('Saved products');
      await expect(restricted.page.locator('body')).toContainText(/access denied|not have permission|Invalid token/i);
    } finally {
      await restricted.close();
    }
  });
});
