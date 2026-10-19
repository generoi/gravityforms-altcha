// The `altcha` package's entry registers the <altcha-widget> custom element
// as a side-effect. That's all we need — the PHP side renders the markup,
// the widget auto-solves the proof on load, and Gravity Forms posts the
// resulting hidden input back to the server like any other field.
import 'altcha';

/**
 * Keeps the proof fresh for as long as the form stays open.
 *
 * The widget solves its proof once, on load, and the challenge the server
 * issued is only valid for a limited window (see Challenge::DEFAULT_EXPIRES_
 * SECONDS). When that lapses the widget clears the solution and moves to the
 * `expired` state — it does not re-solve on its own. On a long form (a product
 * dropdown with hundreds of options, date pickers, a file upload) a visitor can
 * easily still be typing by then, and submitting would fail validation with a
 * form-level error that highlights no field, losing everything they entered.
 *
 * Re-solving on expiry closes that hole. `display="invisible"` means the
 * visitor never sees any of it.
 */

const BOUND_FLAG = 'gfAltchaRenew';

/**
 * How many times a failed solve is retried with a fresh challenge before the
 * widget is left in its error state. The widget gives up on a proof after its
 * 90s timeout ("Failed to find solution.") — on a slow machine or a throttled
 * background tab that's just bad luck with one challenge, and the next one
 * usually lands. The cap stops a persistent failure (challenge endpoint down)
 * from looping forever.
 */
const MAX_RETRIES = 3;

const retries = new WeakMap();

/**
 * Fetches a brand new challenge and solves it. `verify()` re-reads the
 * `challenge` attribute (a URL), so this issues a fresh challenge rather than
 * re-solving the expired one.
 *
 * @param {HTMLElement} widget
 */
function renew(widget) {
    // A backgrounded tab can't be submitting anything, so there's no point
    // burning CPU on proof-of-work there. Wait until it's back on screen.
    if (document.visibilityState === 'hidden') {
        document.addEventListener(
            'visibilitychange',
            () => renew(widget),
            { once: true }
        );

        return;
    }

    // Already grinding through a proof — a second call would race it.
    if (widget.getState?.() === 'verifying') {
        return;
    }

    widget.verify?.();
}

/**
 * @param {HTMLElement} widget
 */
function bind(widget) {
    if (widget.dataset[BOUND_FLAG]) {
        return;
    }
    widget.dataset[BOUND_FLAG] = '1';

    // The widget dispatches `expired` on itself without `bubbles`, so the
    // listener has to sit on each element rather than on the document.
    widget.addEventListener('expired', () => renew(widget));

    widget.addEventListener('statechange', (event) => {
        const state = event.detail?.state;
        if (state === 'verified') {
            retries.delete(widget);
        } else if (state === 'error' && canRetry(widget)) {
            retries.set(widget, (retries.get(widget) ?? 0) + 1);
            renew(widget);
        }
    });
}

/**
 * @param {HTMLElement} widget
 */
function canRetry(widget) {
    return (retries.get(widget) ?? 0) < MAX_RETRIES;
}

/**
 * Resolves once the widget holds a solved proof, or has failed for good.
 *
 * `auto="onload"` only *starts* the proof on load; nothing stops the visitor
 * submitting before it's done, and the form then posts an empty `altcha` field
 * that the server rejects as `missing`. On a rejected AJAX submit Gravity Forms
 * re-renders the form, the replacement widget starts from zero, and a quick
 * retry fails the same way — so the visitor never gets through.
 *
 * @param {HTMLElement} widget
 * @returns {Promise<void>}
 */
function whenSolved(widget) {
    return new Promise((resolve) => {
        const check = () => {
            const state = widget.getState?.();
            if (
                state === undefined ||
                state === 'verified' ||
                (state === 'error' && !canRetry(widget)) ||
                !widget.isConnected
            ) {
                widget.removeEventListener('statechange', check);
                resolve();
            } else if (state === 'expired' || state === 'unverified') {
                renew(widget);
            }
        };
        widget.addEventListener('statechange', check);
        check();
    });
}

/**
 * Holds a Gravity Forms submission until the proof is ready. GF awaits async
 * `gform/submission/pre_submission` filters before posting, and keeps its
 * spinner up meanwhile, so the visitor just sees a slower submit instead of a
 * rejection. If the proof fails for good the submission goes ahead and the
 * server shows its "reload and try again" message.
 */
function holdSubmissions() {
    const addAsyncFilter = window.gform?.utils?.addAsyncFilter;
    if (!addAsyncFilter) {
        return false;
    }

    addAsyncFilter('gform/submission/pre_submission', async (data) => {
        const widget = data.form?.querySelector('altcha-widget');
        if (widget) {
            await whenSolved(widget);
        }

        return data;
    });

    return true;
}

// GF's own scripts normally load first, but don't depend on the order.
if (!holdSubmissions()) {
    document.addEventListener('DOMContentLoaded', holdSubmissions, {
        once: true,
    });
}

/**
 * @param {ParentNode} root
 */
function bindAll(root) {
    root.querySelectorAll?.('altcha-widget').forEach(bind);
}

bindAll(document);

// Gravity Forms re-renders the form markup after an AJAX submission or a page
// change in a multi-page form, which replaces the widget with a fresh element.
// Catch those so the replacement is covered by the same renewal.
new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (node.nodeType !== Node.ELEMENT_NODE) {
                continue;
            }
            if (node.tagName === 'ALTCHA-WIDGET') {
                bind(node);
            } else {
                bindAll(node);
            }
        }
    }
}).observe(document.documentElement, { childList: true, subtree: true });
