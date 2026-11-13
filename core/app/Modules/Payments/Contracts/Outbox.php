<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

use Juwri\Modules\Payments\Services\Webhooks\SendOnce;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;

/**
 * J25-7 transactional outbox (public surface for modules above Payments). Implemented by {@see TransactionalOutbox}.
 *
 * record() inside the same DB transaction as the state change the side effect belongs to (e.g. the payment claim):
 * the intent is then durable exactly when the change is. relay() performs recorded messages through the handler
 * registered for their type — call it after the transaction commits for the usual in-request timing; anything left
 * (a crash, a failing handler) is retried by `payments:outbox-relay`. Delivery is at least once, so handlers must be
 * idempotent (or tolerate a repeat).
 */
interface Outbox
{
    /**
     * @param  array<string, mixed>  $payload  JSON-serialisable
     * @return int|null the message id; for a $dedupeKey already recorded: its id when that message is still owed
     *                  (pending / failed — made due now), null when it is done or being performed
     */
    public function record(string $type, array $payload, ?string $dedupeKey = null): ?int;

    /**
     * Perform pending messages now: these ids (null = every due message, oldest first, up to $limit). Never throws:
     * a failing handler leaves its message pending with a back-off.
     *
     * @param  list<int>|null  $ids
     * @return int messages performed
     */
    public function relay(?array $ids = null, int $limit = 100): int;

    /**
     * relay($ids) once the current DB transaction commits (right away when there is none) — a side effect recorded
     * inside a transaction that then rolls back is never performed.
     *
     * @param  list<int>  $ids
     */
    public function relayAfterCommit(array $ids): void;

    /** pending | processing | done | failed, or null for an unknown id. */
    public function status(int $id): ?string;

    /** The last handler error recorded on a message (null when none). */
    public function lastError(int $id): ?string;

    /**
     * @param  callable(array<string, mixed>): void  $handler
     * @param  bool  $sendOnce  P6-158: the handler sends something that cannot be rolled back (email, print) and guards
     *                          it with {@see SendOnce}. It then runs
     *                          WITHOUT the wrapping transaction, so the marker it writes survives a kill.
     */
    public function handle(string $type, callable $handler, bool $sendOnce = false): void;

    /**
     * P6-158: run $send at most once per ($type, $key) through the {@see SendOnce} marker, for a send-once handler of
     * a module above Payments. False when it was already claimed (nothing sent); an exception from $send frees the
     * claim and is rethrown.
     *
     * @param  callable(): void  $send
     */
    public function sendOnce(string $type, string $key, callable $send): bool;
}
