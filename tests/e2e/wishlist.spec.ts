import { expect, test } from '@playwright/test';
import { login, logout, newCustomer, register } from './support/accounts';
import { BackOffice } from './support/admin';
import { env, hasAdmin } from './support/env';
import { msg, Storefront } from './support/storefront';

/**
 * Task 1, Gherkin scenarios 1–11 and the User Acceptance Criteria they cover.
 * Every test starts in a fresh browser context, i.e. a new guest with an empty
 * wishlist and an empty cart, so tests don't depend on each other.
 * Tests tagged @account register a throwaway customer.
 */

test.describe('Scenario 1: add a product to the wishlist from the product page', () => {
  test('saves the selected combination and confirms it', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoProduct(env.combinationProductId);
    await shop.selectCombination(env.combinationGroup, env.combinationOption);
    await expect(shop.pdpButton()).toHaveAttribute('aria-pressed', 'false');

    expect(await shop.clickAdd(shop.pdpButton())).toMatch(msg.added);
    await expect(shop.pdpButton()).toHaveAttribute('aria-pressed', 'true');
    expect(await shop.headerCount()).toBe(1);

    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(1);
    await expect(shop.items().first().locator('.sw-item__variant')).toContainText(env.combinationOption);
  });

  test('works without JavaScript (plain form post, no nested form)', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    const shop = new Storefront(page);
    await shop.gotoProduct(env.combinationProductId);
    await expect(page.locator('#add-to-cart-or-refresh form')).toHaveCount(0);
    await shop.pdpButton().click();

    await expect(shop.notifications()).toContainText(msg.added);
    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(1);
    await context.close();
  });
});

test.describe('Scenario 2: add a product to the wishlist from the product listing', () => {
  test('from a category page, with the heart filling in', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const productId = await shop.firstCardProductId();
    await expect(shop.cardButton(productId)).toHaveAttribute('aria-pressed', 'false');

    expect(await shop.addFromCard(productId)).toMatch(msg.added);
    await expect(shop.cardButton(productId)).toHaveAttribute('aria-pressed', 'true');

    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(1);
  });

  test('from a product widget on the homepage (featured products)', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoHome();
    const productId = await shop.firstCardProductId();

    expect(await shop.addFromCard(productId)).toMatch(msg.added);
    expect(await shop.headerCount()).toBe(1);
  });

  test('the saved state survives a reload (server-rendered)', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const productId = await shop.firstCardProductId();
    await shop.addFromCard(productId);

    await page.reload();
    await expect(shop.cardButton(productId)).toHaveAttribute('aria-pressed', 'true');
  });
});

test.describe('Scenario 3: prevent duplicate wishlist entries', () => {
  // Hearts and the PDP button are toggles: once saved, the UI offers "remove", not "add again".
  // The duplicate guard itself is server-side and is exercised with repeated add requests.

  test('adding the same product again (repeated request) keeps one entry', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const productId = await shop.firstCardProductId();

    expect((await shop.addByRequest(productId)).messages.join(' ')).toMatch(msg.added);
    expect((await shop.addByRequest(productId)).messages.join(' ')).toMatch(msg.alreadyPresent);
    expect((await shop.addByRequest(productId)).messages.join(' ')).toMatch(msg.alreadyPresent);

    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(1);
  });

  test('a saved product shows as saved everywhere, so there is no way to add it twice', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoProduct(env.combinationProductId);
    expect(await shop.clickAdd(shop.pdpButton())).toMatch(msg.added); // default combination

    await shop.gotoHome();
    await expect(shop.cardButton(env.combinationProductId)).toHaveAttribute('aria-pressed', 'true');
    await expect(shop.cardButton(env.combinationProductId)).toHaveAttribute('title', /remove/i);

    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(1);
  });
});

