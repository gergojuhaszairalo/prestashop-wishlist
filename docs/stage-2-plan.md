# Stage 2: multiple named wishlists (implementation plan)

Source: "Task 2" tab (Stage 2, 14 scenarios, 19 acceptance criteria). It builds on module 1.0.1 as it is on `main`: redesign, toggle hearts, guest-list claim, empty list, post-cart prompt, catalog-access fix. Target release: **1.1.0**, as an in-place upgrade.

## 1. What Stage 1 already gives us

| Stage 2 need | Already in 1.0.1 |
|---|---|
| Separate wishlist and entries | `summitwishlist` (lists) + `summitwishlist_product` (entries) |
| Many lists per owner | `owner_key` + nullable `default_marker`, so only one default per owner is enforced and any number of other lists is allowed |
| Same product in several lists, once per list | `UNIQUE (id_wishlist, id_product, id_product_attribute)` |
| Each list has a name | `name` column, filled with "My wishlist" |
| Guests and customers, isolated | `OwnerResolver` (hashed guest token / customer), `WishlistAccessPolicy` on every operation |
| Every operation targets a list | Service methods already take an explicit `wishlistId` |
| Picker-ready triggers | `SummitWishlist.add(url, data, {wishlistId})`, `data-summitwishlist-action` |

**Consequence:** no table changes. The existing list becomes the default list and keeps its items and IDs. "Existing saved products should not be lost" holds by construction. `upgrade-1.1.0.php` only bumps the version (and registers nothing new unless a hook is added during the build).

## 2. Decisions

Decision 1 was made on 4 Nov 2026. The rest are recommendations I'll follow unless told otherwise.

