# Stage 3: admin wishlist overview (implementation plan)

Status: **draft for review; design pending** (the "Task 3: Admin design et captures" tab is still empty). Source: "Task 3" tab (10 scenarios, 16 acceptance criteria). Baseline: module **1.1.0** on `main` (`af871db`). Target release: **1.2.0**, as an in-place upgrade.

Stage 3 is **read-only visibility for employees**: a back-office listing of every wishlist (name, owner, product count, search by customer) and a detail page per wishlist. No create, edit, delete or move from the back office.

## 1. What the earlier stages already give us

| Stage 3 need | Already there |
|---|---|
| Guest lists visible server-side (Task 3 technical note) | All lists, guest ones included, are rows in `PREFIX_summitwishlist`; guests are identified by `owner_key = guest:<sha256>`, never browser-only storage |
| Owner of each list | `id_customer` on customer lists (also guest-checkout customers since 1.1.0); `NULL` for guests |
| Several lists per customer | Separate rows with their own `name`, `default_marker` |
| Product count | `COUNT(summitwishlist_product)` per list (indexed on `id_wishlist`) |
| Product data per entry | `id_product`, `id_product_attribute`, `fallback_name` (kept for deleted products) |
| Shop scope | `id_shop` on every list |

**Consequence:** no schema change. Stage 3 adds read-only code only.

## 2. Decisions (recommendations; I'll follow them unless told otherwise)

1. **Native PrestaShop grid** (Task 3 suggests it): `AbstractGridDefinitionFactory` + `AbstractDoctrineQueryBuilder` + `DoctrineGridDataFactory` + `GridFactory`. Pagination, sorting, filters and "reset" come from core, rendered with the core grid Twig templates. All classes exist in 9.2.0 (checked).
2. **A modern Symfony admin controller** (`PrestaShopAdminController`, `#[AdminSecurity]` attributes, Twig), the same pattern as the core `ps_linklist` module shipped in this shop.
3. **Menu placement:** *Customers → Wishlists* (`parent_class_name: AdminParentCustomer`), tab class `AdminSummitWishlist`, icon `favorite_border`. Permissions come from the standard tab roles (`ROLE_MOD_TAB_ADMINSUMMITWISHLIST_READ`…), granted to SuperAdmin on install and editable per employee profile.
4. **Read-only by construction:** only **GET** routes are registered. The grid has no bulk actions, no row delete or edit, and no position handling; the only row action is **View**. The detail page has no forms. Even an employee with update or delete rights has no endpoint to call.
5. **Grid columns:**
   - ID;
   - Wishlist name;
   - Owner: customer full name, or "Guest";
   - Owner email (empty for guests);
   - Owner type: "Customer", "Guest checkout" or "Guest";
   - Products (count);
   - Default (yes/no);
   - Last updated;
   - Shop (multistore only);
   - View action.
6. **Search by customer (Scenario 8):** one filter on *Owner* that matches first name, last name, "first last" and email (`LIKE`), plus an *Owner type* select filter. Also filters on wishlist name, ID, products (range) and dates (core date-range filter). Default sort: last updated, newest first.
7. **Guests (Scenario 6):** shown as **"Guest"**, with a short stable reference (`Guest · a1b2c3`, the first 6 hex characters of the stored `owner_key` hash). This lets an employee tell two guests apart. It's a hash of a hash, so it can't be used to impersonate the browser. Guest-checkout customers show their name and email with a "Guest checkout" type.
8. **Detail page** (`/wishlists/{id}`):
   - **header:** name, a Default badge, product count, created/updated dates, shop;
   - **owner card:** name, email, a link to the native *Customer* page (read-only navigation), or "Guest" + reference;
   - **products table:** image, name (linking to the native product edit page in a new tab, for navigation only), combination, reference, current price, availability status, date added;
   - an empty-state message for lists with no products (Scenario 7);
   - an unknown ID gives a native 404-style page with a "back to wishlists" link.
9. **Prices and images, as the shop sells them now:**
   - price: `Product::getPriceStatic(…, $taxIncluded = true, $idProductAttribute)` for the list's shop, default currency and the default customer group, formatted with the back-office locale;
   - image: the combination's image, else the product cover, via `Link::getImageLink(…, 'small_default')`;
   - a deleted product shows its saved `fallback_name` and a "Deleted product" badge; disabled or out-of-stock products show a status badge.

   Employees see everything (no storefront catalog-access redaction): they're authorised staff, and the purpose is visibility.
