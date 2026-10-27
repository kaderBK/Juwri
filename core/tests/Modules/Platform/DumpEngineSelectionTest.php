<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Services\Ops\DatabaseDump;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** P6-119a: when the PHP-only dumper is chosen instead of mysqldump. */
class DumpEngineSelectionTest extends TestCase
{
    private function mysql(): DatabaseDump
    {
        return DatabaseDump::for(['driver' => 'mysql', 'database' => 'x']);
    }

    public function test_config_forces_either_engine(): void
    {
        config(['backup.dump_engine' => 'php']);
        $this->assertTrue($this->mysql()->usePhpFallback());

        config(['backup.dump_engine' => 'native', 'backup.mysqldump_path' => '/nonexistent/mysqldump']);
        $this->assertFalse($this->mysql()->usePhpFallback());
    }

    public function test_auto_falls_back_when_mysqldump_is_missing(): void
    {
        config(['backup.dump_engine' => 'auto', 'backup.mysqldump_path' => '/nonexistent/mysqldump']);
        $this->assertTrue($this->mysql()->usePhpFallback());
    }

    public function test_postgres_never_uses_the_php_dumper(): void
    {
        config(['backup.dump_engine' => 'php']);
        $this->assertFalse(DatabaseDump::for(['driver' => 'pgsql', 'database' => 'x'])->usePhpFallback());
    }

    public function test_auto_falls_back_when_proc_open_is_disabled(): void
    {
        // A fresh PHP with proc_open disabled, a mysqldump that DOES exist (this PHP binary stands in for it).
        $php = (string) (new PhpExecutableFinder)->find(false);
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";'
            .' $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' config(["backup.dump_engine" => "auto", "backup.mysqldump_path" => '.var_export($php, true).']);'
            .' $d = Juwri\Core\Services\Ops\DatabaseDump::for(["driver" => "mysql", "database" => "x"]);'
            .' echo (Juwri\Core\Services\Ops\DatabaseDump::procOpenAvailable() ? "proc" : "noproc"), " ", ($d->usePhpFallback() ? "php" : "native");';

        $run = function (array $ini) use ($php, $code): string {
            $p = new Process([$php, ...$ini, '-r', $code], base_path());
            $p->run();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());

            return trim($p->getOutput());
        };

        $this->assertStringEndsWith('noproc php', $run(['-d', 'disable_functions=proc_open']));
        $this->assertStringEndsWith('proc native', $run([]));
    }
}
