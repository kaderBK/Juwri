// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/* PARITY-RUN T8 — first-run screen: which store does this till belong to?
   The answer becomes both the page the shell loads AND the CORS allowlist the
   bridge enforces, so it is asked once, up front, rather than inferred later. */

(async function () {
    const state = await window.shell.state();
    document.body.setAttribute('dir', state.dir || 'ltr');
    document.title = state.strings['app.name'];

    for (const node of document.querySelectorAll('[data-i18n]')) {
        node.textContent = state.strings[node.dataset.i18n] || node.textContent;
    }

    const input = document.getElementById('serverUrl');
    const error = document.getElementById('error');
    input.placeholder = state.strings['setup.placeholder'];
    input.value = state.serverUrl || '';
    document.getElementById('bridgeUrl').textContent = state.bridgeUrl;

    async function submit() {
        error.hidden = true;
        try {
            await window.shell.setServerUrl(input.value);
        } catch (e) {
            error.textContent = state.strings['setup.invalidUrl'];
            error.hidden = false;
        }
    }

    document.getElementById('continue').addEventListener('click', submit);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') submit();
    });
    input.focus();
}());