10. **Multistore:** the grid is scoped to the back-office shop context (`Shop::getContextListShopID()`); the Shop column only appears in multistore mode. The detail page refuses lists outside the context with the same "not found".
11. **Performance:**
    - one paginated SQL query with `LEFT JOIN customer` and a grouped count subquery;
    - a separate `COUNT(*)` for the pager (core pattern);
    - existing indexes: `owner (id_shop, owner_key)`, `customer (id_customer)`, items `wishlist_order (id_wishlist, …)`;
    - detail pages batch-load product data for their entries (no N+1 per row).

## 3. Security (Scenarios 9, 10)

- **Admin-only:** routes live in the admin router (`config/routes.yml`, only loaded for the back office) and carry `_legacy_controller: AdminSummitWishlist`. Every action has `#[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]`, and the back office's own employee authentication and session token protection apply.
- **Storefront unchanged:** no new front controllers, routes or hooks; the storefront `wishlist` page still only resolves the visitor's own lists, so customers can't reach other wishlists (already covered by Stage 2's forged-ID tests).
- **Data minimisation:** the grid and detail page show what Task 3 asks for (name, email) and nothing more (no addresses, no guest token).

## 4. Backward compatibility (strict; a release gate)

Same rule as Stage 2: **replace the files, click Upgrade, lose nothing.** For Stage 3 that means:

### 4.1 Nothing in the storefront changes

- No changes to front controllers, hooks, templates, JS or CSS, the cookie, the CSRF formula, JSON contracts, URLs or translations used by the storefront.
- The Stage 1 and Stage 2 Playwright suites must pass **unmodified** on the upgraded shop.

### 4.2 Database

- **No schema change, no data migration.** Read-only queries only. Fresh install and upgrade still converge on identical tables (`SHOW CREATE TABLE` diff = empty).

### 4.3 New moving parts and their upgrade path

| New in 1.2.0 | Fresh install | Upgrade from 1.0.x / 1.1.0 |
|---|---|---|
| Admin tab *Customers → Wishlists* | `$tabs` property (auto-registered on install) | **`upgrade-1.2.0.php` creates the Tab** (`$tabs` is only processed on install/reset, so relying on it would leave upgraded shops without a menu entry). It is idempotent: it reuses an existing `AdminSummitWishlist` tab |
| Admin routes (`config/routes.yml`) | loaded once the module is active | loaded after the upgrade's cache clear |
| Admin services (`config/admin/services.yml` only, never `config/services.yml` or `config/front/`) | ditto | ditto. Admin-only on purpose, so the storefront container is untouched |
| `vendor/autoload.php` (a few lines, PSR-4 for `Summit\Wishlist\`) | the kernel includes it for active modules (`AppKernel`, lines 290–298) | ditto. The module's own `spl_autoload_register` stays as-is for the storefront and legacy paths, so nothing that worked before depends on the new file |
| Translation domain `Modules.Summitwishlist.Admin` | new strings only | already used for the module name; nothing reworded |

- **Disable, enable, uninstall:** core hides the tab when the module is disabled and removes it on uninstall (module-owned tab). Uninstall still drops the tables (documented, unchanged).
- **Rollback check:** with 1.2.0 installed, put back the 1.1.0 files. The storefront is unaffected, but the leftover tab would point at a route that no longer exists. The rollback note therefore says: disable or reset the module, or delete the tab, after a downgrade. This is the one non-transparent part, and it's documented in the README.
- **PHP / PS versions:** unchanged (PS 9.0+, PHP 8.1+); attributes such as `#[AdminSecurity]` require 9.x, which the module already requires.

### 4.4 Verification (all required before release)

1. Tag `v1.1.0` on `af871db` first, so the upgrade source is reproducible.
2. **Upgrade rehearsal on the current shop data:** guest lists, multi-list owner, default lists, and the item in the cart.
   1. Upgrade.
   2. Check that *Customers → Wishlists* appears for SuperAdmin.
   3. Check that storefront pages and counts are identical before and after.
3. **Unmodified Stage 1 suite + Stage 2 checks** on the upgraded shop.
4. **Fresh install** of 1.2.0 on a clean shop: the same tab, the same tables.
5. **Permission check:**
   - an employee profile without the tab's read right gets "Access denied";
   - a storefront customer hitting the admin route gets the back-office login.

## 5. Files (additive)

```
config/routes.yml                                  admin routes (GET only)
config/admin/services.yml                          grid definition, query builder, data factory, grid factory, controller wiring
vendor/autoload.php                                PSR-4 for Summit\Wishlist\ (kernel include)
src/Admin/Controller/WishlistAdminController.php   listAction, viewAction (read-only)
src/Admin/Grid/WishlistGridDefinitionFactory.php   columns, filters, View row action
src/Admin/Grid/WishlistQueryBuilder.php            search + count queries, shop context
src/Admin/Grid/WishlistFilters.php                 default sort/limit
src/Admin/Detail/WishlistDetailProvider.php        metadata, owner, entries with price/image/status
views/templates/admin/wishlist/list.html.twig      core grid layout
views/templates/admin/wishlist/view.html.twig      detail (cards + products table + empty state)
upgrade/upgrade-1.2.0.php                          creates the tab (idempotent)
summitwishlist.php                                 version 1.2.0, $tabs for fresh installs
```

## 6. Build order (with a demo checkpoint after each step)

**On hold** until the design is in; the plan already fixes the data and security contracts so the design only shapes the presentation.

0. **Tag `v1.1.0`;** capture the storefront baseline (Stage 1 suite results; screenshots of `/wishlist` overview and a list). *(10 min)*
1. **Autoload + routes + services + empty controller + tab** (install and upgrade paths). *Checkpoint: the menu entry opens a blank, protected page on both a fresh and an upgraded shop.* *(40 min)*
2. **Grid:** definition, query builder (owner join, counts, shop context), filters and search. *Checkpoint: Scenarios 1, 2, 6, 8.* *(60 min)*
3. **Detail page:** provider (batch product data, prices, images, deleted/disabled states), Twig. *Checkpoint: Scenarios 3, 4, 5, 7.* *(50 min)*
4. **Security and read-only:** permission check with a restricted profile, storefront-customer check, an audit that no POST routes exist. *Checkpoint: Scenarios 9, 10.* *(20 min)*
5. **Compatibility gate (§4.4),** README/changelog, admin Playwright tests. *(45 min)*

## 7. Test plan (`tests/e2e/admin.spec.ts`, needs `SWL_ADMIN_*` like Scenario 11)

| Scenario | Test |
|---|---|
| 1 Listing | grid shows name, owner, count; View opens the detail page |
| 2 One customer, three lists | three rows with the same owner and their own counts |
| 3 Detail | name, owner, the two products, total count |
| 4 Product info | each row has a name, a loaded image, a formatted current price |
| 5 Registered owner | full name and email on the grid and the detail page |
| 6 Guest | owner "Guest", row visible, details open |
| 7 Empty list | name, owner, empty message, count 0 |
| 8 Search by customer | the "Doe" filter returns John Doe's two lists only |
| 9 Restricted | profile without read right → access denied; storefront session → back-office login |
| 10 Read-only | no edit/delete/bulk controls; POST to the routes → 405/404 |
| Regression (gate) | Stage 1 suite unmodified; Stage 2 checks; storefront screenshots unchanged |
| Upgrade (gate) | 1.1.0 data → 1.2.0 → tab present, grid lists existing lists, storefront unchanged |

The seed data for 2/5/8 (a customer "John Doe" with three lists) comes from storefront registration in the test itself (`@account`), or an SQL fixture on a local shop.

## 8. Open questions

1. **Menu placement:** *Customers → Wishlists* (recommended) or *Catalog*?
2. **Guest reference:** OK to show `Guest · a1b2c3` (a hash fragment) so employees can tell guests apart, or plain "Guest" only?
3. **Product link target:** product edit page in the back office (recommended, for navigation only) or the storefront product page?
4. **GDPR export/erase** (`psgdpr` hooks): include wishlists in the customer's data export and erase? Not required by Task 3; recommend a small follow-up.
