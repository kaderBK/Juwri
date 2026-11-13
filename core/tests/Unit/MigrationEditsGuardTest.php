<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Juwri\Core\Database\MigrationChecksums;
use PHPUnit\Framework\TestCase;

/**
 * P6-171: an edited released migration (its manifest fingerprint moved away from the frozen baseline) must be
 * named, with its new fingerprint, by an MEn entry in core/database/MIGRATION-EDITS.md. New migrations are free.
 */
class MigrationEditsGuardTest extends TestCase
{
    private const A = 'app/Modules/X/Database/Migrations/2026_01_01_000000_a.php';

    private const B = 'app/Modules/X/Database/Migrations/2026_01_01_000001_b.php';

    private function core(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_manifest_edits_match_migration_edits_log(): void
    {
        $log = dirname($this->core()).'/'.MigrationChecksums::EDITS_LOG;
        $this->assertFileExists($log);
        $baseline = MigrationChecksums::baseline($this->core());
        $this->assertNotEmpty($baseline, MigrationChecksums::BASELINE.' is missing');

        $problems = MigrationChecksums::editProblems(
            $baseline,
            MigrationChecksums::manifest($this->core()),
            MigrationChecksums::parseEdits((string) file_get_contents($log)),
        );
        $this->assertSame([], $problems, 'Released migration edits must each have an ME entry in MIGRATION-EDITS.md.');
    }

    public function test_every_manifest_entry_is_in_the_baseline(): void
    {
        $missing = array_diff_key(MigrationChecksums::manifest($this->core()), MigrationChecksums::baseline($this->core()));
        $this->assertSame([], array_keys($missing), 'Run `php artisan juwri:migrations:checksums` - it adds new migrations to the baseline too.');
    }

    public function test_seeded_entries_me1_and_me2_are_parsed(): void
    {
        $edits = MigrationChecksums::parseEdits((string) file_get_contents(dirname($this->core()).'/'.MigrationChecksums::EDITS_LOG));
        $counts = array_count_values(array_column($edits, 'entry'));
        $this->assertSame(5, $counts['ME1'] ?? 0);
        $this->assertSame(1, $counts['ME2'] ?? 0);
    }

    public function test_unlisted_change_fails_and_new_keys_pass(): void
    {
        $base = [self::A => 'a1'];
        $this->assertSame([], MigrationChecksums::editProblems($base, [self::A => 'a1', self::B => 'b1'], []));
        $this->assertCount(1, MigrationChecksums::editProblems($base, [self::A => 'a2'], []));
        $this->assertCount(1, MigrationChecksums::editProblems($base, [], []));
    }

    public function test_listed_change_passes_only_with_current_fingerprint(): void
    {
        $md = "# x\n\n## ME1 - y\n\nfiles: ".self::A."@a2\n";
        $edits = MigrationChecksums::parseEdits($md);
        $this->assertSame([], MigrationChecksums::editProblems([self::A => 'a1'], [self::A => 'a2'], $edits));
        // edited a second time without a new entry
        $this->assertCount(1, MigrationChecksums::editProblems([self::A => 'a1'], [self::A => 'a3'], $edits));
        // a later entry wins
        $edits = MigrationChecksums::parseEdits($md."\n## ME2 - z\n\nfiles: ".self::A.'@a3, '.self::B."@b2\n");
        $this->assertSame('ME2', $edits[self::A]['entry']);
        $this->assertSame([], MigrationChecksums::editProblems([self::A => 'a1', self::B => 'b1'], [self::A => 'a3', self::B => 'b2'], $edits));
    }

    public function test_listed_file_that_did_not_change_fails(): void
    {
        $edits = MigrationChecksums::parseEdits("## ME1\nfiles: ".self::A."@a1\n");
        $this->assertCount(1, MigrationChecksums::editProblems([self::A => 'a1'], [self::A => 'a1'], $edits));
        $edits = MigrationChecksums::parseEdits("## ME1\nfiles: ".self::B."@b1\n");
        $this->assertCount(1, MigrationChecksums::editProblems([self::A => 'a1'], [self::A => 'a1', self::B => 'b1'], $edits));
    }

    public function test_write_grows_the_baseline_without_moving_values(): void
    {
        $dir = sys_get_temp_dir().'/mcg-'.bin2hex(random_bytes(4));
        mkdir($dir.'/database/migrations', 0777, true);
        file_put_contents($dir.'/database/migrations/2026_01_01_000000_a.php', "<?php\n\$a = 1;\n");
        MigrationChecksums::write($dir);
        $first = MigrationChecksums::baseline($dir);
        $this->assertCount(1, $first);

        file_put_contents($dir.'/database/migrations/2026_01_01_000000_a.php', "<?php\n\$a = 2;\n");
        file_put_contents($dir.'/database/migrations/2026_01_01_000001_b.php', "<?php\n\$b = 1;\n");
        MigrationChecksums::write($dir, rebuild: true);
        $grown = MigrationChecksums::baseline($dir);
        $this->assertCount(2, $grown);
        $this->assertSame($first['database/migrations/2026_01_01_000000_a.php'], $grown['database/migrations/2026_01_01_000000_a.php']);
        $this->assertNotSame($grown['database/migrations/2026_01_01_000000_a.php'], MigrationChecksums::manifest($dir)['database/migrations/2026_01_01_000000_a.php']);

        array_map('unlink', glob($dir.'/database/migrations/*.php') ?: []);
        array_map('unlink', glob($dir.'/database/*.json') ?: []);
        rmdir($dir.'/database/migrations');
        rmdir($dir.'/database');
        rmdir($dir);
    }
}
