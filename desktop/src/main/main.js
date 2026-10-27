// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — Electron shell (DECISION D2).
 *
 * The shell wraps the EXISTING POS web app; it does not reimplement any of it.
 * Its whole reason to exist is the Node main process, which doubles as the LAN
 * print bridge on 127.0.0.1:8543 — a browser cannot open a raw TCP socket to a
 * `tcp://<ip>:9100` kitchen printer, and this process can.
 *
 * Trust boundary: THE LOADED SITE IS UNTRUSTED CODE. It gets `contextIsolation`,
 * `nodeIntegration: false`, sandboxing and **no preload at all** — nothing of
 * Electron is exposed to the page. The only thing the page may use is the bridge,
 * over ordinary HTTP with a bearer token, exactly like any other browser. The
 * privileged preload is loaded solely by the local settings/setup windows.
 */

const path = require('node:path');
const { app, BrowserWindow, Menu, dialog, ipcMain, session, shell } = require('electron');

const pkg = require('../../package.json');
const { createConfig } = require('./config');
const { createI18n, LOCALES } = require('./i18n');
const { createBridgeRuntime } = require('./bridge-runtime');
const { consoleLogger, BRIDGE_PORT, BRIDGE_HOST } = require('../bridge/server');
const { normalizeOrigin } = require('../bridge/cors');

const SESSION_PARTITION = 'persist:juwri';
const RENDERER_DIR = path.join(__dirname, '..', 'renderer');
const PRELOAD = path.join(__dirname, 'preload.js');

const config = createConfig();
const i18n = createI18n(config.get('locale', 'en'));
const logger = consoleLogger();
const runtime = createBridgeRuntime({ config, version: pkg.version, logger });

let mainWindow = null;
let settingsWindow = null;
let setupWindow = null;
let crashReloads = 0;

// ── windows ──────────────────────────────────────────────────────────────────

function localWindowOptions(extra = {}) {
    return Object.assign({
        show: false,
        webPreferences: {
            preload: PRELOAD,
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            spellcheck: false,
        },
    }, extra);
}

function openSetupWindow() {
    if (setupWindow) {
        setupWindow.focus();

        return setupWindow;
    }

    setupWindow = new BrowserWindow(localWindowOptions({
        width: 560,
        height: 460,
        resizable: false,
        title: i18n.t('setup.title'),
    }));

    setupWindow.loadFile(path.join(RENDERER_DIR, 'setup.html'));
    setupWindow.once('ready-to-show', () => setupWindow.show());
    setupWindow.on('closed', () => {
        setupWindow = null;
        if (!mainWindow && !config.serverUrl()) app.quit();
    });

    return setupWindow;
}

function openSettingsWindow() {
    if (settingsWindow) {
        settingsWindow.focus();

        return settingsWindow;
    }

    settingsWindow = new BrowserWindow(localWindowOptions({
        width: 720,
        height: 660,
        title: i18n.t('settings.title'),
        parent: mainWindow || undefined,
    }));

    settingsWindow.loadFile(path.join(RENDERER_DIR, 'settings.html'));
    settingsWindow.once('ready-to-show', () => settingsWindow.show());
    settingsWindow.on('closed', () => {
        settingsWindow = null;
    });

    return settingsWindow;
}

