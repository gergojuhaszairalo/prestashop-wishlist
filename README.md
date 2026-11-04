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

## Changelog

- **1.0.1:** storefront redesign per the design proposal (`docs/design.md`); mobile header entry (`displayTop`, registered by `upgrade/upgrade-1.0.1.php`); guest list claimed into the account on login. No schema change.
- **1.0.0:** Stage 1.

After upgrading on a shop with CCC (combine/compress/cache) or template caching on, clear the cache (*Advanced Parameters → Performance → Clear cache*) so the new JS, CSS and templates are served.

## Compatibility

PrestaShop 9.0+, PHP 8.1+. Manually tested as a guest on 9.2.0 with the Hummingbird theme. The Classic theme and customer-account flows have not been exercised yet. If the core `blockwishlist` module is also installed, both modules' buttons will appear.
