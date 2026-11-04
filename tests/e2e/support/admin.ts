import { Browser, BrowserContext, expect, Page } from '@playwright/test';
import { env } from './env';

/**
 * Minimal back-office driver for tests that must change catalog state after a product
 * was saved (Scenario 11). Uses its own browser context so storefront sessions are untouched.
 */
export class BackOffice {
  private constructor(private readonly context: BrowserContext, private readonly page: Page) {}

  static async open(browser: Browser): Promise<BackOffice> {
    const context = await browser.newContext({ baseURL: env.adminUrl + '/' });
    const page = await context.newPage();
    await page.goto(`${env.adminUrl}/index.php?controller=AdminLogin`);
    await page.locator('input[name$="[email]"], input#email').first().fill(env.adminEmail);
    await page.locator('input[name$="[passwd]"], input#passwd').first().fill(env.adminPassword);
    await page.locator('button[name$="[submit_login]"], button#submit_login').first().click();
    await expect(page.locator('a[href*="/sell/catalog/products"]').first()).toBeAttached({ timeout: 30_000 });

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
}