function openMainWindow() {
    const url = config.serverUrl();
    if (!url) return openSetupWindow();

    if (mainWindow) {
        mainWindow.focus();

        return mainWindow;
    }

    const kiosk = !!config.get('kiosk', false);

    mainWindow = new BrowserWindow({
        width: 1280,
        height: 860,
        show: false,
        title: i18n.t('app.name'),
        fullscreen: kiosk,
        webPreferences: {
            // No preload: the site is the trust boundary and gets nothing.
            partition: SESSION_PARTITION,
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            webSecurity: true,
            spellcheck: true,
        },
    });

    mainWindow.once('ready-to-show', () => mainWindow.show());
    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    // External links leave the shell: a POS window that can be navigated to an
    // arbitrary site is a phishing surface wearing the store's own chrome.
    mainWindow.webContents.setWindowOpenHandler(({ url: target }) => {
        openExternal(target);

        return { action: 'deny' };
    });

    // In-window navigation stays on the store, or on any https page — a card
    // gateway redirect must not be bounced into another browser, or the operator
    // would come back to a session the shell cannot see. Everything else (http to
    // a foreign host, file:, data:, javascript:) is refused outright.
    mainWindow.webContents.on('will-navigate', (event, target) => {
        if (isStoreOrigin(target)) return;

        event.preventDefault();

        if (/^https:\/\//i.test(target)) {
            logger.info('nav.offsite', { host: (normalizeOrigin(target) || '') });
            mainWindow.loadURL(target);

            return;
        }

        openExternal(target);
    });

    // Reload on crash — an unattended till must come back on its own, but a crash
    // loop must not spin: back off, then stop and let the operator see it.
    mainWindow.webContents.on('render-process-gone', (event, details) => {
        logger.error('renderer.gone', { reason: details && details.reason });
        crashReloads += 1;
        if (crashReloads > 5) return;
        setTimeout(() => mainWindow && mainWindow.reload(), Math.min(30000, 1000 * crashReloads));
    });

    mainWindow.webContents.on('did-finish-load', () => {
        crashReloads = 0;
    });

    mainWindow.webContents.on('unresponsive', () => logger.warn('renderer.unresponsive', {}));

    mainWindow.loadURL(url);

    return mainWindow;
}

function isStoreOrigin(target) {
    const origin = normalizeOrigin(target);

    return !!origin && config.origins().includes(origin);
}

function openExternal(target) {
    const parsed = normalizeOrigin(target);
    if (!parsed) return;
    shell.openExternal(target).catch(() => {});
}

// ── application menu ─────────────────────────────────────────────────────────

