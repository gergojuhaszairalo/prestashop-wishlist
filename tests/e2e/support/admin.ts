import { Browser, BrowserContext, expect, Locator, Page } from '@playwright/test';
import { env } from './env';

/**
 * Minimal back-office driver: catalog changes for Scenario 11 and the read-only wishlist
 * pages of Stage 3. Uses its own browser context so storefront sessions are untouched.
 */
export class BackOffice {
  private constructor(private readonly context: BrowserContext, readonly page: Page) {}

  static async open(browser: Browser, email = env.adminEmail, password = env.adminPassword): Promise<BackOffice> {
    const context = await browser.newContext({ baseURL: env.adminUrl + '/' });
    const page = await context.newPage();
    await page.goto(`${env.adminUrl}/index.php?controller=AdminLogin`);
    await page.locator('input[name$="[email]"], input#email').first().fill(email);
    await page.locator('input[name$="[passwd]"], input#passwd').first().fill(password);
    await page.locator('button[name$="[submit_login]"], button#submit_login').first().click();
    await expect(page.locator('#main-div').first()).toBeAttached({ timeout: 30_000 });
    await expect(page).not.toHaveURL(/login|AdminLogin/);

    return new BackOffice(context, page);
  }

  async close(): Promise<void> {
    await this.context.close();
  }

  /** Sets a product's status through the product grid toggle (the same request the switch sends). */
  async setProductActive(productId: number, active: boolean): Promise<void> {
    const listHref = await this.page.locator('a[href*="/sell/catalog/products"]').first().getAttribute('href');
    const url = new URL(listHref!, env.adminUrl + '/');
    url.searchParams.set('product[filters][id_product][min_field]', String(productId));
    url.searchParams.set('product[filters][id_product][max_field]', String(productId));
    await this.page.goto(url.toString());

    const toggle = this.page.locator('#product_grid_table tbody tr td.column-active .ps-switch').first();
    await expect(toggle).toBeAttached();
    const current = (await toggle.locator('input:checked').getAttribute('value')) === '1';
    if (current === active) {
      return;
    }

    const toggleUrl = await toggle.getAttribute('data-toggle-url');
    const result = await this.page.evaluate(async (target) => {
      const response = await fetch(target!, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return response.json();
    }, toggleUrl);
    expect(result.status, `toggle product ${productId}: ${result.message}`).toBe(true);
  }

  // Stage 3: Customers > Wishlists

  /** Opens the grid from the sidebar entry, so the URL carries a valid back-office token. */
  async gotoWishlists(): Promise<void> {
    const href = await this.page.locator('a[href*="/modules/summitwishlist/wishlists"]').first().getAttribute('href');
    await this.page.goto(new URL(href!, env.adminUrl + '/').toString());
    await expect(this.wishlistGrid()).toBeVisible();
  }

  wishlistGrid(): Locator {
    return this.page.locator('#summitwishlist_grid_table');
  }

  wishlistRows(): Locator {
    return this.wishlistGrid().locator('tbody tr:not(.empty_row)');
  }

  /** Filters the grid by customer name or email (the grid's single customer search field). */
  async searchCustomer(term: string): Promise<void> {
    const field = this.page.locator('input[name="summitwishlist[customer]"]');
    await field.fill(term);
    await this.page.locator('#summitwishlist_grid_table button[name="summitwishlist[actions][search]"]').click();
    await expect(this.wishlistGrid()).toBeVisible();
  }

  async resetFilters(): Promise<void> {
    const reset = this.page.locator('#summitwishlist_grid_table .js-reset-search');
    if (await reset.count()) {
      await reset.click();
      await expect(this.wishlistGrid()).toBeVisible();
    }
  }

  /** Opens a row's read-only detail page through its View action. */
  async openRow(row: Locator): Promise<void> {
    await row.locator('a[href*="/modules/summitwishlist/wishlists/"]').first().click();
    await expect(this.page.locator('.card').first()).toBeVisible();
  }

  /** The detail page URL of a grid row, with its back-office token. */
  async rowDetailUrl(row: Locator): Promise<string> {
    const href = await row.locator('a[href*="/modules/summitwishlist/wishlists/"]').first().getAttribute('href');
    return new URL(href!, env.adminUrl + '/').toString();
  }
}
