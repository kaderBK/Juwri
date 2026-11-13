// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — per store + host key/value storage (IndexedDB, in-memory fallback).
   One database per `juwri-sf:<host>:<store slug>`: two stores on one host, or one store on two hosts, never
   share a cart or a queue (J28). Values are plain JSON. */

export function dbName(host, slug) {
    return 'juwri-sf:' + host + ':' + slug;
}

/** Map-backed store with the same surface as the IndexedDB one (private mode, old browsers, node tests). */
export function memoryStore() {
    const m = new Map();
    return {
        kind: 'memory',
        async get(bucket, key) { const v = m.get(bucket + '\u0000' + key); return v === undefined ? undefined : JSON.parse(v); },
        async put(bucket, key, value) { m.set(bucket + '\u0000' + key, JSON.stringify(value)); },
        async del(bucket, key) { m.delete(bucket + '\u0000' + key); },
        async all(bucket) {
            const out = [];
            for (const [k, v] of m) if (k.startsWith(bucket + '\u0000')) out.push(JSON.parse(v));
            return out;
        },
        async clear(bucket) { for (const k of [...m.keys()]) if (k.startsWith(bucket + '\u0000')) m.delete(k); },
    };
}

const BUCKETS = ['cart', 'queue', 'meta'];

export function openStore(name, idb = globalThis.indexedDB) {
    if (!idb) return Promise.resolve(memoryStore());
    return new Promise((resolve) => {
        let req;
        try { req = idb.open(name, 1); } catch (e) { resolve(memoryStore()); return; }
        req.onupgradeneeded = () => {
            const db = req.result;
            for (const b of BUCKETS) if (!db.objectStoreNames.contains(b)) db.createObjectStore(b);
        };
        req.onerror = () => resolve(memoryStore());
        req.onblocked = () => resolve(memoryStore());
        req.onsuccess = () => {
            const db = req.result;
            const run = (bucket, mode, fn) => new Promise((res, rej) => {
                let tx;
                try { tx = db.transaction(bucket, mode); } catch (e) { rej(e); return; }
                const out = fn(tx.objectStore(bucket));
                tx.oncomplete = () => res(out && 'result' in out ? out.result : undefined);
                tx.onerror = () => rej(tx.error);
                tx.onabort = () => rej(tx.error);
            });
            resolve({
                kind: 'indexeddb',
                get: (b, k) => run(b, 'readonly', (s) => s.get(k)),
                put: (b, k, v) => run(b, 'readwrite', (s) => s.put(v, k)),
                del: (b, k) => run(b, 'readwrite', (s) => s.delete(k)),
                all: (b) => run(b, 'readonly', (s) => s.getAll()),
                clear: (b) => run(b, 'readwrite', (s) => s.clear()),
            });
        };
    });
}
