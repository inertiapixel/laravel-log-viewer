/**
 * Date-range control: preset select, manual YYYY-MM-DD entry and a calendar
 * popover built with vanilla-calendar-pro (MIT, bundled).
 *
 * Without JavaScript, or if the calendar cannot start, the server-rendered
 * native date inputs keep working. Dates are calendar days in the display
 * timezone shown next to the control; the server converts them.
 */

import { Calendar, months } from 'vanilla-calendar-pro';

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Class names for the calendar's elements, prefixed to avoid collisions. */
const STYLES = Object.fromEntries([
    'calendar', 'controls', 'grid', 'column', 'header', 'headerContent', 'month', 'year', 'wrapper', 'content',
    'months', 'monthsRow', 'monthsCell', 'monthsMonth', 'years', 'yearsRow', 'yearsCell', 'yearsYear',
    'week', 'weekDay', 'weekDayBtn', 'weekNumbers', 'weekNumbersTitle', 'weekNumbersContent', 'weekNumber',
    'collapse', 'dates', 'datesRow', 'date', 'dateBtn', 'datePopup', 'dateRangeTooltip',
    'time', 'timeContent', 'timeHour', 'timeMinute', 'timeKeeping', 'timeRanges', 'timeRange',
].map((key) => [key, `ipx-log-viewer__vc-${key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`]));

STYLES.arrowPrev = 'ipx-log-viewer__vc-arrow ipx-log-viewer__vc-arrow--prev';
STYLES.arrowNext = 'ipx-log-viewer__vc-arrow ipx-log-viewer__vc-arrow--next';

