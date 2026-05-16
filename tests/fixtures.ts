import { expect, test as base } from 'playwright/test';
import type { Page, Response, TestInfo } from 'playwright/test';

type DiagnosticEntry = {
  type: string;
  method?: string;
  status?: number;
  url?: string;
  message?: string;
  text?: string;
  location?: string;
};

type Diagnostics = {
  consoleMessages: DiagnosticEntry[];
  consoleErrors: DiagnosticEntry[];
  pageErrors: DiagnosticEntry[];
  failedRequests: DiagnosticEntry[];
  serverResponses: DiagnosticEntry[];
  networkEvents: DiagnosticEntry[];
};

export const test = base.extend<{ diagnostics: Diagnostics }>({
  diagnostics: async ({ page }, use, testInfo) => {
    const diagnostics: Diagnostics = {
      consoleMessages: [],
      consoleErrors: [],
      pageErrors: [],
      failedRequests: [],
      serverResponses: [],
      networkEvents: [],
    };

    page.on('console', (msg) => {
      const location = msg.location();
      const entry = {
        type: 'console',
        message: msg.type(),
        text: msg.text(),
        location: `${location.url || 'unknown'}:${location.lineNumber || 0}:${location.columnNumber || 0}`,
      };
      pushCapped(diagnostics.consoleMessages, entry);

      if (msg.type() === 'error') {
        pushCapped(diagnostics.consoleErrors, entry);
      }
    });

    page.on('pageerror', (error) => {
      pushCapped(diagnostics.pageErrors, {
        type: 'pageerror',
        message: error.message,
      });
    });

    page.on('request', (request) => {
      if (!isUsefulNetworkUrl(request.url())) {
        return;
      }

      pushCapped(diagnostics.networkEvents, {
        type: 'request',
        method: request.method(),
        url: request.url(),
      });
    });

    page.on('requestfailed', (request) => {
      pushCapped(diagnostics.failedRequests, {
        type: 'requestfailed',
        method: request.method(),
        url: request.url(),
        message: request.failure()?.errorText || 'request failed',
      });
    });

    page.on('response', (response) => {
      const status = response.status();
      if (status >= 500) {
        pushCapped(diagnostics.serverResponses, {
          type: 'response',
          status,
          url: response.url(),
        });
      }
      if (isUsefulNetworkUrl(response.url())) {
        pushCapped(diagnostics.networkEvents, {
          type: 'response',
          status,
          url: response.url(),
        });
      }
    });

    await use(diagnostics);
    await attachDiagnostics(testInfo, diagnostics);
  },
});

export { expect };

export type Credentials = {
  email: string;
  password: string;
};

export async function visitReadOnly(page: Page, path: string): Promise<Response | null> {
  const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
  return response;
}

export async function expectPageHealthy(page: Page, response: Response | null): Promise<void> {
  expect(response, 'navigation response').not.toBeNull();
  expect(response?.status(), `HTTP status for ${response?.url()}`).toBeLessThan(500);

  const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');
  expect(bodyText.trim().length, 'page has visible body text').toBeGreaterThan(0);
  expect(bodyText).not.toMatch(/Fatal error|Parse error|SQLSTATE|Undefined function|Uncaught Throwable|Call to undefined function/i);
}

export function expectNoServerErrors(diagnostics: Diagnostics): void {
  expect(diagnostics.serverResponses, '5xx responses during page load').toEqual([]);
  expect(diagnostics.pageErrors, 'uncaught browser page errors').toEqual([]);
}

export async function loginAs(page: Page, credentials: Credentials): Promise<void> {
  await page.goto('/login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(credentials.email);
  await page.locator('input[name="password"]').fill(credentials.password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded').catch(() => undefined),
    page.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);

  const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');
  expect(
    !/\/login\.php/i.test(page.url()) && !/Неверный email|Доступ запрещ|Нет доступа|access denied/i.test(bodyText),
    `Login did not reach an authenticated page. url=${page.url()}`
  ).toBeTruthy();
}

export async function expectRestrictedOrRedirected(page: Page, response: Response | null): Promise<void> {
  const status = response?.status() ?? 0;
  const url = page.url();
  const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');

  expect(
    status === 401
      || status === 403
      || /\/login\.php/i.test(url)
      || /access denied|доступ запрещ|нет доступа|вход/i.test(bodyText),
    `Expected restricted access or login redirect. status=${status} url=${url}`
  ).toBeTruthy();
}

export async function expectJsonResponseHealthy(response: Response): Promise<unknown> {
  expect(response.status(), `HTTP status for ${response.url()}`).toBeLessThan(500);
  const text = await response.text();
  expect(
    /^\s*[\[{]/.test(text),
    `Expected JSON from ${response.url()}, received: ${text.slice(0, 180)}`
  ).toBeTruthy();
  const payload = JSON.parse(text);
  expect(payload, `JSON payload for ${response.url()}`).toBeTruthy();
  return payload;
}

export async function waitForUsefulPolling(page: Page, pattern: RegExp, timeout = 20_000): Promise<Response> {
  return page.waitForResponse((response) => pattern.test(response.url()) && response.status() < 500, { timeout });
}

export async function waitForJsonPolling(
  page: Page,
  pattern: RegExp,
  timeout = 20_000
): Promise<{ response: Response; payload: unknown }> {
  const response = await waitForUsefulPolling(page, pattern, timeout);
  const payload = await expectJsonResponseHealthy(response);
  return { response, payload };
}

async function attachDiagnostics(testInfo: TestInfo, diagnostics: Diagnostics): Promise<void> {
  await testInfo.attach('console-messages.json', {
    contentType: 'application/json',
    body: Buffer.from(JSON.stringify(diagnostics.consoleMessages, null, 2)),
  });

  await testInfo.attach('console-errors.json', {
    contentType: 'application/json',
    body: Buffer.from(JSON.stringify(diagnostics.consoleErrors, null, 2)),
  });

  await testInfo.attach('page-errors.json', {
    contentType: 'application/json',
    body: Buffer.from(JSON.stringify(diagnostics.pageErrors, null, 2)),
  });

  await testInfo.attach('failed-requests.json', {
    contentType: 'application/json',
    body: Buffer.from(JSON.stringify(diagnostics.failedRequests, null, 2)),
  });

  await testInfo.attach('server-responses.json', {
    contentType: 'application/json',
    body: Buffer.from(JSON.stringify(diagnostics.serverResponses, null, 2)),
  });

  await testInfo.attach('network-events.json', {
    contentType: 'application/json',
    body: Buffer.from(JSON.stringify(diagnostics.networkEvents, null, 2)),
  });
}

function isUsefulNetworkUrl(url: string): boolean {
  return /\/ajax\/|\/staff\/.*(?:_api|_update|_resolve|_create_order)\.php|\/restaurant\/(?:copilot_action|copilot_answer)\.php|\/order_status\.php|\/qr\.php|\/checkout\.php/i.test(url);
}

function pushCapped<T>(items: T[], item: T, cap = 250): void {
  if (items.length >= cap) {
    items.shift();
  }
  items.push(item);
}
