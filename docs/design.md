# Storefront design: implementation decisions

Source: the "design proposal" tab of the hackathon document (Hummingbird 2.1.2, PS 9.2.0). The Figma frames weren't available while building, so the written spec was followed. Compare against Figma before final sign-off.

## What follows the spec

- **Native first.** Pages extend `page.tpl` and inherit the shell, breadcrumb, notifications and title. Controls are `btn btn-primary`, `btn-outline-primary`, `btn-outline-tertiary`, `form-check-input` and `badge text-bg-*`. CSS uses the theme's `--bs-*` variables with fallbacks, scoped to `sw-*` classes. No second framework, no new icon font (Material Icons `E87E` outline heart, `E87D` filled).
- **Wishlist page:**
  - count line, guest notice (`--bs-primary-bg-subtle`, body-colored text), selection toolbar (`--bs-tertiary-bg`), bordered rows with 8px radius, and the bulk action after the list;
  - rows: 44px select column, 112px image, details (name 600, variant 14px muted, price 600, availability), and actions;
  - mobile (<768px): image | details | checkbox on top, full-width add + 44px remove underneath.
- **Selection:** "Select available items" covers purchasable rows only. It supports unchecked, checked, indeterminate and disabled states. The count and bulk label come from the actual state, and bulk is disabled when nothing is selected (JS only; without JS the server answers "select at least one").
- **Unavailable rows** keep name and reason readable. Only the image is muted, selection and cart are disabled, and remove stays available.
- **Empty state:** centered heart, "Your wishlist is empty", short copy and a primary "Continue shopping". The toolbar is hidden.
- **Card heart:**
  - rendered by `displayProductListReviews` as a standalone form (the no-JS fallback);
  - `wishlist.js` moves the module's own wrapper into `.product-miniature__top` (`sw-card-heart-host`, 8px from top/end);
  - placement is idempotent (`data-sw-placed`) and re-runs on `updateProductList` and through a narrow MutationObserver that only reacts to unplaced hearts.
- **Heart states:** outline means not saved, filled primary means saved. The accessible name stays constant, `aria-pressed` carries the state, and the tooltip names the next action (add/remove).
- **PDP:** `displayProductActions` outputs a button *inside* the core form (no nested form) with `formaction`/`formmethod` and a separately named CSRF field. The selected combination is resolved server-side from `group[]`.
- **Header:** `displayNav2` (desktop, hidden below md by the theme) plus `displayTop` with `d-md-none` and the native `header-block` structure for mobile.
- **My Account:** `displayCustomerAccount` renders an `account-menu__link`, which works in both the tiles and the sidebar.
- **Feedback:**
  - AJAX adds use `Theme.components.useToast`, with text escaped first because the helper inserts HTML, and fall back to a polite live region;
  - success is decided by the JSON `success` field, not the HTTP status;
  - wishlist-to-cart stays a normal POST/redirect with server notifications;
  - the cart badge is never faked client-side.

## Deliberate deviations and choices

- **Saved state is per variant.** A card heart is filled only when the variant it would add (the card's default combination) is saved. This keeps "filled" and "what a click does" consistent. A product saved in another size shows an outline heart on its card.
- **PDP button height follows the native row.** It sits between the 38px quantity input and the 38px "Add to cart", so it uses the native `btn` height instead of the 44px module target, which made it taller and offset. The 44px target stays on standalone icon actions (card heart, remove).
- **Hearts and the PDP button toggle (product decision, after the proposal).** Clicking a saved item removes it. With one list in Stage 1 that is unambiguous. When the multi-list picker arrives, the proposal's rule applies again: a click must not remove from an arbitrary list, so the picker decides. The server's `op=add` stays idempotent for direct requests and the API.
- **No extra mobile inset.** The theme container already pads 12px. Adding the proposed 16px on top would give 28px, so the module inherits the container edge.
- **Field names.** The spec's sample uses `sw_csrf`/`sw_action`; the module keeps `swl_token`/`op` from 1.0.0, so templates and controllers didn't change contract.
- **List picker (frame 04)** is not implemented. Per the spec it's a future feature, shown only when multiple lists exist. The JS contract is ready: `SummitWishlist.add(url, data, {wishlistId})`.

## Not yet checked

- 360, 768, 992 and 1440px widths. Only 390px and the default desktop pane were checked.
- Long translated names, very large prices and missing images.
- Screen-reader pass.
