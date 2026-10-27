// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — headless bridge runner.
 *
 *   BRIDGE_TOKEN=... BRIDGE_ORIGIN=https://menu.example.com \
 *   BRIDGE_PRINTERS='[{"id":"tcp://192.168.1.50:9100","label":"Kitchen","widthMm":80}]' \
 *   npm run bridge
 *
 * Same server object the Electron shell runs, without a window. Two uses:
 * verifying the contract against a real printer on a workbench, and letting CI
 * curl `/health` without an Electron runner. It is NOT the shipping entry point —
 * `npm start` is.
 */

const { createBridgeServer, consoleLogger, BRIDGE_PORT, BRIDGE_HOST } = require('../src/bridge/server');
const { createNetworkTransport } = require('../src/bridge/transport/network');
const { createUsbTransport } = require('../src/bridge/transport/usb');
const { createConfig, MemoryStore } = require('../src/main/config');
const pkg = require('../package.json');

function parsePrinters(raw) {
    if (!raw) return [];
    try {
        const rows = JSON.parse(raw);

        return Array.isArray(rows) ? rows : [];
    } catch (e) {
        // eslint-disable-next-line no-console
        console.error('BRIDGE_PRINTERS is not valid JSON — starting with no printers');

        return [];
    }
}

const config = createConfig({
    store: new MemoryStore({
        serverUrl: process.env.BRIDGE_ORIGIN || '',
        extraOrigins: (process.env.BRIDGE_EXTRA_ORIGINS || '').split(/[\s,]+/).filter(Boolean),
        token: process.env.BRIDGE_TOKEN || '',
        tokenEncrypted: '',
        printers: parsePrinters(process.env.BRIDGE_PRINTERS),
    }),
    secureStorage: null, // no Electron here — the token stays in memory
});

const bridge = createBridgeServer({
    getConfig: () => config.bridgeConfig(),
    transports: { network: createNetworkTransport(), usb: createUsbTransport() },
    logger: consoleLogger(),
    version: pkg.version,
});

bridge.listen({ port: Number(process.env.BRIDGE_PORT) || BRIDGE_PORT, host: BRIDGE_HOST })
    .then(() => {
        if (!config.token()) {
            // eslint-disable-next-line no-console
            console.log('bridge.unpaired set BRIDGE_TOKEN to the store pairing token — every request will 409 until then');
        }
    })
    .catch((err) => {
        // Fail loudly. The contract fixes the port; drifting to another one would
        // leave the web side probing an address nothing answers on.
        // eslint-disable-next-line no-console
        console.error(`bridge.listen_failed port=${BRIDGE_PORT} code=${err && err.code}`);
        process.exit(1);
    });

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        bridge.close().then(() => process.exit(0));
    });
}
