/**
 * One dashboard instance.
 *
 * The server renders everything (escaped) and the page works without
 * JavaScript. This layer adds in-page updates: filters apply as they
 * change, results are fetched as HTML fragments, the URL stays shareable
 * (history API), details expand inline, and charts and the calendar are
 * attached. All listeners are delegated to the dashboard root or tracked,
 * and removed by destroy().
 */

import { destroyCharts, renderCharts } from './charts.js';
import { copyFrom, revealCopyButtons } from './copy.js';
import { DateRangeControl } from './date-range.js';

const SEARCH_DELAY = 400;
const FRAGMENT_HEADER = 'X-IPX-Log-Viewer';

export class Dashboard {
    constructor(root, config) {
        this.root = root;
        this.config = config;
        this.labels = config.labels || {};
        this.doc = root.ownerDocument;
        this.win = this.doc.defaultView;
        this.form = root.querySelector('[data-ipx-lv-form]');
        this.results = root.querySelector('[data-ipx-lv-results]');
        this.status = root.querySelector('[data-ipx-lv-status]');
        this.charts = [];
        this.listeners = [];
        this.controller = null;
        this.searchTimer = null;
        this.refreshTimer = null;
        this.dateRange = null;
        this.destroyed = false;
        // Incremented on every edit, so a slow response never overwrites newer input.
        this.formVersion = 0;
    }

    init() {
        this.root.classList.add('ipx-log-viewer--js');

        this.on(this.root, 'submit', (event) => this.onSubmit(event));
        this.on(this.root, 'change', (event) => this.onChange(event));
        this.on(this.root, 'input', (event) => this.onInput(event));
        this.on(this.root, 'click', (event) => this.onClick(event));
        this.on(this.win, 'popstate', () => this.load(this.win.location.search, { history: false }));

        const fieldset = this.root.querySelector('[data-ipx-lv-date-range]');

        if (fieldset) {
            this.dateRange = new DateRangeControl(fieldset, this.config.picker || {}, this.labels);
            this.dateRange.init();
        }

        if (typeof this.win.matchMedia === 'function') {
            this.colorScheme = this.win.matchMedia('(prefers-color-scheme: dark)');
            this.on(this.colorScheme, 'change', () => this.redrawCharts());
        }

        this.setupAutoRefresh();
        this.enhance();

        // Remember the server-rendered state so "Back" can return to it.
        const state = this.fragmentState();

        if (state && this.win.history && this.win.history.replaceState) {
            this.win.history.replaceState({ ipxLogViewer: true }, '', this.win.location.href);
        }
    }

    /** Attach behaviour to the current results fragment. */
    enhance() {
        destroyCharts(this.charts);
        this.charts = renderCharts(this.results, this.root, this.labels);

        for (const link of this.results.querySelectorAll('[data-ipx-lv-detail-link]')) {
            link.hidden = true;
        }

        for (const button of this.results.querySelectorAll('[data-ipx-lv-detail]')) {
            button.hidden = false;
        }

        revealCopyButtons(this.results, this.win);
        this.syncChannels();
    }

    redrawCharts() {
        destroyCharts(this.charts);
        this.charts = renderCharts(this.results, this.root, this.labels);
    }

    /* ---------------------------------------------------------------- Events */

    onSubmit(event) {
        if (event.target !== this.form) {
            return;
        }

        event.preventDefault();

        if (this.dateRange && this.dateRange.validate()) {
            return;
        }

        this.apply();
    }

    onChange(event) {
        const target = event.target;

        if (this.form && this.form.contains(target)) {
            this.formVersion += 1;
        }

        if (!this.form || !this.form.contains(target) || target.name === 'q') {
            return;
        }

        this.apply();
    }

