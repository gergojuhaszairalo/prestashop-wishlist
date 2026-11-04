# Catalog access regression

Run this integration test against a **disposable PrestaShop test shop** with the module installed, customer groups enabled and distinct visitor/customer groups. It uses actual PrestaShop catalog permissions, module services, product presentation and message formatting. It requires no extra test packages.

```sh
PRESTASHOP_ROOT=/path/to/prestashop php tests/integration/catalog-access.php
```

When the module is installed in the local PrestaShop 9.2 review container:

```sh
docker exec -w /var/www/html/modules/summitwishlist \
  -e PRESTASHOP_ROOT=/var/www/html \
  ps92-wishlist-design-prestashop-1 \
  php tests/integration/catalog-access.php
```

`PRESTASHOP_TEST_SHOP_ID` defaults to `1`. The runner is CLI-only and exits before loading PrestaShop if requested over HTTP.

The test creates temporary products, a category, a customer and wishlists. It removes these fixtures in `finally` and never edits existing catalog objects or persistent shop configuration. The disposable-shop requirement still applies because normal PrestaShop object hooks run while fixtures are created and removed. JSON output records every assertion and cleanup result; exit status is nonzero if an assertion fails or an exception occurs.

Coverage includes guest rejection of guessed private product IDs, an authorized customer saving and viewing the product, subsequent permission revocation, retained redacted rows, public row mapping, refusal of cart additions without name disclosure, safe removal feedback and the existing fallback behavior for deleted products. The permission cache is cleared after the fixture permission change to model a fresh storefront request.
