<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\Installed;
use App\Support\HostCapabilities;
use App\Support\HostingPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioTemplateExportService;
use Tests\TestCase;

/** P6-119d: Node/Chrome and DuckDB are optional; their absence degrades with a plain message, never a 500. */
class HostCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array{proc?: bool, node?: ?string, puppeteer?: bool}  $host */
    private function fakeCapabilities(array $host = []): HostCapabilities
    {
        $fake = new class($host) extends HostCapabilities
        {
            /** @param  array{proc?: bool, node?: ?string, puppeteer?: bool}  $host */
            public function __construct(private array $host) {}

            public function processesAllowed(): bool
            {
                return $this->host['proc'] ?? true;
            }

            public function nodePath(): ?string
            {
                return array_key_exists('node', $this->host) ? $this->host['node'] : '/usr/bin/node';
            }

            protected function puppeteerInstalled(): bool
            {
                return $this->host['puppeteer'] ?? true;
            }
        };
        $this->app->instance(HostCapabilities::class, $fake);

        return $fake;
    }

    /** An executable file inside every open_basedir a test host may set (PHP_BINARY is usually outside it). */
    private function anExecutable(): string
    {
        $file = sys_get_temp_dir().'/fake-tool-'.getmypid();
        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0755);

        return $file;
    }

    public function test_headless_browser_reasons_are_plain_and_ordered(): void
    {
        config(['studio.export.chrome_path' => null]);
        $this->assertNull($this->fakeCapabilities()->headlessBrowserReason());
        $this->assertTrue($this->fakeCapabilities()->headlessBrowserAvailable());

        $this->assertStringContainsString('proc_open', (string) $this->fakeCapabilities(['proc' => false])->headlessBrowserReason());
        $this->assertStringContainsString('Node.js', (string) $this->fakeCapabilities(['node' => null])->headlessBrowserReason());
        $this->assertStringContainsString('puppeteer', (string) $this->fakeCapabilities(['puppeteer' => false])->headlessBrowserReason());

        config(['studio.export.chrome_path' => '/nonexistent/chrome']);
        $this->assertStringContainsString('/nonexistent/chrome', (string) $this->fakeCapabilities()->headlessBrowserReason());
    }

    public function test_duckdb_reasons(): void
    {
        config(['directory.overture.duckdb_binary' => '/nonexistent/duckdb']);
        $this->assertNotNull($this->fakeCapabilities()->duckDbReason());
        $this->assertFalse($this->fakeCapabilities()->duckDbAvailable());

        config(['directory.overture.duckdb_binary' => $this->anExecutable()]); // any executable file counts as "installed"
        $this->assertNull($this->fakeCapabilities()->duckDbReason());

        $this->assertStringContainsString('proc_open', (string) $this->fakeCapabilities(['proc' => false])->duckDbReason());
    }

    public function test_preflight_reports_missing_tools_as_warnings_not_blockers(): void
    {
        $this->fakeCapabilities(['node' => null, 'proc' => false]);
        config(['directory.overture.duckdb_binary' => '/nonexistent/duckdb']);

        $rows = collect((new HostingPreflight)->run('shared', '8.4.3'))->keyBy('check');
        $this->assertSame('warn', $rows['Studio PDF/image export (Node + Chrome)']['status']);
        $this->assertSame('warn', $rows['Directory import (DuckDB)']['status']);
        $this->assertStringContainsString('browser print', $rows['Studio PDF/image export (Node + Chrome)']['detail']);
        $this->assertSame([], array_values(array_filter(
            [$rows['Studio PDF/image export (Node + Chrome)'], $rows['Directory import (DuckDB)']],
            fn (array $r) => $r['status'] === 'fail',
        )));
    }

    public function test_preflight_reports_available_tools_as_ok(): void
    {
        config(['studio.export.chrome_path' => null, 'directory.overture.duckdb_binary' => $this->anExecutable()]);
        $this->fakeCapabilities();

        $rows = collect((new HostingPreflight)->run('docker', '8.4.3'))->keyBy('check');
        $this->assertSame('ok', $rows['Studio PDF/image export (Node + Chrome)']['status']);
        $this->assertSame('ok', $rows['Directory import (DuckDB)']['status']);
    }

    public function test_studio_export_reports_unavailable_when_node_is_missing(): void
    {
        $this->fakeCapabilities(['node' => null]);
        $this->assertFalse(app(StudioTemplateExportService::class)->available());

        // The route-level 501 itself is covered by StudioTemplateExportTest::test_export_501_when_toolchain_missing.
    }

    public function test_studio_shell_disables_the_export_button_with_a_message_when_unavailable(): void
    {
        $this->withoutMiddleware([Installed::class, EncryptCookies::class]);
        Storage::fake('local');
        $session = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);

        $this->fakeCapabilities(['node' => null]);
        $res = $this->call('GET', route('studio.app.shell'), [], ['studio_guest' => $session->guest_token])->assertOk();
        $res->assertSee('var TPL_EXPORT_OK = false;', false);
        $res->assertSee('PDF and image export is not available on this server', false);

        $this->fakeCapabilities();
        config(['studio.export.chrome_path' => null]);
        $this->call('GET', route('studio.app.shell'), [], ['studio_guest' => $session->guest_token])
            ->assertOk()->assertSee('var TPL_EXPORT_OK = true;', false);
    }
}
