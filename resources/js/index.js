/**
 * InertiaPixel Log Viewer entry point.
 *
 * The bundle is a strict-mode IIFE. The only global it creates is
 * window.InertiaPixelLogViewer. Loading the script twice is harmless: the
 * second copy reuses the first one's API, and every dashboard is
 * initialized at most once.
 */

import { Dashboard } from './dashboard.js';
import { CopyButtons } from './copy.js';

const VERSION = '1.0.0';
const GLOBAL = 'InertiaPixelLogViewer';
const SELECTOR = '[data-ipx-log-viewer]';

function readConfig(root) {
    const raw = root.getAttribute('data-ipx-log-viewer-config');

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        console.error('InertiaPixel Log Viewer: invalid configuration.', error);

        return null;
    }
}

function createApi(win) {
    const doc = win.document;
    const instances = new Map();
    // Roots destroyed through the API stay destroyed until init() is called for them.
    const stopped = new WeakSet();
    let observer = null;

    const create = (root) => {
        if (instances.has(root)) {
            return instances.get(root);
        }

        const config = readConfig(root);
        // Pages without a configuration (the standalone entry page) only need copy buttons.
        const instance = config ? new Dashboard(root, config) : new CopyButtons(root, {});

        instances.set(root, instance);
        instance.init();

        return instance;
    };

    const roots = (target) => {
        if (!target) {
            return Array.from(doc.querySelectorAll(SELECTOR));
        }

        if (typeof target === 'string') {
            return Array.from(doc.querySelectorAll(target)).filter((element) => element.matches(SELECTOR));
        }

        return target.matches && target.matches(SELECTOR) ? [target] : [];
    };

    const api = {
        version: VERSION,

        /** Initialize every dashboard (or the given root/selector). Idempotent. */
        init(target) {
            const found = roots(target);

            for (const root of found) {
                stopped.delete(root);
            }

            return found.map(create);
        },

        /** Remove listeners, timers, charts and the calendar. */
        destroy(target) {
            for (const root of target ? roots(target) : Array.from(instances.keys())) {
                const instance = instances.get(root);

                if (instance) {
                    instances.delete(root);
                    stopped.add(root);
                    instance.destroy();
                }
            }
        },

        get(target) {
            const [root] = roots(target);

            return root ? instances.get(root) || null : null;
        },

        instances() {
            return Array.from(instances.values());
        },
    };

    // Dashboards added or removed later (Livewire, Turbo, partial page loads).
    const observe = () => {
        if (observer || typeof win.MutationObserver !== 'function' || !doc.body) {
            return;
        }

        observer = new win.MutationObserver(() => {
            for (const [root, instance] of Array.from(instances.entries())) {
                if (!root.isConnected) {
                    instances.delete(root);
                    instance.destroy();
                }
            }

            for (const root of roots()) {
                if (!instances.has(root) && !stopped.has(root)) {
                    create(root);
                }
            }
        });

        observer.observe(doc.body, { childList: true, subtree: true });
    };

    const start = () => {
        roots().forEach(create);
        observe();
    };

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }

    return api;
}

if (typeof window !== 'undefined' && !window[GLOBAL]) {
    Object.defineProperty(window, GLOBAL, {
        value: createApi(window),
        writable: false,
        configurable: false,
        enumerable: true,
    });
}
