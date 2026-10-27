<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Console\Commands\LangScan;
use Juwri\Core\Models\Language;
use Juwri\Core\Services\LangPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Lang-pipeline SCAN/STATUS command tests (Lane A). The scan ROOTS and lang BASE
 * are injected via --root / --lang-base so nothing touches the real core/lang
 * tree (HARD RULE 2). No AI call anywhere.
 */
class LangPipelineScanTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.lang' => 'en', 'app.default_language' => 'en']);

        $this->root = sys_get_temp_dir().'/langscan-src-'.uniqid();
        $this->base = sys_get_temp_dir().'/langscan-lang-'.uniqid();
        File::makeDirectory($this->root, 0755, true);
        File::makeDirectory($this->base.'/en', 0755, true);

        // Fixture source: literal ___()/___n(), dynamic first-args, a dup, an .php file.
        File::put($this->root.'/sample.blade.php', <<<'BLADE'
            <div>{{ ___('Save Changes') }} {{ ___("Delete") }} {{ ___n('item', 3) }}</div>
            <span>{{ ___($dynamicKey) }} {{ ___n($var, 2) }}</span>
            BLADE);
        File::put($this->root.'/foo.php', "<?php echo ___('Save Changes'); echo ___('Only In Source');");

        // Fixture en/lang.php: has save_changes (used) + never_used (orphan); delete/n_item/only_in_source missing.
        File::put($this->base.'/en/lang.php', "<?php\n\nreturn ".var_export([
            'save_changes' => 'Save Changes',
            'never_used'   => 'Never',
        ], true).";\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function readEn(): array
    {
        return include $this->base.'/en/lang.php';
    }

    // ── A6: lang:scan ─────────────────────────────────────────────────────────

    public function test_scan_reports_missing_orphans_and_dynamic(): void
    {
        $res = new LangScan()->runScan([$this->root], $this->base, false);

        // 4 literal keys used (dup collapsed): save_changes, delete, n_item, only_in_source
        $this->assertSame(['delete', 'n_item', 'only_in_source', 'save_changes'], array_keys($res['used']));

        // missing = used not in en
        $this->assertSame(['delete', 'n_item', 'only_in_source'], array_keys($res['missing']));
        $this->assertSame('Delete', $res['missing']['delete']);
        $this->assertSame('Only In Source', $res['missing']['only_in_source']);
        // ___n stores the plural default (matches Helper ___n auto-register)
        $this->assertSame('{1} :count item|[2,*] :count items', $res['missing']['n_item']);

        // orphan = en key never used (report only)
        $this->assertSame(['never_used'], $res['orphans']);

        // 2 dynamic first-arg call sites (___($dynamicKey), ___n($var))
        $this->assertSame(2, $res['dynamic']);

        // report-only: nothing written without --write
        $this->assertSame(0, $res['written']);
        $this->assertArrayNotHasKey('delete', $this->readEn());
    }

    public function test_scan_write_appends_missing_to_en_only(): void
    {
        $res = new LangScan()->runScan([$this->root], $this->base, true);
        $this->assertSame(3, $res['written']);

        $en = $this->readEn();
        $this->assertSame('Delete', $en['delete']);
        $this->assertSame('Only In Source', $en['only_in_source']);
        $this->assertSame('{1} :count item|[2,*] :count items', $en['n_item']);
        $this->assertSame('Save Changes', $en['save_changes']); // pre-existing preserved
        $this->assertSame('Never', $en['never_used']);          // orphan preserved (never purged)

        // idempotent: a second scan finds nothing missing.
        $again = new LangScan()->runScan([$this->root], $this->base, false);
        $this->assertSame([], $again['missing']);
    }

    public function test_scan_command_exit_codes_and_write_flag(): void
    {
        // missing keys + no --write → CI-failing exit 1
        $this->artisan('lang:scan', ['--root' => [$this->root], '--lang-base' => $this->base])
            ->assertExitCode(1);

        // --write resolves them → exit 0
        $this->artisan('lang:scan', ['--root' => [$this->root], '--lang-base' => $this->base, '--write' => true])
            ->assertExitCode(0);

        // now clean → exit 0 even without --write
        $this->artisan('lang:scan', ['--root' => [$this->root], '--lang-base' => $this->base])
            ->assertExitCode(0);
    }

    // ── A6: lang:status ───────────────────────────────────────────────────────

    public function test_status_json_reflects_coverage(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1, 'position' => 5]);
        File::makeDirectory($this->base.'/ar', 0755, true);
        File::put($this->base.'/en/lang.php', "<?php\n\nreturn ".var_export(['a' => 'A', 'b' => 'B'], true).";\n");
        File::put($this->base.'/ar/lang.php', "<?php\n\nreturn ".var_export(['a' => 'ايه', 'b' => 'B'], true).";\n"); // 1 human, 1 placeholder

        // Point lang:status at the fixture tree via a bound service instance.
        $this->app->instance(LangPipelineService::class, new LangPipelineService($this->base));

        $this->artisan('lang:status', ['--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('"pct_done": 50');
    }
}
