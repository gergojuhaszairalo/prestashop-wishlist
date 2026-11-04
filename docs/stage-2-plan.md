# Stage 2: multiple named wishlists (implementation plan)

Source: "Task 2" tab (Stage 2, 14 scenarios, 19 acceptance criteria). It builds on module 1.0.1 as it is on `main`: redesign, toggle hearts, guest-list claim, empty list, post-cart prompt, catalog-access fix. Target release: **1.1.0**, as an in-place upgrade.

## 1. What Stage 1 already gives us

Status: **draft, under revision; designs pending. Do not start building.**

| Stage 2 need | Already in 1.0.1 |
|---|---|
| Separate wishlist and entries | `summitwishlist` (lists) + `summitwishlist_product` (entries) |
| Many lists per owner | `owner_key` + nullable `default_marker`, so only one default per owner is enforced and any number of other lists is allowed |
| Same product in several lists, once per list | `UNIQUE (id_wishlist, id_product, id_product_attribute)` |
| Each list has a name | `name` column, filled with "My wishlist" |
| Guests and customers, isolated | `OwnerResolver` (hashed guest token / customer), `WishlistAccessPolicy` on every operation |
| Every operation targets a list | Service methods already take an explicit `wishlistId` |
| Picker-ready triggers | `SummitWishlist.add(url, data, {wishlistId})`, `data-summitwishlist-action` |

**Consequence:** no table changes. The existing list becomes the default list and keeps its items and IDs. "Existing saved products should not be lost" holds by construction. `upgrade-1.1.0.php` changes no schema. It only runs the idempotent default-list repair from §5.1 and registers any hook added during the build.

## 2. Decisions

Decision 1 was made on 4 Nov 2026. The rest are recommendations I'll follow unless told otherwise.

1. **Destination picker: checkboxes (decided; this replaces an earlier choice of radios).** With 2+ lists, the heart or PDP button opens a dialog with **one checkbox per list**, checked when the variant is already in that list. Save applies the difference: newly checked lists get the variant, unchecked ones lose it. That covers Scenario 4 (tick Birthday), Scenario 5 (tick Christmas while Birthday stays ticked) and the remove-on-click rule in one control. This deviates from Figma frame 04, which shows radios. With exactly one list the heart keeps the Stage 1 toggle (decision 3).
2. **Duplicate list names: allowed?** *Recommend rejecting* a name that matches another list of the same owner, ignoring case and surrounding whitespace ("Birthday" vs "birthday "). Task 2 only forbids empty names, but two lists called "Birthday" make the picker ambiguous.
3. **With exactly one list, nothing changes from Stage 1.** The heart toggles directly, with no picker, so a single-list shopper never sees extra steps.
4. **Default list (decided 4 Nov 2026):**
   - **The first created list is the default.** For every existing shopper that is the Stage 1 list, so nothing changes on upgrade.
   - **Any list can be marked as default** ("Set as default" on the list header; the default shows a "Default" badge). The switch runs in one transaction: clear the old `default_marker`, then set the new one. Clearing first is required by the `UNIQUE (id_shop, owner_key, default_marker)` key.
   - The default is the target for single-list toggles, the guest-claim merge, "Add to wishlist" when no list is chosen, and `/wishlist` without `?list=`.
   - **Deleting the default promotes the oldest remaining list** (lowest `id_wishlist`), consistent with "first created is default". Deleting the last list leaves the empty state; the next save lazily creates "My wishlist" again, which becomes the default.
   - It can be renamed like any list.
