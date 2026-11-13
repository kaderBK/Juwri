<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Juwri\Core\Database\MigrationChecksums;
use PHPUnit\Framework\TestCase;

/**
 * P6-38: a migration that has shipped must never change — installs that already ran it never run it again, so
 * an in-place edit never reaches them (P4-22b edited create_switch_imports_table; upgraded installs lacked
 * rows_total / rows_done / error). Change the schema with a NEW migration file.
 *
 * Adding a migration: create the new file, then run `php artisan juwri:migrations:checksums` (from core/) and
 * commit the updated database/migration-checksums.json with it. Comments / SPDX headers may change freely
 * (the fingerprint ignores comments and whitespace); code may not. Never edit or rebuild a listed entry to make
 * this test pass.
 */
class MigrationChecksumsTest extends TestCase
{
    private function core(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_released_migrations_are_unchanged(): void
    {
        $manifest = MigrationChecksums::manifest($this->core());
        $this->assertNotEmpty($manifest, MigrationChecksums::MANIFEST.' is missing');
        $current = MigrationChecksums::compute($this->core());

        $changed = [];
        foreach ($manifest as $path => $sum) {
            if (! isset($current[$path])) {
                $changed[] = "$path (deleted or renamed)";
            } elseif ($current[$path] !== $sum) {
                $changed[] = "$path (code changed)";
            }
        }
        $this->assertSame([], $changed, 'Released migrations must not be edited — add a new migration instead.');
    }

    public function test_every_migration_is_in_the_manifest(): void
    {
        $missing = array_diff(array_keys(MigrationChecksums::compute($this->core())), array_keys(MigrationChecksums::manifest($this->core())));
        $this->assertSame([], array_values($missing), 'New migration(s) not recorded: run `php artisan juwri:migrations:checksums` and commit database/migration-checksums.json.');
    }

    public function test_fingerprint_ignores_comments_but_not_code(): void
    {
        $a = "<?php\n// SPDX\n/** doc */\n\$t->string('a');\n";
        $this->assertSame(MigrationChecksums::fingerprint($a), MigrationChecksums::fingerprint("<?php\n\$t->string('a'); // x\n"));
        $this->assertNotSame(MigrationChecksums::fingerprint($a), MigrationChecksums::fingerprint("<?php\n\$t->string('b');\n"));
    }
}