1. **Destination picker: radios, as in Figma frame 04 (decided).** With 2+ lists, the heart or PDP button opens a dialog with **one radio per list** and Save. Save **adds** the variant to the chosen list (Scenarios 4 and 5). Lists that already hold the variant show a "Saved" badge. Choosing one of them answers "already in Birthday" and changes nothing (idempotent add). With 2+ lists the heart never removes; removal happens per list on the wishlist page (Scenario 8). With exactly one list the heart keeps the Stage 1 toggle (decision 3).
2. **Duplicate list names: allowed?** *Recommend rejecting* a name that matches another list of the same owner, ignoring case and surrounding whitespace ("Birthday" vs "birthday "). Task 2 only forbids empty names, but two lists called "Birthday" make the picker ambiguous.
3. **With exactly one list, nothing changes from Stage 1.** The heart toggles directly, with no picker, so a single-list shopper never sees extra steps.
4. **Default list.** It stays the target for single-list toggles, guest claim merges and new visitors. It can be renamed and deleted. Deleting it promotes the most recently updated remaining list. Deleting the last list leaves the empty state; the next save lazily creates "My wishlist" again.
5. **Header count and heart state:** the count is total saved entries across all lists. A heart is filled when the variant is saved in **at least one** list (the design proposal's rule).
6. **Moving:** a dedicated `move` operation in one transaction: insert into the target (`INSERT IGNORE`), then delete from the source. If the target already has the variant, the source entry is still removed and the message says "already in Christmas". This keeps entry IDs stable and is safe against double submits.
7. **Delete confirmation** reuses the native `<dialog>` from "Empty wishlist". Delete removes the list's entries and the list row. Catalog products are never touched (Scenario 7).
8. **Limits:** names 1–64 characters after trimming (`Validate::isGenericName`, whitespace collapsed), and at most 20 lists per owner. This guards guest abuse; both limits are constants.
9. **Guest claim on login (extends 1.0.1):**
   - the guest's default list merges into the customer's default (as today);
   - other guest lists are re-assigned to the customer;
   - if the customer already has a list with the same name, that list absorbs the guest list's items (duplicates dropped);
   - everything runs in one transaction, with one notice: "N items from your visit were added".
10. **Unauthorised access (Scenario 13):** list IDs come from the URL/POST, so every read and write resolves the list through `WishlistAccessPolicy`. A foreign or unknown ID renders as **not found** (404 page / `not_found` code), never 403, so IDs can't be probed for existence.

## 3. Behaviour by screen

### Wishlist section (`/wishlist`, `/wishlist?list=ID`)

- **List navigation:** a row of tabs (desktop) or a native `<select>` (mobile). Each shows the name and item count; the selected list has `aria-current`. With JS off, the tabs are plain links.
- **"New list"** form at the end of the navigation: a name field plus a Create button. It validates server-side, shows the error inline with `is-invalid` + `invalid-feedback`, and the field keeps the typed value (Scenario 10). A successful create opens the new, empty list (Scenario 1).
- **List header:** name, item count, **Rename** (inline form in a dialog, same validation) and **Delete** (confirmation dialog stating the item count). Contents stay unchanged on rename (Scenario 6).
- **Items:** the Stage 1 rows, scoped to the selected list. Each row gains **Move to…**: a `<select>` of the owner's other lists plus a Move button (Scenario 9). Remove, Add to cart, the selection toolbar, Add selected, Empty list and the post-cart prompt all act on the selected list only (Scenarios 8, 14).
- **Empty list state:** per list ("Birthday is empty"). With no lists at all, the Stage 1 empty state.

### PDP and product cards

- **1 list:** the Stage 1 toggle.
- **2+ lists:** the heart or PDP button opens the **picker** dialog, per Figma frame 04:
  - 500px wide, 24px padding, 8px radius;
  - product name + selected combination;
  - **one radio per list**, with lists already holding the variant marked "Saved" and the first non-saved list preselected;
  - a "New list" radio with a name field;
  - Cancel / Save.
- **Opening, cancelling or Escape saves nothing.** Save posts `op=add` with an explicit `id_wishlist`, or `op=add_to_new` with a name. The server answers `added` / `already_present` and the new "saved in any list" state, so the heart fills.
- **No JS:** the heart form posts as today. When the owner has 2+ lists, the server redirects to a small **picker page** (`controller=picker&id_product=…&id_product_attribute=…`) that renders the same form, so it still works without JavaScript.
- **Data for the picker:** a JSON endpoint (`controller=lists&op=for_variant`) returns the owner's lists with an `in_list` flag for the variant. It is loaded when the dialog opens, so cards don't embed every list.

## 4. Server changes

**Repository** (`DbWishlistRepository`)
- `findAllByOwner(shopId, ownerKey)` with item counts (one grouped query);
- `create(shopId, ownerKey, customerId, name)`, `rename(id, name)`, `delete(id)`;
- `nameExists(shopId, ownerKey, name, exceptId)`;
- `listIdsContaining(shopId, ownerKey, productId, attrId)` for the picker and heart state;
- `moveItem(fromId, itemId, toId)` (transaction);
- `promoteDefault(shopId, ownerKey)`.

**Service** (`WishlistService`), all access-checked
- `getWishlists(owner)`, `createWishlist(owner, name)` (returns the list or a validation code), `renameWishlist(owner, id, name)`, `deleteWishlist(owner, id)`;
- `moveItem(owner, fromId, itemId, toId)`;
- `addToNewList(owner, name, productId, attrId, groups)`, i.e. create + add in one step from the picker;
- `listsForVariant(owner, productId, attrId)`.

The picker's Save reuses the existing idempotent `addItem(owner, wishlistId, …)`. `toggleItem` stays for single-list owners; heart state becomes "saved in any list".

**`WishlistNameValidator`:** trim, collapse inner whitespace, 1–64 characters, `Validate::isGenericName`, unique per owner (case-insensitive). Codes: `name_required`, `name_too_long`, `name_invalid`, `name_taken`, `too_many_lists`.

**Controllers**
- `lists` (new, POST): `create`, `rename`, `delete`, `for_variant` (GET JSON).
- `item`: adds `move`, `add_to_new`; existing ops gain the explicit `id_wishlist` instead of "default". A `toggle` from an owner with 2+ lists answers `needs_picker` (JS opens the dialog; no-JS redirects to the picker page).
- `cart`: takes `id_wishlist`.
- `wishlist`: reads `?list=` (404 when not owned).
- `picker` (new, GET): no-JS fallback.

All POSTs keep the stateless CSRF token and the server-side owner resolution. Nothing new goes into the cookie, which keeps the 502 fix intact.

**Guest claim:** `claimGuestList` becomes `claimGuestLists` (decision 9). Same lazy trigger, same transaction pattern.

## 5. Upgrade and compatibility

- `upgrade/upgrade-1.1.0.php`: version bump, no schema change. Existing lists and items keep their IDs, and the default list is the one shoppers already have.
- **Stage 1 behaviour is unchanged for single-list owners.** All Stage 1 Playwright tests must still pass unmodified, except where they assert markup that moves.
- **Before the demo:** don't restart the container (it restores the DB dump). Clear the shop cache after copying the new version (CCC and compiled Smarty templates).

## 6. Build order (with a demo checkpoint after each step)

1. **Repository + service + validator** for list CRUD and move; unit-style checks through direct requests. *(45 min)*
2. **Wishlist page:** list navigation, `?list=`, create with validation, rename, delete with confirmation, per-list items/cart/empty. *Checkpoint: Scenarios 1–3, 6–8, 10, 14.* *(60 min)*
3. **Move between lists.** *Checkpoint: Scenario 9.* *(20 min)*
4. **Picker:** `for_variant` endpoint, radio dialog, `add` / `add_to_new`, no-JS picker page, heart "saved in any list". *Checkpoint: Scenarios 4, 5.* *(60 min)*
5. **Guest/customer:** multi-list claim, persistence checks. *Checkpoint: Scenarios 11, 12, 13.* *(30 min)*
6. **Upgrade rehearsal on a shop with 1.0.1 data,** README/changelog, Playwright. *(30 min)*

## 7. Test plan (Playwright additions, `tests/e2e/multi-list.spec.ts`)

| Scenario | Test |
|---|---|
| 1 Create | create "Birthday" → in navigation, selected, empty |
| 2 View all | three lists → three tabs, each opens |
| 3 Isolation | Birthday shows its two products, not Christmas-only ones |
| 4 Add to selected | PDP → picker → choose Birthday radio → Save → in Birthday, not in My Favorites |
| 5 Same product, two lists | in Birthday → picker shows Birthday "Saved" → choose Christmas → in both; saving to Birthday again keeps one entry |
| Picker cancel | open → Escape / Cancel → nothing saved |
| Picker new list | choose "New list", type "Gifts" → list created with the product |
| 6 Rename | "Birthday Ideas", same two products, old name gone everywhere |
| 7 Delete | confirm → list gone, other lists unchanged, product page still works |
| 8 Remove from one | removed from Birthday, still in Christmas |
| 9 Move | gone from Birthday, present in Christmas |
| 10 Empty name | "", "   " → no list created, validation message, value kept |
| 11 Customer persistence (`@account`) | logout/login → Birthday and items still there |
| 12 Guest multi-list | create two, add to Birthday, reload → both lists, item in Birthday |
| 13 Unauthorised | guest B opens/posts A's list ID → not found, nothing changes |
| 14 Cart per list | select two in Christmas → in cart, still in Christmas, other lists unchanged |
| Regression | the whole Stage 1 suite, including single-list toggle |
| Upgrade | 1.0.1 data → upgrade → default list and items intact |

## 8. Risks

- **The picker is the biggest UI piece.** The no-JS page fallback means it can ship without the dialog if time runs short.
- **Heart behaviour changes with the list count.** With one list it toggles; with 2+ lists it opens the picker and never removes. This is intentional (radio picker) but worth showing explicitly in the demo, since an organiser may click a filled heart and expect removal.
- **Container restart** restores the DB dump and wipes the module (unchanged risk).
