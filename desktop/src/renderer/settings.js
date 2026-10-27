// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/* PARITY-RUN T8 — settings window.
   Three jobs, in the order an operator meets them: pair with the store, add the
   printers, then the shell's own behaviour. Every string comes from the catalog
   the main process hands over, so the window is EN/AR from the first paint. */

(function () {
    let strings = {};

    const $ = (id) => document.getElementById(id);
    const t = (key) => strings[key] || key;

    function toast(message) {
        const node = $('toast');
        node.textContent = message;
        node.classList.add('show');
        setTimeout(() => node.classList.remove('show'), 2200);
    }

    function applyStrings() {
        for (const node of document.querySelectorAll('[data-i18n]')) {
            node.textContent = t(node.dataset.i18n);
        }
        $('pLabel').placeholder = t('printers.labelPlaceholder');
    }

    function selectTab(name) {
        for (const tab of document.querySelectorAll('[role="tab"]')) {
            tab.setAttribute('aria-selected', String(tab.dataset.panel === name));
        }
        for (const panel of document.querySelectorAll('[data-tabpanel]')) {
            panel.hidden = panel.dataset.tabpanel !== name;
        }
    }

    function renderBridge(state) {
        const bridge = state.bridge || {};
        const dot = $('bridgeDot');
        const banner = $('bridgeBanner');

        dot.className = 'dot ' + (bridge.listening ? 'ok' : 'bad');
        $('bridgeState').textContent = bridge.listening ? t('bridge.listening') : t('bridge.' + (bridge.error || 'stopped'));
        $('bridgeUrl').textContent = state.bridgeUrl;

        const stats = bridge.stats || {};
        $('statPrinted').textContent = stats.printed || 0;
        $('statReplayed').textContent = stats.replayed || 0;
        $('statFailed').textContent = stats.failed || 0;

        if (!bridge.listening) {
            banner.hidden = false;
            banner.className = 'banner bad';
            banner.textContent = bridge.error === 'port_in_use' ? t('bridge.port_in_use_help') : t('bridge.listen_failed');
        } else {
            banner.hidden = true;
        }
    }

    function renderPairing(state) {
        $('pairDot').className = 'dot ' + (state.paired ? 'ok' : 'warn');
        $('pairState').textContent = state.paired ? t('pairing.paired') : t('pairing.unpaired');
        $('tokenStorage').textContent = state.tokenEncrypted ? t('pairing.encrypted') : t('pairing.plaintext');
        $('token').value = '';
        $('token').placeholder = state.paired ? '••••••••••••••••••••••••••••••••••••••••' : '';
        $('origins').value = (state.extraOrigins || []).join('\n');
        $('activeOrigins').textContent = (state.origins || []).join('  ·  ');
    }

    function renderPrinters(state) {
        const body = $('printerRows');
        body.textContent = '';
        const printers = state.printers || [];
        $('noPrinters').hidden = printers.length > 0;

        for (const printer of printers) {
            const tr = document.createElement('tr');

            const label = document.createElement('td');
            label.textContent = printer.label;

            const target = document.createElement('td');
            target.className = 'mono small';
            target.textContent = printer.id;

            const width = document.createElement('td');
            width.textContent = printer.widthMm + ' mm';

            const actions = document.createElement('td');
            const test = document.createElement('button');
            test.className = 'btn';
            test.textContent = t('printers.test');
            test.addEventListener('click', async () => {
                test.disabled = true;
                const result = await window.shell.testPrint(printer.id);
                test.disabled = false;
                toast(result.ok
                    ? t('printers.testOk')
                    : `${t('printers.testFail')}: ${t('error.' + (result.detail === 'usb_driver_unavailable' ? 'usb_driver_unavailable' : result.error))}`);
            });

            const remove = document.createElement('button');
            remove.className = 'btn danger';
            remove.textContent = t('printers.remove');
            remove.addEventListener('click', async () => {
                await window.shell.removePrinter(printer.id);
                refresh();
            });

            actions.append(test, remove);
            tr.append(label, target, width, actions);
            body.append(tr);
        }

        const unknown = (state.bridge && state.bridge.unknownTargets) || [];
        $('suggestedPanel').hidden = unknown.length === 0;
        const list = $('suggested');
        list.textContent = '';
        for (const entry of unknown) {
            const li = document.createElement('li');
            li.textContent = entry.id + ' ';
            const add = document.createElement('button');
            add.className = 'btn';
            add.textContent = t('printers.addSuggested');
            add.addEventListener('click', async () => {
                await window.shell.upsertPrinter({ id: entry.id, label: entry.id, widthMm: 80 });
                refresh();
            });
            li.append(add);
            list.append(li);
        }
    }

    function renderShell(state) {
        $('serverUrl').value = state.serverUrl || '';
        $('kiosk').checked = !!state.kiosk;
        $('autoLaunch').checked = !!state.autoLaunch;
        $('version').textContent = state.version;

        const select = $('locale');
        select.textContent = '';
        for (const code of state.locales || ['en']) {
            const option = document.createElement('option');
            option.value = code;
            option.textContent = code.toUpperCase();
            option.selected = code === state.locale;
            select.append(option);
        }
    }

    async function refresh() {
        const state = await window.shell.state();
        strings = state.strings || {};
        document.body.setAttribute('dir', state.dir || 'ltr');
        document.title = t('settings.title');
        applyStrings();
        renderBridge(state);
        renderPairing(state);
        renderPrinters(state);
        renderShell(state);

        return state;
    }

    document.addEventListener('DOMContentLoaded', async () => {
        await refresh();

        for (const tab of document.querySelectorAll('[role="tab"]')) {
            tab.addEventListener('click', () => selectTab(tab.dataset.panel));
        }

        $('saveToken').addEventListener('click', async () => {
            const value = $('token').value.trim();
            if (value.length < 40) {
                toast(t('pairing.tooShort'));

                return;
            }
            await window.shell.setToken(value);
            await refresh();
            toast(t('settings.saved'));
        });

        $('clearToken').addEventListener('click', async () => {
            await window.shell.setToken('');
            await refresh();
        });

        $('saveOrigins').addEventListener('click', async () => {
            await window.shell.setExtraOrigins($('origins').value.split(/\n+/));
            await refresh();
            toast(t('settings.saved'));
        });

        $('addPrinter').addEventListener('click', async () => {
            try {
                await window.shell.upsertPrinter({
                    id: $('pTarget').value.trim(),
                    label: $('pLabel').value.trim(),
                    widthMm: Number($('pWidth').value),
                });
            } catch (e) {
                toast(t('printers.invalid'));

                return;
            }
            $('pTarget').value = '';
            $('pLabel').value = '';
            await refresh();
            toast(t('settings.saved'));
        });

        $('saveServerUrl').addEventListener('click', async () => {
            try {
                await window.shell.setServerUrl($('serverUrl').value.trim());
            } catch (e) {
                toast(t('setup.invalidUrl'));

                return;
            }
            await refresh();
            toast(t('settings.saved'));
        });

        $('kiosk').addEventListener('change', (event) => window.shell.setFlag({ key: 'kiosk', value: event.target.checked }));
        $('autoLaunch').addEventListener('change', (event) => window.shell.setFlag({ key: 'autoLaunch', value: event.target.checked }));
        $('reloadStore').addEventListener('click', () => window.shell.reloadStore());

        $('locale').addEventListener('change', async (event) => {
            await window.shell.setLocale(event.target.value);
            await refresh();
        });

        // The bridge's counters are the operator's only window onto printing.
        setInterval(async () => {
            const status = await window.shell.bridgeStatus();
            renderBridge({ bridge: status, bridgeUrl: $('bridgeUrl').textContent });
        }, 3000);
    });
}());
