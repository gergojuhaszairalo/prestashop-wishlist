import { expect, test } from '@playwright/test';
import { env } from './support/env';
import { Storefront } from './support/storefront';

/**
 * Design proposal checks that are behaviour, not pixels: placement, responsive entry
 * points, overflow and accessible names. Visual fidelity is reviewed against Figma by hand.
 */

const widths = [360, 390, 768, 992, 1440];

test.describe('Product-card heart placement', () => {
  test('moves into the card image area, once per card', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoHome();
    const cards = page.locator('.product-miniature');
    const total = await page.locator('.sw-card-heart').count();
    expect(total).toBeGreaterThan(0);
    await expect(page.locator('.product-miniature__top.sw-card-heart-host .sw-card-heart')).toHaveCount(total);
    for (const card of (await cards.all()).slice(0, 4)) {
      await expect(card.locator('.sw-card-heart')).toHaveCount(1);
    }
  });

  test('every heart has an accessible name and pressed state', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    for (const heart of (await shop.cardButtons().all()).slice(0, 4)) {
      await expect(heart).toHaveAttribute('aria-label', /wishlist/i);
      await expect(heart).toHaveAttribute('aria-pressed', /true|false/);
    }
  });

  test('stays in place after the listing is refreshed (sort/filter)', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    const sort = page.locator('.products-sort-order a, .products-sort-order .dropdown-item').nth(1);
    test.skip(!(await sort.count()), 'No sort control on this listing');
    await sort.click({ force: true });
    await page.waitForLoadState('networkidle');
    const total = await page.locator('.sw-card-heart').count();
    await expect(page.locator('.sw-card-heart-host .sw-card-heart')).toHaveCount(total);
  });
});

test.describe('Responsive wishlist page', () => {
  for (const width of widths) {
    test(`no horizontal overflow and a reachable wishlist entry at ${width}px`, async ({ browser }) => {
      const context = await browser.newContext({ viewport: { width, height: 900 } });
      const page = await context.newPage();
      const shop = new Storefront(page);
      await shop.gotoCategory(env.categoryPath);
      await shop.addFromCard(await shop.firstCardProductId());
      await shop.gotoWishlist();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow).toBeLessThanOrEqual(0);

      // Below md the theme hides displayNav2, so the displayTop entry must be the visible one.
      const entry = width < 768 ? page.locator('.sw-nav--mobile a') : page.locator('.sw-nav--desktop a');
      await expect(entry).toBeVisible();

      const row = shop.items().first();
      await expect(shop.addButton(row)).toBeVisible();
      await expect(shop.removeButton(row)).toBeVisible();
      await context.close();
    });
  }
});

test.describe('Accessibility basics on the wishlist page', () => {
  test('checkboxes are labelled and actions have names', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());
    await shop.gotoWishlist();

    const row = shop.items().first();
    const boxId = await shop.selectBox(row).getAttribute('id');
    await expect(page.locator(`label[for="${boxId}"]`)).toHaveText(/\S/);
    await expect(page.getByLabel(/select available items/i)).toBeVisible();
    await expect(shop.removeButton(row)).toHaveAccessibleName(/remove/i);
  });

  test('controls are reachable by keyboard', async ({ page }) => {
    const shop = new Storefront(page);
    await shop.gotoCategory(env.categoryPath);
    await shop.addFromCard(await shop.firstCardProductId());
    await shop.gotoWishlist();

    await shop.selectAll().focus();
    await page.keyboard.press('Space');
    await expect(shop.selectBox(shop.items().first())).toBeChecked();
    await expect(shop.bulkButton()).toBeEnabled();
  });
});
