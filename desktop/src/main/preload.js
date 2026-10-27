// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — preload for the LOCAL windows only (setup + settings).
 *
 * The wrapped POS site deliberately has NO preload: it is untrusted code and must
 * see a plain browser. This bridge is loaded exclusively by `file://` pages that
 * ship inside the app, and it exposes a fixed, enumerable list of calls — never
 * `ipcRenderer` itself, which would let any injected script reach every channel.
 */

const { contextBridge, ipcRenderer } = require('electron');

const call = (channel) => (payload) => ipcRenderer.invoke(channel, payload);

contextBridge.exposeInMainWorld('shell', {
    state: call('shell:state'),
    bridgeStatus: call('shell:bridgeStatus'),
    setServerUrl: call('shell:setServerUrl'),
    setToken: call('shell:setToken'),
    setExtraOrigins: call('shell:setExtraOrigins'),
    upsertPrinter: call('shell:upsertPrinter'),
    removePrinter: call('shell:removePrinter'),
    testPrint: call('shell:testPrint'),
    reloadStore: call('shell:reloadStore'),
    setLocale: call('shell:setLocale'),
    setFlag: call('shell:setFlag'),
});
