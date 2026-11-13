<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataEraser;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Core\Support\Sql;
use Tests\TestCase;

/**
 * P4-07 — "account deletion really removes data" (Privacy §8, DPA 10.2, Terms 7.6): after the 30-day window the
 * owner's purge leaves NO row of the account in any tenant table except the records the law makes us keep, and no
 * other account loses a row. Every registered table (Community + the installed edition: the store-data registry, the
 * same list the export and the hard erasure use) gets one synthetic row for the deleted account and one for another
 * account; after the purge each table is counted in the deleted account's scope.
 *
 * Kept, by name ({@see AccountDeletionService::RETAINED_TABLES}): invoices, payment and tax records (Privacy §8: 6 years)
 * with the store's customers' personal data stripped, the account / owner / store rows they point at (anonymised),
 * and the deletion request itself (records of rights requests, 6 years). Anything else left is a bug.
 */
class AccountDeletionPurgeCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> table => why a synthetic row could not be written (schema constraints) */
    private array $unseeded = [];

    /** @var list<string> absolute paths of the files the test wrote (removed in tearDown) */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Every file column the registry knows (plus erase-only archives) gets a real file for each row of the account.
     *
     * @return array<string, string> absolute path => "table.column"
     */
    private function attachFiles(int $accountId, string $tag): array
    {
        $scope = StoreDataScope::account($accountId);
        $out = [];
        foreach (StoreDataRegistry::tables() as $table) {
            if ($table->kind === 'platform' || ! StoreDataEraser::ownsFiles($table) || ($q = $scope->query(DB::connection(), $table)) === null) {
                continue;
            }
            foreach ((clone $q)->get() as $row) {
                $row = (array) $row;
                $update = [];
                foreach ($table->files + (StoreDataEraser::ERASE_ONLY_FILES[$table->table] ?? []) as $column => $disk) {
                    $path = "p407/{$tag}/{$table->table}-{$column}-{$row['id']}.bin";
                    Storage::disk($disk)->put($path, 'x');
                    $update[$column] = $path;
                    $out[Storage::disk($disk)->path($path)] = "{$table->table}.{$column}";
                }
                foreach ($table->media as $column => $locations) {
                    $name = "p407-{$tag}-{$table->table}-".str_replace('[]', 'list', $column)."-{$row['id']}.jpg";
                    $abs = StoreDataExporter::mediaPath($locations[0], $row, $name);
                    @mkdir(dirname($abs), 0775, true);
                    file_put_contents($abs, 'x');
                    $this->written[] = $abs;
                    $list = str_ends_with($column, '[]');
                    $update[$list ? substr($column, 0, -2) : $column] = $list ? json_encode([$name]) : $name;
                    $out[$abs] = "{$table->table}.{$column}";
                }
                DB::table($table->table)->where('id', $row['id'])->update($update);
            }
        }

        return $out;
    }

    private function owner(string $tag): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'name' => 'Owner '.$tag,
            'username' => 'own'.$tag.Str::lower(Str::random(5)), 'email' => $tag.Str::lower(Str::random(6)).'@purge.test', 'password' => Hash::make('pw')]);
    }

    private function store(User $owner, string $title): Store
    {
        return Store::create(['user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => $title,
            'sub_title' => 't', 'description' => 'Private description', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 Private St', 'phone' => '0555000111']);
    }

    /**
     * One row per registered table for this account (parents first, children pointing at the parent's row).
     *
     * @return array<string, int> table => id
     */
    private function seedAccount(User $owner, Store $store): array
    {
        $ids = [];
        $accountId = (int) $owner->fresh()->account_id;
        $scope = StoreDataScope::account($accountId);
        Sql::withoutForeignKeyChecks(function () use ($scope, $owner, $store, $accountId, &$ids): void {
            $queue = array_filter($scope->orderedTables(DB::connection()),
                fn ($t) => ! in_array($t->table, ['stores', 'users', 'accounts', 'account_deletions'], true)); // the deletion request is made by the service
            // a few passes: a reference the schema does not declare is not ordered, so a child may come before its parent
            for ($pass = 0; $pass < 4 && $queue !== []; $pass++) {
                $retry = [];
                foreach ($queue as $table) {
                    $row = $this->rowFor($table, $owner, $store, $accountId, $ids);
                    if ($row === null) {
                        $retry[] = $table;

                        continue;
                    }
                    try {
                        DB::transaction(function () use ($table, $row, &$ids): void {
                            // the key is not always `id` (upgrades.upgrade_id); a table without one is inserted plainly
                            $key = collect(Schema::getColumns($table->table))->firstWhere('auto_increment', true)['name'] ?? null;
                            if ($key === null) {
                                DB::table($table->table)->insert($row);
                            } else {
                                $ids[$table->table] = (int) DB::table($table->table)->insertGetId($row, $key);
                            }
                        });
                        unset($this->unseeded[$table->table]);
                    } catch (\Throwable $e) {
                        $this->unseeded[$table->table] = Str::limit($e->getMessage(), 600);
                        $retry[] = $table;
                    }
                }
                $queue = $retry;
            }
        });

        return $ids;
    }

    /**
     * @param  array<string, int>  $ids
     * @return array<string, mixed>|null
     */
    private function rowFor(StoreTable $table, User $owner, Store $store, int $accountId, array $ids): ?array
    {
        $row = [];
        $fks = [];
        foreach (Schema::getForeignKeys($table->table) as $fk) {
            if (count($fk['columns']) === 1) {
                $fks[$fk['columns'][0]] = (string) $fk['foreign_table'];
            }
        }
        foreach (TenantTables::parents()[$table->table] ?? [] as [$column, $parent]) {
            $fks[$column] ??= $parent; // references the schema does not declare (no FK constraint)
        }
        foreach (Schema::getColumns($table->table) as $col) {
            $name = $col['name'];
            if ($col['auto_increment'] || ($col['generation'] ?? null) !== null) {
                continue;
            }
            $row[$name] = match (true) {
                $name === 'store_id' => $store->id,
                $name === 'account_id' => $accountId,
                $table->scopeColumn === $name && $table->kind === 'owned' => $owner->id,
                $table->scopeColumn === $name && $table->kind === 'child' => $ids[$table->parentTable] ?? null,
                $name === 'user_id' || $name === 'owner_id' || $name === 'owner_user_id' => $owner->id,
                // a reference points at this account's own row of that table (never another account's)
                isset($fks[$name]) => $fks[$name] === 'stores' ? $store->id : ($fks[$name] === 'users' ? $owner->id : ($ids[$fks[$name]] ?? null)),
                ! $col['nullable'] && $col['default'] === null => $this->checkValue($table->table, $name) ?? $this->value($col),
                preg_match('/(^|_)(email|slug|code|token|key|uuid)$/', $name) === 1 && preg_match('/char|text|string|uuid/', strtolower((string) $col['type_name'])) === 1 => $col['type_name'] === 'uuid' ? (string) Str::uuid() : substr('p'.Str::lower(Str::random(20)), 0, $this->length($col, 21)),
                default => null,
            };
            if ($row[$name] === null && ($col['nullable'] || $col['default'] !== null)) {
                unset($row[$name]);
            }
        }
        foreach ($fks as $column => $foreign) {
            if (($row[$column] ?? null) === null && ! $this->nullable($table->table, $column)) {
                $this->unseeded[$table->table] = "references {$foreign}, not seeded";

                return null;
            }
        }
        if ($table->kind === 'child' && $table->scopeColumn !== null && ($row[$table->scopeColumn] ?? null) === null) {
            $this->unseeded[$table->table] = 'parent '.$table->parentTable.' not seeded';

            return null;
        }

        return $row;
    }

    /** @var array<string, list<string>> table => CHECK clauses */
    private array $checks = [];

    /** The first value a CHECK (col IN (…)) constraint allows (checkedString columns), if any. */
    private function checkValue(string $table, string $column): ?string
    {
        $this->checks[$table] ??= match (DB::getDriverName()) {
            'sqlite' => [(string) DB::table('sqlite_master')->where('name', $table)->value('sql')],
            'pgsql' => array_map(fn ($r) => (string) $r->def, DB::select("select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass and contype = 'c'", [$table])),
            default => array_map(fn ($r) => (string) $r->clause, DB::select('select c.CHECK_CLAUSE as clause from information_schema.CHECK_CONSTRAINTS c join information_schema.TABLE_CONSTRAINTS t on t.CONSTRAINT_NAME = c.CONSTRAINT_NAME and t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA where t.TABLE_NAME = ? and t.TABLE_SCHEMA = database()', [$table])),
        };
        foreach ($this->checks[$table] as $clause) {
            $clause = str_replace('\\', '', $clause); // MySQL reports the literals as _utf8mb4\'asset\'
            $q = preg_quote($column, '/');
            if (preg_match('/\\b'.$q.'\\b[^\']*?\\b(?:in|any)\\b[^\']*\'([^\']+)\'/i', $clause, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function nullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $col) {
            if ($col['name'] === $column) {
                return (bool) $col['nullable'] || $col['default'] !== null;
            }
        }

        return true;
    }

    /**
     * The column's declared length (char(3), varchar(16), …), capped at $max — MySQL and PostgreSQL refuse longer
     * values, SQLite does not.
     *
     * @param  array<string, mixed>  $col
     */
    private function length(array $col, int $max): int
    {
        $full = strtolower((string) ($col['type'] ?? $col['type_name']));

        return max(1, min($max, preg_match('/\((\d+)\)/', $full, $l) === 1 ? (int) $l[1] : $max));
    }

    /** @param array<string, mixed> $col */
    private function value(array $col): mixed
    {
        $type = strtolower((string) $col['type_name']);
        $full = strtolower((string) ($col['type'] ?? $type));
        if (preg_match("/^enum\\('([^']*)'/", $full, $m) === 1) {
            return $m[1];
        }
        $len = $this->length($col, 40);

        return match (true) {
            str_contains($type, 'bool') => false,
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int2', 'int4', 'int8'], true) => 1,
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'double precision'], true) => 0,
            in_array($type, ['date'], true) => now()->toDateString(),
            str_contains($type, 'time') => now(),
            in_array($type, ['json', 'jsonb'], true) => '{}',
            $type === 'uuid' => (string) Str::uuid(),
            in_array($type, ['blob', 'bytea', 'binary', 'varbinary', 'longblob'], true) => '',
            default => substr('p'.Str::lower(Str::random(39)), 0, max(1, min($len, 40))),
        };
    }

    /** @return array<string, int> rows per table in the account's scope */
    private function inScope(int $accountId): array
    {
        $scope = StoreDataScope::account($accountId);
        $out = [];
        foreach ($scope->orderedTables(DB::connection()) as $table) {
            $q = $scope->query(DB::connection(), $table);
            if ($q !== null && ($n = $q->count()) > 0) {
                $out[$table->table] = $n;
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, int> */
    private function totals(): array
    {
        $out = [];
        foreach (array_keys(StoreDataRegistry::tables()) as $name) {
            $out[$name] = DB::table($name)->count();
        }

        return $out;
    }

    public function test_after_the_window_no_row_of_the_account_is_left_except_the_retained_records_and_no_other_account_loses_one(): void
    {
        $victim = $this->owner('v');
        $victimStores = [$this->store($victim, 'Victim A'), $this->store($victim, 'Victim B')];
        $other = $this->owner('o');
        $otherStore = $this->store($other, 'Other');
        foreach ($victimStores as $store) {
            $this->seedAccount($victim, $store);
        }
        $this->seedAccount($other, $otherStore);
        $accountId = (int) $victim->fresh()->account_id;
        $otherAccount = (int) $other->fresh()->account_id;
        // security events (kept 12 months) beside an ordinary activity row (deleted at the purge), for both accounts
        foreach ([[$accountId, $victim, $victimStores[0]], [$otherAccount, $other, $otherStore]] as [$acc, $u, $st]) {
            foreach (['auth.passkey_login', '2fa.disable', 'admin.impersonate', 'menu.update', 'authx.not_security'] as $action) {
                DB::table('activity_logs')->insert(['account_id' => $acc, 'actor_user_id' => $u->id, 'store_id' => $st->id, 'action' => $action, 'created_at' => now()]);
            }
        }
        $seeded = $this->inScope($accountId);
        $this->assertGreaterThan(150, count($seeded), 'tables seeded for the deleted account; not seeded: '.json_encode($this->unseeded));
        // Every registered table has rows of the account, except those that only reference a platform catalogue the
        // fixture cannot fill (an app / theme of the marketplace, a shopper of another module).
        $never = array_values(array_diff(array_keys($this->unseeded), array_keys($seeded)));
        sort($never);
        $this->assertSame([], array_diff($never, ['app_installations', 'app_usage_records', 'customer_passkeys', 'theme_purchases']), 'tables the fixture could not seed: '.json_encode(array_intersect_key($this->unseeded, array_flip($never))));
        $victimFiles = $this->attachFiles($accountId, 'v');
        $otherFiles = $this->attachFiles($otherAccount, 'o');
        $this->assertGreaterThan(10, count($victimFiles));
        $otherBefore = $this->inScope($otherAccount);
        $totalsBefore = $this->totals();

        $service = app(AccountDeletionService::class);
        $service->request($victim->fresh());
        $this->travel(AccountDeletionService::GRACE_DAYS + 1)->days();
        $service->purge(AccountDeletion::where('user_id', $victim->id)->sole()); // surfaces errors (the command logs them)
        $this->assertSame(AccountDeletion::PURGED, AccountDeletion::where('user_id', $victim->id)->value('status'));

        // 1. Nothing of the account is left except the retained records and (owner decision 2026-10-07) its security
        // logs: sign-ins and security events, kept SECURITY_LOG_DAYS.
        $kept = [...AccountDeletionService::RETAINED_TABLES, ...AccountDeletionService::SECURITY_LOG_TABLES, 'activity_logs'];
        $left = $this->inScope($accountId);
        $leftovers = array_diff_key($left, array_flip($kept));
        $detail = [];
        foreach (array_keys($leftovers) as $name) {
            $t = StoreDataRegistry::get($name);
            $detail[$name] = $t ? StoreDataScope::account($accountId)->query(DB::connection(), $t)?->limit(3)->get()->map(fn ($r) => Str::limit(json_encode($r), 300))->all() : [];
        }
        $this->assertSame([], $leftovers, 'rows of the deleted account left behind (table => rows): '.json_encode($detail));
        $retainedSeeded = array_values(array_intersect([...array_keys($seeded), 'account_deletions'], $kept));
        sort($retainedSeeded);
        $this->assertSame($retainedSeeded, array_keys($left), 'exactly the retained records of the account are kept');
        $this->assertContains('login_audits', array_keys($left), 'the sign-ins are kept');
        $events = fn (int $acc) => DB::table('activity_logs')->where('account_id', $acc)->orderBy('action')->pluck('action')->all();
        $this->assertSame(['2fa.disable', 'admin.impersonate', 'auth.passkey_login'], $events($accountId), 'only the security events are kept');

        // 1b. Its files are gone too, except the receipts of the kept expenses (tax records); another account's stay.
        $keptFiles = [];
        foreach ($victimFiles as $abs => $owner) {
            if (is_file($abs)) {
                $keptFiles[] = $owner;
            }
        }
        sort($keptFiles);
        $this->assertSame(array_values(array_filter(['expenses.receipt_path'], fn ($c) => in_array($c, $victimFiles, true))), array_values(array_unique($keptFiles)), 'files of the deleted account left behind');
        foreach ($otherFiles as $abs => $owner) {
            $this->assertFileExists($abs, "another account's file ($owner) was deleted");
        }

        // 2. Another account's rows are all there.
        $this->assertSame($otherBefore, $this->inScope($otherAccount), 'another account lost rows');
        $deletedByTable = [];
        foreach ($this->totals() as $name => $n) {
            $deletedByTable[$name] = ($totalsBefore[$name] ?? 0) - $n;
        }
        foreach ($deletedByTable as $name => $gone) {
            $this->assertLessThanOrEqual(($seeded[$name] ?? 0), $gone, "$name: more rows deleted than the account had");
        }

        // 3. What is kept carries no personal data of the owner.
        $u = $victim->fresh();
        $this->assertSame('Deleted user', $u->name);
        $this->assertStringEndsWith('@deleted.invalid', (string) $u->email);
        foreach ($victimStores as $store) {
            $s = $store->fresh();
            $this->assertSame('Deleted store', $s->title);
            $this->assertSame('', (string) $s->address);
            $this->assertSame('', (string) $s->phone);
            $this->assertSame('', (string) $s->description);
        }
        $account = DB::table('accounts')->where('id', $accountId)->first();
        $this->assertNotNull($account, 'the account row is kept (the invoices point at it)');
        foreach ((array) $account as $col => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('Owner v', $value, "accounts.$col still names the owner");
                $this->assertStringNotContainsString((string) $victim->email, $value, "accounts.$col still has the e-mail");
            }
        }
        $other->refresh();
        $this->assertSame(1, (int) $other->status);
        $this->assertSame('Other', $otherStore->fresh()->title);

        // 4. The security logs go 12 months after the purge (accounts:prune-security-logs), once; a live account's stay.
        $otherLogs = [DB::table('login_audits')->whereIn('user_id', [$other->id])->count(), $events($otherAccount)];
        $this->travel(AccountDeletionService::SECURITY_LOG_DAYS - 2)->days();
        $this->artisan('accounts:prune-security-logs')->assertSuccessful();
        $this->assertArrayHasKey('login_audits', $this->inScope($accountId), 'not before 12 months');
        $this->travel(3)->days();
        $this->artisan('accounts:prune-security-logs')->assertSuccessful();
        $after = $this->inScope($accountId);
        $this->assertArrayNotHasKey('login_audits', $after);
        $this->assertSame([], $events($accountId));
        $this->assertSame(array_keys(array_diff_key($left, array_flip(['login_audits', 'activity_logs']))), array_keys($after), 'the tax records stay');
        $this->assertSame($otherLogs, [DB::table('login_audits')->whereIn('user_id', [$other->id])->count(), $events($otherAccount)]);
        $this->assertNotEmpty(AccountDeletion::where('user_id', $victim->id)->sole()->purge_report['security_logs_pruned_at'] ?? null);
        $this->assertSame([], app(AccountDeletionService::class)->pruneSecurityLogs(), 'pruned once');
    }
}
