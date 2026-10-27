// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — the LAN print bridge.
 *
 * Implements rev/parity/contracts/print-bridge.md VERBATIM. That file is frozen:
 * T1 shipped and merged the consumer half against it, so anything here that
 * drifts becomes a printer that silently stops working in production.
 *
 * The surface is deliberately tiny — `GET /health`, `POST /print`, and the
 * preflight — and this module is plain Node with no Electron import, which is
 * what makes the whole contract testable without a printer or a window.
 *
 * Security posture (this is an HTTP server on an operator's till):
 *   • loopback only — `listen()` refuses any host but 127.0.0.1;
 *   • the port is FIXED at 8543 and a clash fails loudly, because the web side
 *     has no discovery mechanism and would silently lose printing;
 *   • bearer token compared in constant time;
 *   • CORS is an allowlist of the store's own origin(s), never `*`;
 *   • `target` must be a printer the operator configured — the bridge is not a
 *     general-purpose "write bytes to any host on the LAN" proxy;
 *   • nothing logs a payload or a token.
 */

const http = require('node:http');

const { authFailure } = require('./auth');
const { buildAllowlist, evaluateOrigin, corsHeaders, normalizeOrigin } = require('./cors');
const { JobLedger } = require('./jobs');
const { parseTarget, canonicalId } = require('./targets');
const { PrinterError, classify, ensureVocabulary } = require('./errors');

const BRIDGE_PORT = 8543;
const BRIDGE_HOST = '127.0.0.1';

/** Contract: `dataB64` is ≤ 512 KB DECODED. */
const MAX_DECODED_BYTES = 512 * 1024;

/**
 * Raw request cap. base64 inflates by 4/3, plus the JSON envelope; anything past
 * this is refused before it is buffered, so a hostile local process cannot make
 * the till swap by streaming at us.
 */
const MAX_BODY_BYTES = 768 * 1024;

const HEALTH_STATUS_TTL_MS = 5 * 60 * 1000;

function nullLogger() {
    return { info() {}, warn() {}, error() {} };
}

function consoleLogger() {
    const line = (level, event, fields) => {
        const parts = [new Date().toISOString(), level, event];
        for (const [key, value] of Object.entries(fields || {})) {
            if (value !== undefined && value !== null && value !== '') parts.push(`${key}=${value}`);
        }
        // eslint-disable-next-line no-console
        console[level === 'error' ? 'error' : 'log'](parts.join(' '));
    };

    return {
        info: (event, fields) => line('info', event, fields),
        warn: (event, fields) => line('warn', event, fields),
        error: (event, fields) => line('error', event, fields),
    };
}

/** Read the body with a hard cap, without buffering past it. */
function readBody(req, maxBytes) {
    return new Promise((resolve) => {
        const declared = Number(req.headers['content-length']);
        if (Number.isFinite(declared) && declared > maxBytes) {
            resolve({ tooLarge: true, body: null });

            return;
        }

        const chunks = [];
        let size = 0;
        let done = false;

        const finish = (result) => {
            if (done) return;
            done = true;
            resolve(result);
        };

        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > maxBytes) {
                finish({ tooLarge: true, body: null });
                req.destroy();

                return;
            }
            chunks.push(chunk);
        });
        req.on('end', () => finish({ tooLarge: false, body: Buffer.concat(chunks) }));
        req.on('error', () => finish({ tooLarge: false, body: null, aborted: true }));
        req.on('aborted', () => finish({ tooLarge: false, body: null, aborted: true }));
    });
}

const BASE64_RE = /^[A-Za-z0-9+/\r\n]+={0,2}$/;

function decodePayload(dataB64) {
    if (typeof dataB64 !== 'string' || dataB64 === '') return null;

    const compact = dataB64.replace(/\s+/g, '');
    if (compact === '' || !BASE64_RE.test(dataB64)) return null;

    const buf = Buffer.from(compact, 'base64');
    if (buf.length === 0) return null;

    // Round-trip guard: Buffer.from is lenient and will happily swallow garbage.
    if (buf.toString('base64').replace(/=+$/, '') !== compact.replace(/=+$/, '')) return null;

    return buf;
}

