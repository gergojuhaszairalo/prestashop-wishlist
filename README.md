# Summit Wishlist (PrestaShop 9)

Guest and customer wishlists: add from product pages and product cards, a dedicated wishlist page, and single or bulk add to cart through the core cart. Built for the PS Summit Hackathon 2026, Stage 1.

## Install

1. Copy this folder to `modules/summitwishlist/` in the shop (the folder name must stay `summitwishlist`).
2. Back office → **Modules → Module Manager** → search "Summit Wishlist" → **Install**.

Nothing in core or the theme is modified. The buttons appear through standard hooks (`displayProductActions`, `displayProductListReviews`), so any theme that calls them works.

The wishlist page is at `/wishlist` when friendly URLs are on (otherwise `index.php?fc=module&module=summitwishlist&controller=wishlist`). It is also linked from the header (`displayNav2`) and My Account.

## Upgrade

Replace the module files with the newer version, then click **Upgrade** in the Module Manager. Each version ships an `upgrade/upgrade-x.y.z.php` script, and PrestaShop runs every script newer than the installed version, in order. Existing wishlists are kept.

**Do not uninstall to upgrade:** uninstall drops the wishlist tables. Disabling the module keeps the data.

## Behaviour and decisions

- **Entries are purchasable variants:** product + combination. Product pages save the selected combination; product cards save the default one. The same variant is never saved twice.
- **Guests** are identified by a random token in the PrestaShop cookie. Only its SHA-256 hash is stored. **Customers** are identified by their account.
- **Logging in keeps the guest's items (1.0.1).** On the first request after any login path (login form, registration, password reset, cart recovery, social login), the guest list moves into the account's default list.
  - If the account has no list yet, the guest list simply becomes the account list.
  - Otherwise the two are merged; duplicates are dropped.
  - The guest list and token are then deleted, so logging out later starts empty.
  - A notice says how many items were added.
  - Guest-checkout customers keep their cookie list until they create a real account.
- **CSRF:** every POST carries a per-visitor token bound to the current guest or customer. It is not `Tools::getToken()`, which is identical for all guests.
- **Add to cart** reproduces the core cart controller's checks before calling `Cart::updateQty()`: shop and active/orderable status, customer-group access, combination validity, required customisation, minimal quantity, and stock/backorder rules. Results are reported per item. Adding to the cart never removes items from the wishlist or touches other cart lines.
- **Unavailable items** (disabled, out of stock without backorders, deleted, restricted) stay visible with a reason and can't be added. Deleted products show the name saved when they were added.

## Back office (1.2.0)

**Customers → Wishlists** lists every wishlist in the current shop context: name, owner (customer name, or "Guest"), email for customers, and product count. It can be searched by customer name or email. **View** opens a read-only detail page with each entry's image, name, combination and current price (tax included, the shop's default currency, as a storefront visitor sees it). Nothing in the back office can change a wishlist. Access follows the tab's permissions per employee profile.

## Development

The module follows [PrestaShop/example-modules](https://github.com/PrestaShop/example-modules): Composer autoload, services in `config/common.yml` (imported by `config/front/services.yml` and `config/services.yml`), and admin routes in `config/routes.yml`. After adding or moving a class under `src/`, run `composer dump-autoload` in the module folder. `vendor/` is committed, so a plain file copy installs and upgrades without Composer.

## Changelog

- **1.2.0:** Stage 3, a read-only back-office overview (*Customers → Wishlists*, created for upgraded shops by `upgrade/upgrade-1.2.0.php`). Restructured after the PrestaShop example modules: Composer autoload, container services, and DBAL instead of `Db`. Fix: the product-page heart now follows the selected combination, even when the theme's refresh arrives after the wishlist response. Back-office acceptance tests in `tests/e2e/admin.spec.ts`. No schema change, and the storefront is otherwise unchanged. **Rollback to 1.1.0:** also delete the *Wishlists* tab (*Advanced Parameters → Menus*), because 1.1.0 has no route for it. Don't use Reset for this: it reinstalls the module and drops the wishlist tables.
- **1.1.0:** Stage 2, multiple named wishlists with a default list, the membership picker, move, rename and delete. No schema change.
- **1.0.1:** storefront redesign per the design proposal (`docs/design.md`); mobile header entry (`displayTop`, registered by `upgrade/upgrade-1.0.1.php`); guest list claimed into the account on login. No schema change.
- **1.0.0:** Stage 1.

After upgrading on a shop with CCC (combine/compress/cache) or template caching on, clear the cache (*Advanced Parameters → Performance → Clear cache*) so the new JS, CSS and templates are served.

## Compatibility

PrestaShop 9.0+, PHP 8.1+. Manually tested as a guest on 9.2.0 with the Hummingbird theme. The Classic theme and customer-account flows have not been exercised yet. If the core `blockwishlist` module is also installed, both modules' buttons will appear.
