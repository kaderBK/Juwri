// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — idempotency, per rev/parity/contracts/print-bridge.md §Idempotency.
 *
 * "The bridge keeps the last 200 jobIds (in memory, ~10 min TTL). A repeat of a
 * jobId that already succeeded returns the original {"ok":true} without printing
 * again."
 *
 * This is the single most consequential behaviour in the bridge. T1 retries the
 * SAME jobId twice after a 15 s timeout, and a duplicate kitchen ticket is a real
 * cost to the restaurant. Two mechanisms are needed, not one:
 *
 *   SUCCEEDED — remembered for 200 ids / 10 minutes and replayed verbatim.
 *   IN FLIGHT — a retry that lands while the first attempt is still writing must
 *               await that attempt, not open a second socket. The client's 15 s
 *               fetch budget is exactly the bridge's own 5 s connect + 10 s write
 *               worst case, so this overlap is the normal case, not the rare one.
 *
 * Failures are deliberately NOT remembered: a retry after `econnrefused` must
 * really try again, otherwise the operator can never recover by fixing the cable.
 */

const DEFAULT_MAX = 200;
const DEFAULT_TTL_MS = 10 * 60 * 1000;

class JobLedger {
    constructor({ max = DEFAULT_MAX, ttlMs = DEFAULT_TTL_MS, now = Date.now } = {}) {
        this.max = max;
        this.ttlMs = ttlMs;
        this.now = now;
        this.succeeded = new Map(); // jobId -> { at, result }
        this.inFlight = new Map();  // jobId -> Promise<result>
    }

    /** The remembered result for a succeeded job, or null. */
    replay(jobId) {
        const entry = this.succeeded.get(jobId);
        if (!entry) return null;

        if (this.now() - entry.at > this.ttlMs) {
            this.succeeded.delete(jobId);

            return null;
        }

        // Refresh recency so a job that is still being retried stays remembered.
        this.succeeded.delete(jobId);
        this.succeeded.set(jobId, entry);

        return entry.result;
    }

    /** The promise of an attempt already running for this jobId, or null. */
    pending(jobId) {
        return this.inFlight.get(jobId) || null;
    }

    /**
     * Run `attempt` under the jobId's lock. A concurrent call with the same id
     * awaits the first attempt instead of starting a second one.
     */
    run(jobId, attempt) {
        const existing = this.inFlight.get(jobId);
        if (existing) return existing;

        const promise = (async () => attempt())()
            .then((result) => {
                if (result && result.ok) this.remember(jobId, result);

                return result;
            })
            .finally(() => {
                this.inFlight.delete(jobId);
            });

        this.inFlight.set(jobId, promise);

        return promise;
    }

    remember(jobId, result) {
        this.succeeded.delete(jobId);
        this.succeeded.set(jobId, { at: this.now(), result });
        this.prune();
    }

    prune() {
        const cutoff = this.now() - this.ttlMs;

        for (const [id, entry] of this.succeeded) {
            if (entry.at <= cutoff) this.succeeded.delete(id);
        }

        // Map preserves insertion order, so the oldest key is the first one.
        while (this.succeeded.size > this.max) {
            const oldest = this.succeeded.keys().next().value;
            this.succeeded.delete(oldest);
        }
    }

    get size() {
        return this.succeeded.size;
    }
}

module.exports = { JobLedger, DEFAULT_MAX, DEFAULT_TTL_MS };
