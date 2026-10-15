import { expect, test } from '@playwright/test';
import { nextResults, open } from './helpers.js';

const isoDay = (offset) => {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() - offset);

    return date.toISOString().slice(0, 10);
};

test.describe('Date-range picker', () => {
    test('opens as a labelled dialog and closes with Escape, restoring focus', async ({ page }) => {
        await open(page);

        const toggle = page.getByRole('button', { name: 'Open calendar' });
        await expect(toggle).toBeVisible();
        await expect(page.locator('#ipx-lv-from')).toHaveAttribute('type', 'text');

        await toggle.click();
        const dialog = page.getByRole('dialog', { name: 'Choose a date range' });
        await expect(dialog).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(dialog.getByRole('grid').first()).toBeVisible();

        // The active preset is shown as a range in the calendar.
        await expect(dialog.locator('[data-vc-date-selected="first"]')).toHaveCount(1);
        await expect(dialog.locator('[data-vc-date-selected="last"]')).toHaveCount(1);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(toggle).toBeFocused();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });

    test('selecting two days applies a custom range', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Open calendar' }).click();

        const from = isoDay(3);
        const to = isoDay(1);
        await page.locator(`[data-vc-date="${from}"] [data-vc-date-btn]`).first().click();
        await page.locator(`[data-vc-date="${to}"] [data-vc-date-btn]`).first().click();
        await expect(page.locator('[data-ipx-lv-calendar-summary]')).toHaveText(`Selected: ${from} – ${to}`);

        const response = nextResults(page);
        await page.getByRole('button', { name: 'Apply range' }).click();
        await response;

        await expect(page.getByLabel('From')).toHaveValue(from);
        await expect(page.getByLabel('To', { exact: true })).toHaveValue(to);
        await expect(page.getByLabel('Date range', { exact: true })).toHaveValue('custom');
        await expect(page).toHaveURL(new RegExp(`preset=custom&from=${from}&to=${to}`));
        await expect(page.locator('.ipx-log-viewer__chip')).toContainText([`${from} – ${to}`]);
    });

    test('dates can be chosen with the keyboard', async ({ page }) => {
        await open(page, { path: '/log-viewer?preset=all' });
        await page.getByRole('button', { name: 'Open calendar' }).click();

        const focused = () => page.evaluate(() => document.activeElement.closest('[data-vc-date]')?.getAttribute('data-vc-date'));
        const start = await focused();
        expect(start).toBeTruthy();

        await page.keyboard.press('Enter');
        await expect(page.locator('[data-ipx-lv-calendar-summary]')).toHaveText(`Selected: ${start}`);

        await page.keyboard.press('ArrowLeft');
        const previous = await focused();
        expect(previous < start).toBe(true);

        await page.keyboard.press('Enter');
        await expect(page.locator('[data-ipx-lv-calendar-summary]')).toHaveText(`Selected: ${previous} – ${start}`);
    });

    test('presets in the popover apply immediately', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Open calendar' }).click();

        const response = nextResults(page);
        await page.getByRole('dialog').getByRole('button', { name: 'Yesterday' }).click();
        await response;

        await expect(page.getByLabel('Date range', { exact: true })).toHaveValue('yesterday');
        await expect(page).toHaveURL(/preset=yesterday/);
        await expect(page.getByRole('dialog')).toBeHidden();
    });

    test('manual entry is validated before querying', async ({ page }) => {
        await open(page);
        let requests = 0;
        page.on('request', (request) => {
            if (request.url().includes('/log-viewer/results')) {
                requests += 1;
            }
        });

        const fromInput = page.getByLabel('From');
        await fromInput.fill('2026-02-30');
        await fromInput.press('Tab');

        const error = page.locator('[data-ipx-lv-date-error]');
        await expect(error).toBeVisible();
        await expect(error).toHaveText('Enter a valid date as YYYY-MM-DD.');
        await expect(fromInput).toHaveAttribute('aria-invalid', 'true');
        await expect(fromInput).toHaveAccessibleDescription('Enter a valid date as YYYY-MM-DD.');
        expect(requests).toBe(0);

        const response = nextResults(page);
        await fromInput.fill(isoDay(2));
        await fromInput.press('Tab');
        await response;
        await expect(error).toBeHidden();
        await expect(page.getByLabel('Date range', { exact: true })).toHaveValue('custom');
    });

    test('a reversed range is rejected', async ({ page }) => {
        await open(page);

        await page.getByLabel('To', { exact: true }).fill(isoDay(5));
        await page.getByLabel('From').fill(isoDay(1));
        await page.getByLabel('From').press('Tab');

        await expect(page.locator('[data-ipx-lv-date-error]')).toHaveText('The end date must be on or after the start date.');
    });

    test('native date inputs are used when the picker is disabled', async ({ page }) => {
        await open(page, { scenario: 'no-picker' });

        await expect(page.locator('#ipx-lv-from')).toHaveAttribute('type', 'date');
        await expect(page.getByRole('button', { name: 'Open calendar' })).toBeHidden();
    });

    test('presets in the select clear the custom dates', async ({ page }) => {
        await open(page, { path: `/log-viewer?preset=custom&from=${isoDay(4)}&to=${isoDay(2)}` });
        await expect(page.getByLabel('From')).toHaveValue(isoDay(4));

        const response = nextResults(page);
        await page.getByLabel('Date range', { exact: true }).selectOption('today');
        await response;

        await expect(page.getByLabel('From')).toHaveValue('');
        await expect(page).toHaveURL(/preset=today/);
        await expect(page).not.toHaveURL(/from=/);
    });
});
