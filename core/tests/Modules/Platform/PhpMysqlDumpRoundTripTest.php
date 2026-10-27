<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Ops\PhpMysqlDump;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * P6-119a: the PHP-only dumper round-trips on MySQL. Works ONLY in two scratch schemas derived from the test
 * database name (`<test db>_p6119a_src` / `_dst`); the test database itself is never dumped into or dropped.
 */
#[Group('mysql')]
class PhpMysqlDumpRoundTripTest extends TestCase
{
    private const SUFFIXES = ['_p6119a_src', '_p6119a_dst'];

    private string $gz = '';

    protected function tearDown(): void
    {
        DB::purge('p6131_limited');
        DB::purge('p6131_owner_dst');
        foreach (self::SUFFIXES as $suffix) {
            $this->dropScratch($suffix);
        }
        if ($this->gz !== '') {
            @unlink($this->gz);
        }
        parent::tearDown();
    }

    public function test_dump_and_restore_round_trip_rows_binary_nulls_utf8mb4_fks_and_views(): void
    {
        $src = $this->scratch('_p6119a_src');
        $dst = $this->scratch('_p6119a_dst');

        // `a_child` sorts before its parent: the load only works because FOREIGN_KEY_CHECKS=0.
        $src->statement('CREATE TABLE z_parent (id INT PRIMARY KEY, name VARCHAR(191) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $src->statement('CREATE TABLE a_child (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NOT NULL,
            label TEXT NULL,
            amount DECIMAL(12,3) NULL,
            payload LONGBLOB NULL,
            flags BIT(5) NULL,
            meta JSON NULL,
            label_len INT GENERATED ALWAYS AS (CHAR_LENGTH(label)) VIRTUAL,
            created_at DATETIME(6) NULL,
            CONSTRAINT a_child_parent FOREIGN KEY (parent_id) REFERENCES z_parent (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $src->statement('CREATE TABLE empty_one (id INT PRIMARY KEY) ENGINE=InnoDB');
        $src->statement('CREATE VIEW v_children AS SELECT c.id, p.name FROM a_child c JOIN z_parent p ON p.id = c.parent_id');
        $src->statement('CREATE VIEW a_view_of_view AS SELECT COUNT(*) AS n FROM v_children');

        $src->table('z_parent')->insert([
            ['id' => 1, 'name' => "مطعم 'الأول' \\ \"q\" 😀"],
            ['id' => 2, 'name' => null],
        ]);

        mt_srand(119);
        $rows = [];
        for ($i = 1; $i <= 1500; $i++) {
            $bytes = '';
            for ($b = 0; $b < 700; $b++) {
                $bytes .= chr(mt_rand(0, 255));
            }
            $rows[] = [
                'parent_id' => $i % 2 + 1,
                'label' => $i % 7 === 0 ? null : "row {$i}\nline two;\r\t-- not a comment; 'x' `y` 🍕 ".str_repeat('ج', $i % 50),
                'amount' => $i % 5 === 0 ? null : ($i * 1.125).'',
                'payload' => $i % 11 === 0 ? null : ($i % 13 === 0 ? '' : "\0".$bytes."\0'\\"),
                'flags' => $i % 3 === 0 ? null : DB::raw("b'1".($i % 2)."101'"),
                'meta' => $i % 4 === 0 ? null : json_encode(['i' => $i, 's' => 'ü"😀'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2026-10-13 12:34:56.'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ];
            if (count($rows) === 250) {
                $src->table('a_child')->insert($rows);
                $rows = [];
            }
        }

        $this->gz = tempnam(sys_get_temp_dir(), 'p6119a').'.sql.gz';
        (new PhpMysqlDump($src->getPdo()))->dumpTo($this->gz);

        $this->assertTrue(PhpMysqlDump::gzipComplete($this->gz));
        $sql = (string) gzdecode((string) file_get_contents($this->gz));
        $this->assertStringContainsString('-- Dump completed', substr($sql, -200));
        $this->assertGreaterThan(1, substr_count($sql, 'INSERT INTO `a_child`'), 'large table must be chunked');
        $this->assertStringNotContainsString('DEFINER=', $sql);

        $statements = (new PhpMysqlDump($dst->getPdo()))->restoreFrom($this->gz);
        $this->assertGreaterThan(10, $statements);

        foreach (['z_parent', 'a_child', 'empty_one'] as $table) {
            $this->assertSame($this->snapshot($src, $table), $this->snapshot($dst, $table), "table {$table} differs after the round trip");
        }
        $this->assertSame(1500, $dst->table('a_child')->count());
        $this->assertEquals(
            $src->select('SELECT * FROM v_children ORDER BY id'),
            $dst->select('SELECT * FROM v_children ORDER BY id'),
        );
        $this->assertEquals(1500, $dst->selectOne('SELECT n FROM a_view_of_view')->n);

        // AUTO_INCREMENT carried over and FK enforced again after the load
        $dst->table('a_child')->insert(['parent_id' => 1, 'label' => 'next']);
        $this->assertSame(1501, (int) $dst->table('a_child')->max('id'));
        $this->expectException(QueryException::class);
        $dst->table('a_child')->insert(['parent_id' => 999, 'label' => 'orphan']);
    }

    public function test_a_truncated_dump_is_detected(): void
    {
        $src = $this->scratch('_p6119a_src');
        $src->statement('CREATE TABLE t (id INT PRIMARY KEY, s VARCHAR(50))');
        $src->table('t')->insert(['id' => 1, 's' => 'x']);

        $this->gz = tempnam(sys_get_temp_dir(), 'p6119a').'.sql.gz';
        (new PhpMysqlDump($src->getPdo()))->dumpTo($this->gz);
        $full = (string) file_get_contents($this->gz);
        file_put_contents($this->gz, substr($full, 0, -4));

        $this->assertFalse(PhpMysqlDump::gzipComplete($this->gz));
    }

    public function test_triggers_routines_and_events_round_trip_without_definer(): void
    {
        $this->scratch('_p6119a_src');
        $this->scratch('_p6119a_dst');
        // binary logging is on (as on most real servers): only an account allowed to create stored programs can
        // create triggers/functions, so this test works through the runner-provided owner account
        $src = $this->limitedConnection('OWNER', '_p6119a_src');
        $dst = $this->limitedConnection('OWNER', '_p6119a_dst', 'p6131_owner_dst');

        $src->statement('CREATE TABLE items (id INT PRIMARY KEY, qty INT NOT NULL, note VARCHAR(100) NULL)');
        $src->statement('CREATE TABLE audit (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, what VARCHAR(20))');
        $src->table('items')->insert([['id' => 1, 'qty' => 5, 'note' => 'a;b'], ['id' => 2, 'qty' => 7, 'note' => null]]);
        $src->table('audit')->insert(['item_id' => 1, 'what' => 'seed']);
        // a body with `;`, quotes inside a comment and a string, and a `#` comment
        $src->unprepared("CREATE TRIGGER items_bi BEFORE INSERT ON items FOR EACH ROW BEGIN
            -- don't let qty go negative; clamp it
            IF NEW.qty < 0 THEN SET NEW.qty = 0; END IF; # it's fine
            INSERT INTO audit (item_id, what) VALUES (NEW.id, 'ins;\"x\"');
        END");
        $src->unprepared('CREATE FUNCTION double_it(x INT) RETURNS INT DETERMINISTIC NO SQL BEGIN /* a ; b */ RETURN x * 2; END');
        $src->unprepared('CREATE PROCEDURE bump(IN delta INT) MODIFIES SQL DATA BEGIN UPDATE items SET qty = qty + delta; SELECT COUNT(*) FROM items; END');
        $src->statement('CREATE VIEW v_doubled AS SELECT id, double_it(qty) AS d FROM items');
        $src->unprepared("CREATE EVENT nightly ON SCHEDULE EVERY 1 DAY STARTS '2030-01-01 00:00:00' DISABLE DO DELETE FROM audit WHERE what = 'old'");

        $this->gz = tempnam(sys_get_temp_dir(), 'p6131').'.sql.gz';
        (new PhpMysqlDump($src->getPdo()))->dumpTo($this->gz);
        $sql = (string) gzdecode((string) file_get_contents($this->gz));
        $this->assertDoesNotMatchRegularExpression('/DEFINER\s*=/i', $sql);
        foreach (['TRIGGER', 'FUNCTION', 'PROCEDURE', 'EVENT'] as $kind) {
            $this->assertStringContainsString('DROP '.$kind.' IF EXISTS', $sql);
        }
        // order: data, then triggers, then routines, then views, then events
        $this->assertLessThan(strpos($sql, 'DROP TRIGGER'), strrpos($sql, 'INSERT INTO `audit`'));
        $this->assertLessThan(strpos($sql, 'DROP FUNCTION'), strpos($sql, 'DROP TRIGGER'));
        $this->assertLessThan(strpos($sql, 'DROP VIEW'), strpos($sql, 'DROP FUNCTION'));
        $this->assertLessThan(strpos($sql, 'DROP EVENT'), strpos($sql, 'DROP VIEW'));
        foreach (PhpMysqlDump::statements(gzopen($this->gz, 'rb')) as $statement) {
            $this->assertStringStartsNotWith('DELIMITER', strtoupper($statement));
        }

        (new PhpMysqlDump($dst->getPdo()))->restoreFrom($this->gz);

        // the trigger did NOT fire during the load: audit has exactly the source rows
        $this->assertSame($this->snapshot($src, 'audit'), $this->snapshot($dst, 'audit'));
        $this->assertSame($this->snapshot($src, 'items'), $this->snapshot($dst, 'items'));

        $dst->table('items')->insert(['id' => 3, 'qty' => -4]);
        $this->assertSame(0, (int) $dst->table('items')->where('id', 3)->value('qty'));
        $this->assertSame('ins;"x"', $dst->table('audit')->where('item_id', 3)->value('what'));
        $this->assertSame(14, (int) $dst->selectOne('SELECT double_it(7) AS v')->v);
        $this->assertEquals(14, $dst->selectOne('SELECT d FROM v_doubled WHERE id = 2')->d);
        $call = $dst->getPdo()->query('CALL bump(10)');
        do {
            $call->fetchAll();
        } while ($call->nextRowset());
        $call->closeCursor();
        $this->assertSame(17, (int) $dst->table('items')->where('id', 2)->value('qty'));
        $event = $dst->selectOne("SELECT STATUS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'nightly'");
        $this->assertNotNull($event);
        $this->assertSame('DISABLED', $event->STATUS);
        $this->assertSame('DAY', $event->INTERVAL_FIELD);
        $this->assertSame(1, $dst->selectOne('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->n);
        $this->assertSame(2, $dst->selectOne('SELECT COUNT(*) AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->n);
    }

    public function test_an_account_without_trigger_or_event_privilege_fails_loudly_and_writes_nothing(): void
    {
        $src = $this->scratch('_p6119a_src');
        $src->statement('CREATE TABLE t (id INT PRIMARY KEY)');
        $limited = $this->limitedConnection('NOPRIV');

        $this->gz = tempnam(sys_get_temp_dir(), 'p6131').'.sql.gz';
        @unlink($this->gz);
        try {
            (new PhpMysqlDump($limited->getPdo()))->dumpTo($this->gz);
            $this->fail('the dump must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('backup refused', $e->getMessage());
            $this->assertStringContainsString('TRIGGER and EVENT', $e->getMessage());
        }
        $this->assertSame('', (string) gzdecode((string) file_get_contents($this->gz)), 'nothing may be written');
    }

    public function test_a_routine_whose_body_the_account_cannot_read_fails_loudly(): void
    {
        $src = $this->scratch('_p6119a_src');
        $src->statement('CREATE TABLE t (id INT PRIMARY KEY)');
        $src->unprepared('CREATE PROCEDURE secret_proc() BEGIN SELECT 1; END');
        // TRIGGER + EVENT granted, the procedure is visible through EXECUTE, but its body belongs to another definer
        $limited = $this->limitedConnection('EXEC');

        $this->gz = tempnam(sys_get_temp_dir(), 'p6131').'.sql.gz';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/backup refused: .*PROCEDURE secret_proc/');
        (new PhpMysqlDump($limited->getPdo()))->dumpTo($this->gz);
    }

    /**
     * A second, limited MySQL account. The app's test account has no CREATE USER privilege, so the runner script
     * (the runner's P6-131 MySQL script) creates it as the server admin and passes it in P6131_<KIND>_USER / _PASSWORD:
     *   NOPRIV: SELECT, SHOW VIEW, LOCK TABLES on the `_p6119a_src` scratch schema (no TRIGGER, no EVENT);
     *   EXEC:   SELECT, SHOW VIEW, TRIGGER, EVENT, EXECUTE on it (a routine is visible, its body is not);
     *   OWNER:  ALL on both scratch schemas + SUPER (creating triggers/functions while binary logging is on).
     */
    private function limitedConnection(string $kind, string $suffix = '_p6119a_src', string $key = 'p6131_limited'): Connection
    {
        $user = (string) getenv('P6131_'.$kind.'_USER');
        $password = (string) getenv('P6131_'.$kind.'_PASSWORD');
        if ($user === '') {
            $this->markTestSkipped('P6131_'.$kind.'_USER not set: needs a limited MySQL account (see the P6-131 MySQL script on the runner)');
        }
        config(['database.connections.'.$key => array_merge(config('database.connections.mysql'), [
            'database' => $this->scratchName($suffix), 'prefix' => '', 'username' => $user, 'password' => $password,
        ])]);
        DB::purge($key);

        return DB::connection($key);
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(Connection $c, string $table): array
    {
        $cols = array_map(
            fn ($r) => (string) $r->COLUMN_NAME,
            $c->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table])
        );
        $select = implode(', ', array_map(fn ($col) => 'HEX(`'.$col.'`) AS `'.$col.'`', $cols));

        return array_map(fn ($r) => (array) $r, $c->select("SELECT {$select} FROM `{$table}` ORDER BY 1"));
    }

    /** A fresh scratch schema next to the test database, with its own connection. */
    private function scratch(string $suffix): Connection
    {
        $name = $this->scratchName($suffix);
        DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
        DB::statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $key = 'p6119a'.$suffix;
        config(['database.connections.'.$key => array_merge(config('database.connections.mysql'), ['database' => $name, 'prefix' => ''])]);
        DB::purge($key);

        return DB::connection($key);
    }

    private function dropScratch(string $suffix): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::purge('p6119a'.$suffix);
        DB::statement('DROP DATABASE IF EXISTS `'.$this->scratchName($suffix).'`');
    }

    private function scratchName(string $suffix): string
    {
        $live = (string) config('database.connections.mysql.database');
        $name = $live.$suffix;
        // fence: only ever a derived scratch name, never the test database itself
        $this->assertTrue($live !== '' && in_array($suffix, self::SUFFIXES, true) && $name !== $live && str_ends_with($name, $suffix));

        return $name;
    }
}