test.describe('Toggle: clicking a saved product removes it', () => {
  test('on a product card', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const productId = await shop.firstCardProductId();

    expect(await shop.addFromCard(productId)).toMatch(msg.added);
    expect(await shop.addFromCard(productId)).toMatch(msg.removed);
    await expect(shop.cardButton(productId)).toHaveAttribute('aria-pressed', 'false');
    expect(await shop.headerCount()).toBe(0);

    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(0);
  });

  test('on the product page, for the selected combination only', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoProduct(env.combinationProductId);
    await shop.clickAdd(shop.pdpButton()); // default combination
    await shop.selectCombination(env.combinationGroup, env.combinationOption);
    expect(await shop.clickAdd(shop.pdpButton())).toMatch(msg.added); // second combination
    expect(await shop.clickAdd(shop.pdpButton())).toMatch(msg.removed); // toggles that one off
    await expect(shop.pdpButton()).toHaveAttribute('aria-pressed', 'false');

    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(1); // the default combination stays
    await expect(shop.items().first().locator('.sw-item__variant')).not.toContainText(env.combinationOption);
  });

  test('without JavaScript the toggle works as a plain form post', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const shop = new Storefront(await context.newPage());
    await shop.gotoProduct(env.combinationProductId);
    await shop.pdpButton().click();
    await expect(shop.notifications()).toContainText(msg.added);

    await shop.gotoProduct(env.combinationProductId);
    await shop.pdpButton().click();
    await expect(shop.notifications()).toContainText(msg.removed);
    await context.close();
  });
});

test.describe('Scenario 4: guest customer wishlist', () => {
  test('survives navigation and page refreshes in the same browser', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());

    await page.reload();
    expect(await shop.headerCount()).toBe(1);

    await shop.gotoHome();
    await shop.gotoWishlist();
    await page.reload();
    await expect(shop.items()).toHaveCount(1);
  });

  test('shows the guest persistence notice with a sign-in link', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoWishlist();
    const notice = page.locator('.sw-notice');
    await expect(notice).toContainText(/saved in this browser/i);
    await expect(notice.getByRole('link')).toHaveAttribute('href', /authentication|login/);
  });

  test('is private to the browser: another guest sees an empty wishlist', async ({ page, browser }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());

    const other = await browser.newContext();
    const otherShop = new Storefront(await other.newPage());
    await otherShop.gotoWishlist();
    await expect(otherShop.items()).toHaveCount(0);
    await other.close();
  });
});

test.describe('Scenario 5: authenticated customer wishlist @account', () => {
  test('persists across logout and login', async ({ page }) => {
    const shop = new Storefront(page);
    const customer = newCustomer();
    await register(page, customer);

    await shop.gotoCategory(env.categoryPath);
    const first = await shop.firstCardProductId();
    await shop.addFromCard(first);
    await shop.addFromCard(await shop.firstCardProductId([first]));
    await shop.gotoWishlist();
    await expect(page.locator('.sw-notice')).toHaveCount(0); // no guest notice for customers
    const saved = await shop.itemNames();
    expect(saved).toHaveLength(2);

    await logout(page);
    await shop.gotoWishlist();
    await expect(shop.items()).toHaveCount(0); // the account list is never shown after logout

    await login(page, customer);
    await shop.gotoWishlist();
    expect(await shop.itemNames()).toEqual(saved);
  });

  test('is reachable from My Account', async ({ page }) => {
    await register(page, newCustomer());
    await page.goto('/index.php?controller=my-account');
    await page.locator('#summitwishlist_link').first().click();
    await expect(page.locator('.sw-wishlist')).toBeVisible();
    await expect(page.locator('.sw-empty')).toBeVisible();
  });
});

