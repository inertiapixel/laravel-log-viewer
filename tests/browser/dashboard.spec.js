import { expect, test } from '@playwright/test';
import { nextResults, open, rows } from './helpers.js';

test.describe('Dashboard', () => {
    test('renders entries, summary and charts without errors', async ({ page }) => {
        const errors = await open(page);

        await expect(rows(page)).toHaveCount(50);
        await expect(page.locator('[data-ipx-lv-stat="total"]')).toHaveText('73');
        await expect(page.locator('.ipx-log-viewer__chart canvas')).toHaveCount(2);

        const size = await page.locator('.ipx-log-viewer__chart--trend canvas').boundingBox();
        expect(size.width).toBeGreaterThan(200);
        expect(size.height).toBeGreaterThan(150);

        // Charts drew, so their data tables are collapsed (but present).
        await expect(page.locator('[data-ipx-lv-chart-table]').first()).not.toHaveAttribute('open', '');
        await expect(page.locator('.ipx-log-viewer__chart canvas').first()).toHaveAttribute('role', 'img');
        await expect(page.locator('.ipx-log-viewer__chart canvas').first()).toHaveAttribute('aria-label', /Entries over time: 73 entries/);
        expect(errors).toEqual([]);
    });

    test('entries are ordered by time, newest first', async ({ page }) => {
        await open(page);

        const times = await page.locator('.ipx-log-viewer__td--time time').evaluateAll((elements) => elements.map((element) => element.getAttribute('datetime')));
        const sorted = [...times].sort().reverse();
        expect(times).toEqual(sorted);
    });

    test('log content is rendered as text, never as HTML', async ({ page }) => {
        await open(page);

        await expect(page.getByText('<script>window.__ipxXss = true</script>', { exact: false })).toBeVisible();
        await page.locator('tr', { hasText: '__ipxXss' }).locator('[data-ipx-lv-detail]').click();
        await expect(page.locator('[data-ipx-lv-detail-content]')).toBeVisible();

        expect(await page.evaluate(() => window.__ipxXss)).toBeUndefined();
        await expect(page.locator('.ipx-log-viewer img')).toHaveCount(0);
        await expect(page.locator('.ipx-log-viewer script')).toHaveCount(0);
    });

    test('level filter updates results and the URL, and history restores it', async ({ page }) => {
        await open(page);

        const response = nextResults(page);
        await page.getByRole('checkbox', { name: 'Error', exact: true }).check();
        await response;

        await expect(page).toHaveURL(/levels%5B0%5D=error/);
        await expect(page.locator('[data-ipx-lv-stat="total"]')).toHaveText('11');
        await expect(page.locator('.ipx-log-viewer__chip')).toContainText(['Error']);
        await expect(page.locator('[data-ipx-lv-status]')).toHaveText('Results updated: 11 entries.');

        await page.goBack();
        await expect(page.locator('[data-ipx-lv-stat="total"]')).toHaveText('73');
        await expect(page.getByRole('checkbox', { name: 'Error', exact: true })).not.toBeChecked();
    });

    test('search runs after typing stops', async ({ page }) => {
        await open(page);

        const response = nextResults(page);
        await page.getByRole('searchbox', { name: 'Search' }).fill('slow query');
        await response;

        await expect(page.locator('[data-ipx-lv-stat="total"]')).toHaveText('10');
        await expect(page).toHaveURL(/q=slow%20query/);
        await expect(rows(page).first()).toContainText('Slow query detected');
    });

    test('searching stack traces is opt-in', async ({ page }) => {
        await open(page, { path: '/log-viewer?q=OrderController' });
        await expect(page.locator('[data-ipx-lv-stat="total"]')).toHaveText('0');

        const response = nextResults(page);
        await page.getByLabel('Include stack traces').check();
        await response;
        await expect(page.locator('[data-ipx-lv-stat="total"]')).toHaveText('10');
    });

    test('pagination and per-page size update in place', async ({ page }) => {
        await open(page);

        let response = nextResults(page);
        await page.getByRole('navigation', { name: 'Pagination' }).getByRole('link', { name: 'Next' }).click();
        await response;
        await expect(page).toHaveURL(/[?&]page=2/);
        await expect(rows(page)).toHaveCount(23);
        await expect(page.getByText('Showing 51–73 of 73')).toBeVisible();

        response = nextResults(page);
        await page.getByLabel('Per page').selectOption('25');
        await response;
        await expect(rows(page)).toHaveCount(25);
        await expect(page).not.toHaveURL(/[?&]page=2/);
    });

    test('details expand inline, are lazy-loaded and collapse again', async ({ page, browserName }) => {
        if (browserName === 'chromium') {
            await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        }

        await open(page, { path: '/log-viewer?q=SQLSTATE' });

        const toggle = page.locator('[data-ipx-lv-detail]').first();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(page.locator('[data-ipx-lv-detail-link]').first()).toBeHidden();

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        const detail = page.locator('[data-ipx-lv-detail-row]:not([hidden])');
        await expect(detail.getByRole('heading', { name: 'Stack trace' })).toBeVisible();
        await expect(detail.locator('.ipx-log-viewer__frame--vendor').first()).toContainText('vendor/laravel');
        await expect(detail).toContainText('Illuminate\\Database\\QueryException');

        if (browserName === 'chromium') {
            const copy = detail.getByRole('button', { name: 'Copy Message' });
            await expect(copy).toBeVisible();
            await copy.click();
            await expect(copy).toContainText('Copied');
            expect(await page.evaluate(() => navigator.clipboard.readText())).toContain('SQLSTATE[42S02]');
        }

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(page.locator('[data-ipx-lv-detail-row]:not([hidden])')).toHaveCount(0);
    });

    test('secrets are masked in the details', async ({ page }) => {
        await open(page, { path: '/log-viewer?q=Password+reset' });

        await page.locator('[data-ipx-lv-detail]').first().click();
        const detail = page.locator('[data-ipx-lv-detail-content]');
        await expect(detail).toContainText('[REDACTED]');
        await expect(detail).not.toContainText('hunter2');
        await expect(detail).not.toContainText('abc123def456');
    });

    test('active filter chips and reset', async ({ page }) => {
        await open(page, { path: '/log-viewer?levels[]=warning&q=Slow' });
        await expect(page.locator('.ipx-log-viewer__chip')).toHaveCount(3);

        let response = nextResults(page);
        await page.getByRole('link', { name: /Remove filter: Search: Slow/ }).click();
        await response;
        await expect(page.locator('.ipx-log-viewer__chip')).toHaveCount(2);
        await expect(page.getByRole('searchbox', { name: 'Search' })).toHaveValue('');

        response = nextResults(page);
        await page.getByRole('link', { name: 'Reset' }).click();
        await response;
        await expect(page.locator('.ipx-log-viewer__chip')).toHaveCount(0);
        await expect(page.getByLabel('Date range', { exact: true })).toHaveValue('all');
    });

    test('file filter and download', async ({ page }) => {
        await open(page);

        const response = nextResults(page);
        const value = await page.getByLabel('File').locator('option', { hasText: 'scheduler.log' }).getAttribute('value');
        await page.getByLabel('File').selectOption(value);
        await response;
        await expect(rows(page)).toHaveCount(1);

        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download file' }).click();
        expect((await download).suggestedFilename()).toBe('scheduler.log');
    });

    test('server errors and expired sessions are reported with a retry', async ({ page }) => {
        await open(page);

        await page.route('**/log-viewer/results**', (route) => route.fulfill({ status: 500, body: 'boom' }));
        await page.getByLabel('Sort').selectOption('oldest');
        await expect(page.getByRole('alert')).toContainText('The results could not be loaded.');

        await page.unroute('**/log-viewer/results**');
        await page.route('**/log-viewer/results**', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Unauthenticated."}' }));
        await page.getByRole('button', { name: 'Try again' }).click();
        await expect(page.getByRole('alert')).toContainText('Your session may have expired');

        await page.unroute('**/log-viewer/results**');
        const response = nextResults(page);
        await page.getByRole('button', { name: 'Try again' }).click();
        await response;
        await expect(page.locator('[data-ipx-lv-load-error]')).toHaveCount(0);
    });

    test('works without JavaScript', async ({ browser }) => {
        const context = await browser.newContext({ javaScriptEnabled: false });
        const page = await context.newPage();

        await page.goto('/log-viewer');
        await expect(page.locator('.ipx-log-viewer__table--entries tbody tr[data-ipx-lv-row]')).toHaveCount(50);
        await expect(page.locator('[data-ipx-lv-chart-table]').first()).toHaveAttribute('open', '');
        await expect(page.locator('.ipx-log-viewer__chart').first()).toBeHidden();
        await expect(page.locator('#ipx-lv-from')).toHaveAttribute('type', 'date');

        await page.getByRole('checkbox', { name: 'Warning', exact: true }).check();
        await page.getByRole('button', { name: 'Apply filters' }).click();
        await expect(page).toHaveURL(/levels%5B%5D=warning/);
        await expect(page.locator('.ipx-log-viewer__table--entries tbody tr[data-ipx-lv-row]')).toHaveCount(10);

        await page.locator('[data-ipx-lv-detail-link]').first().click();
        await expect(page.getByRole('heading', { name: 'Entry details' })).toBeVisible();
        await context.close();
    });

    test('auto-refresh is opt-in and refreshes periodically', async ({ page }) => {
        await page.clock.install();
        await open(page, { scenario: 'auto-refresh' });

        const toggle = page.getByLabel(/Auto-refresh every 5 s/);
        await expect(toggle).not.toBeChecked();

        await toggle.check({ force: true });
        const response = nextResults(page);
        await page.clock.fastForward(6000);
        await response;
    });
});

test.describe('Responsive layout', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('no horizontal page overflow on a phone', async ({ page }) => {
        await open(page);

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);
        await expect(page.locator('.ipx-log-viewer__table--entries thead')).toHaveCSS('position', 'absolute');
    });
});
