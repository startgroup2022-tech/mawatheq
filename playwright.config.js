// Playwright configuration for the Mawatheq browser test suite.
//
// Runs against the local PHP dev server (php -S 127.0.0.1:12000 -t .).
// Six viewports cover mobile / tablet / desktop; each spec exercises both
// Arabic (RTL) and English (LTR).
const { defineConfig, devices } = require('@playwright/test');

const BASE_URL = process.env.MAWATHEQ_BASE_URL || 'http://127.0.0.1:12000';

const viewports = {
  'mobile-320': { width: 320, height: 568 },
  'mobile-375': { width: 375, height: 812 },
  'tablet-768': { width: 768, height: 1024 },
  'tablet-1024': { width: 1024, height: 768 },
  'desktop-1440': { width: 1440, height: 900 },
  'desktop-1920': { width: 1920, height: 1080 },
};

module.exports = defineConfig({
  testDir: './tests/e2e',
  outputDir: './tests/e2e/.artifacts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['list'],
    ['json', { outputFile: 'tests/e2e/.artifacts/results.json' }],
    ['html', { outputFolder: 'tests/e2e/.artifacts/html', open: 'never' }],
  ],
  use: {
    baseURL: BASE_URL,
    screenshot: 'only-on-failure',
    trace: 'off',
    video: 'off',
    ignoreHTTPSErrors: true,
  },
  projects: Object.entries(viewports).map(([name, viewport]) => ({
    name,
    use: { ...devices['Desktop Chrome'], viewport },
  })),
});
