<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaCopy;
use Juwri\Modules\LegacyCompat\Console\LegacyMediaIndexCommand;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * J60 + J62 gate: the target host checks (fail closed) and the owner sign-off, shared by legacy:import,
 * legacy:media-copy and legacy:media-index.
 */
class ImportGuardHostGateTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> app.url => refused? */
    public static function hosts(): array
    {
        return [
            'forbidden host' => ['https://juwri.com', true],
            'forbidden sub-domain' => ['https://shop.juwri.com', true],
            'no scheme' => ['juwri.com', true],
            'leading space' => [' https://juwri.com', true],
            'empty host' => ['http:///juwri.com', true],
            'empty app url' => ['', true],
            'blank app url' => ['   ', true],
            'malformed' => ['https://', true],
            'other scheme' => ['ftp://juwri.com', true],
            'trailing dot' => ['https://juwri.com.', true],
            'sub-domain trailing dot' => ['https://shop.juwri.com.', true],
            'upper case' => ['HTTPS://JuWri.COM', true],
            'port' => ['https://juwri.com:8443', true],
            'userinfo on the forbidden host' => ['https://x@juwri.com', true],
            'unicode lookalike host' => ['https://jüwri.com', false],
            'unicode full-width forbidden spelling' => ['https://ｊｕｗｒｉ.ｃｏｍ', true],
            'suffix trick' => ['https://juwri.com.evil.test', false],
            'prefix trick' => ['https://evil-juwri.com', false],
            'any userinfo is refused' => ['https://juwri.com@evil.test', true],
            'backslash in the authority' => ['https://evil.test\\@juwri.com', true],
            'percent escape in the authority' => ['https://juwri%2ecom', true],
            'ordinary host' => ['https://install.example.test', false],
        ];
    }

    #[DataProvider('hosts')]
    public function test_the_forbidden_host_check_fails_closed(string $url, bool $refused): void
    {
        config(['app.url' => $url]);

        $this->assertSame($refused, ImportGuard::forbiddenTargetHostReason() !== null, $url);
    }

    public function test_without_intl_a_non_ascii_host_is_refused_and_ascii_still_works(): void
    {
        $this->app->instance(ImportGuard::NO_INTL_SEAM, true);

        config(['app.url' => 'https://jüwri.com']);
        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
        $this->assertNull(ImportGuard::normalizeHost('jüwri.com'));

        config(['app.url' => 'https://install.example.test']);
        $this->assertNull(ImportGuard::forbiddenTargetHostReason());
        $this->assertSame('install.example.test', ImportGuard::normalizeHost('Install.Example.Test.'));

        config(['app.url' => 'https://juwri.com']);
        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
    }

    public function test_an_invalid_forbidden_list_entry_refuses_everything(): void
    {
        config([
            'app.url' => 'https://install.example.test',
            'legacy_compat.import.forbidden_target_hosts' => ['', '   .'],
        ]);

        $this->assertSame('a forbidden target host entry is invalid (J60)', ImportGuard::forbiddenTargetHostReason());
    }

    public function test_an_extra_forbidden_host_is_normalised_too(): void
    {
        config([
            'app.url' => 'https://Fresh.Example.Test.',
            'legacy_compat.import.forbidden_target_hosts' => ['fresh.example.test.'],
        ]);

        $this->assertNotNull(ImportGuard::forbiddenTargetHostReason());
    }

    public function test_the_target_host_must_be_set_and_equal_the_app_host(): void
    {
        config(['app.url' => 'https://install.example.test', 'legacy_compat.import.target_host' => '']);
        $this->assertContains('the target host is not set (legacy_compat.import.target_host)', ImportGuard::targetHostReasons());

        config(['legacy_compat.import.target_host' => 'other.example.test']);
        $this->assertContains('the target host does not equal the APP_URL host (legacy_compat.import.target_host)', ImportGuard::targetHostReasons());

        config(['legacy_compat.import.target_host' => 'Install.Example.Test.']);
        $this->assertSame([], ImportGuard::targetHostReasons());
    }

    public function test_a_target_host_equal_to_a_forbidden_app_host_is_still_refused(): void
    {
        config(['app.url' => 'https://juwri.com', 'legacy_compat.import.target_host' => 'juwri.com']);

        $this->assertNotSame([], ImportGuard::targetHostReasons());
    }

    public function test_only_a_strict_true_signoff_opens_the_gate(): void
    {
        foreach ([false, null, 'true', 1] as $value) {
            config(['legacy_compat.cutover.owner_signoff' => $value]);
            $this->assertNotNull(ImportGuard::signoffReason(), var_export($value, true));
        }
        config(['legacy_compat.cutover.owner_signoff' => true]);
        $this->assertNull(ImportGuard::signoffReason());
    }

    public function test_the_signoff_defaults_to_false_in_the_shipped_config(): void
    {
        $config = require base_path('config/legacy_compat.php');

        $this->assertSame(false, $config['cutover']['owner_signoff']);
        $this->assertSame('', $config['import']['target_host']);
    }

    public function test_import_check_and_commands_refuse_without_the_signoff(): void
    {
        $this->openGate();
        config(['legacy_compat.cutover.owner_signoff' => false]);

        $reasons = ImportGuard::check(DB::connection(), 'guard_gate_probe');
        $this->assertContains('the owner sign-off is missing (legacy_compat.cutover.owner_signoff, J62)', $reasons);

        foreach ([[], ['--dry-run' => true]] as $extra) {
            $code = Artisan::call('legacy:import', ['--source' => 'guard_gate_probe'] + $extra);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('owner sign-off is missing', Artisan::output());
        }

        $this->assertMediaCommandsRefuse('owner sign-off is missing');
    }

    public function test_media_commands_refuse_a_fresh_install_host(): void
    {
        $this->openGate();
        config(['app.url' => 'https://juwri.com', 'legacy_compat.import.target_host' => 'juwri.com']);

        $this->assertMediaCommandsRefuse('fresh-install host');
    }

    public function test_media_commands_refuse_without_a_target_host(): void
    {
        $this->openGate();
        config(['legacy_compat.import.target_host' => '']);

        $this->assertMediaCommandsRefuse('target host is not set');
    }

    private function assertMediaCommandsRefuse(string $text): void
    {
        Artisan::registerCommand($this->app->make(LegacyMediaCopy::class));
        Artisan::registerCommand($this->app->make(LegacyMediaIndexCommand::class));
        $dir = sys_get_temp_dir().'/juwri-gate-'.bin2hex(random_bytes(6));

        $code = Artisan::call('legacy:media-copy', ['--from-disk' => 'a', '--to-disk' => 'b', '--assignments' => $dir.'/x.json', '--manifest' => $dir.'/m.json', '--dry-run' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString($text, Artisan::output());

        $code = Artisan::call('legacy:media-index', ['--manifest' => $dir.'/m.json']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString($text, Artisan::output());
        $this->assertDirectoryDoesNotExist($dir);
    }

    private function openGate(): void
    {
        config([
            'app.url' => 'https://install.example.test',
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'install.example.test',
            'legacy_compat.import.source_timezone' => 'UTC',
            'database.connections.guard_gate_probe' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
    }
}
