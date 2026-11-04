# Stage 3: admin wishlist overview (implementation plan)

Status: **draft for review, aligned with the "Task 3: Admin design and screenshots" tab**. Sources: "Task 3" tab (10 scenarios, 16 acceptance criteria) and the design tab, which overrides this plan where they differ. Baseline: module **1.1.0** on `main` (`af871db`, pushed to `gergojuhaszairalo/prestashop-wishlist`; the design tab's note about a 1.0.0 fork refers to the earlier `MCPAppsBuilders` fork). Target release: **1.2.0**, as an in-place upgrade.

Stage 3 is **read-only visibility for employees**: a back-office listing of every wishlist (name, owner, product count, search by customer) and a detail page per wishlist. No create, edit, delete or move from the back office.

## 0. Module structure: PrestaShop example-modules (1.2.0)

1.2.0 also restructures the whole module after [PrestaShop/example-modules](https://github.com/PrestaShop/example-modules), without changing storefront behaviour or data:

| Convention | Example module | In 1.2.0 |
|---|---|---|
| Composer PSR-4 autoload, `vendor/autoload.php` required from the module file | demodoctrine, demo_grid | `composer.json` (`Summit\Wishlist\` → `src/`, `prepend-autoloader: false`). The old hand-written autoloader stays only as a fallback for a copy without `vendor/` |
| Services in the container: `config/common.yml`, imported by `config/front/services.yml` and `config/services.yml`; fetched from the container | demodoctrine | All storefront services are defined in `config/common.yml`. `ServiceFactory` now takes them from the container and wires them by hand only when the container doesn't know them yet (install, or a stale cache right after the file swap), like demodoctrine's `getInstaller()` |
| Data access through the injected DBAL connection and `%database_prefix%` | demodoctrine, demo_grid | `DbWishlistRepository`, `Installer` and `WishlistPresenter` use `doctrine.dbal.default_connection` with bound parameters instead of `Db::getInstance()`. Same SQL, same locking (GET_LOCK + transaction on one connection), and the same stored values (legacy `pSQL()` tag stripping is kept) |
| `PrestaShopAdminController`, `#[AdminSecurity]`, `config/routes.yml` with `_legacy_controller`/`_legacy_link` | demo_grid, democontrollertabs | Stage 3 admin controller |
| Grid = definition factory + query builder + `Filters` + Doctrine data factory + `GridFactory`, grid JS extensions | demo_grid | Stage 3 grid, plus a small data decorator that labels guests |
| `$tabs` with `route_name`; a manual Tab for upgrades | democontrollertabs | `$tabs` for installs, `upgrade-1.2.0.php` for upgrades |
| `ps_versions_compliancy` max `9.99.99`, `getContent()` redirecting to the admin page, `config.xml` | all | done |

**Deliberate deviations:**
- **No Doctrine ORM entities** (demodoctrine has them). The repository relies on `INSERT IGNORE`, named locks and owner-scoped transactions that ORM would only obscure, and entities would add nothing for these two tables.
- **`vendor/` is committed** (the examples ignore it and expect `composer install`). The strict upgrade rule is "replace the files, click Upgrade". The Symfony kernel needs `vendor/autoload.php` to compile the admin services, so a copy without it would break the back office.
- **Front controllers stay `ModuleFrontController` with `moduleRoutes`**, as in demomoduleroutes.

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
4. **Read-only by construction:** listing and detail are **GET**. The one POST is the core grid search (demo_grid's `searchAction`), which only stores the employee's filters and redirects. The grid has no bulk actions, no row delete or edit, and no position handling; the only row action is **View**. The detail page has no forms. Even an employee with update or delete rights has no endpoint that changes a wishlist.
5. **Grid columns (per the design):**
   - Wishlist name;
   - Owner: customer full name, or "Guest";
   - Email: only for an identified customer, empty for guests;
   - Products: the number of saved entries, unavailable products included, 0 for an empty list;
   - View action;
   - Shop, only in multistore mode.

   Sorting and pagination are usability extras (the design calls them proposals). Pagination and counts are computed server-side.
6. **Search by customer (Scenario 8):**
   - one search field for **name or email**, with Search and Reset (core grid filter);
   - it matches first name, last name, "first last" and email (`LIKE`) within the shop scope;
   - the filter, sort and page live in the grid parameters, so the detail page's back link returns to the same filtered view;
   - a search with no results keeps the term, explains that no wishlist matches, and offers Reset.
7. **Guests (Scenario 6):** shown as **"Guest"** with no email and **no technical identifier** (no hash or cookie fragment), per the design. Guest-checkout customers have a customer record, so they show their name and email like registered customers.
8. **Detail page** (`/wishlists/{id}`):
   - **title:** the wishlist name, plus a back link to the grid with its filter, sort and page;
   - **summary:** owner (name, or "Guest"), email only for an identified customer, product count;
   - **products table:** image, name, variant when there is one, current price with its context. No product links, and no edit, delete, move or add-to-cart buttons;
   - **empty list:** "This wishlist contains no products", count 0, no empty table (Scenario 7);
   - an unknown or out-of-scope ID gives the same generic "not available" page, without name, owner, count or contents.
9. **Prices and images:**
   - **Price context, implemented as proposed, product sign-off still pending.** The design asks for sign-off before implementation. The context: the list's shop, its default currency, tax included, as an unidentified storefront visitor sees it. It's computed with `Product::getPriceStatic()` in a cloned context and formatted with the back-office locale. The column header says "Price (tax incl., EUR)", and the page states that the price is a visitor price, not the customer's personalised one. Changing the context later only touches `WishlistDetailProvider::priceContext()`.
   - **Price unavailable:** when no price can be computed (deleted product or combination), show "Price unavailable" instead of a number.
   - **Image:** the combination's image, else the product cover, via `Link::getImageLink(…, 'small_default')`; the native "no picture" image when there is none.
   - **Deleted product:** "Unavailable product" (the saved `fallback_name` is not shown, as the design asks), with no link and "Price unavailable".

   Employees see current catalog data without the storefront's customer-group redaction: they are authorised staff, and the purpose is visibility.
10. **Multistore:** the grid is scoped to the back-office shop context (`Shop::getContextListShopID()`); the Shop column only appears in multistore mode. The detail page refuses lists outside the context with the same "not found".
11. **Performance:**
    - one paginated SQL query with `LEFT JOIN customer` and a grouped count subquery;
    - a separate `COUNT(*)` for the pager (core pattern);
    - existing indexes: `owner (id_shop, owner_key)`, `customer (id_customer)`, items `wishlist_order (id_wishlist, …)`;
    - detail pages batch-load product data for their entries (no N+1 per row).

## 2.1 Look and feel (per the design tab)

- **Native back office only:** the pages extend `@PrestaShop/Admin/layout.html.twig`, the grid uses `@PrestaShop/Admin/Common/Grid/grid_panel.html.twig`, and the detail page uses native cards and tables. They inherit the installed admin theme (IBM Plex Sans, Material Symbols, the CDK tokens) and add no second framework or token block.
- **Long content:** long list names, variant labels and missing thumbnails are handled by wrapping and the native placeholder image. All text goes through admin translations (`Modules.Summitwishlist.Admin`); no sample data is hardcoded.
- **Loading and error states:** the grid and detail are server-rendered, so a slow or failed load shows the native back-office error page with a Retry link back to the same URL. There is no client-side loading spinner to build.

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
- **Rollback check:** with 1.2.0 installed, put back the 1.1.0 files. The storefront is unaffected, but the leftover tab would point at a route that no longer exists. The rollback note therefore says: delete the tab (*Advanced Parameters → Menus*) after a downgrade. Never Reset, because that reinstalls the module and drops the tables. This is the one non-transparent part, and it's documented in the README.
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
composer.json, vendor/                                   PSR-4 autoload (composer dump-autoload)
config.xml
config/common.yml                                        storefront/shared services (DBAL repository, services, session)
config/front/services.yml                                imports common.yml
config/services.yml                                      imports common.yml + admin controller, grid, detail provider
config/routes.yml                                        admin routes: GET list, POST search (filters only), GET view
src/Controller/Admin/WishlistController.php              index/search/view, #[AdminSecurity] read
src/Grid/Definition/Factory/WishlistGridDefinitionFactory.php
src/Grid/Query/WishlistQueryBuilder.php                  paginated query, grouped counts, shop context, sort whitelist
src/Grid/Filters/WishlistFilters.php
src/Grid/Data/WishlistGridDataFactory.php                "Guest" / no email / owner key dropped
src/Admin/WishlistDetailProvider.php                     detail read model (batched names, variants, images; prices)
src/Owner/WishlistSession.php                            per-request owner + guest claim (was in ServiceFactory)
views/templates/admin/wishlist/index.html.twig, view.html.twig, views/js/admin/wishlist-grid.js
upgrade/upgrade-1.2.0.php                                creates/updates the tab (idempotent)
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

1. **Price context (blocking for the detail page):** confirm the proposal in decision 9 (shop default currency, default customer group, tax included), or name the context the product team wants.
2. **Menu placement:** *Customers → Wishlists* (recommended) or *Catalog*?
3. **GDPR export/erase** (`psgdpr` hooks): include wishlists in the customer's data export and erase? Not required by Task 3; recommend a small follow-up.

Settled by the design: plain "Guest" with no identifier; no product links on the detail page; grid columns name, owner, email, count, View.
