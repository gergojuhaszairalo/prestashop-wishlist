/**
 * Test data and fixture configuration. Defaults match the PrestaShop 9 demo catalog;
 * every value can be overridden through environment variables.
 */
const int = (name: string, fallback?: number): number | undefined => {
  const raw = process.env[name];
  if (raw === undefined || raw === '') {
    return fallback;
  }
  const value = Number.parseInt(raw, 10);
  if (Number.isNaN(value)) {
    throw new Error(`${name} must be an integer, got "${raw}"`);
  }
  return value;
};

export const env = {
  /** Product with combinations, shown on product cards (demo: Hummingbird printed t-shirt). */
  combinationProductId: int('SWL_COMBINATION_PRODUCT_ID', 1)!,
  /** Attribute group select name and the option label to switch to on its product page. */
  combinationGroup: process.env.SWL_COMBINATION_GROUP ?? 'group[1]',
  combinationOption: process.env.SWL_COMBINATION_OPTION ?? 'M',
  /** Category page with several orderable products (demo: Clothes). */
  categoryPath: process.env.SWL_CATEGORY_PATH ?? '/index.php?id_category=3&controller=category',

  /** Product the unavailable-product test disables and re-enables through the back office. */
  toggleProductId: int('SWL_TOGGLE_PRODUCT_ID', 6)!,

  /** Back office, used only by tests that change catalog state. Tests skip without credentials. */
  adminUrl: (process.env.SWL_ADMIN_URL ?? '').replace(/\/$/, ''),
  adminEmail: process.env.SWL_ADMIN_EMAIL ?? '',
  adminPassword: process.env.SWL_ADMIN_PASSWORD ?? '',

  /** Optional prepared fixtures for the purchase-rule tests (review 2). Tests skip when unset. */
  minQtyProductId: int('SWL_MIN_QTY_PRODUCT_ID'),
  minQty: int('SWL_MIN_QTY', 3)!,
  customizableProductId: int('SWL_CUSTOMIZABLE_PRODUCT_ID'),
  restrictedProductId: int('SWL_RESTRICTED_PRODUCT_ID'),
  outOfStockProductId: int('SWL_OUT_OF_STOCK_PRODUCT_ID'),
};

export const hasAdmin = (): boolean => Boolean(env.adminUrl && env.adminEmail && env.adminPassword);
