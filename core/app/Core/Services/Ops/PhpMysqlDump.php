<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Ops;

use PDO;
use RuntimeException;

/**
 * P6-119a (SHARED-HOSTING F1): a PHP-only dump + restore for MySQL / MariaDB, for hosts where `proc_open` is
 * disabled or `mysqldump` is not installed (most shared hosting). PlatformDbBackup and PlatformDbRestoreCheck pick
 * it through DatabaseDump::usePhpFallback().
 *
 * Output is plain SQL, gzipped, with the same `-- Dump completed` trailer mysqldump writes, so the artifact name,
 * the completion gate, the prune glob and the off-box copy are unchanged.
 *
 *  - Memory stays bounded: rows are read through an UNBUFFERED query (one row in PHP at a time) and written as
 *    multi-row INSERTs capped at ~512 KB each.
 *  - One consistent snapshot (REPEATABLE READ + START TRANSACTION WITH CONSISTENT SNAPSHOT), as --single-transaction.
 *  - Binary columns (binary, varbinary, *blob, bit, geometry types) are written as hex literals; NULL stays NULL;
 *    text goes through PDO::quote() on a utf8mb4 connection, so newlines, quotes and 4-byte characters survive.
 *  - Generated columns are left out of the INSERTs (the server recomputes them).
 *  - FOREIGN_KEY_CHECKS=0 during the load, so table order does not matter; views come after every table, with the
 *    DEFINER clause removed (a shared-hosting user cannot create objects for another definer).
 *  - P6-131: triggers, stored procedures/functions and events are dumped too (the schema has none today; a future
 *    one must not be lost silently). Triggers come after ALL table data (so they never fire during the load),
 *    routines before the views (a view may call a stored function), events last. Each is wrapped in
 *    `DELIMITER ;;` … `DELIMITER ;` lines (so the mysql client loads the file too); statements() interprets those
 *    lines itself, so PDO::exec() only ever sees one plain CREATE. DEFINER is stripped; the object's own sql_mode
 *    (and an event's time_zone) is set around its CREATE.
 *  - If the account cannot read them (no TRIGGER / EVENT privilege on the schema, or a routine whose body is
 *    hidden), dumpTo() throws BEFORE writing anything: never an incomplete dump. Residual limit: MySQL hides a
 *    routine owned by ANOTHER account from a user with no privilege on it at all (no SHOW_ROUTINE / global SELECT);
 *    that cannot be detected from inside the account.
 */
final class PhpMysqlDump
{
    /** Max bytes of one INSERT statement (well under MySQL's default max_allowed_packet). */
    public const STATEMENT_BYTES = 524288;

