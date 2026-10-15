import { expect } from '@playwright/test';

/** Open the viewer with a server-side configuration scenario. */
export async function open(page, { scenario = 'default', path = '/log-viewer' } = {}) {
    await page.context().addCookies([{ name: 'ipx_scenario', value: scenario, url: page.context()._options.baseURL || 'http://127.0.0.1:8899' }]);

    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });

    await page.goto(path);
    await expect(page.locator('.ipx-log-viewer')).toHaveClass(/ipx-log-viewer--js/);
    // In-body stylesheets (embedded layouts) may finish after deferred scripts in WebKit.
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.ipx-log-viewer')).getPropertyValue('--ipx-lv-color-primary') !== '');

    return errors;
}

/** Resolves with the next results-fragment request's URL. */
export function nextResults(page) {
    return page.waitForResponse((response) => response.url().includes('/log-viewer/results') && response.status() === 200);
}

export const rows = (page) => page.locator('.ipx-log-viewer__table--entries tbody tr[data-ipx-lv-row]');

export async function computed(page, selector, properties) {
    return page.locator(selector).first().evaluate((element, names) => {
        // WebKit can report stale computed styles after a late in-body
        // stylesheet (rendering is correct); force a style recalculation.
        const display = element.style.display;
        element.style.display = 'none';
        void element.offsetHeight;
        element.style.display = display;

        const style = getComputedStyle(element);

        return Object.fromEntries(names.map((name) => [name, style.getPropertyValue(name)]));
    }, properties);
}
