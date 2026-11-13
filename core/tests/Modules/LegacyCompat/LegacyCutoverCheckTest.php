<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Juwri\Modules\LegacyCompat\Console\LegacyCutoverCheckCommand;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J60: the cut-over checklist of the import (`legacy:cutover-check`). The command is read-only: it prints one row
 * per check, exits non-zero as soon as one check FAILs (a WARN does not fail the run) and never writes anything.
 *
 * Every value here is synthetic (a test host, a test plan, a test key): no data of a real install is used.
 */
class LegacyCutoverCheckTest extends TestCase
{
    use RefreshDatabase;

    /** The connection the checklist reads as its source (a separate, empty in-memory database). */
    private const SOURCE = 'legacy_import_source';

    /** A secret that must never reach the output. */
    private const SECRET_KEY = 'planted-source-app-key-marker';

    private const SECRET_PASSWORD = 'planted-source-password-marker';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/juwri-cutover-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);

        // The command validates the disk names against config; Storage::fake only swaps the resolved disk instance.
        Storage::fake('cutover_src');
        Storage::fake('cutover_dst');
        foreach (['cutover_src', 'cutover_dst'] as $disk) {
            config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/'.$disk)]]);
        }

        config([
            'app.url' => 'https://install.example.test',
            'legacy_compat.import.target_host' => 'install.example.test',
            'legacy_compat.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.connection.prefix' => 'src_',
            'legacy_compat.import.source_app_key' => 'base64:'.base64_encode(self::SECRET_KEY),
            'legacy_compat.import.plan_map' => ['3' => '1'],
            'legacy_compat.media.from_disk' => 'cutover_src',
            'legacy_compat.media.to_disk' => 'cutover_dst',
            'legacy_compat.media.manifest' => $this->directory.'/manifest.json',
            'legacy_compat.room_token_legacy_keys' => [Crypt::encryptString('synthetic-old-room-key')],
            'legacy_compat.room_token_legacy_until' => now()->addMonths(6)->format('Y-m-d'),
            'legacy_compat.google_wallet.issuer_id' => '',
            'legacy_compat.google_wallet.max_store_id' => 0,
            'legacy_compat.hosts' => ['old-platform.example.test'],
            'database.connections.'.self::SOURCE => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);

        // The edition's plan when one that sells plans is installed; the Community stand-in (free plan) otherwise.
        $planId = TestPlans::productId(TestPlans::create([
            'name' => 'Starter (synthetic)', 'status' => 1, 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 300,
            'settings' => (object) ['menu_items' => 100],
        ]));
        config(['legacy_compat.import.plan_map' => ['3' => $planId === null ? 'free' : (string) $planId]]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    /** The checklist, as the operator runs it. */
    private function runCheck(): array
    {
        Artisan::registerCommand($this->app->make(LegacyCutoverCheckCommand::class));

        $code = Artisan::call('legacy:cutover-check');

        return [$code, Artisan::output()];
    }

    /** The table without its frame and with the line breaks collapsed: one line per row. */
    private function plain(string $output): string
    {
        $text = preg_replace('/[+|]/', ' ', $output) ?? $output;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $item = $path.'/'.$name;
            is_dir($item) ? $this->removeDirectory($item) : @unlink($item);
        }

        @rmdir($path);
    }

    public function test_the_module_off_install_does_not_even_list_the_command(): void
    {
        // The suite boots with the module off (LEGACY_COMPAT_ENABLED is not set), so the provider never registers
        // the checklist: a fresh install has no legacy command at all.
        config(['legacy_compat.enabled' => false]);

        $this->assertArrayNotHasKey('legacy:cutover-check', Artisan::all());
    }

    public function test_a_complete_configuration_passes_every_check(): void
    {
        [$code, $output] = $this->runCheck();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('module on PASS', $this->plain($output));
        $this->assertStringContainsString('import switch PASS', $this->plain($output));
        $this->assertStringContainsString('source connection PASS', $this->plain($output));
        $this->assertStringContainsString('source time zone PASS', $this->plain($output));
        $this->assertStringContainsString('plan map PASS', $this->plain($output));
        $this->assertStringContainsString('media PASS', $this->plain($output));
        $this->assertStringContainsString('room tokens PASS', $this->plain($output));
        $this->assertStringContainsString('google wallet PASS', $this->plain($output));
        $this->assertStringContainsString('hosts PASS', $this->plain($output));
        $this->assertStringContainsString('target empty PASS', $this->plain($output));
        $this->assertStringContainsString('route cache PASS', $this->plain($output));
        $this->assertStringNotContainsString('FAIL', $output);
    }

    public function test_a_missing_owner_signoff_is_a_failure_and_the_gate_is_printed(): void
    {
        config(['legacy_compat.cutover.owner_signoff' => false]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('owner sign-off FAIL', $this->plain($output));
        $this->assertStringContainsString('HARD GATE', $output);
    }

    public function test_the_module_off_is_a_failure(): void
    {
        config(['legacy_compat.enabled' => false]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('module on FAIL', $this->plain($output));
    }

    public function test_an_empty_plan_map_is_a_failure(): void
    {
        config(['legacy_compat.import.plan_map' => []]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('plan map FAIL', $this->plain($output));
        $this->assertStringContainsString('the plan map is empty', $this->plain($output));
    }

    public function test_a_plan_map_naming_a_plan_this_install_does_not_have_is_a_failure(): void
    {
        config(['legacy_compat.import.plan_map' => ['3' => '99']]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('plan map FAIL', $this->plain($output));
        $this->assertStringContainsString('source 3 > plan 99', $this->plain($output));
    }

    public function test_a_missing_source_time_zone_is_a_failure(): void
    {
        config(['legacy_compat.import.source_timezone' => '']);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('source time zone FAIL', $this->plain($output));
        $this->assertStringContainsString('the source time zone is not set', $this->plain($output));
    }

    public function test_a_room_key_that_does_not_decrypt_is_a_failure(): void
    {
        config(['legacy_compat.room_token_legacy_keys' => ['not-an-encrypted-key']]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('room tokens FAIL', $this->plain($output));
        $this->assertStringContainsString('1 of 1 keys do not decrypt', $this->plain($output));
    }

    public function test_a_past_room_token_grace_date_is_a_failure(): void
    {
        config(['legacy_compat.room_token_legacy_until' => now()->subDay()->format('Y-m-d')]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('room tokens FAIL', $this->plain($output));
        $this->assertStringContainsString('the room-token grace period ended on', $this->plain($output));
    }

    public function test_a_half_configured_google_wallet_is_a_failure(): void
    {
        config(['legacy_compat.google_wallet.issuer_id' => '1234567890', 'legacy_compat.google_wallet.max_store_id' => 0]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('google wallet FAIL', $this->plain($output));
        $this->assertStringContainsString('half configured', $this->plain($output));
    }

    public function test_a_target_that_is_not_empty_is_a_failure(): void
    {
        DB::table('sms_optouts')->insert(['phone' => '+15550000000']);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('target empty FAIL', $this->plain($output));
        $this->assertStringContainsString('sms_optouts', $this->plain($output));
    }

    public function test_a_fresh_install_host_is_a_failure(): void
    {
        config(['app.url' => 'https://juwri.com']);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('target host FAIL', $this->plain($output));
        $this->assertStringContainsString('the target APP_URL host is a fresh-install host', $this->plain($output));
    }

    public function test_a_malformed_app_url_makes_the_target_host_row_fail(): void
    {
        foreach (['', 'juwri.com', ' ', 'http:///x.example.test', 'ftp://install.example.test'] as $url) {
            config(['app.url' => $url]);

            [$code, $output] = $this->runCheck();

            $this->assertSame(1, $code, $output);
            $this->assertStringContainsString('target host FAIL', $this->plain($output), "app.url [{$url}]");
        }
    }

    public function test_a_target_host_that_does_not_match_app_url_fails(): void
    {
        config(['legacy_compat.import.target_host' => '']);
        [$code, $output] = $this->runCheck();
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('target host FAIL', $this->plain($output));

        config(['legacy_compat.import.target_host' => 'other.example.test']);
        [$code, $output] = $this->runCheck();
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('does not equal the APP_URL host', $this->plain($output));
    }

    public function test_the_check_runs_no_write_query(): void
    {
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        [$code, $output] = $this->runCheck();

        $this->assertSame(0, $code, $output);
        $this->assertSame([], $writes);
    }

    public function test_the_output_never_carries_a_secret(): void
    {
        config([
            'legacy_compat.import.source_app_key' => 'base64:'.base64_encode(self::SECRET_KEY),
            'database.connections.'.self::SOURCE => [
                'driver' => 'sqlite', 'database' => $this->directory.'/missing/db.sqlite', 'prefix' => '',
                'foreign_key_constraints' => false, 'password' => self::SECRET_PASSWORD,
            ],
        ]);

        [$code, $output] = $this->runCheck();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('source connection FAIL', $this->plain($output));
        $this->assertStringNotContainsString(self::SECRET_KEY, $output);
        $this->assertStringNotContainsString(self::SECRET_PASSWORD, $output);
    }
}
