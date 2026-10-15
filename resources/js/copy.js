/**
 * Copy-to-clipboard buttons ([data-ipx-lv-copy="element-id"]). Buttons are
 * rendered hidden and only revealed when the Clipboard API is available.
 */

export function canCopy(win) {
    return Boolean(win.isSecureContext && win.navigator.clipboard && typeof win.navigator.clipboard.writeText === 'function');
}

/** Reveal copy buttons inside a container. */
export function revealCopyButtons(container, win) {
    if (!canCopy(win)) {
        return;
    }

    for (const button of container.querySelectorAll('[data-ipx-lv-copy]')) {
        button.hidden = false;
    }
}

/** Handle a click on a copy button. */
export async function copyFrom(button, root, labels) {
    const target = root.querySelector(`#${CSS.escape(button.getAttribute('data-ipx-lv-copy'))}`);
    const text = button.querySelector('[data-ipx-lv-copy-text]');

    if (!target || !text) {
        return;
    }

    const original = text.getAttribute('data-original') || text.textContent;
    text.setAttribute('data-original', original);

    try {
        await root.ownerDocument.defaultView.navigator.clipboard.writeText(target.textContent);
        text.textContent = labels.copied || 'Copied';
    } catch (error) {
        text.textContent = labels.copyFailed || 'Copy failed';
    }

    clearTimeout(button._ipxCopyTimer);
    button._ipxCopyTimer = setTimeout(() => {
        text.textContent = original;
    }, 2000);
}

/** Minimal instance for pages that only show entry details. */
export class CopyButtons {
    constructor(root, labels) {
        this.root = root;
        this.labels = labels;
        this.onClick = (event) => {
            const button = event.target.closest('[data-ipx-lv-copy]');

            if (button && this.root.contains(button)) {
                copyFrom(button, this.root, this.labels);
            }
        };
    }

    init() {
        revealCopyButtons(this.root, this.root.ownerDocument.defaultView);
        this.root.addEventListener('click', this.onClick);
    }

    destroy() {
        this.root.removeEventListener('click', this.onClick);
    }
}