function buildMenu() {
    const template = [
        {
            label: i18n.t('menu.file'),
            submenu: [
                { label: i18n.t('menu.settings'), accelerator: 'CmdOrCtrl+,', click: () => openSettingsWindow() },
                { type: 'separator' },
                { label: i18n.t('menu.reload'), accelerator: 'CmdOrCtrl+R', click: () => mainWindow && mainWindow.reload() },
                {
                    label: i18n.t('menu.fullscreen'),
                    accelerator: 'F11',
                    click: () => mainWindow && mainWindow.setFullScreen(!mainWindow.isFullScreen()),
                },
                { type: 'separator' },
                { label: i18n.t('menu.quit'), role: 'quit' },
            ],
        },
        {
            label: i18n.t('menu.edit'),
            submenu: [
                { role: 'undo' }, { role: 'redo' }, { type: 'separator' },
                { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' },
            ],
        },
        {
            label: i18n.t('menu.view'),
            submenu: [
                { role: 'zoomIn' }, { role: 'zoomOut' }, { role: 'resetZoom' },
                { type: 'separator' },
                { label: i18n.t('menu.devtools'), accelerator: 'CmdOrCtrl+Shift+I', click: () => mainWindow && mainWindow.webContents.toggleDevTools() },
            ],
        },
    ];

    Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

// ── hardening ────────────────────────────────────────────────────────────────

function hardenSession() {
    const stores = [session.fromPartition(SESSION_PARTITION), session.defaultSession];

    // The POS needs none of the powerful ones. Camera, microphone, geolocation and
    // friends are refused rather than granted silently on a shared till; the denial
    // is logged so the next track can see what a page actually asked for.
    const allowed = ['fullscreen', 'clipboard-sanitized-write', 'notifications'];

    for (const store of stores) {
        store.setPermissionRequestHandler((webContents, permission, callback) => {
            if (!allowed.includes(permission)) logger.warn('permission.denied', { permission });
            callback(allowed.includes(permission));
        });

        store.setPermissionCheckHandler((webContents, permission) => allowed.includes(permission));
    }
}

app.on('web-contents-created', (event, contents) => {
    contents.on('will-attach-webview', (attachEvent) => attachEvent.preventDefault());
});

// Never silently accept a bad certificate: the pairing token travels to loopback,
// but the operator's session cookie travels to the store over TLS.
app.on('certificate-error', (event, webContents, url, error, certificate, callback) => callback(false));

// ── IPC (local windows only) ─────────────────────────────────────────────────

function assertLocalSender(event) {
    const url = event.senderFrame ? event.senderFrame.url : '';

    return typeof url === 'string' && url.startsWith('file://');
}

function ipc(channel, handler) {
    ipcMain.handle(channel, async (event, payload) => {
        if (!assertLocalSender(event)) throw new Error('forbidden');

        return handler(payload, event);
    });
}

ipc('shell:state', () => ({
    ...config.all(),
    version: pkg.version,
    bridge: runtime.status(),
    bridgeUrl: `http://${BRIDGE_HOST}:${BRIDGE_PORT}`,
    locales: LOCALES,
    strings: i18n.catalog(),
    dir: i18n.dir(),
}));

ipc('shell:setServerUrl', (url) => {
    const stored = config.setServerUrl(url);
    if (setupWindow) setupWindow.close();
    if (mainWindow) mainWindow.loadURL(stored);
    else openMainWindow();

    return stored;
});

ipc('shell:setToken', (token) => config.setToken(token));
ipc('shell:setExtraOrigins', (list) => config.setExtraOrigins(list));
ipc('shell:upsertPrinter', (printer) => config.upsertPrinter(printer));
ipc('shell:removePrinter', (id) => config.removePrinter(id));
ipc('shell:testPrint', (id) => runtime.testPrint(id));
ipc('shell:bridgeStatus', () => runtime.status());
ipc('shell:reloadStore', () => {
    if (mainWindow) mainWindow.reload();

    return true;
});

ipc('shell:setLocale', (locale) => {
    const applied = i18n.setLocale(locale);
    config.set('locale', applied);
    buildMenu();

    return { locale: applied, dir: i18n.dir(), strings: i18n.catalog() };
});

ipc('shell:setFlag', ({ key, value }) => {
    config.set(key, !!value);

    if (key === 'autoLaunch') {
        app.setLoginItemSettings({ openAtLogin: !!value, openAsHidden: false });
    }

    if (key === 'kiosk' && mainWindow) {
        mainWindow.setFullScreen(!!value);
    }

    return !!value;
});

// ── lifecycle ────────────────────────────────────────────────────────────────

/**
 * Load the settings window offscreen, drive the preload/IPC path, and assert the
 * window actually populated. A request-level suite cannot see any of this: the
 * settings window is the operator's ONLY way to pair and to add a printer, and a
 * silently blank one would mean an app that boots perfectly and does nothing.
 */
async function smokeRenderer() {
    const errors = [];
    const win = new BrowserWindow(localWindowOptions({ width: 720, height: 660 }));

    win.webContents.on('console-message', (event, level, message) => {
        if (level >= 2) errors.push(message);
    });

    try {
        await win.loadFile(path.join(RENDERER_DIR, 'settings.html'));

        const probe = await win.webContents.executeJavaScript(`(async () => {
            const state = await window.shell.state();
            return {
                hasIpc: !!state && typeof state.version === 'string',
                bridgeState: (document.getElementById('bridgeState') || {}).textContent || '',
                tabs: document.querySelectorAll('[role="tab"]').length,
                translated: (document.querySelector('[data-i18n="pairing.title"]') || {}).textContent || '',
                leakedToken: document.body.innerHTML.includes('Bearer'),
            };
        })()`);

        if (!probe.hasIpc) return { ok: false, reason: 'ipc_unavailable' };
        if (probe.tabs !== 3) return { ok: false, reason: 'tabs_missing' };
        if (!probe.bridgeState.trim()) return { ok: false, reason: 'bridge_status_blank' };
        if (!probe.translated.trim()) return { ok: false, reason: 'strings_missing' };
        if (probe.leakedToken) return { ok: false, reason: 'token_leaked' };
        if (errors.length) return { ok: false, reason: `console:${errors[0]}` };

        // The Arabic lane, end to end: switching locale must flip both the strings
        // and the document direction. A half-translated RTL settings window is a
        // defect no request assertion can see.
        const previousLocale = i18n.locale;
        const rtl = await win.webContents.executeJavaScript(`(async () => {
            await window.shell.setLocale('ar');
            const state = await window.shell.state();
            return { dir: state.dir, title: state.strings['pairing.title'] };
        })()`);
        await win.webContents.executeJavaScript(`window.shell.setLocale(${JSON.stringify(previousLocale)})`);

        if (rtl.dir !== 'rtl' || !/[؀-ۿ]/.test(rtl.title)) {
            return { ok: false, reason: 'rtl_broken' };
        }

        // The first-run screen is the very first thing an operator sees; if its
        // preload path were broken they could never get past it.
        await win.loadFile(path.join(RENDERER_DIR, 'setup.html'));
        const setup = await win.webContents.executeJavaScript(`({
            button: (document.getElementById('continue') || {}).textContent || '',
            placeholder: (document.getElementById('serverUrl') || {}).placeholder || '',
        })`);

        if (!setup.button.trim() || !setup.placeholder.trim()) return { ok: false, reason: 'setup_blank' };
        if (errors.length) return { ok: false, reason: `console:${errors[0]}` };

        return { ok: true };
    } catch (err) {
        return { ok: false, reason: `load_failed:${err && err.message}` };
    } finally {
        win.destroy();
    }
}

if (!app.requestSingleInstanceLock()) {
    // A second copy would fight for port 8543 and lose — and the operator would
    // be looking at a window whose printing silently does not work.
    app.quit();
} else {
    app.on('second-instance', () => {
        const win = mainWindow || settingsWindow || setupWindow;
        if (win) {
            if (win.isMinimized()) win.restore();
            win.focus();
        }
    });

    app.whenReady().then(async () => {
        hardenSession();
        buildMenu();

        const status = await runtime.start();

        // `electron . --smoke-exit` boots the whole shell (bridge included) and
        // quits, so CI can prove the main process starts without a human at a
        // screen. It is the only argv the shell understands.
        if (process.argv.includes('--smoke-exit')) {
            const rendered = await smokeRenderer();
            logger.info('shell.smoke', {
                listening: status.listening,
                error: status.error || '',
                settingsWindow: rendered.ok ? 'ok' : rendered.reason,
            });
            try {
                await runtime.stop();
            } catch (e) {
                logger.error('shell.smoke_stop_failed', { message: e && e.message });
            }
            app.exit(status.listening && rendered.ok ? 0 : 1);

            return;
        }

        if (!status.listening) {
            // Fail LOUDLY: the contract fixes the port, so a clash means printing
            // is off until the operator resolves it. Never drift to another port.
            dialog.showErrorBox(
                i18n.t('bridge.listen_failed'),
                status.error === 'port_in_use'
                    ? i18n.t('bridge.port_in_use_help')
                    : `${i18n.t('bridge.listen_failed')} (${status.error})`
            );
        }

        if (config.serverUrl()) openMainWindow();
        else openSetupWindow();

        app.on('activate', () => {
            if (BrowserWindow.getAllWindows().length === 0) openMainWindow();
        });
    });

    app.on('window-all-closed', () => {
        if (process.platform !== 'darwin') app.quit();
    });

    app.on('before-quit', async () => {
        await runtime.stop();
    });
}

module.exports = { openSettingsWindow };
