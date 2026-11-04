# Acceptance tests (Playwright)

End-to-end tests for the Task 1 Gherkin scenarios, the User Acceptance Criteria, and the review-2 P1 fixes. They run against a **live shop with the module installed**. Nothing is mocked.

## Run

```bash
npm install
npx playwright install chromium
SWL_BASE_URL=https://your-shop.example/ npx playwright test
```

Open the HTML report with `npm run test:e2e:report`.

Every test starts in a **fresh browser context**: a new guest with an empty wishlist and an empty cart. Customer tests register a throwaway account (`swl-e2e-…@example.test`), so no real credentials are needed. Tests run one at a time (`workers: 1`) because the Scenario 11 test changes catalog state.

### Configuration

| Variable | Needed for | Default |
|---|---|---|
| `SWL_BASE_URL` | everything | none (required) |
| `SWL_COMBINATION_PRODUCT_ID`, `SWL_COMBINATION_GROUP`, `SWL_COMBINATION_OPTION` | product with combinations | `1`, `group[1]`, `M` (demo t-shirt) |
| `SWL_CATEGORY_PATH` | listing page with several orderable products | `/index.php?id_category=3&controller=category` |
| `SWL_ADMIN_URL`, `SWL_ADMIN_EMAIL`, `SWL_ADMIN_PASSWORD` | Scenario 11 (disables, then re-enables, a product) | none; the test is skipped without them |
| `SWL_TOGGLE_PRODUCT_ID` | product Scenario 11 toggles | `6` |
| `SWL_MIN_QTY_PRODUCT_ID`, `SWL_MIN_QTY` | minimal-quantity rule | none / `3` |
| `SWL_CUSTOMIZABLE_PRODUCT_ID` | required-customisation rule | none |
| `SWL_RESTRICTED_PRODUCT_ID` | customer-group rule | none |
| `SWL_OUT_OF_STOCK_PRODUCT_ID` | stock rule and mixed selection | none |

Use local test-shop admin credentials only, passed through the environment. Never commit them; `.env` is git-ignored.

### Preparing the optional fixtures (back office, once per shop)

- **Minimal quantity:** an active product without combinations, *Minimum quantity for sale* = `SWL_MIN_QTY`.
- **Customisation:** an active product with one **required** text customisation field.
- **Group restriction:** an active product whose categories are **not** visible to the *Visitor* and *Guest* groups. The test saves it with a direct request (the page itself isn't reachable for guests), which stands in for "saved before it was restricted".
- **Out of stock:** an active product with quantity 0 and *When out of stock: deny orders*.

Scenario 11 needs no preparation: it disables `SWL_TOGGLE_PRODUCT_ID` through the back office after saving it, then re-enables it in a `finally` block.

**Ngrok:** the config sends `ngrok-skip-browser-warning`, so free tunnels don't show their interstitial page.

## Coverage

| Requirement | Test |
|---|---|
| **Scenario 1**: add from PDP, confirmation | `wishlist.spec` › Scenario 1 (selected combination is saved; also without JavaScript) |
| **Scenario 2**: add from PLP, confirmation | `wishlist.spec` › Scenario 2 (category page and homepage widget) |
| **Scenario 3**: no duplicates | `wishlist.spec` › Scenario 3 (twice from the card; card + PDP for the same variant) |
| **Scenario 4**: guest wishlist survives refresh | `wishlist.spec` › Scenario 4 (refresh and navigation; another guest sees nothing) |
| **Scenario 5**: customer wishlist persists between logins | `wishlist.spec` › Scenario 5 (logout hides it, login restores it; My Account link) |
| **Scenario 6**: name, image, current price, remove, add to cart | `wishlist.spec` › Scenario 6 (opened through the header link; image actually loads) |
| **Scenario 7**: empty message, continue shopping | `wishlist.spec` › Scenario 7 |
| **Scenario 8**: remove one, others unchanged | `wishlist.spec` › Scenario 8 (middle of three removed; order kept) |
| **Scenario 9**: add one to cart, stays in wishlist | `wishlist.spec` › Scenario 9 (cart line has the saved combination) |
| **Scenario 10**: add selected, unselected not added, all stay | `wishlist.spec` › Scenario 10 (1st and 3rd of three; empty selection) |
| **Scenario 11**: unavailable stays visible, can't be added, is marked | `wishlist.spec` › Scenario 11 (UI disabled *and* server refuses; removal still works) |
| UAC: add from PDP and PLP | Scenarios 1, 2 |
| UAC: guests and customers | Scenarios 4, 5 |
| UAC: guest list survives refresh | Scenario 4 |
| UAC: customer list persists between sessions | Scenario 5 |
| UAC: no duplicates | Scenario 3 |
| UAC: dedicated wishlist page | Scenarios 5 (My Account), 6 (header link) |
| UAC: name, image, current price | Scenario 6 |
| UAC: remove individual products | Scenario 8 |
| UAC: empty message | Scenario 7 |
| UAC: add a single product to cart | Scenario 9 |
| UAC: add several selected products in one action | Scenario 10 |
| UAC: adding to cart doesn't remove from wishlist | Scenarios 9, 10 |
| UAC: unavailable products visible but not addable | Scenario 11, `purchase-rules.spec` |
| UAC: wishlist operations don't modify the cart | `wishlist.spec` › Acceptance: wishlist and cart stay independent |
| UAC: demonstrable through the storefront UI | All `wishlist.spec` tests drive the UI; only server-guard checks use direct requests |
| Review 2 P1: CSRF bound to the guest session | `security.spec` › CSRF (cross-guest token, missing/wrong token, rotation on login) |
| Review 2 P1: core purchase checks | `purchase-rules.spec` (minimal quantity, customisation, group restriction, stock, mixed selection) |
| Plan §2.4: ownership | `security.spec` › Ownership (foreign item IDs, GET refused) |