function createBridgeServer(options = {}) {
    const {
        getConfig = () => ({ token: null, origins: [], printers: [] }),
        transports = {},
        logger = nullLogger(),
        version = '1.0.0',
        now = Date.now,
        maxDecodedBytes = MAX_DECODED_BYTES,
        maxBodyBytes = MAX_BODY_BYTES,
    } = options;

    const ledger = options.ledger || new JobLedger({ now });

    /** targetId -> { status, at } — /health's `status` field, learned from real jobs. */
    const printerStatus = new Map();

    /** Well-formed targets nobody configured; the settings window offers to add them. */
    const rejectedTargets = new Map();

    const state = { requests: 0, printed: 0, replayed: 0, failed: 0, lastError: null, listening: false };

    function config() {
        const raw = getConfig() || {};

        return {
            token: typeof raw.token === 'string' && raw.token !== '' ? raw.token : null,
            origins: buildAllowlist(raw.origins || []),
            printers: Array.isArray(raw.printers) ? raw.printers : [],
        };
    }

    function findPrinter(cfg, targetId) {
        return cfg.printers.find((printer) => canonicalId(printer.id) === targetId) || null;
    }

    function statusFor(targetId) {
        const entry = printerStatus.get(targetId);
        if (!entry) return 'unknown';
        if (now() - entry.at > HEALTH_STATUS_TTL_MS) return 'unknown';

        return entry.status;
    }

    function recordStatus(targetId, status) {
        printerStatus.set(targetId, { status, at: now() });
    }

    function send(res, status, payload, headers = {}) {
        if (res.writableEnded) return;

        const body = Buffer.from(JSON.stringify(payload), 'utf8');
        res.writeHead(status, Object.assign({
            'Content-Type': 'application/json; charset=utf-8',
            'Content-Length': String(body.length),
            'Cache-Control': 'no-store',
            'X-Content-Type-Options': 'nosniff',
        }, headers));
        res.end(body);
    }

    async function handlePrint(req, res, cfg, headers) {
        const read = await readBody(req, maxBodyBytes);

        if (read.tooLarge) {
            state.failed += 1;
            logger.warn('print.rejected', { reason: 'payload_too_large' });
            send(res, 413, { ok: false, error: 'payload_too_large' }, headers);

            return;
        }

        if (!read.body) return; // client hung up

        let payload;
        try {
            payload = JSON.parse(read.body.toString('utf8'));
        } catch (e) {
            state.failed += 1;
            send(res, 400, { ok: false, error: 'bad_request' }, headers);

            return;
        }

        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            state.failed += 1;
            send(res, 400, { ok: false, error: 'bad_request' }, headers);

            return;
        }

        const jobId = typeof payload.jobId === 'string' ? payload.jobId.trim() : '';
        // Control characters are refused: the jobId reaches the local log, and a
        // newline in it would let a caller forge log lines.
        // eslint-disable-next-line no-control-regex
        const badJobId = jobId === '' || jobId.length > 64 || /[\x00-\x1f\x7f]/.test(jobId);

        const widthMm = Number(payload.widthMm);
        const badWidth = widthMm !== 80 && widthMm !== 57;

        const target = parseTarget(payload.target);
        const bytes = badJobId ? null : decodePayload(payload.dataB64);

        if (badJobId || badWidth || !target || !bytes) {
            state.failed += 1;
            logger.warn('print.rejected', {
                jobId: badJobId ? '' : jobId,
                reason: badJobId ? 'job_id' : badWidth ? 'width' : !target ? 'target' : 'payload',
            });
            send(res, 400, Object.assign({ ok: false, error: 'bad_request' }, badJobId ? {} : { jobId }), headers);

            return;
        }

        if (bytes.length > maxDecodedBytes) {
            state.failed += 1;
            logger.warn('print.rejected', { jobId, reason: 'payload_too_large', bytes: bytes.length });
            send(res, 413, { ok: false, jobId, error: 'payload_too_large' }, headers);

            return;
        }

        // Idempotency — the contract's most consequential rule. A repeat of a
        // jobId that already succeeded replays the original answer and does NOT
        // print again, because a duplicate kitchen ticket has a real-world cost.
        const replayed = ledger.replay(jobId);
        if (replayed) {
            state.replayed += 1;
            logger.info('print.replayed', { jobId, target: target.id });
            send(res, 200, replayed, headers);

            return;
        }

        const printer = findPrinter(cfg, target.id);
        if (!printer) {
            state.failed += 1;
            rejectedTargets.set(target.id, { at: now(), kind: target.kind });
            logger.warn('print.unknown_target', { jobId, target: target.id });
            send(res, 200, { ok: false, jobId, error: 'unknown_target', detail: target.id }, headers);

            return;
        }

        const startedAt = now();

        // Two retries of the SAME jobId arriving while the first is still writing
        // must share one attempt — the client's 15 s budget is exactly our own
        // 5 s connect + 10 s write worst case, so the overlap is routine.
        const result = await ledger.run(jobId, async () => {
            const transport = transports[target.kind];

            if (!transport || typeof transport.print !== 'function') {
                return { ok: false, jobId, error: 'internal', detail: `${target.kind}_transport_unavailable` };
            }

            try {
                await transport.print(target, bytes, { widthMm, printer });
                recordStatus(target.id, 'online');

                return { ok: true, jobId };
            } catch (err) {
                const code = err instanceof PrinterError ? ensureVocabulary(err.code) : classify(err);
                recordStatus(target.id, code === 'econnrefused' || code === 'etimedout' ? 'offline' : 'unknown');

                const failure = { ok: false, jobId, error: code };
                const detail = (err && err.detail) || target.id;
                if (detail) failure.detail = detail;

                return failure;
            }
        });

        if (result.ok) state.printed += 1;
        else {
            state.failed += 1;
            state.lastError = { jobId, error: result.error, at: now() };
        }

        logger.info(result.ok ? 'print.ok' : 'print.failed', {
            jobId,
            target: target.id,
            error: result.ok ? '' : result.error,
            ms: now() - startedAt,
        });

        // Printer-level problems stay HTTP 200 with ok:false so the UI can show a
        // reason instead of treating them as a transport bug.
        send(res, 200, result, headers);
    }

    async function handle(req, res) {
        state.requests += 1;

        const cfg = config();
        const url = new URL(req.url || '/', 'http://127.0.0.1');
        const path = url.pathname.replace(/\/+$/, '') || '/';
        const originVerdict = evaluateOrigin(req.headers.origin, cfg.origins);

        // A foreign origin is refused server-side. Omitting the CORS header would
        // only stop the attacker READING the answer — the ticket would still print.
        if (originVerdict === 'reject') {
            logger.warn('origin.rejected', { path, origin: req.headers.origin });
            send(res, 401, { ok: false, error: 'unauthorized' });

            return;
        }

        // Echo the caller's origin back in its normalised form.
        const echo = normalizeOrigin(req.headers.origin) || req.headers.origin;
        const headers = originVerdict === 'allow' ? corsHeaders(echo, {}) : {};

        if (req.method === 'OPTIONS') {
            // Preflights carry no Authorization header — the browser strips it —
            // so they are answered on the origin check alone.
            const pna = req.headers['access-control-request-private-network'] === 'true';
            const preflightHeaders = originVerdict === 'allow'
                ? corsHeaders(echo, { preflight: true, privateNetwork: pna })
                : {};
            res.writeHead(204, Object.assign({ 'Content-Length': '0', 'Cache-Control': 'no-store' }, preflightHeaders));
            res.end();

            return;
        }

        if (path !== '/health' && path !== '/print') {
            send(res, 404, { ok: false, error: 'bad_request' }, headers);

            return;
        }

        const failure = authFailure(req, cfg.token);

        /*
         * Contract v1.1 (lead decision, see print-bridge.md): an UNPAIRED bridge
         * answers /health 200 with `paired:false` and an empty printer list,
         * instead of 409.
         *
         * /health is feature detection, and the web client collapses any
         * non-200 to "no bridge here" — so a 409 made a running-but-unpaired
         * bridge indistinguishable from an absent one, and the operator could
         * never be told to pair it. Nothing is disclosed: the printer list stays
         * empty until a valid token arrives, the origin allowlist still applies,
         * and /print is unchanged (still 409 when unpaired).
         */
        if (failure === 'unpaired' && path === '/health') {
            send(res, 200, { ok: true, version, paired: false, printers: [] }, headers);

            return;
        }

        if (failure) {
            logger.warn('auth.rejected', { path, reason: failure });
            send(res, failure === 'unpaired' ? 409 : 401, { ok: false, error: failure }, headers);

            return;
        }

        if (path === '/health') {
            if (req.method !== 'GET' && req.method !== 'HEAD') {
                send(res, 405, { ok: false, error: 'bad_request' }, headers);

                return;
            }

            send(res, 200, {
                ok: true,
                version,
                paired: true,
                printers: cfg.printers.map((printer) => ({
                    id: printer.id,
                    label: printer.label || printer.id,
                    kind: printer.kind || (parseTarget(printer.id) || {}).kind || 'network',
                    status: statusFor(canonicalId(printer.id) || printer.id),
                })),
            }, headers);

            return;
        }

        if (req.method !== 'POST') {
            send(res, 405, { ok: false, error: 'bad_request' }, headers);

            return;
        }

        await handlePrint(req, res, cfg, headers);
    }

    const server = http.createServer((req, res) => {
        handle(req, res).catch((err) => {
            state.lastError = { error: 'internal', at: now() };
            logger.error('bridge.crash', { message: err && err.message });
            try {
                send(res, 500, { ok: false, error: 'internal' });
            } catch (e) {
                /* the response is already gone */
            }
        });
    });

    server.headersTimeout = 20000;
    server.requestTimeout = 30000;

    return {
        server,
        ledger,
        state,

        /**
         * Loopback only, fixed port. A clash rejects loudly instead of drifting to
         * another port: the web side has no discovery and would just lose printing.
         */
        listen({ port = BRIDGE_PORT, host = BRIDGE_HOST } = {}) {
            if (host !== BRIDGE_HOST) {
                return Promise.reject(new Error(`the print bridge binds ${BRIDGE_HOST} only (refused: ${host})`));
            }

            return new Promise((resolve, reject) => {
                const onError = (err) => {
                    server.removeListener('listening', onListening);
                    state.listening = false;
                    reject(err);
                };
                const onListening = () => {
                    server.removeListener('error', onError);
                    state.listening = true;
                    logger.info('bridge.listening', { host, port });
                    resolve(server.address());
                };

                server.once('error', onError);
                server.once('listening', onListening);
                server.listen(port, host);
            });
        },

        close() {
            return new Promise((resolve) => {
                state.listening = false;
                server.close(() => resolve());
                server.closeAllConnections?.();
            });
        },

        /** Targets a caller asked for that the operator has not configured. */
        unknownTargets() {
            return [...rejectedTargets.entries()].map(([id, meta]) => ({ id, ...meta }));
        },

        handle,
    };
}

module.exports = {
    createBridgeServer,
    consoleLogger,
    nullLogger,
    BRIDGE_PORT,
    BRIDGE_HOST,
    MAX_DECODED_BYTES,
    MAX_BODY_BYTES,
};
