<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\User;
use Juwri\Core\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * HPMS E13 — i18n tooling (missing-string detection, pack round-trip) and the
 * dashboard preferred_locale fallback in the Locale middleware.
 */
class I18nToolingTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/langpack-'.uniqid();
        File::makeDirectory($this->tmp.'/en', 0755, true);
        File::makeDirectory($this->tmp.'/ar', 0755, true);
        File::put($this->tmp.'/en/lang.php', "<?php return ".var_export(['save' => 'Save', 'cancel' => 'Cancel', 'delete' => 'Delete'], true).";");
        File::put($this->tmp.'/ar/lang.php', "<?php return ".var_export(['save' => 'حفظ', 'cancel' => ''], true).";");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_missing_reports_absent_and_empty_keys(): void
    {
        $missing = new LanguagePackService($this->tmp)->missing('ar', 'en');

        // 'cancel' is empty, 'delete' is absent → both missing; 'save' is translated.
        $this->assertArrayHasKey('cancel', $missing);
        $this->assertArrayHasKey('delete', $missing);
        $this->assertArrayNotHasKey('save', $missing);
        $this->assertSame('Delete', $missing['delete']['en']);
    }

    public function test_pack_export_import_round_trip(): void
    {
        $svc = new LanguagePackService($this->tmp);
        $pack = $svc->export('en');
        $this->assertSame('Save', $pack['save']);

        // Import a translated pack into ar; keys are slugged to the ___() convention.
        $written = $svc->import('ar', ['cancel' => 'إلغاء', 'Delete' => 'حذف', 'nested' => ['skip' => 'me']]);
        $this->assertSame(2, $written); // the array value is skipped

        $ar = $svc->read('ar');
        $this->assertSame('إلغاء', $ar['cancel']);
        $this->assertSame('حذف', $ar['delete']); // 'Delete' slugged to 'delete'
        $this->assertSame('حفظ', $ar['save']);    // pre-existing key preserved
    }

    // ── dashboard locale default vs explicit pick ─────────────────────────────

    private function runLocale(Request $request): string
    {
        new Locale()->handle($request, fn ($r) => response('ok'));

        return app()->getLocale();
    }

    public function test_authed_user_falls_back_to_preferred_locale(): void
    {
        $user = User::factory()->create(['status' => 1, 'preferred_locale' => 'ar']);
        $this->actingAs($user);
        app()->setLocale('en');

        $this->assertSame('ar', $this->runLocale(Request::create('/dashboard', 'GET')));
    }

    public function test_explicit_session_pick_wins(): void
    {
        $user = User::factory()->create(['status' => 1, 'preferred_locale' => 'ar']);
        $this->actingAs($user);
        app()->setLocale('en');
        session(['locale' => 'fr']);

        $this->assertSame('fr', $this->runLocale(Request::create('/dashboard', 'GET')));
    }

    public function test_storefront_surface_is_untouched(): void
    {
        $user = User::factory()->create(['status' => 1, 'preferred_locale' => 'ar']);
        $this->actingAs($user);
        app()->setLocale('en');

        // A fusion storefront request must not inherit the dashboard preferred_locale.
        $this->assertSame('en', $this->runLocale(Request::create('/fusion', 'GET')));
    }

    public function test_no_preferred_locale_leaves_app_default(): void
    {
        $user = User::factory()->create(['status' => 1, 'preferred_locale' => null]);
        $this->actingAs($user);
        app()->setLocale('en');

        $this->assertSame('en', $this->runLocale(Request::create('/dashboard', 'GET')));
    }
}