    onInput(event) {
        if (this.form && this.form.contains(event.target)) {
            this.formVersion += 1;
        }

        if (!this.form || event.target.name !== 'q' || !this.form.contains(event.target)) {
            return;
        }

        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.apply(), SEARCH_DELAY);
    }

    onClick(event) {
        const target = event.target instanceof Element ? event.target : null;

        if (!target) {
            return;
        }

        const copy = target.closest('[data-ipx-lv-copy]');

        if (copy) {
            copyFrom(copy, this.root, this.labels);

            return;
        }

        const detail = target.closest('[data-ipx-lv-detail]');

        if (detail) {
            this.toggleDetail(detail);

            return;
        }

        const retry = target.closest('[data-ipx-lv-retry]');

        if (retry) {
            this.load(this.win.location.search, { history: false });

            return;
        }

        const link = target.closest('a[data-ipx-lv-nav], a[data-ipx-lv-refresh]');

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const url = new URL(link.href, this.win.location.href);

        if (url.origin !== this.win.location.origin) {
            return;
        }

        event.preventDefault();

        if (link.hasAttribute('data-ipx-lv-refresh')) {
            this.load(this.win.location.search, { history: false });
        } else {
            this.load(url.search, { focus: true });
        }
    }

    /* ---------------------------------------------------------------- Queries */

    /** Run the query described by the form. */
    apply() {
        clearTimeout(this.searchTimer);
        this.load(this.formQuery());
    }

    formQuery() {
        const params = new URLSearchParams();

        if (!this.form) {
            return '';
        }

        const data = new FormData(this.form);
        const preset = data.get('preset');

        for (const [key, value] of data.entries()) {
            const text = typeof value === 'string' ? value.trim() : '';

            if (text === '' || ((key === 'from' || key === 'to') && preset && preset !== 'custom')) {
                continue;
            }

            params.append(key, text);
        }

        const query = params.toString();

        return query ? `?${query}` : '';
    }

    async load(search, { history = true, focus = false, silent = false } = {}) {
        if (this.destroyed) {
            return;
        }

        if (this.controller) {
            this.controller.abort();
        }

        const controller = new AbortController();
        const version = this.formVersion;
        this.controller = controller;
        this.setBusy(true, silent);

        try {
            const response = await this.win.fetch(this.config.endpoints.results + (search || ''), {
                headers: {
                    Accept: 'text/html, */*;q=0.1',
                    'X-Requested-With': 'XMLHttpRequest',
                    [FRAGMENT_HEADER]: 'fragment',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });

            if (response.status === 401 || response.status === 419) {
                throw new LoadError(this.labels.session);
            }

            if (response.status === 403) {
                throw new LoadError(this.labels.forbidden);
            }

            // A redirect to a login page or an error page is not a fragment.
            if (!response.ok || response.headers.get(FRAGMENT_HEADER) !== 'fragment') {
                throw new LoadError(this.labels.error);
            }

            const html = await response.text();

            if (controller.signal.aborted || this.destroyed) {
                return;
            }

            this.render(html);

            const state = this.fragmentState();

            if (state) {
                if (version === this.formVersion) {
                    this.syncForm(state);
                }

                if (history && this.win.history && this.win.history.pushState) {
                    const url = this.config.endpoints.index + (state.query || '');

                    if (url !== this.win.location.pathname + this.win.location.search) {
                        this.win.history.pushState({ ipxLogViewer: true }, '', url);
                    }
                }
            }

            if (!silent) {
                this.announce((this.labels.updated || '').replace(':count', this.total()));
            }

            if (focus) {
                this.results.focus({ preventScroll: true });
                this.results.scrollIntoView({ block: 'start', behavior: 'auto' });
            }
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }

            this.showError(error instanceof LoadError ? error.message : this.labels.error);
        } finally {
            if (this.controller === controller) {
                this.controller = null;
                this.setBusy(false, silent);
            }
        }
    }

    render(html) {
        destroyCharts(this.charts);

        const template = this.doc.createElement('template');
        template.innerHTML = html;
        this.results.replaceChildren(template.content);
        this.enhance();
    }

    fragmentState() {
        const fragment = this.results && this.results.querySelector('[data-ipx-lv-fragment]');

        if (!fragment) {
            return null;
        }

        try {
            return JSON.parse(fragment.getAttribute('data-ipx-lv-state') || 'null');
        } catch (error) {
            return null;
        }
    }

    total() {
        const fragment = this.results.querySelector('[data-ipx-lv-fragment]');

        return fragment ? Number(fragment.getAttribute('data-ipx-lv-total') || 0).toLocaleString() : '0';
    }

    /** Make the form reflect the server's normalized filters. */
    syncForm(state) {
        if (!this.form) {
            return;
        }

        const set = (name, value) => {
            const field = this.form.elements.namedItem(name);

            if (field && 'value' in field && !(field instanceof RadioNodeList)) {
                if (field.type === 'checkbox') {
                    field.checked = Boolean(value);
                } else if (this.doc.activeElement !== field) {
                    field.value = value === null || value === undefined ? '' : String(value);
                }
            }
        };

        set('q', state.q);
        set('case', state.case);
        set('regex', state.regex);
        set('traces', state.traces);
        set('file', state.file);
        set('sort', state.sort);
        set('per_page', state.per_page);
        set('preset', state.preset);
        set('from', state.from);
        set('to', state.to);
        this.syncChannels(state.channel);

        for (const checkbox of this.form.querySelectorAll('input[name="levels[]"]')) {
            checkbox.checked = (state.levels || []).includes(checkbox.value);
        }

        if (this.dateRange) {
            this.dateRange.setError('');
        }
    }

    /** Rebuild the channel options from the channels seen in the results. */
    syncChannels(selected) {
        const select = this.root.querySelector('[data-ipx-lv-channel]');
        const field = this.root.querySelector('[data-ipx-lv-channel-field]');
        const fragment = this.results.querySelector('[data-ipx-lv-fragment]');

        if (!select || !field || !fragment) {
            return;
        }

        let channels = [];

        try {
            channels = JSON.parse(fragment.getAttribute('data-ipx-lv-channels') || '[]');
        } catch (error) {
            channels = [];
        }

        const current = selected === undefined ? select.value : selected;
        const options = [new Option(this.labels.allChannels || '', '')];

        for (const channel of channels) {
            options.push(new Option(channel, channel));
        }

        select.replaceChildren(...options);
        select.value = channels.includes(current) ? current : '';
        field.hidden = channels.length < 2 && !current;
    }

    /* ---------------------------------------------------------------- Details */

    async toggleDetail(button) {
        const row = this.root.querySelector(`#${CSS.escape(button.getAttribute('aria-controls'))}`);

        if (!row) {
            return;
        }

        const expanded = button.getAttribute('aria-expanded') === 'true';
        const label = button.querySelector('.ipx-log-viewer__sr-only');

        button.setAttribute('aria-expanded', String(!expanded));
        row.hidden = expanded;

        if (label) {
            label.textContent = `${expanded ? this.labels.showDetails : this.labels.hideDetails}: `;
        }

        this.updateRefreshPause();

        if (expanded || row.hasAttribute('data-ipx-lv-loaded')) {
            return;
        }

        const cell = row.querySelector('td');
        const loading = this.doc.createElement('p');
        loading.className = 'ipx-log-viewer__detail-loading';
        loading.setAttribute('role', 'status');
        loading.textContent = this.labels.detailLoading || '';
        cell.replaceChildren(loading);

        try {
            const response = await this.win.fetch(button.getAttribute('data-ipx-lv-detail'), {
                headers: { Accept: 'text/html, */*;q=0.1', 'X-Requested-With': 'XMLHttpRequest', [FRAGMENT_HEADER]: 'fragment' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok || response.headers.get(FRAGMENT_HEADER) !== 'fragment') {
                throw new LoadError(response.status === 401 ? this.labels.session : this.labels.detailFailed);
            }

            const template = this.doc.createElement('template');
            template.innerHTML = await response.text();
            cell.replaceChildren(template.content);
            row.setAttribute('data-ipx-lv-loaded', '');
            revealCopyButtons(cell, this.win);
        } catch (error) {
            const failed = this.doc.createElement('p');
            failed.className = 'ipx-log-viewer__detail-failed';
            failed.setAttribute('role', 'alert');
            failed.textContent = error instanceof LoadError ? error.message : this.labels.detailFailed;
            cell.replaceChildren(failed);
        }
    }

    /* ---------------------------------------------------------------- Auto-refresh */

    setupAutoRefresh() {
        const seconds = Number(this.config.autoRefresh || 0);
        const field = this.root.querySelector('[data-ipx-lv-auto-refresh-field]');
        const input = this.root.querySelector('[data-ipx-lv-auto-refresh]');

        if (!seconds || !field || !input) {
            return;
        }

        field.hidden = false;

        this.on(input, 'change', () => {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;

            if (input.checked) {
                this.refreshTimer = setInterval(() => this.autoRefresh(), Math.max(5, seconds) * 1000);
            }

            this.updateRefreshPause();
        });
    }

    autoRefresh() {
        if (this.doc.visibilityState !== 'visible' || this.controller || this.detailsOpen()) {
            return;
        }

        this.load(this.win.location.search, { history: false, silent: true });
    }

    detailsOpen() {
        return Boolean(this.results.querySelector('[data-ipx-lv-detail][aria-expanded="true"]'));
    }

    updateRefreshPause() {
        if (this.refreshTimer && this.detailsOpen()) {
            this.announce(this.labels.paused || '');
        }
    }

    /* ---------------------------------------------------------------- UI state */

    setBusy(busy, silent) {
        if (!this.results) {
            return;
        }

        if (busy) {
            this.results.setAttribute('aria-busy', 'true');

            if (!silent && !this.results.querySelector('.ipx-log-viewer__progress')) {
                const bar = this.doc.createElement('div');
                bar.className = 'ipx-log-viewer__progress';
                bar.setAttribute('aria-hidden', 'true');
                this.results.prepend(bar);
            }
        } else {
            this.results.removeAttribute('aria-busy');

            for (const bar of this.results.querySelectorAll('.ipx-log-viewer__progress')) {
                bar.remove();
            }
        }
    }

    showError(message) {
        let alert = this.results.querySelector('[data-ipx-lv-load-error]');

        if (!alert) {
            alert = this.doc.createElement('div');
            alert.className = 'ipx-log-viewer__alert ipx-log-viewer__alert--danger';
            alert.setAttribute('role', 'alert');
            alert.setAttribute('data-ipx-lv-load-error', '');
            this.results.prepend(alert);
        }

        const text = this.doc.createElement('p');
        text.textContent = message || '';

        const retry = this.doc.createElement('button');
        retry.type = 'button';
        retry.className = 'ipx-log-viewer__button ipx-log-viewer__button--secondary ipx-log-viewer__button--sm';
        retry.setAttribute('data-ipx-lv-retry', '');
        retry.textContent = this.labels.retry || 'Retry';

        alert.replaceChildren(text, retry);
    }

    announce(message) {
        if (this.status) {
            this.status.textContent = '';
            this.status.textContent = message;
        }
    }

    on(target, type, handler) {
        if (!target) {
            return;
        }

        target.addEventListener(type, handler);
        this.listeners.push([target, type, handler]);
    }

    destroy() {
        this.destroyed = true;

        if (this.controller) {
            this.controller.abort();
            this.controller = null;
        }

        clearTimeout(this.searchTimer);
        clearInterval(this.refreshTimer);

        for (const [target, type, handler] of this.listeners) {
            target.removeEventListener(type, handler);
        }

        this.listeners = [];
        destroyCharts(this.charts);

        if (this.dateRange) {
            this.dateRange.destroy();
            this.dateRange = null;
        }

        this.root.classList.remove('ipx-log-viewer--js');
    }
}

class LoadError extends Error {}
