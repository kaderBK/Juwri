<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Controllers\Admin\UserController;
use App\Support\HostCapabilities;
use Illuminate\Support\Facades\Artisan;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Modules\Content\Services\Studio\HeadlessChrome;
use Tests\TestCase;

/**
 * P6-140: behaviours that only show on a shared host (open_basedir, disabled exec-family functions). The
 * whole subset also runs under the real limits via core/bin/test-shared-host.sh.
 */
class SharedHostLimitsTest extends TestCase
{
    private function noProcesses(): void
    {
        $this->app->instance(HostCapabilities::class, new class extends HostCapabilities
        {
            public function processesAllowed(): bool
            {
                return false;
            }
        });
    }

    public function test_headless_render_without_proc_open_throws_the_plain_export_message(): void
    {
        $this->noProcesses();
        $this->expectExceptionMessage(HostCapabilities::MESSAGE_EXPORT);
        (new HeadlessChrome('/x/chrome', '/x/node'))->screenshot('<p>x</p>', 100, 100);
    }

    public function test_db_backup_gzip_validation_falls_back_to_php_when_processes_are_disabled(): void
    {
        $this->noProcesses();
        $validate = new \ReflectionMethod(PlatformDbBackup::class, 'validateArtifact');
        $good = sys_get_temp_dir().'/sh-'.uniqid().'.sql.gz';
        file_put_contents($good, gzencode("CREATE TABLE x;\n-- Dump completed on 2026-08-05\n"));
        $this->assertNull($validate->invoke(new PlatformDbBackup, $good));

        $bad = sys_get_temp_dir().'/sh-'.uniqid().'.sql.gz';
        file_put_contents($bad, substr((string) file_get_contents($good), 0, -4));
        $this->assertStringContainsString('gzip integrity', (string) $validate->invoke(new PlatformDbBackup, $bad));
        @unlink($good);
        @unlink($bad);
    }

    public function test_admin_user_export_runs_in_process_after_the_response_when_proc_open_is_disabled(): void
    {
        $this->noProcesses();
        $ran = [];
        Artisan::command('test:shared-host-bg {token}', function (string $token) use (&$ran) {
            $ran[] = $token;
        });

        (new \ReflectionMethod(UserController::class, 'startBackgroundArtisan'))->invoke(app(UserController::class), 'test:shared-host-bg', 'tok123');
        $this->app->terminate();

        $this->assertSame(['tok123'], $ran);
    }
}