/** Whether a string is a real calendar date in YYYY-MM-DD form. */
export function isValidDate(value) {
    const match = DATE.exec(value);

    if (!match) {
        return false;
    }

    const [year, month, day] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return year >= 1970 && year <= 2100 && date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

export class DateRangeControl {
    constructor(fieldset, config, labels) {
        this.fieldset = fieldset;
        this.config = config;
        this.labels = labels;
        this.calendar = null;
        this.pending = [];
        this.listeners = [];
        this.doc = fieldset.ownerDocument;
        this.win = this.doc.defaultView;
        this.find = (selector) => fieldset.querySelector(selector);
        this.preset = this.find('[data-ipx-lv-preset]');
        this.from = this.find('[data-ipx-lv-from]');
        this.to = this.find('[data-ipx-lv-to]');
        this.toggle = this.find('[data-ipx-lv-calendar-toggle]');
        this.popover = this.find('[data-ipx-lv-calendar-popover]');
        this.mount = this.find('[data-ipx-lv-calendar]');
        this.presetList = this.find('[data-ipx-lv-preset-list]');
        this.summary = this.find('[data-ipx-lv-calendar-summary]');
        this.error = this.find('[data-ipx-lv-date-error]');
        this.customHint = this.find('[data-ipx-lv-custom-hint]');
    }

    /** Returns false when the calendar is unavailable (native inputs remain). */
    init() {
        if (!this.preset || !this.from || !this.to) {
            return false;
        }

        this.on(this.preset, 'change', () => this.onPresetChange());
        this.on(this.from, 'change', (event) => this.onDateChange(event));
        this.on(this.to, 'change', (event) => this.onDateChange(event));

        if (this.customHint) {
            // With JavaScript, typing a date selects "Custom range" automatically.
            this.customHint.hidden = true;
        }

        if (!this.config.enabled || !this.toggle || !this.popover || !this.mount) {
            return false;
        }

        try {
            this.createCalendar();
        } catch (error) {
            console.error('InertiaPixel Log Viewer: the date picker could not start; native date inputs are used instead.', error);
            this.calendar = null;

            return false;
        }

        // Consistent manual entry across browsers once the calendar is available.
        for (const input of [this.from, this.to]) {
            input.type = 'text';
            input.inputMode = 'numeric';
            input.placeholder = this.labels.dateFormat || 'YYYY-MM-DD';
            input.autocomplete = 'off';
            input.maxLength = 10;
        }

        this.buildPresets();
        this.toggle.hidden = false;

        this.on(this.toggle, 'click', () => (this.popover.hidden ? this.open() : this.close(true)));
        this.on(this.find('[data-ipx-lv-calendar-apply]'), 'click', () => this.apply());
        this.on(this.find('[data-ipx-lv-calendar-cancel]'), 'click', () => this.close(true));
        this.on(this.find('[data-ipx-lv-calendar-clear]'), 'click', () => this.clear());
        this.on(this.popover, 'keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
            }
        });
        this.on(this.doc, 'pointerdown', (event) => {
            if (!this.popover.hidden && !this.popover.contains(event.target) && !this.toggle.contains(event.target)) {
                this.close(false);
            }
        });
        this.on(this.fieldset, 'focusout', (event) => {
            const next = event.relatedTarget;

            if (!this.popover.hidden && next && !this.popover.contains(next) && !this.toggle.contains(next)) {
                this.close(false);
            }
        });

        return true;
    }

    createCalendar() {
        const today = this.config.today;
        this.monthCount = this.win.innerWidth < 720 ? 1 : Math.min(2, Math.max(1, this.config.months || 2));
        const multiple = this.monthCount > 1;

        this.calendar = new Calendar(this.mount, {
            ...(multiple ? { type: 'multiple', displayMonthsCount: this.monthCount, extensions: [months] } : {}),
            monthsToSwitch: 1,
            selectionDatesMode: 'multiple-ranged',
            // 'only-arrows': month navigation stays available (false would hide
            // the arrows and confine navigation to the current year).
            selectionMonthsMode: 'only-arrows',
            selectionYearsMode: 'only-arrows',
            displayDatesOutside: false,
            enableEdgeDatesOnly: true,
            firstWeekday: this.config.firstWeekday,
            locale: this.config.locale || 'en',
            dateMax: today,
            dateToday: today,
            selectedTheme: 'light',
            themeAttrDetect: false,
            styles: STYLES,
            labels: {
                application: this.labels.calendar || 'Calendar',
                navigation: this.labels.calendarNavigation || 'Calendar navigation',
                arrowPrev: { month: this.labels.previousMonth || 'Previous month' },
                arrowNext: { month: this.labels.nextMonth || 'Next month' },
            },
            onClickDate: (self) => {
                // Clicking the same day twice clears it; keep only real dates.
                this.pending = [...self.context.selectedDates].filter(isValidDate).sort();
                this.updateSummary();
            },
        });

        this.calendar.init();
    }

    buildPresets() {
        if (!this.presetList) {
            return;
        }

        this.presetList.replaceChildren();

        for (const preset of this.config.presets || []) {
            if (preset.key === 'custom') {
                continue;
            }

            const item = this.doc.createElement('li');
            const button = this.doc.createElement('button');
            button.type = 'button';
            button.className = 'ipx-log-viewer__preset';
            button.textContent = preset.label;
            button.setAttribute('data-ipx-lv-preset-key', preset.key);
            button.setAttribute('aria-pressed', String(this.preset.value === preset.key));
            item.append(button);
            this.presetList.append(item);
        }

        this.on(this.presetList, 'click', (event) => {
            const button = event.target.closest('[data-ipx-lv-preset-key]');

            if (button) {
                this.selectPreset(button.getAttribute('data-ipx-lv-preset-key'));
            }
        });
    }

    open() {
        this.pending = [this.from.value, this.to.value].filter(isValidDate).sort();

        // A named preset is shown as its resolved range.
        if (this.pending.length === 0) {
            const preset = (this.config.presets || []).find((item) => item.key === this.preset.value);

            if (preset && preset.from && preset.to) {
                this.pending = [preset.from, preset.to];
            }
        }

        // Show the end of the selected range (or today) in the last month.
        const anchor = this.pending[this.pending.length - 1] || this.config.today;
        const [year, month] = anchor.split('-').map(Number);
        const shown = this.monthCount > 1 ? new Date(Date.UTC(year, month - 2, 1)) : new Date(Date.UTC(year, month - 1, 1));

        this.calendar.set({
            selectedDates: this.pending.length === 2 && this.pending[0] !== this.pending[1] ? [this.pending[0], this.pending[1]] : this.pending.slice(0, 1),
            selectedMonth: shown.getUTCMonth(),
            selectedYear: shown.getUTCFullYear(),
        });

        for (const button of this.presetList ? this.presetList.querySelectorAll('[data-ipx-lv-preset-key]') : []) {
            button.setAttribute('aria-pressed', String(this.preset.value === button.getAttribute('data-ipx-lv-preset-key')));
        }

        this.updateSummary();
        this.popover.hidden = false;
        this.toggle.setAttribute('aria-expanded', 'true');
        this.position();

        // Focus the end of the selection, else today; presets are one Shift+Tab away.
        const focusTarget = this.mount.querySelector('[data-vc-date-selected="last"] [data-vc-date-btn], [data-vc-date-selected="first-and-last"] [data-vc-date-btn]')
            || this.mount.querySelector('[data-vc-date-today] [data-vc-date-btn]')
            || [...this.mount.querySelectorAll('[data-vc-date-btn][tabindex="0"]')].pop()
            || this.popover.querySelector('[aria-pressed="true"]');

        if (focusTarget) {
            focusTarget.focus();
        }
    }

    /** Open towards the side with room, so the popover stays on screen. */
    position() {
        this.popover.classList.remove('ipx-log-viewer__popover--end');

        if (this.popover.getBoundingClientRect().right > this.doc.documentElement.clientWidth - 8) {
            this.popover.classList.add('ipx-log-viewer__popover--end');
        }
    }

    close(restoreFocus) {
        if (this.popover.hidden) {
            return;
        }

        this.popover.hidden = true;
        this.toggle.setAttribute('aria-expanded', 'false');

        if (restoreFocus) {
            this.toggle.focus();
        }
    }

    apply() {
        if (this.pending.length === 0) {
            this.close(true);

            return;
        }

        this.from.value = this.pending[0];
        this.to.value = this.pending[this.pending.length - 1];
        this.preset.value = 'custom';
        this.setError('');
        this.close(true);
        this.emitChange(this.to);
    }

    clear() {
        this.pending = [];
        this.calendar.set({ selectedDates: [] });
        this.from.value = '';
        this.to.value = '';
        this.preset.value = 'all';
        this.setError('');
        this.close(true);
        this.emitChange(this.preset);
    }

    selectPreset(key) {
        this.preset.value = key;
        this.from.value = '';
        this.to.value = '';
        this.setError('');
        this.close(true);
        this.emitChange(this.preset);
    }

    onPresetChange() {
        // Named presets ignore the date inputs; clear them to avoid confusion.
        if (this.preset.value !== 'custom') {
            this.from.value = '';
            this.to.value = '';
            this.setError('');
        }
    }

    onDateChange(event) {
        const message = this.validate();

        if (message) {
            // Do not run a query with an invalid date.
            event.stopPropagation();

            return;
        }

        if (this.from.value || this.to.value) {
            this.preset.value = 'custom';
        }
    }

    /** Validate manual entry. Returns an error message, or '' when valid. */
    validate() {
        const from = this.from.value.trim();
        const to = this.to.value.trim();
        let message = '';
        let invalid = null;

        if (from && !isValidDate(from)) {
            [message, invalid] = [this.labels.invalidDate, this.from];
        } else if (to && !isValidDate(to)) {
            [message, invalid] = [this.labels.invalidDate, this.to];
        } else if (from && to && from > to) {
            [message, invalid] = [this.labels.rangeOrder, this.to];
        }

        this.setError(message, invalid);

        return message;
    }

    setError(message, invalid = null) {
        for (const input of [this.from, this.to]) {
            if (input === invalid) {
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', 'ipx-lv-error-dates');
            } else {
                input.removeAttribute('aria-invalid');
                input.removeAttribute('aria-describedby');
            }
        }

        if (this.error) {
            this.error.textContent = message;
            this.error.hidden = message === '';
            this.error.setAttribute('role', message ? 'alert' : 'status');
        }
    }

    updateSummary() {
        if (!this.summary) {
            return;
        }

        if (this.pending.length === 0) {
            this.summary.textContent = this.labels.calendarHint || '';

            return;
        }

        const range = this.pending.length === 1 || this.pending[0] === this.pending[this.pending.length - 1]
            ? this.pending[0]
            : `${this.pending[0]} – ${this.pending[this.pending.length - 1]}`;

        this.summary.textContent = (this.labels.selectedRange || ':range').replace(':range', range);
    }

    emitChange(element) {
        element.dispatchEvent(new this.win.Event('change', { bubbles: true }));
    }

    on(target, type, handler) {
        if (target) {
            target.addEventListener(type, handler);
            this.listeners.push([target, type, handler]);
        }
    }

    destroy() {
        for (const [target, type, handler] of this.listeners) {
            target.removeEventListener(type, handler);
        }

        this.listeners = [];

        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
            this.mount.replaceChildren();

            for (const input of [this.from, this.to]) {
                input.type = 'date';
                input.removeAttribute('placeholder');
                input.removeAttribute('inputmode');
                input.removeAttribute('maxlength');
            }
        }

        if (this.toggle) {
            this.toggle.hidden = true;
            this.toggle.setAttribute('aria-expanded', 'false');
        }

        if (this.popover) {
            this.popover.hidden = true;
        }

        if (this.presetList) {
            this.presetList.replaceChildren();
        }

        if (this.customHint) {
            this.customHint.hidden = false;
        }
    }
}
