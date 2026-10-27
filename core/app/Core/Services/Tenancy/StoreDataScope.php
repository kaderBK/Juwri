<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use LogicException;

/**
 * P1-21 part 2 (J29-5/8): which rows of every registered table belong to ONE account or ONE store, and the order the
 * tables must be written (parents first) or deleted (children first). Read by export, delete and restore.
 *
 *  - account: every tenant row with that account_id or one of the account's stores, the account's users (the owner),
 *    the owner's user-level rows (`owned`) and rows of a parent in scope (`child`); plus the `accounts` row itself.
 *  - store: every tenant row of the store and the store row; account-level, owner and platform rows are out of scope.
 *
 * Every query is built from one account id / store id — a row of another account is never selected.
 */
final class StoreDataScope
{
    /** @var list<int>|null */
    private ?array $stores = null;

    /** @var list<int>|null */
    private ?array $users = null;

    private function __construct(public readonly string $type, public readonly int $id) {}

    public static function account(int $accountId): self
    {
        return new self('account', $accountId);
    }

    public static function store(int $storeId): self
    {
        return new self('store', $storeId);
    }

    public static function of(string $type, int $id): self
    {
        return match ($type) {
            'account' => self::account($id),
            'store' => self::store($id),
            default => throw new InvalidArgumentException("unknown store-data scope {$type}"),
        };
    }

    public function label(): string
    {
        return $this->type.':'.$this->id;
    }

    /** @return list<int> */
    public function storeIds(Connection $db): array
    {
        return $this->stores ??= $this->type === 'store'
            ? [$this->id]
            : array_map('intval', $db->table('stores')->where('account_id', $this->id)->orderBy('id')->pluck('id')->all());
    }

    /** @return list<int> the account's users (its owner) — none for a store scope */
    public function userIds(Connection $db): array
    {
        if ($this->type === 'store') {
            return [];
        }
        if ($this->users === null) {
            $ids = $db->table('users')->where('account_id', $this->id)->pluck('id')->all();
            $owner = $db->table('accounts')->where('id', $this->id)->value('owner_user_id');
            if ($owner !== null) {
                $ids[] = $owner;
            }
            $this->users = array_values(array_unique(array_map('intval', $ids)));
        }

        return $this->users;
    }

    /** The rows of $table in this scope, or null when the table has none by definition. */
    public function query(Connection $db, StoreTable $table): ?Builder
    {
        $name = $table->table;

        if ($table->kind === 'platform') {
            return null;
        }
        if ($table->kind === 'owned') {
            $users = $this->userIds($db);
            if ($users === [] || $table->scopeColumn === null) {
                return null;
            }
            $q = $db->table($name)->whereIn($table->scopeColumn, $users);
            if ($table->scopeWhere !== null) {
                ($table->scopeWhere)($q);
            }

            return $q;
        }
        if ($table->kind === 'child') {
            $parent = $table->parentTable !== null ? StoreDataRegistry::get($table->parentTable) : null;
            $parentQuery = $parent !== null ? $this->query($db, $parent) : null;
            if ($parentQuery === null || $table->scopeColumn === null) {
                return null;
            }

            return $db->table($name)->whereIn($table->scopeColumn, $parentQuery->select($parent->table.'.id'));
        }

        if ($name === 'stores') {
            return $this->type === 'store' ? $db->table('stores')->where('id', $this->id) : $db->table('stores')->where('account_id', $this->id);
        }
        if ($name === 'users') {
            return $this->type === 'account' ? $db->table('users')->where('account_id', $this->id) : null;
        }

        $meta = TenantTables::get($name);
        $hasStore = (bool) ($meta['store'] ?? false);
        $hasAccount = (bool) ($meta['account'] ?? false);
        if ($this->type === 'store') {
            return $hasStore ? $db->table($name)->where('store_id', $this->id) : null;
        }
        $stores = $this->storeIds($db);

        return $db->table($name)->where(function (Builder $q) use ($hasStore, $hasAccount, $stores): void {
            if ($hasAccount) {
                $q->where('account_id', $this->id);
            }
            if ($hasStore && $stores !== []) {
                $q->orWhereIn('store_id', $stores);
            }
            if (! $hasAccount && (! $hasStore || $stores === [])) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * The registered tables this scope can hold rows of, parents first (foreign keys of the live schema, plus: every
     * store row after `stores`, owner rows after `users`, a child after its parent). Delete in reverse.
     *
     * @return list<StoreTable>
     */
    public function orderedTables(Connection $db): array
    {
        $tables = array_filter(
            StoreDataRegistry::tables(),
            fn (StoreTable $t) => $t->kind !== 'platform' && ($this->type === 'account' || $t->kind === 'tenant'),
        );
        $schema = $db->getSchemaBuilder();
        $deps = [];
        foreach ($tables as $name => $table) {
            $deps[$name] = [];
            foreach ($schema->getForeignKeys($name) as $fk) {
                $deps[$name][] = (string) $fk['foreign_table'];
            }
            $meta = TenantTables::get($name);
            if (($meta['store'] ?? false) && $name !== 'stores') {
                $deps[$name][] = 'stores';
            }
            if ($name === 'stores' || $table->kind === 'owned') {
                $deps[$name][] = 'users';
            }
            if ($table->kind === 'child' && $table->parentTable !== null) {
                $deps[$name][] = $table->parentTable;
            }
            $deps[$name] = array_values(array_unique(array_filter($deps[$name], fn (string $d) => $d !== $name && isset($tables[$d]))));
        }

        $ordered = [];
        while ($deps !== []) {
            $ready = array_keys(array_filter($deps, fn (array $d) => array_diff($d, array_keys($ordered)) === []));
            if ($ready === []) {
                throw new LogicException('store-data tables have a reference cycle: '.implode(', ', array_keys($deps)));
            }
            sort($ready);
            foreach ($ready as $name) {
                $ordered[$name] = $tables[$name];
                unset($deps[$name]);
            }
        }

        return array_values($ordered);
    }
}