test.describe('Scenario 6: view wishlist contents', () => {
  test('shows name, image, current price, availability and per-item actions', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const first = await shop.firstCardProductId();
    await shop.addFromCard(first);
    await shop.addFromCard(await shop.firstCardProductId([first]));

    await page.locator('.sw-nav--desktop a').click(); // dedicated page via header link
    await expect(shop.items()).toHaveCount(2);
    await expect(page.locator('.sw-wishlist__count')).toContainText('2');

    for (const row of await shop.items().all()) {
      await expect(row.locator('.sw-item__name')).not.toBeEmpty();
      await expect(row.locator('.sw-item__price')).toHaveText(/\d/);
      await expect(shop.status(row)).toContainText(/\S/);
      const image = row.locator('.sw-item__image');
      await image.scrollIntoViewIfNeeded();
      await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
      await expect(image).toHaveAttribute('alt', /\S/);
      await expect(shop.removeButton(row)).toHaveAttribute('aria-label', /remove/i);
      await expect(shop.addButton(row)).toBeEnabled();
      await expect(shop.selectBox(row)).toBeEnabled();
    }
  });
});

test.describe('Scenario 7: display an empty wishlist', () => {
  test('shows the empty state, hides the toolbar, and offers continue shopping', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoWishlist();
    await expect(page.locator('.sw-empty__title')).toContainText(/empty/i);
    await expect(page.locator('.sw-toolbar')).toHaveCount(0);
    await expect(page.locator('.sw-wishlist__count')).toContainText('0');

    await page.locator('.sw-empty a.btn-primary').click();
    await expect(page).not.toHaveURL(/summitwishlist|\/wishlist/);
  });
});

test.describe('Scenario 8: remove a product from the wishlist', () => {
  test('removes only the chosen item', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const ids: number[] = [];
    for (let i = 0; i < 3; i++) {
      ids.push(await shop.firstCardProductId(ids));
      await shop.addFromCard(ids[i]);
    }
    await shop.gotoWishlist();
    const before = await shop.itemNames();

    await shop.removeButton(shop.items().nth(1)).click();

    await expect(shop.notifications()).toContainText(msg.removed);
    expect(await shop.itemNames()).toEqual([before[0], before[2]]);
  });
});

test.describe('Scenario 9: add a single wishlist product to the cart', () => {
  test('adds it to the cart and keeps it in the wishlist', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoProduct(env.combinationProductId);
    await shop.selectCombination(env.combinationGroup, env.combinationOption);
    await shop.clickAdd(shop.pdpButton());
    expect(await shop.cartLines()).toHaveLength(0);

    await shop.gotoWishlist();
    await shop.addButton(shop.items().first()).click();

    await expect(shop.notifications()).toContainText(msg.addedToCart);
    await expect(shop.items()).toHaveCount(1);
    const lines = await shop.cartLines();
    expect(lines).toHaveLength(1);
    expect(lines[0].idProduct).toBe(env.combinationProductId);
    expect(lines[0].idProductAttribute).toBeGreaterThan(0);
  });
});

test.describe('Scenario 10: add multiple wishlist products to the cart', () => {
  test('adds only the selected items and keeps all of them in the wishlist', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const ids: number[] = [];
    for (let i = 0; i < 3; i++) {
      ids.push(await shop.firstCardProductId(ids));
      await shop.addFromCard(ids[i]);
    }

    await shop.gotoWishlist();
    await shop.selectBox(shop.items().nth(0)).check();
    await shop.selectBox(shop.items().nth(2)).check();
    await expect(shop.bulkButton()).toHaveText(/2/);
    await shop.bulkButton().click();

    await expect(shop.notifications()).toContainText(msg.addedToCart);
    await expect(shop.items()).toHaveCount(3);
    const inCart = (await shop.cartLines()).map((l) => l.idProduct).sort();
    expect(inCart).toEqual([ids[0], ids[2]].sort());
  });

  test('"Select available items" drives the indeterminate state, count and bulk label', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const first = await shop.firstCardProductId();
    await shop.addFromCard(first);
    await shop.addFromCard(await shop.firstCardProductId([first]));
    await shop.gotoWishlist();

    const all = shop.selectAll();
    await expect(shop.bulkButton()).toBeDisabled();

    await shop.selectBox(shop.items().first()).check();
    expect(await all.evaluate((el: HTMLInputElement) => el.indeterminate)).toBe(true);
    await expect(page.locator('[data-sw-selected-count]')).toContainText('1');
    await expect(shop.bulkButton()).toBeEnabled();

    await all.check();
    expect(await all.evaluate((el: HTMLInputElement) => el.indeterminate)).toBe(false);
    await expect(page.locator('[data-sw-selected-count]')).toContainText('2');

    await all.uncheck();
    await expect(shop.bulkButton()).toBeDisabled();
  });

  test('an empty selection is refused by the server too', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());
    await shop.gotoWishlist();

    const result = await shop.postModule('cart', { bulk: '1', swl_token: await shop.csrfToken() });
    expect(result.success).toBe(false);
    expect(result.messages.join(' ')).toMatch(msg.nothingSelected);
    expect(await shop.cartLines()).toHaveLength(0);
  });
});

