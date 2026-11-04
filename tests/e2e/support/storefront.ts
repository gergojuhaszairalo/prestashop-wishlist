import { APIResponse, expect, Locator, Page } from '@playwright/test';

export type CartLine = { idProduct: number; idProductAttribute: number; quantity: number; name: string };

/** Module result text; kept in one place so a wording change breaks one line, not every test. */
export const msg = {
  added: /was added to your wishlist/i,
  alreadyPresent: /is already in your wishlist/i,
  removed: /was removed from your wishlist/i,
  addedToCart: /added to your cart/i,
  minimumQuantity: /minimum purchase quantity/i,
  unavailable: /is no longer available/i,
  noAccess: /not available for your account/i,
  needsOptions: /needs options to be chosen/i,
  outOfStock: /is out of stock/i,
  invalidToken: /session has changed/i,
  nothingSelected: /select at least one/i,
  claimed: /items? from your visit (was|were) added to your wishlist/i,
};

/**
 * Page object for the storefront parts the module renders. Selectors use the module's
 * own sw-* classes and data attributes, so tests don't depend on theme markup.
 */
export class Storefront {
  constructor(readonly page: Page) {}

  async gotoHome(): Promise<void> {
    await this.page.goto('/');
  }

  async gotoCategory(path: string): Promise<void> {
    await this.page.goto(path);
    await expect(this.cardButtons().first()).toBeVisible();
  }

