// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — the bridge's lifecycle inside the shell.
 *
 * Keeps the HTTP server and the transports in one place so `main.js` stays about
 * windows and this stays about printing. The bridge reads its config through a
 * closure, so changing a printer or re-pairing takes effect on the NEXT request
 * without a restart — an operator fixing a typo mid-service should not have to
 * quit the app.
 *
 * The port is fixed at 8543 and a clash is fatal to the bridge (not to the
 * shell): the web side has no discovery, so a bridge on another port is a bridge
 * that silently does not exist. The failure is surfaced loudly in the UI instead.
 */

const { createBridgeServer, consoleLogger, BRIDGE_PORT, BRIDGE_HOST } = require('../bridge/server');
const { createNetworkTransport } = require('../bridge/transport/network');
const { createUsbTransport } = require('../bridge/transport/usb');
const { sendToNetworkPrinter } = require('../bridge/transport/network');
const { sendToUsbPrinter } = require('../bridge/transport/usb');
const { parseTarget } = require('../bridge/targets');
const { testTicket } = require('./test-ticket');

function createBridgeRuntime({ config, version = '1.0.0', logger = consoleLogger() } = {}) {
    const transports = {
        network: createNetworkTransport(),
        usb: createUsbTransport(),
    };

    const bridge = createBridgeServer({
        getConfig: () => config.bridgeConfig(),
        transports,
        logger,
        version,
    });

    const status = { listening: false, error: null, port: BRIDGE_PORT, host: BRIDGE_HOST };

    return {
        bridge,
        status: () => ({ ...status, stats: { ...bridge.state }, unknownTargets: bridge.unknownTargets() }),

        async start() {
            try {
                await bridge.listen({ port: BRIDGE_PORT, host: BRIDGE_HOST });
                status.listening = true;
                status.error = null;
            } catch (err) {
                status.listening = false;
                status.error = err && err.code === 'EADDRINUSE' ? 'port_in_use' : 'listen_failed';
                logger.error('bridge.listen_failed', { port: BRIDGE_PORT, code: err && err.code });
            }

            // A COPY: `status` is mutable and `stop()` flips `listening`, so a
            // caller holding the live object would see its own start result change
            // underneath it.
            return { ...status };
        },

        async stop() {
            status.listening = false;
            await bridge.close();
        },

        /**
         * Fire the canned test ticket straight at a printer, bypassing HTTP. This
         * is how an operator proves the setup before the web app is even paired.
         */
        async testPrint(targetId) {
            const target = parseTarget(targetId);
            if (!target) return { ok: false, error: 'bad_request' };

            const printer = config.printers().find((row) => row.id === target.id);
            if (!printer) return { ok: false, error: 'unknown_target' };

            const bytes = testTicket({
                label: printer.label,
                target: printer.id,
                widthMm: printer.widthMm,
                version,
            });

            try {
                if (target.kind === 'usb') await sendToUsbPrinter(target, bytes);
                else await sendToNetworkPrinter(target, bytes);

                return { ok: true };
            } catch (err) {
                return { ok: false, error: (err && err.code) || 'internal', detail: (err && err.detail) || '' };
            }
        },
    };
}

module.exports = { createBridgeRuntime };
