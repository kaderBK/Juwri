<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WI-09 (WI-00 follow-up): rendering a page never writes missing translation keys
 * into lang/<locale>/lang.php unless the local opt-in LANG_AUTO_REGISTER is on.
 */
class LangAutoRegisterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/juwri-lang-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/en');
        File::ensureDirectoryExists($this->dir.'/ar');
        File::ensureDirectoryExists($this->dir.'/xx');
        File::put($this->dir.'/en/lang.php', "<?php\n\nreturn ['hello' => 'Hello'];\n");
        File::put($this->dir.'/ar/lang.php', "<?php\n\nreturn ['hello' => 'مرحبا', 'new_key' => 'kept'];\n");
        File::put($this->dir.'/xx/lang.php', "<?php\n\nreturn 'broken';\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_flag_is_off_by_default_and_never_on_under_tests_or_production(): void
    {
        $this->assertFalse(config('app.lang_auto_register'));
        $this->assertFalse(lang_auto_register_enabled());

        config(['app.lang_auto_register' => true]);
        $this->assertFalse(lang_auto_register_enabled(), 'never under the test runner');

        $this->app['env'] = 'production';
        $this->assertFalse(lang_auto_register_enabled(), 'never in production');
    }

    public function test_missing_key_falls_back_to_its_source_text_without_touching_lang_files(): void
    {
        $files = glob(base_path('lang/*/lang.php')) ?: [];
        $before = array_map('md5_file', $files);

        $key = 'Unregistered wi09 probe :name';
        $this->assertSame('Unregistered wi09 probe Nia', ___($key, ['name' => 'Nia']));
        $this->assertSame('3 wi09probes', ___n('wi09probe', 3));

        clearstatcache();
        $this->assertSame($before, array_map('md5_file', $files));
    }

    public function test_writer_adds_the_key_only_where_missing_and_skips_bad_files(): void
    {
        lang_register_missing_key('new_key', 'New key', $this->dir);

        $this->assertSame(['hello' => 'Hello', 'new_key' => 'New key'], include $this->dir.'/en/lang.php');
        $this->assertSame(['hello' => 'مرحبا', 'new_key' => 'kept'], include $this->dir.'/ar/lang.php');
        $this->assertSame('broken', include $this->dir.'/xx/lang.php');
    }
}