test.describe('Scenario 11: handle a product unavailable for purchase', () => {
  test.skip(!hasAdmin(), 'Needs SWL_ADMIN_URL, SWL_ADMIN_EMAIL and SWL_ADMIN_PASSWORD to disable a product after it is saved');

  test('stays visible, is marked unavailable and cannot be added to the cart', async ({ page, browser }) => {
    const shop = new Storefront(page);
    await shop.gotoProduct(env.toggleProductId);
    await shop.clickAdd(shop.pdpButton());

    const admin = await BackOffice.open(browser);
    try {
      await admin.setProductActive(env.toggleProductId, false);

      await shop.gotoWishlist();
      const row = shop.items().first();
      await expect(row).toBeVisible();
      await expect(row).toHaveClass(/sw-item--unavailable/);
      await expect(shop.status(row)).toContainText(/no longer available/i);
      await expect(row.locator('.sw-item__name')).toBeVisible(); // still readable
      await expect(shop.selectBox(row)).toBeDisabled();
      await expect(shop.addButton(row)).toBeDisabled();
      await expect(shop.removeButton(row)).toBeEnabled();
      await expect(shop.selectAll()).toBeDisabled(); // nothing purchasable to select

      // The server refuses it too, not only the disabled button.
      const [itemId] = await shop.itemIds();
      const result = await shop.postModule('cart', { single: itemId, swl_token: await shop.csrfToken() });
      expect(result.success).toBe(false);
      expect(result.messages.join(' ')).toMatch(msg.unavailable);
      expect(await shop.cartLines()).toHaveLength(0);
    } finally {
      await admin.setProductActive(env.toggleProductId, true);
      await admin.close();
    }
  });
});

test.describe('Acceptance: wishlist and cart stay independent', () => {
  test('wishlist add and remove do not change existing cart contents', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.addToCartFromProductPage(env.combinationProductId);
    const cartBefore = await shop.cartLines();
    expect(cartBefore).toHaveLength(1);

    await shop.gotoCategory(env.categoryPath);
    const first = await shop.firstCardProductId();
    await shop.addFromCard(first);
    await shop.addFromCard(await shop.firstCardProductId([first]));
    await shop.gotoWishlist();
    await shop.removeButton(shop.items().first()).click();
    await expect(shop.notifications()).toContainText(msg.removed);

    expect(await shop.cartLines()).toEqual(cartBefore);
  });

  test('adding to the cart leaves unrelated cart lines untouched', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.addToCartFromProductPage(env.combinationProductId);
    const [existing] = await shop.cartLines();

    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId([env.combinationProductId]));
    await shop.gotoWishlist();
    await shop.addButton(shop.items().first()).click();
    await expect(shop.notifications()).toContainText(msg.addedToCart);

    const after = await shop.cartLines();
    expect(after).toHaveLength(2);
    expect(after).toContainEqual(existing);
  });
});
