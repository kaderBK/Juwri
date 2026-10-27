<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * P1-21 (J29-8): one store- or account-owned table as its module declares it to the store-data registry — the
 * policy per operation ({@see StoreDataPolicy}) plus what the engine needs to run it:
 *
 *  - transfer Reset: {@see resetWith()} — the handler resets the previous owner's connection for one store and
 *    returns what it reset (written to the transfer's audit entry under its key; list values under one key merge);
 *  - transfer, any policy: {@see repoint()} — user columns that name the store's owner and follow the new owner;
 *    {@see files()} — file paths under the account prefix, moved to the new account's prefix;
 *  - duplicate Copy: {@see remap()} (references onto other copied rows), {@see clear()} (references that cannot be
 *    copied: other stores' rows, integrations), {@see fresh()} (new unique values: public ids, QR keys),
 *    {@see set()} (constants: counters, branch), {@see keep()} (references to global rows), {@see transform()}.
 *
 * Shorthands: {@see data()} business data (move / exclude), {@see catalog()} (move / copy), {@see connection()}
 * an owner connection (reset / exclude), {@see ledger()} a store row that stays with the account that made it,
 * {@see account()} an account-level row (stay / exclude).
 */
final class StoreTable
{
    public StoreDataPolicy $transfer = StoreDataPolicy::Move;

    public StoreDataPolicy $duplicate = StoreDataPolicy::Exclude;

    /** @var (Closure(ConnectionInterface, StoreTransferContext): mixed)|null */
    public ?Closure $reset = null;

    /** Audit key of the reset (default: the table name). */
    public ?string $resetKey = null;

    /** @var list<string> */
    public array $repoint = [];

    /** @var array<string, string> column => copied table; '?table' = null when the referenced row was not copied */
    public array $remap = [];

    /** @var array<string, string> JSON column whose object keys are ids of a copied table => that table (P3-08) */
    public array $remapKeys = [];

    /** @var list<string> */
    public array $clear = [];

    /** @var array<string, Closure(ConnectionInterface): mixed> */
    public array $fresh = [];

    /** @var array<string, mixed> */
    public array $values = [];

    /** @var list<string> */
    public array $keep = [];

    /** @var array<string, string> column holding a full relative file path => storage disk */
    public array $files = [];

    /** @var (Closure(array<string, mixed>): (array<string, mixed>|null))|null */
    public ?Closure $transform = null;

    /** @var array<string, list<string>> column holding a bare file name => where the file lives ('public:<dir>/' = public_path, '<disk>:<dir>/' = a storage disk; `{store_id}` is replaced; first existing wins). A `[]` suffix on the column = a JSON list of names. */
    public array $media = [];

    /** @var array<string, (Closure(): mixed)|null> secret column => value written on restore (null = NULL) */
    public array $secret = [];

    /** False: the table is never written to an export bundle (whole-table secrets: vaulted credentials, certificates). */
    public bool $exported = true;

    /**
     * What the table is keyed by. `tenant`: store_id / account_id (the registry must hold every such table);
     * `owned`: an account owner's user-level rows ({@see $scopeColumn} = their user id); `child`: rows of a parent
     * table in scope ({@see $scopeColumn} -> {@see $parentTable}); `platform`: references a user/store but is
     * platform data (actors, partners) — never exported, deleted or restored.
     */
    public string $kind = 'tenant';

    public ?string $scopeColumn = null;

    public ?string $parentTable = null;

    /** @var (Closure(Builder): mixed)|null extra condition for `owned` rows */
    public ?Closure $scopeWhere = null;

    public ?string $note = null;

    /** Set by the registry: the declaring module. */
    public string $module = '';

    private function __construct(public readonly string $table) {}

    /** Business data of the store: follows the store on transfer, never copied. */
    public static function data(string $table): self
    {
        return new self($table);
    }

    /** Catalogue/configuration: follows the store on transfer, copied by a duplicate. */
    public static function catalog(string $table): self
    {
        $t = new self($table);
        $t->duplicate = StoreDataPolicy::Copy;

        return $t;
    }

    /**
     * An owner's third-party connection: reset on transfer (the handler), never copied.
     *
     * @param  Closure(ConnectionInterface, StoreTransferContext): mixed  $reset
     */
    public static function connection(string $table, Closure $reset, ?string $auditKey = null): self
    {
        return (new self($table))->resetWith($reset, $auditKey);
    }

    /** A store row that stays with the account that made it (cost/usage ledgers). */
    public static function ledger(string $table): self
    {
        $t = new self($table);
        $t->transfer = StoreDataPolicy::StayWithOldAccount;

        return $t;
    }

    /**
     * A user-level table of the account's OWNER (no store_id/account_id: api tokens, passkeys, wallet …): exported,
     * deleted and restored with the account; never touched by a store transfer or duplicate.
     *
     * @param  (Closure(Builder): mixed)|null  $where
     */
    public static function owned(string $table, string $column = 'user_id', ?Closure $where = null): self
    {
        $t = self::ledger($table);
        $t->kind = 'owned';
        $t->scopeColumn = $column;
        $t->scopeWhere = $where;

        return $t;
    }

    /** Rows of a parent table in scope ($column -> $parent.id), e.g. a supplier's payments. */
    public static function child(string $table, string $column, string $parent): self
    {
        $t = self::ledger($table);
        $t->kind = 'child';
        $t->scopeColumn = $column;
        $t->parentTable = $parent;

        return $t;
    }

    /** Platform data that merely references a user or store (actors, partners): never exported, deleted or restored. */
    public static function platform(string $table, string $why): self
    {
        $t = self::ledger($table);
        $t->kind = 'platform';
        $t->exported = false;
        $t->note = $why;

        return $t;
    }

    /** An account-level row (no store): never touched by a store operation. */
    public static function account(string $table): self
    {
        return self::ledger($table);
    }

    /** @param  Closure(ConnectionInterface, StoreTransferContext): mixed  $reset */
    public function resetWith(Closure $reset, ?string $auditKey = null): self
    {
        $this->transfer = StoreDataPolicy::Reset;
        $this->reset = $reset;
        $this->resetKey = $auditKey;

        return $this;
    }

    /** @param  list<string>  $columns */
    public function repoint(array $columns): self
    {
        $this->repoint = $columns;

        return $this;
    }

    /**
     * Copy this table too (a connection whose non-secret part is copied, e.g. gateway titles).
     *
     * @param  (Closure(array<string, mixed>): (array<string, mixed>|null))|null  $transform
     */
    public function copy(?Closure $transform = null): self
    {
        $this->duplicate = StoreDataPolicy::Copy;
        if ($transform !== null) {
            $this->transform = $transform;
        }

        return $this;
    }

    /** @param  array<string, string>  $columns */
    public function remap(array $columns): self
    {
        $this->remap = $columns + $this->remap;

        return $this;
    }

    /**
     * P3-08: JSON object columns whose numeric KEYS are ids of another copied table (a variant cell's
     * {option group id: option index}); on a duplicate the keys are rewritten to the copies' ids (a key whose row was
     * not copied is dropped).
     *
     * @param  array<string, string>  $columns  column => copied table
     */
    public function remapKeys(array $columns): self
    {
        $this->remapKeys = $columns + $this->remapKeys;

        return $this;
    }

    /** @param  list<string>  $columns */
    public function clear(array $columns): self
    {
        $this->clear = array_values(array_unique([...$this->clear, ...$columns]));

        return $this;
    }

    /** @param  array<string, Closure(ConnectionInterface): mixed>  $columns */
    public function fresh(array $columns): self
    {
        $this->fresh = $columns + $this->fresh;

        return $this;
    }

    /** @param  array<string, mixed>  $columns */
    public function set(array $columns): self
    {
        $this->values = $columns + $this->values;

        return $this;
    }

    /**
     * Columns that hold a full relative path of a file the row owns (per-account prefix `accounts/<id>/…`): moved to
     * the new account's prefix on transfer, written to an export bundle, deleted with the row.
     *
     * @param  array<string, string>  $columns  column => disk
     */
    public function files(array $columns): self
    {
        $this->files = $columns + $this->files;

        return $this;
    }

    /**
     * Secret columns: never written to an export bundle (listed in its manifest). On restore the column gets the
     * closure's value (an unusable placeholder for a NOT NULL secret) or NULL.
     *
     * @param  array<int|string, (Closure(): mixed)|string|null>  $columns  names, or name => restore value closure
     */
    public function secret(array $columns): self
    {
        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                $this->secret[(string) $value] = null;
            } else {
                $this->secret[$key] = $value instanceof Closure ? $value : null;
            }
        }

        return $this;
    }

    /** The whole table stays out of export bundles (and so is not restored): the new install reconnects. */
    public function notExported(): self
    {
        $this->exported = false;

        return $this;
    }

    /** @param  array<string, list<string>>  $columns */
    public function media(array $columns): self
    {
        $this->media = $columns + $this->media;

        return $this;
    }

    /** @param  list<string>  $columns */
    public function keep(array $columns): self
    {
        $this->keep = array_values(array_unique([...$this->keep, ...$columns]));

        return $this;
    }

    /** @param  Closure(array<string, mixed>): (array<string, mixed>|null)  $transform */
    public function transform(Closure $transform): self
    {
        $this->transform = $transform;

        return $this;
    }

    /**
     * The table a remapped column points at, and whether an unmapped reference drops the row (true) or is nulled.
     *
     * @return array{0: string, 1: bool}|null
     */
    public function remapTarget(string $column): ?array
    {
        $target = $this->remap[$column] ?? null;
        if ($target === null) {
            return null;
        }

        return str_starts_with($target, '?') ? [substr($target, 1), false] : [$target, true];
    }

    public function validate(): void
    {
        if (! in_array($this->transfer, StoreDataPolicy::forTransfer(), true)) {
            throw new InvalidArgumentException("{$this->table}: transfer policy {$this->transfer->value} is not a transfer policy");
        }
        if (! in_array($this->duplicate, StoreDataPolicy::forDuplicate(), true)) {
            throw new InvalidArgumentException("{$this->table}: duplicate policy {$this->duplicate->value} is not a duplicate policy");
        }
        if (($this->transfer === StoreDataPolicy::Reset) !== ($this->reset !== null)) {
            throw new InvalidArgumentException("{$this->table}: a reset policy needs a reset handler (and only a reset policy has one)");
        }
    }
}
