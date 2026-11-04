import { expect, Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';

export type Customer = { email: string; password: string };

/**
 * Throwaway customer created through the storefront registration form, so tests
 * never need real credentials and never share an account between runs.
 */
export function newCustomer(): Customer {
  const id = `${Date.now()}-${randomBytes(3).toString('hex')}`;
  return {
    email: `swl-e2e-${id}@example.test`,
    password: `Swl-${randomBytes(6).toString('hex')}-Pw9!`,
  };
}

export async function register(page: Page, customer: Customer): Promise<void> {
  await page.goto('/index.php?controller=registration');
  const form = page.locator('#customer-form');
  await form.locator('input[name="firstname"]').fill('Wishlist');
  await form.locator('input[name="lastname"]').fill('Tester');
  await form.locator('input[name="email"]').fill(customer.email);
  await form.locator('input[name="password"]').fill(customer.password);
  for (const checkbox of await form.locator('input[type="checkbox"][required]').all()) {
    await checkbox.check();
  }
  await form.locator('[data-link-action="save-customer"]').click();
  await expect(page.locator('a[href*="mylogout"]').first()).toBeAttached();
}

export async function login(page: Page, customer: Customer): Promise<void> {
  await page.goto('/index.php?controller=authentication');
  const form = page.locator('#login-form');
  await form.locator('input[name="email"]').fill(customer.email);
  await form.locator('input[name="password"]').fill(customer.password);
  await form.locator('#submit-login').click();
  await expect(page.locator('a[href*="mylogout"]').first()).toBeAttached();
}

export async function logout(page: Page): Promise<void> {
  await page.goto('/index.php?mylogout=');
  await expect(page.locator('a[href*="mylogout"]')).toHaveCount(0);
}
