// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   POS-V2 T1-7 — keyboard shortcuts + "?" help overlay (T1 zone).
   Registers on window.PosModules; the core factory composes it over the state,
   so `this` inside these methods is the live register instance. Search-focus (/,
   F2) and the scanner wedge live in pos-core.js; this module adds the action
   shortcuts and the help sheet.
   ========================================================================== */
window.PosModules = window.PosModules || {};

window.PosModules.shortcuts = {
    helpOpen: false, // the "?" help overlay (rows live in partials/shortcuts.blade.php)
    toggleHelp() { this.helpOpen = !this.helpOpen; },

    // Called from boot(). Binds the action + help shortcuts. Guards: never fire
    // while a field is focused (except Esc/function keys) or the register is locked.
    initShortcuts() {
        window.addEventListener('keydown', (e) => {
            if (this.locked) return;
            const t = e.target;
            const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);

            // Help toggle — '?' (Shift+/) or F1.
            if (e.key === '?' || e.key === 'F1') { e.preventDefault(); this.toggleHelp(); return; }

            // Esc: close the topmost surface (help → sheet → add-sheet → panel).
            if (e.key === 'Escape') {
                if (this.helpOpen) { this.helpOpen = false; return; }
                if (this.sheet && this.sheet.open) { this.closeSheet(); return; }
                if (this.addSheet) { this.closeAdd(); return; }
                if (this.activePanel && this.activePanel !== 'home') { this.activePanel = 'home'; return; }
                return;
            }

            // Action keys — only when not typing in a field and the cart has lines.
            if (typing) return;
            if (e.key === 'F9' && this.cart.length) { e.preventDefault(); this.submit(false); }
            else if (e.key === 'F8' && this.cart.length) { e.preventDefault(); this.openCash(); }
            else if (e.key === 'F4' && this.cart.length) { e.preventDefault(); this.submit(true); }
        });
    },
};
