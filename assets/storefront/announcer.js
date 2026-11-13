// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — the live announcer: one polite `role="status"` region for cart and queue changes
   ("Added to your order", "Waiting for connection"). Visually hidden through the CSSOM (allowed under the
   store's CSP; no inline style attribute is written). */

const HIDE = 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0';

export function createAnnouncer(doc = globalThis.document) {
    let region = null;
    let last = '';
    let timer = null;

    function node() {
        if (region || !doc) return region;
        region = doc.getElementById('juwri-live');
        if (!region) {
            region = doc.createElement('div');
            region.id = 'juwri-live';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            region.style.cssText = HIDE;
            (doc.body || doc.documentElement).appendChild(region);
        }
        return region;
    }

    /** Announce a message. The region is emptied first so the same text twice is read twice. */
    function announce(message) {
        const el = node();
        if (!el || !message) return;
        if (timer) clearTimeout(timer);
        el.textContent = '';
        last = String(message);
        timer = setTimeout(() => { el.textContent = last; timer = null; }, 60);
    }

    return announce;
}
