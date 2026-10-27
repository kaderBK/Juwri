// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * The shell's persisted configuration — pairing, the derived CORS allowlist, and
 * the printer list the bridge answers `/health` with.
 *
 * The allowlist is DERIVED from the server URL the operator paired against; that
 * is what "the store's own origin(s) recorded at pairing" means in practice, and
 * it is why an operator cannot accidentally end up with a wide-open bridge.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { createConfig, MemoryStore } = require('../src/main/config');
const { testTicket } = require('../src/main/test-ticket');

function config(initial = {}) {
    return createConfig({ store: new MemoryStore(initial), secureStorage: null });
}

test('the allowlist is derived from the paired server URL', () => {
    const cfg = config();
    cfg.setServerUrl('https://menu.example.com/staff/pos');

    assert.deepEqual(cfg.origins(), ['https://menu.example.com']);
    assert.equal(cfg.serverUrl(), 'https://menu.example.com/staff/pos', 'the path is kept for loading the page');
});

test('a bare domain is coerced to https, and junk is refused', () => {
    const cfg = config();
    cfg.setServerUrl('menu.example.com');
    assert.deepEqual(cfg.origins(), ['https://menu.example.com']);

    assert.throws(() => cfg.setServerUrl('not a url'), /invalid_server_url/);
    assert.throws(() => cfg.setServerUrl('file:///etc/passwd'), /invalid_server_url/);
});

test('extra origins are normalised and deduped; nothing else can widen the list', () => {
    const cfg = config();
    cfg.setServerUrl('https://menu.example.com');
    cfg.setExtraOrigins(['https://custom.example', 'https://custom.example/x', 'garbage', '']);

    assert.deepEqual(cfg.origins(), ['https://menu.example.com', 'https://custom.example']);
    assert.deepEqual(cfg.bridgeConfig().origins, cfg.origins());
});

test('the pairing token round-trips and can be cleared', () => {
    const cfg = config();
    assert.equal(cfg.paired(), false);

    cfg.setToken('T'.repeat(40));
    assert.equal(cfg.paired(), true);
    assert.equal(cfg.bridgeConfig().token, 'T'.repeat(40));

    cfg.setToken('');
    assert.equal(cfg.paired(), false);
    assert.equal(cfg.bridgeConfig().token, '');
});

test('the token is encrypted at rest when the OS offers a keychain', () => {
    const store = new MemoryStore();
    const fakeKeychain = {
        isEncryptionAvailable: () => true,
        encryptString: (value) => Buffer.from(`enc:${value}`),
        decryptString: (buf) => buf.toString().replace(/^enc:/, ''),
    };

    const cfg = createConfig({ store, secureStorage: fakeKeychain });
    cfg.setToken('S'.repeat(40));

    assert.equal(store.get('token'), '', 'no plaintext copy is left behind');
    assert.ok(store.get('tokenEncrypted'), 'the ciphertext is what is persisted');
    assert.equal(cfg.token(), 'S'.repeat(40));
    assert.equal(cfg.all().tokenEncrypted, true);
});

test('the shell state never leaks the token to a renderer', () => {
    const cfg = config();
    cfg.setToken('L'.repeat(40));

    const state = cfg.all();
    assert.equal(state.paired, true);
    assert.equal(JSON.stringify(state).includes('L'.repeat(40)), false);
});

test('printers are keyed by canonical target, so an edit updates instead of duplicating', () => {
    const cfg = config();
    cfg.upsertPrinter({ id: 'tcp://192.168.1.50:9100', label: 'Kitchen', widthMm: 80 });
    cfg.upsertPrinter({ id: 'tcp://192.168.1.50', label: 'Kitchen (renamed)', widthMm: 57 });

    const printers = cfg.printers();
    assert.equal(printers.length, 1, 'the default port makes these one printer');
    assert.equal(printers[0].label, 'Kitchen (renamed)');
    assert.equal(printers[0].widthMm, 57);
    assert.equal(printers[0].kind, 'network');

    cfg.removePrinter('tcp://192.168.1.50:9100');
    assert.deepEqual(cfg.printers(), []);
});

test('an invalid printer target is refused rather than silently stored', () => {
    const cfg = config();
    assert.throws(() => cfg.upsertPrinter({ id: 'printer1', label: 'x' }), /invalid_target/);
    assert.throws(() => cfg.upsertPrinter({ id: 'http://192.168.1.50', label: 'x' }), /invalid_target/);
});

test('a width other than 80/57 falls back to 80 rather than reaching the contract', () => {
    const cfg = config();
    cfg.upsertPrinter({ id: 'tcp://10.0.0.1:9100', label: 'x', widthMm: 72 });
    assert.equal(cfg.printers()[0].widthMm, 80);
});

test('a corrupted printer row is dropped, not crashed on', () => {
    const cfg = config({ printers: [{ id: 'nonsense' }, { id: 'tcp://10.0.0.1:9100', label: 'ok' }] });
    assert.deepEqual(cfg.printers().map((p) => p.label), ['ok']);
});

test('the canned test ticket is real ESC/POS and respects the paper width', () => {
    const wide = testTicket({ label: 'Kitchen', target: 'tcp://10.0.0.1:9100', widthMm: 80 });
    const narrow = testTicket({ label: 'Kitchen', target: 'tcp://10.0.0.1:9100', widthMm: 57 });

    assert.deepEqual(wide.subarray(0, 2), Buffer.from([0x1b, 0x40]), 'starts with ESC @');
    assert.deepEqual(wide.subarray(-4), Buffer.from([0x1d, 0x56, 0x42, 0x00]), 'ends with a cut');
    assert.ok(wide.includes(Buffer.from('juwri')));
    assert.ok(wide.length > narrow.length, '48 columns of rules beats 32');
});
