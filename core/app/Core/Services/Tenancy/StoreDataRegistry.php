<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreDataPolicy;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use LogicException;

/**
 * P1-21 (J29-8): the store-data registry — every store- and account-owned table with its policy per operation,
 * declared by the module that owns it ({@see StoreData}, registered from the module's service provider; Core may
 * not import module classes). One engine reads it for transfer and duplicate ({@see StoreDataEngine}); export,
 * delete and restore follow (P1-21 part 2). tests/Architecture/StoreDataRegistryTest fails when a table with
 * store_id/account_id is unregistered, declared by another module, or copied without its references handled.
 *
 * A boot-time registry of declarations, not database rows (like {@see TenantJobClasses}): a provider registers
 * once per application; registering a module again replaces its declaration.
 */
final class StoreDataRegistry
{
    /** @var array<string, StoreData> module => declaration */
    private static array $modules = [];

    /** @var array<string, StoreTable>|null */
    private static ?array $tables = null;

    public static function register(string $module, StoreData $data): void
    {
        self::$modules[$module] = $data;
        self::$tables = null;
    }

    /** Drop a module's declaration (tests that register a temporary one). */
    public static function forget(string $module): void
    {
        unset(self::$modules[$module]);
        self::$tables = null;
    }

    /** @return array<string, StoreData> */
    public static function modules(): array
    {
        return self::$modules;
    }

    /**
     * Every declared table, by name.
     *
     * @return array<string, StoreTable>
     */
    public static function tables(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }
        $tables = [];
        foreach (self::$modules as $module => $data) {
            foreach ($data->tables() as $table) {
                if (isset($tables[$table->table]) && $tables[$table->table]->module !== $module) {
                    throw new LogicException("store-data table {$table->table} declared by {$tables[$table->table]->module} and {$module}");
                }
                $table->module = $module;
                $table->validate();
                $tables[$table->table] = $table;
            }
        }
        ksort($tables);

        return self::$tables = $tables;
    }

    public static function get(string $table): ?StoreTable
    {
        return self::tables()[$table] ?? null;
    }

    /**
     * Tables with the given transfer or duplicate policy.
     *
     * @return array<string, StoreTable>
     */
    public static function where(StoreDataPolicy $policy): array
    {
        return array_filter(self::tables(), fn (StoreTable $t) => $t->transfer === $policy || $t->duplicate === $policy);
    }

    /** Is this store setting an owner's connection (any module says so)? Reset on transfer, never copied. */
    public static function isOwnerSetting(string $name): bool
    {
        foreach (self::$modules as $data) {
            if ($data->isOwnerSetting($name)) {
                return true;
            }
        }

        return false;
    }
}
