import { defineConfig, devices } from '@playwright/test';

/**
 * Acceptance tests run against a live shop with the module installed.
 * Configuration is environment-only; see tests/e2e/README.md.
 */
const baseURL = process.env.SWL_BASE_URL;
if (!baseURL) {
  throw new Error('Set SWL_BASE_URL to the storefront URL, e.g. https://my-shop.example/');
}

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './tests/e2e/results',
  // Some tests change shared catalog state through the back office, so run one at a time.
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  // Tunnelled dev shops (ngrok) can take several seconds per request.
  timeout: 90_000,
  expect: { timeout: 20_000 },
  reporter: [['list'], ['html', { outputFolder: 'tests/e2e/report', open: 'never' }]],
  use: {
    baseURL,
    // ngrok free tunnels show an interstitial page to browsers without this header.
    extraHTTPHeaders: { 'ngrok-skip-browser-warning': '1' },
    locale: 'en-US',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