  /** Opens a product page by ID, following the canonical-URL notice debug mode shows instead of a redirect. */
  async gotoProduct(productId: number): Promise<void> {
    await this.page.goto(`/index.php?id_product=${productId}&controller=product`);
    const moved = this.page.getByText('This page has moved');
    if (await moved.isVisible().catch(() => false)) {
      await this.page.locator('a').filter({ hasText: /^https?:\/\// }).first().click();
    }
    await expect(this.pdpButton()).toBeVisible();
  }

  async gotoWishlist(): Promise<void> {
    await this.page.goto('/index.php?fc=module&module=summitwishlist&controller=wishlist');
    await expect(this.page.locator('.sw-wishlist')).toBeVisible();
  }

  // Add triggers

  cardButtons(): Locator {
    return this.page.locator('.sw-card-heart [data-summitwishlist-action]');
  }

  cardButton(productId: number): Locator {
    return this.page.locator(`.sw-card-heart [data-summitwishlist-action][data-sw-product="${productId}"]`).first();
  }

  pdpButton(): Locator {
    return this.page.locator('.sw-pdp__trigger');
  }

  /** Theme toast (Hummingbird useToast) or the module's fallback live region. */
  lastToast(): Locator {
    return this.page.locator('.toast, .sw-live__message').last();
  }

  /**
   * Clicks a heart / PDP toggle and returns the application messages from the JSON answer.
   * Asserting on the response, not the toast text, keeps tests stable when the toast auto-hides.
   */
  async clickAdd(button: Locator): Promise<string> {
    const response = this.page.waitForResponse(
      (r) => r.request().method() === 'POST' && /summitwishlist/.test(r.url()) && /item/.test(r.url())
    );
    await button.click();
    const json = await (await response).json();
    await expect(this.lastToast()).toBeVisible();
    return (json.messages ?? []).join(' ');
  }

  async addFromCard(productId: number): Promise<string> {
    return this.clickAdd(this.cardButton(productId));
  }

  /** Product ID of the first card on the current listing that has a heart, skipping some IDs. */
  async firstCardProductId(skip: number[] = []): Promise<number> {
    const ids = await this.page
      .locator('.sw-card-heart input[name="id_product"]')
      .evaluateAll((inputs) => inputs.map((i) => Number((i as HTMLInputElement).value)));
    const id = ids.find((value) => !skip.includes(value));
    if (!id) {
      throw new Error('No product card with a wishlist heart found on this page');
    }
    return id;
  }

  async selectCombination(groupName: string, optionLabel: string): Promise<void> {
    const select = this.page.locator(`#add-to-cart-or-refresh select[name="${groupName}"]`);
    // The theme refreshes the product block over AJAX; wait for it so the click uses the new combination.
    const refresh = this.page.waitForResponse((r) => /action=refresh|controller=product/.test(r.url()), { timeout: 15_000 });
    await select.selectOption({ label: optionLabel });
    await refresh.catch(() => undefined);
    await expect(this.pdpButton()).toBeEnabled();
  }

  async headerCount(): Promise<number> {
    return Number((await this.page.locator('.js-sw-count').first().textContent())?.trim());
  }

  // Wishlist page

  items(): Locator {
    return this.page.locator('.sw-item');
  }

  async itemNames(): Promise<string[]> {
    return this.items().evaluateAll((rows) =>
      rows.map((row) => {
        const name = row.querySelector('.sw-item__name')?.textContent?.trim() ?? '';
        const variant = row.querySelector('.sw-item__variant')?.textContent?.trim();
        return variant ? `${name} (${variant})` : name;
      })
    );
  }

  async itemIds(): Promise<string[]> {
    return this.items().evaluateAll((rows) => rows.map((row) => row.getAttribute('data-sw-item') ?? ''));
  }

  addButton(row: Locator): Locator {
    return row.locator('.sw-add');
  }

  removeButton(row: Locator): Locator {
    return row.locator('.sw-remove');
  }

  selectBox(row: Locator): Locator {
    return row.locator('[data-sw-select]');
  }

  status(row: Locator): Locator {
    return row.locator('.sw-item__availability');
  }

  bulkButton(): Locator {
    return this.page.locator('[data-sw-bulk]');
  }

  selectAll(): Locator {
    return this.page.locator('[data-sw-select-all]');
  }

  notifications(): Locator {
    return this.page.locator('#notifications .alert');
  }

  // Cart

  /** Cart contents read from the core cart page in a separate tab, so the current page is untouched. */
  async cartLines(): Promise<CartLine[]> {
    const tab = await this.page.context().newPage();
    try {
      await tab.goto('/index.php?controller=cart&action=show');
      return await tab.evaluate(() => {
        const cart = (window as any).prestashop?.cart;
        return (cart?.products ?? []).map((p: any) => ({
          idProduct: Number(p.id_product),
          idProductAttribute: Number(p.id_product_attribute),
          quantity: Number(p.cart_quantity ?? p.quantity),
          name: String(p.name),
        }));
      });
    } finally {
      await tab.close();
    }
  }

  /** Adds a product through the core product page "Add to cart", as a shopper would. */
  async addToCartFromProductPage(productId: number): Promise<void> {
    await this.gotoProduct(productId);
    const added = this.page.waitForResponse((r) => r.request().method() === 'POST' && /controller=cart|\/cart/.test(r.url()));
    await this.page.locator('#add-to-cart-or-refresh [data-button-action="add-to-cart"]').click();
    await added;
  }

  // Raw module requests, for security and server-side-guard tests

  async csrfToken(): Promise<string> {
    return this.page.locator('input[name="swl_token"]').first().inputValue();
  }

  async moduleUrl(controller: 'item' | 'cart'): Promise<string> {
    const urls = await this.page.evaluate(() => ({
      item:
        document.querySelector<HTMLFormElement>('.sw-card-heart')?.action ??
        document.querySelector<HTMLButtonElement>('.sw-pdp__trigger')?.formAction ??
        document.querySelector<HTMLButtonElement>('.sw-remove')?.formAction ??
        '',
      cart: document.querySelector<HTMLFormElement>('.sw-wishlist__form')?.action ?? '',
    }));
    return urls[controller] || `/index.php?fc=module&module=summitwishlist&controller=${controller}`;
  }

  /** POSTs to a module endpoint with this browser's cookies and returns the JSON body. */
  async postModule(controller: 'item' | 'cart', form: Record<string, string | string[]>): Promise<any> {
    const url = await this.moduleUrl(controller);
    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(form)) {
      for (const v of Array.isArray(value) ? value : [value]) {
        body.append(key, v);
      }
    }
    const response: APIResponse = await this.page.request.post(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      data: body.toString(),
    });
    expect(response.ok()).toBeTruthy();
    return response.json();
  }

  /** Saves a product by ID without going through its page; mirrors what a stale page or crafted request would send. */
  async addByRequest(productId: number, productAttributeId = 0): Promise<any> {
    return this.postModule('item', {
      op: 'add',
      id_product: String(productId),
      swl_id_product_attribute: String(productAttributeId),
      swl_token: await this.csrfToken(),
    });
  }
}