    /** First line of every dump this class writes. */
    public const HEADER = '-- Juwri PHP dump (P6-119a)';

    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon',
        'geometrycollection', 'geomcollection'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Stream the whole database behind the PDO into a gzip file. Throws on any error; the caller deletes the partial.
     */
    public function dumpTo(string $gzPath): void
    {
        $out = @gzopen($gzPath, 'wb6');
        if ($out === false) {
            throw new RuntimeException('cannot open '.$gzPath.' for writing');
        }

        try {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('SET NAMES utf8mb4');
            $this->pdo->exec("SET SESSION time_zone = '+00:00'");
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            [$tables, $views] = $this->objects();
            $programs = $this->programObjects(); // throws before anything is written if they cannot all be read

            $this->write($out, self::HEADER."\n"
                ."/*!40101 SET NAMES utf8mb4 */;\n"
                ."SET time_zone = '+00:00';\n"
                ."SET FOREIGN_KEY_CHECKS = 0;\n"
                ."SET UNIQUE_CHECKS = 0;\n"
                ."SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($tables as $table) {
                $this->dumpTable($out, $table);
            }
            foreach ($programs['triggers'] as $object) {
                $this->writeProgram($out, $object);
            }
            foreach ($programs['routines'] as $object) {
                $this->writeProgram($out, $object);
            }
            foreach ($this->orderViews($views) as $view) {
                $this->dumpView($out, $view);
            }
            foreach ($programs['events'] as $object) {
                $this->writeProgram($out, $object);
            }

            $this->write($out, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n\n-- Dump completed on ".gmdate('Y-m-d H:i:s')."\n");
            $this->pdo->exec('COMMIT');
        } finally {
            gzclose($out);
        }
    }

    /**
     * Load a gzipped SQL dump (one this class wrote) into the database behind the PDO, one statement at a time.
     * Streams: never holds more than one statement in memory. Throws on the first failing statement.
     */
    public function restoreFrom(string $gzPath): int
    {
        $in = @gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('cannot open '.$gzPath.' for reading');
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $count = 0;
        try {
            foreach (self::statements($in) as $sql) {
                $this->pdo->exec($sql);
                $count++;
            }
        } finally {
            gzclose($in);
        }

        return $count;
    }

    /**
     * Split a SQL stream into statements on `;` outside quotes ('…', "…", `…`, with backslash escapes) and outside
     * comments. Whole-line `--` comments are dropped; `/*!… *\/` version comments are kept (MySQL runs them).
     *
     * @param  resource  $gz  a gzopen() handle
     * @return \Generator<int, string>
     */
    public static function statements($gz): \Generator
    {
        $buf = '';
        $delimiter = ';';
        $quote = null;      // current quote char, or null
        $escaped = false;
        $lineStart = true;
        $dropLine = false;  // a whole-line `--` comment between statements: dropped
        $comment = null;    // 'line' (`-- ` / `#` inside a statement) or 'block' (`/* … */`): kept, quotes ignored
        $blockFrom = 0;
        $directive = null;  // the rest of a `DELIMITER x` line, while it is read

        while (! gzeof($gz)) {
            $chunk = gzread($gz, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $c = $chunk[$i];

                if ($dropLine) {
                    if ($c === "\n") {
                        $dropLine = false;
                        $lineStart = true;
                    }

                    continue;
                }

                if ($directive !== null) {
                    if ($c !== "\n") {
                        $directive .= $c;

                        continue;
                    }
                    $delimiter = trim($directive);
                    if ($delimiter === '' || preg_match('/\s/', $delimiter) === 1) {
                        throw new RuntimeException('dump has an invalid DELIMITER line');
                    }
                    $directive = null;
                    $buf = '';
                    $lineStart = true;

                    continue;
                }

                if ($quote !== null) {
                    $buf .= $c;
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\' && $quote !== '`') {
                        $escaped = true;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($comment === 'line') {
                    $buf .= $c;
                    if ($c === "\n") {
                        $comment = null;
                        $lineStart = true;
                    }

                    continue;
                }
                if ($comment === 'block') {
                    $buf .= $c;
                    if ($c === '/' && strlen($buf) >= $blockFrom + 2 && $buf[strlen($buf) - 2] === '*') {
                        $comment = null;
                    }

                    continue;
                }

                if ($lineStart && $c === '-' && trim($buf) === '' && ($chunk[$i + 1] ?? '-') === '-') {
                    // a `--` comment line between statements
                    $dropLine = true;
                    $buf = '';

                    continue;
                }
                $lineStart = $c === "\n";

                if (($c === ' ' || $c === "\t") && strcasecmp(ltrim($buf), 'DELIMITER') === 0) {
                    $directive = '';

                    continue;
                }

                if (($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") && str_ends_with($buf, '--')) {
                    $comment = $c === "\n" ? null : 'line';
                } elseif ($c === '#') {
                    $comment = 'line';
                } elseif ($c === '*' && str_ends_with($buf, '/')) {
                    $comment = 'block';
                    $blockFrom = strlen($buf) + 1;
                } elseif ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                }
                $buf .= $c;

                if ($comment === null && $quote === null && str_ends_with($buf, $delimiter)) {
                    $sql = trim(substr($buf, 0, -strlen($delimiter)));
                    $buf = '';
                    if ($sql !== '') {
                        yield $sql;
                    }
                }
            }
        }

        if ($quote !== null || $comment === 'block') {
            throw new RuntimeException('dump ends inside a quoted value or comment: it is truncated');
        }
        if ($directive !== null || trim($buf) !== '') {
            throw new RuntimeException('dump ends with an unterminated statement: it is truncated');
        }
    }

    /**
     * True when the file is a COMPLETE gzip stream (PHP's gzread() does not notice a cut-off CRC/size trailer, so
     * this inflates incrementally and requires ZLIB_STREAM_END). The PHP-only twin of `gzip -t`.
     */
    public static function gzipComplete(string $gzPath): bool
    {
        $fh = @fopen($gzPath, 'rb');
        if ($fh === false) {
            return false;
        }
        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
        if ($ctx === false) {
            fclose($fh);

            return false;
        }
        $ok = true;
        while (! feof($fh)) {
            $data = fread($fh, 262144);
            if ($data === false) {
                $ok = false;
                break;
            }
            if ($data === '') {
                continue;
            }
            if (@inflate_add($ctx, $data, ZLIB_SYNC_FLUSH) === false) {
                $ok = false;
                break;
            }
        }
        fclose($fh);

        return $ok && inflate_get_status($ctx) === ZLIB_STREAM_END;
    }

    /** @return array{0: list<string>, 1: list<string>} base tables, views */
    private function objects(): array
    {
        $tables = [];
        $views = [];
        foreach ($this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (($row[1] ?? '') === 'VIEW') {
                $views[] = (string) $row[0];
            } else {
                $tables[] = (string) $row[0];
            }
        }
        sort($tables);
        sort($views);

        return [$tables, $views];
    }

    /** @param  resource  $out */
    private function dumpTable($out, string $table): void
    {
        $q = self::ident($table);
        $create = $this->pdo->query('SHOW CREATE TABLE '.$q)->fetch(PDO::FETCH_NUM);
        $this->write($out, "DROP TABLE IF EXISTS {$q};\n".((string) $create[1]).";\n\n");

        $columns = [];   // name => binary?
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$name, $type, $extra]) {
            if (stripos((string) $extra, 'GENERATED') !== false) {
                continue; // VIRTUAL / STORED generated: the server recomputes it
            }
            $columns[(string) $name] = in_array(strtolower((string) $type), self::BINARY_TYPES, true);
        }
        if ($columns === []) {
            return;
        }

        $select = [];
        foreach ($columns as $name => $binary) {
            $select[] = $binary ? 'HEX('.self::ident($name).')' : self::ident($name);
        }
        $prefix = 'INSERT INTO '.$q.' ('.implode(', ', array_map(self::ident(...), array_keys($columns))).') VALUES ';
        $binaryFlags = array_values($columns);

        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $rows = $this->pdo->query('SELECT '.implode(', ', $select).' FROM '.$q);
            $pending = '';
            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($row as $i => $v) {
                    if ($v === null) {
                        $values[] = 'NULL';
                    } elseif ($binaryFlags[$i]) {
                        $values[] = $v === '' ? "''" : '0x'.$v;
                    } else {
                        $values[] = $this->pdo->quote((string) $v);
                    }
                }
                $tuple = '('.implode(',', $values).')';
                if ($pending !== '' && strlen($pending) + strlen($tuple) > self::STATEMENT_BYTES) {
                    $this->write($out, $pending.";\n");
                    $pending = '';
                }
                $pending .= $pending === '' ? $prefix.$tuple : ','.$tuple;
            }
            $rows->closeCursor();
            if ($pending !== '') {
                $this->write($out, $pending.";\n");
            }
            $this->write($out, "\n");
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /** @param  resource  $out */
    private function dumpView($out, string $view): void
    {
        $q = self::ident($view);
        $create = (string) $this->pdo->query('SHOW CREATE VIEW '.$q)->fetch(PDO::FETCH_NUM)[1];
        $create = (string) preg_replace('/\sDEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`/', '', $create);
        $this->write($out, "DROP VIEW IF EXISTS {$q};\n{$create};\n\n");
    }

    /**
     * Triggers, routines and events of this schema, read up front. Throws when the account cannot read all of them.
     *
     * @return array{triggers: list<array{kind: string, name: string, sql: string, sql_mode: string, time_zone: ?string}>, routines: list<array{kind: string, name: string, sql: string, sql_mode: string, time_zone: ?string}>, events: list<array{kind: string, name: string, sql: string, sql_mode: string, time_zone: ?string}>}
     */
    private function programObjects(): array
    {
        $missing = [];
        foreach (['TRIGGER', 'EVENT'] as $privilege) {
            if (! $this->hasSchemaPrivilege($privilege)) {
                $missing[] = $privilege;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('backup refused: the database user lacks the '.implode(' and ', $missing)
                .' privilege on this database, so its triggers/events cannot be read and the dump would be incomplete.'
                .' Grant it (GRANT '.implode(', ', $missing).' ON `db`.* TO the app user) or use mysqldump.');
        }

        $result = ['triggers' => [], 'routines' => [], 'events' => []];

        $rows = $this->pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
            .' ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$name]) {
            $row = $this->showCreate('TRIGGER', (string) $name);
            $result['triggers'][] = $this->program('TRIGGER', (string) $name, $row['SQL Original Statement'] ?? null, $row);
        }

        $rows = $this->pdo->query('SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES'
            .' WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$type, $name]) {
            $kind = strtoupper((string) $type) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $row = $this->showCreate($kind, (string) $name);
            $result['routines'][] = $this->program($kind, (string) $name, $row['Create '.ucfirst(strtolower($kind))] ?? null, $row);
        }

        $rows = $this->pdo->query('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()'
            .' ORDER BY EVENT_NAME')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$name]) {
            $row = $this->showCreate('EVENT', (string) $name);
            $result['events'][] = $this->program('EVENT', (string) $name, $row['Create Event'] ?? null, $row);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function showCreate(string $kind, string $name): array
    {
        try {
            $row = $this->pdo->query('SHOW CREATE '.$kind.' '.self::ident($name))->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new RuntimeException("backup refused: cannot read {$kind} {$name} (".$e->getMessage().'); the dump would be incomplete.', 0, $e);
        }

        return is_array($row) ? $row : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{kind: string, name: string, sql: string, sql_mode: string, time_zone: ?string}
     */
    private function program(string $kind, string $name, mixed $create, array $row): array
    {
        if (! is_string($create) || trim($create) === '') {
            throw new RuntimeException("backup refused: the database user cannot read the definition of {$kind} {$name}"
                .' (it belongs to another account; grant SHOW_ROUTINE or use mysqldump as its owner); the dump would be incomplete.');
        }
        // DEFINER only in the CREATE header (never inside the body)
        $sql = (string) preg_replace('/^(CREATE\s+)DEFINER\s*=\s*(?:`(?:[^`]|``)*`|\'[^\']*\'|[^\s@]+)(?:@(?:`(?:[^`]|``)*`|\'[^\']*\'|[^\s]+))?\s+/i', '$1', ltrim($create), 1);
        if (str_contains($sql, ';;')) {
            throw new RuntimeException("backup refused: {$kind} {$name} contains ';;' and cannot be written safely.");
        }

        return [
            'kind' => $kind,
            'name' => $name,
            'sql' => $sql,
            'sql_mode' => (string) ($row['sql_mode'] ?? ''),
            'time_zone' => isset($row['time_zone']) ? (string) $row['time_zone'] : null,
        ];
    }

    /**
     * @param  resource  $out
     * @param  array{kind: string, name: string, sql: string, sql_mode: string, time_zone: ?string}  $object
     */
    private function writeProgram($out, array $object): void
    {
        $data = "DROP {$object['kind']} IF EXISTS ".self::ident($object['name']).";\n"
            .'SET SESSION SQL_MODE = '.$this->pdo->quote($object['sql_mode']).";\n";
        if ($object['time_zone'] !== null) {
            $data .= 'SET SESSION time_zone = '.$this->pdo->quote($object['time_zone']).";\n";
        }
        $data .= "DELIMITER ;;\n".$object['sql']." ;;\nDELIMITER ;\n"
            ."SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        if ($object['time_zone'] !== null) {
            $data .= "SET SESSION time_zone = '+00:00';\n";
        }
        $this->write($out, $data."\n");
    }

    /**
     * True when the connected account holds $privilege globally or on this schema (grant patterns like `app\_%`
     * match through LIKE, as MySQL itself matches them).
     */
    private function hasSchemaPrivilege(string $privilege): bool
    {
        $current = (string) $this->pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $at = strrpos($current, '@');
        $grantee = $at === false
            ? "'".$current."'"
            : "'".substr($current, 0, $at)."'@'".substr($current, $at + 1)."'";

        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = ?'
            .' UNION ALL SELECT 1 FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = ?'
            .' AND DATABASE() LIKE TABLE_SCHEMA');
        $stmt->execute([$grantee, $privilege, $grantee, $privilege]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Views that read other views must be created after them. Uses VIEW_TABLE_USAGE (MySQL 8.0.13+); on a server
     * without it the name order stands.
     *
     * @param  list<string>  $views
     * @return list<string>
     */
    private function orderViews(array $views): array
    {
        if (count($views) < 2) {
            return $views;
        }
        $deps = array_fill_keys($views, []);
        try {
            $rows = $this->pdo->query('SELECT VIEW_NAME, TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE'
                .' WHERE VIEW_SCHEMA = DATABASE() AND TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$v, $t]) {
                if (isset($deps[$v], $deps[$t])) {
                    $deps[$v][] = (string) $t;
                }
            }
        } catch (\Throwable) {
            return $views;
        }

        $ordered = [];
        $visit = function (string $v, array $stack) use (&$visit, &$ordered, $deps): void {
            if (in_array($v, $ordered, true) || in_array($v, $stack, true)) {
                return;
            }
            foreach ($deps[$v] as $d) {
                $visit($d, [...$stack, $v]);
            }
            $ordered[] = $v;
        };
        foreach ($views as $v) {
            $visit($v, []);
        }

        return $ordered;
    }

    /** @param  resource  $out */
    private function write($out, string $data): void
    {
        if (gzwrite($out, $data) !== strlen($data)) {
            throw new RuntimeException('write to the dump file failed (disk full?)');
        }
    }

    private static function ident(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
