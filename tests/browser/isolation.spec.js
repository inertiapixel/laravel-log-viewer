import { expect, test } from '@playwright/test';
import { computed, nextResults, open } from './helpers.js';

const HOST_ELEMENTS = ['#host-heading', '#host-paragraph', '#host-button', '#host-badge', '#host-cell', '#host-input'];
const PROPERTIES = ['font-family', 'font-size', 'font-weight', 'color', 'background-color', 'margin-top', 'margin-bottom', 'padding-top', 'padding-left', 'border-top-width', 'border-radius', 'line-height', 'display', 'box-sizing', 'text-transform'];

test.describe('JavaScript isolation', () => {
    test('defines exactly one global', async ({ page }) => {
        await page.addInitScript(() => {
            window.__before = Object.getOwnPropertyNames(window);
        });
        await open(page);

        const added = await page.evaluate(() => Object.getOwnPropertyNames(window).filter((name) => !window.__before.includes(name) && name !== '__before'));
        expect(added).toEqual(['InertiaPixelLogViewer']);
        expect(await page.evaluate(() => window.InertiaPixelLogViewer.version)).toBe('1.0.0');
    });

    test('loading the script twice and re-initializing does not duplicate listeners', async ({ page }) => {
        await open(page);

        await page.addScriptTag({ url: await page.locator('script[data-ipx-log-viewer-asset]').getAttribute('src') });
        await page.evaluate(() => {
            window.InertiaPixelLogViewer.init();
            window.InertiaPixelLogViewer.init('[data-ipx-log-viewer]');
        });
        expect(await page.evaluate(() => window.InertiaPixelLogViewer.instances().length)).toBe(1);

        let requests = 0;
        page.on('request', (request) => {
            if (request.url().includes('/log-viewer/results')) {
                requests += 1;
            }
        });

        const response = nextResults(page);
        await page.getByLabel('Sort').selectOption('oldest');
        await response;
        await page.waitForTimeout(300);
        expect(requests).toBe(1);
    });

    test('destroy removes behaviour and init restores it once', async ({ page }) => {
        await open(page);

        await page.evaluate(() => window.InertiaPixelLogViewer.destroy());
        await expect(page.locator('.ipx-log-viewer')).not.toHaveClass(/ipx-log-viewer--js/);
        await expect(page.getByRole('button', { name: 'Open calendar' })).toBeHidden();
        await expect(page.locator('#ipx-lv-from')).toHaveAttribute('type', 'date');
        expect(await page.evaluate(() => window.InertiaPixelLogViewer.instances().length)).toBe(0);

        await page.evaluate(() => window.InertiaPixelLogViewer.init());
        await expect(page.locator('.ipx-log-viewer')).toHaveClass(/ipx-log-viewer--js/);

        let requests = 0;
        page.on('request', (request) => {
            if (request.url().includes('/log-viewer/results')) {
                requests += 1;
            }
        });

        const response = nextResults(page);
        await page.getByLabel('Sort').selectOption('oldest');
        await response;
        await page.waitForTimeout(300);
        expect(requests).toBe(1);
    });

    test('dashboards removed from the page are destroyed automatically', async ({ page }) => {
        await open(page);

        await page.evaluate(() => document.querySelector('[data-ipx-log-viewer]').remove());
        await expect.poll(() => page.evaluate(() => window.InertiaPixelLogViewer.instances().length)).toBe(0);
    });
});

