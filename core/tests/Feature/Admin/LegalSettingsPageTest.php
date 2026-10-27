<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Admin;

use App\Http\Middleware\AdminMiddleware;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Juwri\Core\Legal\LegalSettings;
use Juwri\Core\Models\Language;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * J51b: Settings > Legal & compliance (the superadmin page that fills the {{FILL}} / {{VERIFY}} markers in
 * resources/legal/{en,ar}). The DB values in PlatformSettings override the .env LEGAL_* fallback, "Not used"
 * removes a sub-processor row in both languages, and every save is audit-logged. Public pages are asserted
 * here too, because that is what the owner actually buys: a filled legal page, never a raw marker.
 */
class LegalSettingsPageTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        foreach (['en' => ['English', 'ltr'], 'ar' => ['Arabic', 'rtl']] as $code => [$name, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => $dir, 'active' => 1]);
        }
        config(['legal.enabled' => true, 'legal.source_path' => base_path('tests/Fixtures/legal-open')]);
        $this->bootAdminPanel();
    }

    /**
     * One POST body that fills every field the form declares, with all product promises on.
     *
     * @return array{fill: array<string, string>, verify: array<string, string>, unused: array<string, string>}
     */
    private function filledPayload(): array
    {
        $fill = [];
        $verify = [];
        foreach (LegalSettings::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $key = (string) $field['key'];
                if (($field['kind'] ?? 'fill') === 'verify') {
                    $verify[$key] = '1';

                    continue;
                }
                $fill[$key] = ($field['input'] ?? 'text') === 'url' ? 'https://example.test/'.$key : 'Test '.$key;
            }
        }

        return ['fill' => $fill, 'verify' => $verify, 'unused' => []];
    }

    private function auditRows(): Collection
    {
        return ActivityLog::query()->withoutGlobalScopes()->where('action', 'legal.settings_update')->get();
    }

    public function test_superadmin_sees_the_page_with_the_live_status_figure(): void
    {
        $status = LegalSettings::status();

        $this->actingAs($this->admin())
            ->get(route('admin.settings.legal'))
            ->assertOk()
            ->assertSee('Legal & compliance')
            ->assertSee($status['filled'].' of '.$status['total']);
    }

    public function test_hosting_region_is_saved_in_the_database_and_renders_on_both_languages_of_the_dpa(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.legal.update'), ['fill' => ['hosting_region' => 'Frankfurt, Germany']])
            ->assertRedirect(route('admin.settings.legal'));

        $this->assertSame('Frankfurt, Germany', PlatformSettings::get('legal_fill_hosting_region'));

        $this->get('/legal/dpa?lang=en')->assertOk()->assertSee('Frankfurt, Germany');
        $this->get('/legal/dpa?lang=ar')->assertOk()->assertSee('Frankfurt, Germany');
    }

    public function test_marking_a_subprocessor_unused_removes_its_row_in_both_languages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.legal.update'), ['unused' => ['cdn' => '1']])
            ->assertRedirect(route('admin.settings.legal'));
        $this->assertSame('1', (string) PlatformSettings::get('legal_unused_cdn'));

        $this->get('/legal/subprocessors?lang=en')->assertOk()
            ->assertDontSee('Content delivery and network security')
            ->assertSee('Backup and object storage');
        $this->get('/legal/subprocessors?lang=ar')->assertOk()
            ->assertDontSee('توصيل المحتوى وأمان الشبكة')
            ->assertSee('النسخ الاحتياطي وتخزين الكائنات');

        $this->actingAs($admin)
            ->post(route('admin.settings.legal.update'), ['unused' => ['cdn' => '0']])
            ->assertRedirect(route('admin.settings.legal'));
        $this->assertSame('0', (string) PlatformSettings::get('legal_unused_cdn'));

        $this->get('/legal/subprocessors?lang=en')->assertOk()->assertSee('Content delivery and network security');
        $this->get('/legal/subprocessors?lang=ar')->assertOk()->assertSee('توصيل المحتوى وأمان الشبكة');
    }

    public function test_confirming_the_ai_promise_removes_the_verify_marker_and_counts_as_filled(): void
    {
        $before = LegalSettings::status();

        $this->actingAs($this->admin())
            ->post(route('admin.settings.legal.update'), ['verify' => ['ai_no_training' => '1']])
            ->assertRedirect(route('admin.settings.legal'));

        $this->assertTrue(LegalSettings::effective()['verify']['ai_no_training']);
        $this->assertGreaterThan($before['filled'], LegalSettings::status()['filled']);

        $this->get('/terms?lang=en')->assertOk()->assertDontSee('VERIFY: ai_no_training');
    }

    public function test_the_page_is_admin_only(): void
    {
        $payload = ['fill' => ['hosting_region' => 'Should not save']];

        $this->get(route('admin.settings.legal'))->assertRedirect(route('login'));
        $this->post(route('admin.settings.legal.update'), $payload)->assertRedirect(route('login'));

        $plain = $this->plainUser();
        $this->actingAs($plain)->get(route('admin.settings.legal'))->assertRedirect(route('login'));
        $this->actingAs($plain)->post(route('admin.settings.legal.update'), $payload)->assertRedirect(route('login'));
        $this->assertNull(PlatformSettings::get('legal_fill_hosting_region'));

        // The admin middleware already turns non-admins away; with it removed the controller's own gate answers.
        $this->actingAs($plain)->withoutMiddleware(AdminMiddleware::class)->get(route('admin.settings.legal'))->assertForbidden();
        $this->actingAs($plain)->withoutMiddleware(AdminMiddleware::class)->post(route('admin.settings.legal.update'), $payload)->assertForbidden();
        $this->assertNull(PlatformSettings::get('legal_fill_hosting_region'));
    }

    public function test_every_save_writes_one_audit_entry_with_the_changes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.legal.update'), ['fill' => ['hosting_region' => 'Frankfurt, Germany']])
            ->assertRedirect(route('admin.settings.legal'));

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $log = $rows->first();
        $this->assertSame($admin->id, (int) $log->actor_user_id);

        $changes = $log->meta['changes'] ?? null;
        $this->assertIsArray($changes);
        $entry = collect($changes)->firstWhere('name', 'legal_fill_hosting_region');
        $this->assertNotNull($entry, 'the audit meta carries the changed setting');
        $this->assertSame('Frankfurt, Germany', $entry['to']);
        $this->assertSame(count($changes), $log->meta['count'] ?? null);
    }

    public function test_filling_every_field_makes_the_legal_pages_ready(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.settings.legal.update'), $this->filledPayload())
            ->assertRedirect(route('admin.settings.legal'));

        $this->assertSame(0, Artisan::call('legal:check'), Artisan::output());

        $status = LegalSettings::status();
        $this->assertTrue($status['ready']);
        $this->assertSame($status['total'], $status['filled']);

        $this->actingAs($admin)->get(route('admin.settings.legal'))->assertOk()
            ->assertSee($status['total'].' of '.$status['total']);
    }

    public function test_saving_the_same_values_twice_still_writes_two_audit_entries(): void
    {
        $admin = $this->admin();
        $payload = ['fill' => ['hosting_region' => 'Frankfurt, Germany']];

        $this->actingAs($admin)->post(route('admin.settings.legal.update'), $payload)->assertRedirect(route('admin.settings.legal'));
        $this->actingAs($admin)->post(route('admin.settings.legal.update'), $payload)->assertRedirect(route('admin.settings.legal'));

        $this->assertSame(2, $this->auditRows()->count());
    }
}