5. **Header count (decided 4 Nov 2026):** the nav entries (desktop `displayNav2`, mobile `displayTop`) show the item count of the **default** list. With a single list this equals the Stage 1 count, so nothing visible changes. With several lists the link's accessible name names the list ("Wishlist: My Favorites, 3 items"). AJAX responses return the default list's count, so the header stays correct after adding to another list.
   **Heart state:** filled when the variant is saved in **at least one** list (the design proposal's rule). The header count and heart state can therefore differ when an item sits only in a non-default list. That is expected, and the demo should show it once.
6. **Moving:** a dedicated `move` operation in one transaction: insert into the target (`INSERT IGNORE`), then delete from the source. If the target already has the variant, the source entry is still removed and the message says "already in Christmas". This keeps entry IDs stable and is safe against double submits.
7. **Delete confirmation** reuses the native `<dialog>` from "Empty wishlist". Delete removes the list's entries and the list row. Catalog products are never touched (Scenario 7).
8. **Limits:** names 1–64 characters after trimming (`Validate::isGenericName`, whitespace collapsed), and at most 20 lists per owner. This guards guest abuse; both limits are constants.
9. **Guest and customer lists follow the core cart's model.** See §2.1 for the mapping and the deliberate deviations.
10. **Unauthorised access (Scenario 13):** list IDs come from the URL/POST, so every read and write resolves the list through `WishlistAccessPolicy`. A foreign or unknown ID renders as **not found** (404 page / `not_found` code), never 403, so IDs can't be probed for existence.

## 2.1 Guest ↔ customer model: based on the core cart

General direction (decided 4 Nov 2026): **handle guest and logged-in lists the way PrestaShop handles the cart.** Below is how the cart does it in 9.2.0 (`FrontController::init()` lines 360–464, `Context::updateCustomer()` lines 350–426, `Cookie::mylogout()`), and how each rule maps onto wishlists.

| # | Core cart behaviour (verified in source) | Wishlist rule |
|---|---|---|
| 1 | **One row, owner columns, no separate guest storage.** A cart row has `id_customer` (0 for guests) and `id_guest`; the browser points to it with one cookie value (`id_cart`). | **Same:** list rows carry the owner (`owner_key`, `id_customer`); the browser holds one value (`swl_guest`, hashed into `owner_key`). Stage 1 already works this way. No change. |
| 2 | **Identity is attached by updating the row, not copying it.** On login, the visitor's cart gets `id_customer` set and is saved (`updateCustomer`, lines 391–419). | **Same:** guest lists are **re-assigned** (`owner_key` and `id_customer` updated in place), so list IDs and item IDs survive login. Copy-and-delete is used only where a merge is unavoidable (row 5). |
| 3 | **Reconciliation happens on every request, early.** In `init()`, if the cookie's customer differs from the cart's, the cart is updated (lines 397–410). This catches every login path, whatever controller or module logged the customer in. | **Same, moved earlier:** the lazy claim moves from `actionFrontControllerSetMedia` to **`actionFrontControllerInitAfter`**. That hook fires at the end of `init()`, right after core resolved the cart, and before `setMedia`, `postProcess` and the notifications are assigned. It still covers login, registration, password reset, cart recovery and social-login modules. The new hook is registered in `upgrade-1.1.0.php` (§5.3). Behaviour is identical to 1.0.1, only earlier in the request. |
| 4 | **Nothing is persisted until it is needed.** A visitor without a cart gets an unsaved `new Cart()`, which gets an ID on first save (lines 438–460). | **Not adopted; keep 1.0.1 behaviour** (the token is created early in the request on the first page view). Creating `swl_guest` only on the first wishlist write would need a CSRF binding that exists before that write. The only candidate in the core cookie, `id_guest`, is **not** set by core for anonymous visitors: in 9.2.0 it's assigned by the optional `statsdata` module (`Cookie::makeNewLog()`), or by `updateCustomer` at login. Binding CSRF to it would break the first click on shops without statsdata. The cost of keeping it is one extra `Set-Cookie` on a visitor's **first** page only; returning visitors get none (measured after the 502 fix). Lists themselves stay lazy as in the cart: no row until the first save. |
| 5 | **Conflicts: the visitor's current basket wins; an old account cart is left behind, never merged** (lines 374–380 restore the account's last cart only when the visitor's is empty, with `PS_CART_FOLLOWING`). | **Deliberate deviation, because lists are long-lived:** an account's existing lists are never dropped or replaced. In one transaction: the guest **default** list merges into the account default (duplicates dropped by the unique key); a guest list whose name matches an account list (case-insensitive, trimmed) merges into it; every other guest list is **re-assigned** (row 2). One notice reports the number of items that are new to the account. |
| 6 | **Guest has nothing → the account's data simply appears.** That is the `PS_CART_FOLLOWING` "re-display cart at login" case. | **Same, always on:** if the guest has no lists, login just shows the account's lists. There is nothing to merge and no notice. |
| 7 | **Logout detaches; the account keeps its data.** `Cookie::mylogout()` unsets `id_cart`, so the next request starts an empty basket while the account's cart stays on the account. | **Same:** after a claim the guest token is forgotten (1.0.1 already does this), so a logout starts an empty guest wishlist and the account's lists stay with the account. Visitors who logged in with no guest lists also get their token dropped, so nothing reappears after logout. |
| 8 | **Guest-checkout customers are customers.** `updateCustomer` sets `id_customer` on the cart for them too (`is_guest` only changes the cookie flag); `Customer::transformToCustomer()` later turns the same `id_customer` into a full account. | **Adopt (changes the 1.0.1 decision):** when a guest-checkout customer is in context, their lists are attached to that `id_customer` (same claim as row 5), not only to the cookie. A later conversion to a full account then keeps the lists with no extra step, and they survive cookie loss like the cart does. This needs `OwnerResolver` to treat `is_guest` customers as customers (`isLogged(true)`). |
| 9 | **The cookie reference is re-validated each request.** A cart that is deleted or already ordered is dropped from the cookie (lines 373–376). | **Same:** a guest token whose lists no longer exist resolves to an empty guest (as today). An owner with lists but no default is repaired on read (§5.1), not trusted. |
| 10 | **Scope:** carts carry `id_shop` / `id_shop_group`. | **Same:** lists stay scoped to `id_shop`. Claims only move lists of the current shop. |

**Not copied from the cart, on purpose**
- **The cart trusts the `id_cart` in the cookie.** Wishlists never take a list ID from the cookie or the request as proof of ownership: every access goes through `WishlistAccessPolicy` (decision 10).
- **No `PS_CART_FOLLOWING`-style setting.** Lists are always kept, so there is nothing to toggle.

**Backward compatibility of these changes** (checked against §5)
- Rows 1–4, 6, 7, 9 and 10 match 1.0.1 behaviour. Row 3 only moves the claim earlier in the request.
- Row 8 widens who counts as a customer. A guest-checkout customer's existing cookie list is claimed on their next request, which is the same mechanism as a login, and nothing is lost.

## 3. Behaviour by screen

### Wishlist section (`/wishlist`, `/wishlist?list=ID`)

- **List navigation:** a row of tabs (desktop) or a native `<select>` (mobile). Each shows the name and item count; the selected list has `aria-current`. With JS off, the tabs are plain links.
- **"New list"** form at the end of the navigation: a name field plus a Create button. It validates server-side, shows the error inline with `is-invalid` + `invalid-feedback`, and the field keeps the typed value (Scenario 10). A successful create opens the new, empty list (Scenario 1).
- **List header:** name, item count, "Default" badge when applicable, **Rename** (inline form in a dialog, same validation), **Set as default** (hidden on the default list) and **Delete** (confirmation dialog stating the item count, and saying when the default will move to another list). Contents stay unchanged on rename (Scenario 6).
- **Items:** the Stage 1 rows, scoped to the selected list. Each row gains **Move to…**: a `<select>` of the owner's other lists plus a Move button (Scenario 9). Remove, Add to cart, the selection toolbar, Add selected, Empty list and the post-cart prompt all act on the selected list only (Scenarios 8, 14).
- **Empty list state:** per list ("Birthday is empty"). With no lists at all, the Stage 1 empty state.

### PDP and product cards

- **1 list:** the Stage 1 toggle.
- **2+ lists:** the heart or PDP button opens the **picker** dialog, with Figma frame 04's sizing (500px wide, 24px padding, 8px radius) but checkboxes:
  - product name + selected combination;
  - **one checkbox per list**, checked when the variant is already in it;
  - "New list" checkbox with a name field;
  - Cancel / Save.
- **Opening, cancelling or Escape saves nothing.** Save posts `op=save_to_lists` with explicit `wishlist_ids[]` (the checked lists) and an optional new list name. The server adds to newly checked lists, removes from unchecked ones, and answers with the per-list state plus "saved in any list", so the heart fills or empties.
- **Save with nothing checked** removes the variant from every list. The dialog says so on the Save button ("Remove from all lists") so it's never a surprise.
- **No JS:** the heart form posts as today. When the owner has 2+ lists, the server redirects to a small **picker page** (`controller=picker&id_product=…&id_product_attribute=…`) that renders the same form, so it still works without JavaScript.
- **Data for the picker:** a JSON endpoint (`controller=lists&op=for_variant`) returns the owner's lists with an `in_list` flag for the variant. It is loaded when the dialog opens, so cards don't embed every list.

## 4. Server changes

**Repository** (`DbWishlistRepository`)
- `findAllByOwner(shopId, ownerKey)` with item counts (one grouped query);
- `create(shopId, ownerKey, customerId, name)`, `rename(id, name)`, `delete(id)`;
- `nameExists(shopId, ownerKey, name, exceptId)`;
- `listIdsContaining(shopId, ownerKey, productId, attrId)` for the picker and heart state;
- `moveItem(fromId, itemId, toId)` (transaction);
- `setDefault(shopId, ownerKey, wishlistId)` (transaction: clear, then set);
- `promoteOldestToDefault(shopId, ownerKey)` (after deleting the default).

**Service** (`WishlistService`), all access-checked
- `getWishlists(owner)` (default first, then by creation), `createWishlist(owner, name)` (returns the list or a validation code; the owner's first list becomes default), `renameWishlist(owner, id, name)`, `setDefaultWishlist(owner, id)`, `deleteWishlist(owner, id)`;
- `countItems(owner)` keeps its Stage 1 meaning, the default list's count, which is now what the header shows;
- `moveItem(owner, fromId, itemId, toId)`;
- `saveToLists(owner, productId, attrId, groups, listIds, newListName)`, i.e. the picker diff in one transaction (optional create + adds + removes);
- `listsForVariant(owner, productId, attrId)`.

Adds reuse the existing idempotent `addItem(owner, wishlistId, …)`. `toggleItem` stays for single-list owners; heart state becomes "saved in any list".

**`WishlistNameValidator`:** trim, collapse inner whitespace, 1–64 characters, `Validate::isGenericName`, unique per owner (case-insensitive). Codes: `name_required`, `name_too_long`, `name_invalid`, `name_taken`, `too_many_lists`.

**Controllers**
- `lists` (new, POST): `create`, `rename`, `set_default`, `delete`, `for_variant` (GET JSON).
- `item`: adds `move`, `save_to_lists`; existing ops gain the explicit `id_wishlist` instead of "default". A `toggle` from an owner with 2+ lists answers `needs_picker` (JS opens the dialog; no-JS redirects to the picker page).
- `cart`: takes `id_wishlist`.
- `wishlist`: reads `?list=` (404 when not owned).
- `picker` (new, GET): no-JS fallback.

All POSTs keep the stateless CSRF token and the server-side owner resolution. Nothing new goes into the cookie, which keeps the 502 fix intact.

**Guest claim:** `claimGuestList` becomes `claimGuestLists` (decision 9). Same lazy trigger, same transaction pattern.

## 5. Backward compatibility (strict; a release gate)

Rule: **a shop running 1.0.1 must be able to replace the files, click Upgrade, and lose nothing: no data, no behaviour, no open page.** Every item below is checked before 1.1.0 ships; any "no" blocks the release.

### 5.1 Database

- **No destructive or renaming changes.** No dropped or renamed columns, tables or keys. Only additive changes are allowed, and Stage 2 needs none.
- **`upgrade-1.1.0.php` is idempotent and repairs, never assumes.** It runs on every owner:
  - if an owner has lists but none with `default_marker = 1`, it marks the oldest one as default. It is safe to run twice and does nothing on healthy data. This guards against data written by any earlier build, including hand edits.
  - `default_marker` values other than `1`/`NULL` are normalised to `NULL`.
- **A fresh install and an upgraded install converge on the same schema.** This is verified by comparing `SHOW CREATE TABLE` for both tables after each path.
- **IDs are stable:** `id_wishlist` and `id_wishlist_product` never change on upgrade, so open pages and URLs with item IDs keep working.
- **Uninstall is not part of any upgrade path.** Uninstall still drops the tables, as documented.

### 5.2 Existing data and behaviour

- **Every Stage 1 list becomes the owner's default and only list.** It keeps its name ("My wishlist"), its items and its ID.
- **For owners with one list, every Stage 1 behaviour is identical:**
  - heart/PDP toggle;
  - header count;
  - wishlist page at `/wishlist`;
  - cart single and bulk;
  - post-cart prompt;
  - empty wishlist;
  - guest claim on login;
  - catalog-access rules.
- **Guest identity is unchanged.** Same cookie key (`swl_guest`), same SHA-256 owner key, so guest lists made before the upgrade are still found after it.
- **The CSRF token formula is unchanged,** so a page rendered by 1.0.1 and submitted after the upgrade still validates.
- **No new cookie keys.** This is the 502 lesson: cookie size is part of compatibility.

### 5.3 Interfaces other code or pages rely on

- **URLs:**
  - `/wishlist` (and the `fc=module` form) shows the default list;
  - `?list=` is optional;
  - `swl_added` keeps working.
- **POST contracts:**
  - every existing `op` (`add`, `toggle`, `remove`, `remove_selected`, `clear`) and parameter (`id_product`, `swl_id_product_attribute`, `group[]`, `id_item`, `single`, `ids[]`, `bulk`, `swl_token`) keeps its meaning;
  - a missing `id_wishlist` means the default list. New parameters are optional.
- **JSON responses:** existing keys (`success`, `messages`, `code`, `count`, `saved`, `id_product`, `id_product_attribute`, `added`, `failed`, `added_item_ids`, `removed`) keep their names and types. `count` keeps its meaning (default list). Only new keys are added.
- **Hooks:** all 1.0.1 hooks stay registered. Any new hook is registered in `upgrade-1.1.0.php` (a hook added only in `install()` would be missing on upgraded shops).
- **Markup:**
  - all existing `sw-*` classes, `data-sw-*` / `data-summitwishlist-action` attributes and element IDs (`#summitwishlist_link`, `#sw-post-cart`, `#sw-clear-dialog`) stay, so theme overrides, module CSS and tests keep matching;
  - new markup is additive;
  - template file paths stay (themes may override `modules/summitwishlist/views/templates/...`).
- **JS API:** `window.SummitWishlist.add(url, data, options)` keeps its signature; `options.wishlistId` becomes functional.
- **Translations:** the domain (`Modules.Summitwishlist.Shop`) and existing message keys are unchanged; new strings are added, not reworded.

### 5.4 Verification (all required before release)

1. **Tag `v1.0.1`** on `main` before Stage 2 work starts, so the upgrade source is reproducible.
2. **Upgrade rehearsal:**
   - install `v1.0.1` and seed it: a guest with items, a customer with items, an item in the cart and a page left open;
   - copy the 1.1.0 files, clear the cache and click Upgrade;
   - verify lists, items, IDs and defaults with SQL;
   - submit the open page's form (CSRF still valid) and log in as the guest (claim still works).
3. **The Stage 1 Playwright suite runs unmodified against 1.1.0,** both on a fresh install and on the upgraded shop. Any test edit counts as a compatibility break unless it only adds assertions.
4. **Schema diff** between fresh-install and upgraded-install tables is empty.
5. **Rollback check:** with 1.1.0 data, put back the 1.0.1 files. The shop must still work, using the default lists (extra lists are ignored, not corrupted). This documents a safe downgrade path.

### 5.5 Demo notes

- Don't restart the container; it restores the DB dump and wipes the module.
- Clear the shop cache after copying files (CCC and compiled Smarty templates).

## 6. Build order (with a demo checkpoint after each step)

**On hold** until the plan revision and the incoming designs are final. The wishlist-page layout (list navigation, list header, picker) will follow those designs rather than §3 where they differ.

0. **Tag `v1.0.1`** and capture the compatibility baseline: Stage 1 suite results, `SHOW CREATE TABLE`, JSON response samples. *(15 min)*
1. **Repository + service + validator** for list CRUD and move; unit-style checks through direct requests. *(45 min)*
2. **Wishlist page:** list navigation, `?list=`, create with validation, rename, delete with confirmation, per-list items/cart/empty. *Checkpoint: Scenarios 1–3, 6–8, 10, 14.* *(60 min)*
3. **Move between lists.** *Checkpoint: Scenario 9.* *(20 min)*
4. **Picker:** `for_variant` endpoint, checkbox dialog, `save_to_lists`, no-JS picker page, heart "saved in any list". *Checkpoint: Scenarios 4, 5.* *(60 min)*
5. **Guest/customer, following the cart model (§2.1):**
   - move the claim to `actionFrontControllerInitAfter` (registered in the upgrade script);
   - multi-list claim (re-assign, merge default and same-name lists);
   - guest-checkout customers attached by `id_customer`;
   - persistence checks.

   *Checkpoint: Scenarios 11, 12, 13.* *(40 min)*
6. **Backward-compatibility gate (§5.4):** upgrade rehearsal, unmodified Stage 1 suite on fresh and upgraded shops, schema diff, rollback check; then README/changelog. *(45 min)*

## 7. Test plan (Playwright additions, `tests/e2e/multi-list.spec.ts`)

| Scenario | Test |
|---|---|
| 1 Create | create "Birthday" → in navigation, selected, empty |
| 2 View all | three lists → three tabs, each opens |
| 3 Isolation | Birthday shows its two products, not Christmas-only ones |
| 4 Add to selected | PDP → picker → tick Birthday → Save → in Birthday, not in My Favorites |
| 5 Same product, two lists | in Birthday → picker shows Birthday ticked → also tick Christmas → in both, one entry each |
| Picker removes | untick Birthday → Save → gone from Birthday, still in Christmas |
| Picker cancel | open → change ticks → Escape / Cancel → nothing saved |
| Picker new list | tick "New list", type "Gifts" → list created with the product |
| 6 Rename | "Birthday Ideas", same two products, old name gone everywhere |
| 7 Delete | confirm → list gone, other lists unchanged, product page still works |
| 8 Remove from one | removed from Birthday, still in Christmas |
| 9 Move | gone from Birthday, present in Christmas |
| 10 Empty name | "", "   " → no list created, validation message, value kept |
| 11 Customer persistence (`@account`) | logout/login → Birthday and items still there |
| 12 Guest multi-list | create two, add to Birthday, reload → both lists, item in Birthday |
| 13 Unauthorised | guest B opens/posts A's list ID → not found, nothing changes |
| Claim: re-assign (`@account`) | guest creates "Birthday" + adds items → logs in to an account without lists → same list ID now on the account, items intact |
| Claim: merge (`@account`) | guest default + "Birthday" → account already has default + "birthday" → merged into the existing two, no duplicates, one notice |
| Claim: logout (`@account`) | after any claim, logout → empty guest wishlist; account lists unchanged |
| Guest checkout (`@account`) | guest-checkout order while holding a list → list attached to that customer; converting to an account keeps it |
| 14 Cart per list | select two in Christmas → in cart, still in Christmas, other lists unchanged |
| Default: first created | first list of a new owner is default; creating more doesn't change it |
| Default: mark | "Set as default" on Birthday → badge moves, header count shows Birthday's count, `/wishlist` opens Birthday, single-list toggles target it |
| Default: delete | delete the default → oldest remaining list becomes default, header count follows |
| Header count | item added only to a non-default list → heart filled, header count unchanged |
| Regression (gate) | the whole Stage 1 suite **unmodified**, on a fresh install and on an upgraded shop |
| Upgrade (gate) | 1.0.1 data → upgrade → default list, items, IDs intact; open 1.0.1 page still submits; guest claim still works |
| Rollback | 1.1.0 data + 1.0.1 files → shop works on default lists |

## 8. Risks

- **The picker is the biggest UI piece.** The no-JS page fallback means it can ship without the dialog if time runs short.
- **Heart behaviour changes with the list count.** With one list it toggles directly; with 2+ lists it opens the picker, where unticking removes. Show both in the demo.
- **Deviation from Figma frame 04** (radios → checkboxes): mention it to whoever reviews the design.
- **Container restart** restores the DB dump and wipes the module (unchanged risk).