test.describe('CSS isolation', () => {
    for (const framework of ['plain', 'tailwind', 'bootstrap']) {
        test(`the stylesheet does not change host elements (${framework})`, async ({ page }) => {
            await page.goto(`/host/${framework}`);
            const before = {};

            for (const selector of HOST_ELEMENTS) {
                before[selector] = await computed(page, selector, PROPERTIES);
            }

            const href = await page.evaluate(async () => {
                const response = await fetch('/log-viewer/assets/log-viewer.css');

                return response.ok ? '/log-viewer/assets/log-viewer.css' : null;
            });
            await page.addStyleTag({ url: href });
            await page.addScriptTag({ url: '/log-viewer/assets/log-viewer.js' });

            for (const selector of HOST_ELEMENTS) {
                expect(await computed(page, selector, PROPERTIES), selector).toEqual(before[selector]);
            }

            expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ipx-lv-color-primary'))).toBe('');
        });
    }

    for (const scenario of ['embedded-tailwind', 'embedded-bootstrap']) {
        test(`the viewer looks the same inside a host layout (${scenario})`, async ({ page, browser }) => {
            const reference = await browser.newPage();
            await open(reference);
            await open(page, { scenario });

            await expect(page.locator('#host-main .ipx-log-viewer')).toBeVisible();

            const checks = {
                '.ipx-log-viewer__title': ['font-size', 'font-weight', 'margin-bottom', 'color'],
                '.ipx-log-viewer__form-actions .ipx-log-viewer__button--primary': ['background-color', 'color', 'padding-left', 'border-radius', 'font-size', 'height'],
                '.ipx-log-viewer__badge--error': ['background-color', 'color', 'font-size', 'text-transform', 'padding-left'],
                '.ipx-log-viewer__levels > .ipx-log-viewer__label': ['float', 'font-size', 'width'],
                '.ipx-log-viewer__td--message': ['padding-top', 'background-color', 'border-bottom-width', 'font-size'],
                '.ipx-log-viewer__input--search': ['height', 'border-top-width', 'border-radius', 'background-color', 'font-size'],
                '.ipx-log-viewer__pages': ['display', 'padding-left', 'margin-bottom'],
                '.ipx-log-viewer__card': ['padding-top', 'border-top-width', 'border-top-color', 'background-color'],
                '.ipx-log-viewer__tag--code': ['color', 'font-size'],
                '.ipx-log-viewer': ['font-family', 'font-size', 'line-height', 'color'],
            };

            for (const [selector, properties] of Object.entries(checks)) {
                expect(await computed(page, selector, properties), selector).toEqual(await computed(reference, selector, properties));
            }

            // Host elements next to the viewer keep their own styles.
            expect((await computed(page, '#host-button', ['background-color']))['background-color']).toBe('rgb(255, 165, 0)');
            await reference.close();
        });
    }
});

test.describe('Accessibility', () => {
    test('form controls are labelled and results announce updates', async ({ page }) => {
        await open(page);

        for (const label of ['Search', 'File', 'Sort', 'Per page', 'From']) {
            await expect(page.getByLabel(label, { exact: true }).first()).toBeVisible();
        }

        await expect(page.getByRole('search', { name: 'Filters' })).toBeVisible();
        await expect(page.getByRole('group', { name: 'Levels' })).toBeVisible();
        await expect(page.getByRole('table', { name: 'Log entries matching the current filters' })).toBeVisible();
        await expect(page.locator('[data-ipx-lv-status]')).toHaveAttribute('role', 'status');

        const response = nextResults(page);
        const busy = page.waitForFunction(() => document.querySelector('[data-ipx-lv-results]').getAttribute('aria-busy') === 'true');
        await page.getByLabel('Sort').selectOption('oldest');
        await busy;
        await response;
        await expect(page.locator('[data-ipx-lv-results]')).not.toHaveAttribute('aria-busy', 'true');
    });

    test('keyboard users can reach the skip link and expand details', async ({ page, browserName }) => {
        await open(page);

        // WebKit (like Safari) only moves focus to links with Alt+Tab.
        await page.keyboard.press(browserName === 'webkit' ? 'Alt+Tab' : 'Tab');
        await expect(page.getByRole('link', { name: 'Skip to results' })).toBeFocused();

        const toggle = page.locator('[data-ipx-lv-detail]').first();
        await toggle.focus();
        await page.keyboard.press('Enter');
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });
});
