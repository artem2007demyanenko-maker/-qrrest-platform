import dotenv from 'dotenv';
dotenv.config({ path: '.env.playwright' });

import { defineConfig, devices } from '@playwright/test';

const baseURL =
  process.env.PLAYWRIGHT_BASE_URL ||
  process.env.BASE_URL ||
  'http://localhost';

const headed =
  process.env.PLAYWRIGHT_HEADED === '1' ||
  process.env.HEADED === '1';

export default defineConfig({
  testDir: './tests',

  fullyParallel: false,

  forbidOnly: !!process.env.CI,

  retries: process.env.CI ? 2 : 1,

  workers: process.env.CI ? 1 : undefined,

  timeout: 60_000,

  expect: {
    timeout: 10_000,
  },

  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],

  outputDir: 'test-results',

  use: {
    baseURL,

    browserName: 'chromium',

    headless: !headed,

    actionTimeout: 15_000,

    navigationTimeout: 30_000,

    screenshot: 'only-on-failure',

    video: 'retain-on-failure',

    trace: 'retain-on-failure',

    ignoreHTTPSErrors: false,

    launchOptions: {
      slowMo: headed ? 75 : 0,
    },
  },

  projects: [
    {
      name: 'chromium',

      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],
});
