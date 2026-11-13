<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Core\Services\LangPipelineService;
use Tests\TestCase;

/**
 * Lang Pipeline run — Lane B (surfaces) HTTP tests.
 *
 * The engine's real numbers are Lane A / F0's job. In THIS tree
 * {@see LangPipelineService} returns empty shapes by design, so we bind a
 * fixture-stub subclass (ctor overridden to take no base path) and prove the
 * routes/controller/blade wiring against the locked seam shapes — page render,
 * coverage strip, key browser, status filters, review queue, auto-translate
 * guards, and the progress endpoint. No test touches core/lang or the real AI.
 */
class LangPipelinePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // The test env forces DEFAULT_LANGUAGE=ar (phpunit.xml) and settings.lang is
        // null, so the pipeline SOURCE locale is `ar`. `fa` (Persian, RTL) is the
        // active translate target the page renders. updateOrCreate is idempotent —
        // the base migration ships an `en` row.
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        Language::updateOrCreate(['code' => 'fa'], ['name' => 'Persian', 'direction' => 'rtl', 'active' => 1]);

        $this->bindStub();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    /** Bind a fixture-stub service with populated, distinctive shapes. */
    private function bindStub(): void
    {
        app()->instance(LangPipelineService::class, new class extends LangPipelineService
        {
            public function __construct()
            {
                // F0: real service now backs the seam. Give un-overridden methods
                // (page-load bootstrap(), coverage()) a dead base path so they read
                // an empty tree and return natural empties instead of hitting an
                // uninitialised $basePath.
                parent::__construct(sys_get_temp_dir().'/lp_stub_nonexistent');
            }

            public function coverage(): array
            {
                return [
                    'en_total' => 100,
                    'locales' => [
                        ['code' => 'fa', 'direction' => 'rtl', 'total' => 80, 'human' => 30, 'machine' => 20,
                            'review' => 5, 'placeholder' => 25, 'absent' => 20, 'pct_done' => 50.0],
                    ],
                ];
            }

            public function bootstrap(string $code): array
            {
                return ['placeholder' => 0, 'human' => 0];
            }

            public function keys(string $code, ?string $q = null, ?string $status = null, int $limit = 200): array
            {
                $rows = [
                    ['key' => 'save_changes', 'en' => 'Save Changes', 'value' => 'حفظ التغييرات', 'status' => 'human', 'candidate' => null],
                    ['key' => 'add_new', 'en' => 'Add New', 'value' => 'إضافة جديد', 'status' => 'machine', 'candidate' => null],
                    ['key' => 'delete', 'en' => 'Delete', 'value' => 'Delete', 'status' => 'placeholder', 'candidate' => null],
                    ['key' => 'orders', 'en' => 'Orders', 'value' => null, 'status' => 'absent', 'candidate' => null],
                    ['key' => 'greeting', 'en' => 'Hello :name', 'value' => 'مرحبا', 'status' => 'review', 'candidate' => 'مرحبا'],
                ];
                if ($status !== null) {
                    $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $status));
                }
                if ($q !== null) {
                    $needle = mb_strtolower($q);
                    $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['key'].' '.$r['en'].' '.(string) $r['value']), $needle)));
                }

                return $rows;
            }

            public function reviewQueue(string $code): array
            {
                return [
                    ['key' => 'greeting', 'en' => 'Hello :name', 'value' => null, 'candidate' => 'مرحبا', 'why' => 'placeholders'],
                ];
            }
        });
    }

    public function test_pipeline_page_renders_for_admin_with_coverage_strip(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        $response->assertStatus(200);
        $response->assertSee(___('Coverage'));
        $response->assertSee('50%');                 // pct_done from the stub
        $response->assertSeeHtml(___('Human').': 30');
        $response->assertSeeHtml(___('Machine').': 20');
        $response->assertSeeHtml(___('Missing').': 20');
        $response->assertSeeHtml(___('Source keys').': 100');
    }

    public function test_pipeline_page_renders_string_rows(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        $response->assertSee('save_changes');
        $response->assertSee('حفظ التغييرات');
        // RTL value cell carries the locale direction.
        $response->assertSeeHtml('dir="rtl"');
    }

    public function test_pipeline_page_empty_state(): void
    {
        // Rebind a service that returns nothing (the empty shapes this tree's real
        // service yields) — the page must render a clean empty state. keys() is
        // overridden so the parent never dereferences the unset base path.
        app()->instance(LangPipelineService::class, new class extends LangPipelineService
        {
            public function __construct()
            {
                // F0: real service now backs the seam. Give un-overridden methods
                // (page-load bootstrap(), coverage()) a dead base path so they read
                // an empty tree and return natural empties instead of hitting an
                // uninitialised $basePath.
                parent::__construct(sys_get_temp_dir().'/lp_stub_nonexistent');
            }

            public function keys(string $code, ?string $q = null, ?string $status = null, int $limit = 200): array
            {
                return [];
            }
        });

        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        $response->assertStatus(200);
        $response->assertSee(___('No strings to show yet.'));
        $response->assertSee('0%');   // zeroed coverage default
    }

    public function test_search_query_filters_rows(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', ['code' => 'fa', 'q' => 'save']));

        $response->assertStatus(200);
        $response->assertSee('save_changes');
        $response->assertDontSee('add_new');
    }

    public function test_status_pill_filters_rows(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', ['code' => 'fa', 'status' => 'machine']));

        $response->assertStatus(200);
        $response->assertSee('add_new');        // machine row
        $response->assertDontSee('save_changes'); // human row filtered out
    }

    public function test_invalid_status_is_ignored(): void
    {
        $admin = $this->makeAdmin();

        // A bogus ?status must be whitelisted away → all rows show (no filter).
        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', ['code' => 'fa', 'status' => 'bogus']));

        $response->assertStatus(200);
        $response->assertSee('save_changes');
        $response->assertSee('add_new');
    }

    public function test_status_badges_render(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        // Colour-coded badges for the seam statuses.
        $response->assertSeeHtml('data-tone="success">'.___('Human').'</span>');  // human
        $response->assertSeeHtml('data-tone="accent">'.___('Machine').'</span>');  // machine
        $response->assertSeeHtml('data-tone="warning">'.___('Review').'</span>');  // review
    }

    public function test_status_pills_are_get_links_not_ajax(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        // Filter pills are anchors to ?status=, never AJAX buttons.
        $response->assertSeeHtml('href="'.route('admin.languages.pipeline', ['code' => 'fa', 'status' => 'human']).'"');
        $response->assertSeeHtml('href="'.route('admin.languages.pipeline', ['code' => 'fa', 'status' => 'machine']).'"');
    }

    public function test_non_admin_is_redirected(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);

        $this->actingAs($user)->get(route('admin.languages.pipeline', 'fa'))
            ->assertRedirect(route('login'));
    }

    public function test_unknown_code_is_404(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.languages.pipeline', 'zz'))->assertStatus(404);
    }

    public function test_progress_endpoint_returns_idle_by_default(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->getJson(route('admin.languages.pipeline.progress', 'fa'))
            ->assertOk()
            ->assertJsonPath('status', 'idle');
    }

    public function test_progress_endpoint_returns_seeded_payload(): void
    {
        Cache::put(LangPipelineService::progressKey('fa'), ['status' => 'running', 'done' => 3, 'total' => 10], 3600);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->getJson(route('admin.languages.pipeline.progress', 'fa'))
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('done', 3);
    }

    public function test_translate_rejects_source_locale(): void
    {
        // `ar` is the source locale in this env — never machine-translated.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.translate', 'ar'))
            ->assertStatus(302);

        // Source locale never seeds a run.
        $this->assertNull(Cache::get(LangPipelineService::progressKey('ar')));
    }

    public function test_translate_rejects_inactive_language(): void
    {
        Language::updateOrCreate(['code' => 'de'], ['name' => 'German', 'direction' => 'ltr', 'active' => 0]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.translate', 'de'))
            ->assertStatus(302);

        $this->assertNull(Cache::get(LangPipelineService::progressKey('de')));
    }

    public function test_translate_unknown_code_is_404(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.translate', 'zz'))->assertStatus(404);
    }

    public function test_review_queue_renders_candidate_why_and_actions(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        $response->assertSee(___('Review queue'));
        $response->assertSee('مرحبا');                       // candidate
        $response->assertSee(___('Placeholder mismatch'));    // why label for 'placeholders'
        $response->assertSee(___('Approve'));
        $response->assertSee(___('Reject'));
        // Approve/Reject are POST forms to the pipeline endpoints.
        $response->assertSeeHtml('action="'.route('admin.languages.pipeline.approve', 'fa').'"');
        $response->assertSeeHtml('action="'.route('admin.languages.pipeline.reject', 'fa').'"');
    }

    public function test_review_queue_empty_state(): void
    {
        app()->instance(LangPipelineService::class, new class extends LangPipelineService
        {
            public function __construct()
            {
                // F0: real service now backs the seam. Give un-overridden methods
                // (page-load bootstrap(), coverage()) a dead base path so they read
                // an empty tree and return natural empties instead of hitting an
                // uninitialised $basePath.
                parent::__construct(sys_get_temp_dir().'/lp_stub_nonexistent');
            }

            public function keys(string $code, ?string $q = null, ?string $status = null, int $limit = 200): array
            {
                return [];
            }

            public function reviewQueue(string $code): array
            {
                return [];
            }
        });

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'))
            ->assertStatus(200)
            ->assertSee(___('No candidates awaiting review.'));
    }

    public function test_approve_candidate_redirects_back(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.approve', 'fa'), ['key' => 'greeting'])
            ->assertStatus(302);
    }

    public function test_reject_candidate_redirects_back(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.reject', 'fa'), ['key' => 'greeting'])
            ->assertStatus(302);
    }

    public function test_approve_without_key_redirects_back(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.approve', 'fa'), [])
            ->assertStatus(302);
    }

    public function test_approve_unknown_code_is_404(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.pipeline.approve', 'zz'), ['key' => 'greeting'])
            ->assertStatus(404);
    }

    public function test_languages_index_shows_coverage_dashboard_linking_pipeline(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.index'));

        $response->assertStatus(200);
        $response->assertSee(___('Translation coverage'));
        $response->assertSee('Persian');   // languageNames['fa']
        $response->assertSee('50%');       // pct_done bar label
        $response->assertSeeHtml(___('Human').': 30');
        // Dashboard links each locale to its pipeline page (reachability).
        $response->assertSeeHtml('href="'.route('admin.languages.pipeline', 'fa').'"');
    }

    public function test_languages_index_coverage_empty_state(): void
    {
        // Empty coverage → the dashboard shows its guidance row, page still 200.
        app()->instance(LangPipelineService::class, new class extends LangPipelineService
        {
            public function __construct()
            {
                // F0: real service now backs the seam. Give un-overridden methods
                // (page-load bootstrap(), coverage()) a dead base path so they read
                // an empty tree and return natural empties instead of hitting an
                // uninitialised $basePath.
                parent::__construct(sys_get_temp_dir().'/lp_stub_nonexistent');
            }

            public function coverage(): array
            {
                return ['en_total' => 0, 'locales' => []];
            }

            public function keys(string $code, ?string $q = null, ?string $status = null, int $limit = 200): array
            {
                return [];
            }
        });

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.languages.index'))
            ->assertStatus(200)
            ->assertSee(___('Add another language to start translating the platform UI.'));
    }

    public function test_auto_translate_button_present_for_target_locale(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        $response->assertSee(___('Auto-translate missing'));
        $response->assertSeeHtml('action="'.route('admin.languages.pipeline.translate', 'fa').'"');
    }

    public function test_auto_translate_button_hidden_for_source_locale(): void
    {
        // `ar` is the source locale — no auto-translate control.
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'ar'));

        $response->assertStatus(200);
        $response->assertDontSee(___('Auto-translate missing'));
    }

    public function test_progress_poller_is_nonced_and_polls_every_two_seconds(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'))->getContent();

        // The poll target is wired to the progress endpoint.
        $this->assertStringContainsString('id="lang-pipeline-progress"', $html);
        $this->assertStringContainsString('data-progress-url="'.route('admin.languages.pipeline.progress', 'fa').'"', $html);

        // The poller script carries a nonce and ticks every 2000ms, stopping on done/error.
        preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $blocks, PREG_SET_ORDER);
        $found = false;
        foreach ($blocks as [$full, $attrs, $body]) {
            if (! str_contains($body, 'lang-pipeline-progress')) {
                continue;
            }
            $found = true;
            $this->assertStringContainsString('nonce=', $attrs, 'Progress poller <script> is missing a CSP nonce.');
            $this->assertStringContainsString('setInterval', $body);
            $this->assertStringContainsString('2000', $body);
            $this->assertStringContainsString('clearInterval', $body); // stops on done/error
        }
        $this->assertTrue($found, 'progress poller inline script not found');
    }

    public function test_pipeline_blades_are_csp_clean(): void
    {
        // CSP ratchet (our files): every inline <script>/<style> is nonce-based and
        // there are no on*= handler attributes. Scans the blade source directly so
        // it never depends on the shared layout's legacy markup.
        foreach (['admin/languages/pipeline.blade.php', 'admin/languages/index.blade.php'] as $rel) {
            $blade = file_get_contents(resource_path('views/'.$rel));
            $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $blade), "un-nonced inline <script> in {$rel}");
            $this->assertSame(0, preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $blade), "un-nonced inline <style> in {$rel}");
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $blade, "inline on*= handler in {$rel}");
        }
    }

    public function test_translate_update_marks_changed_chrome_keys_human(): void
    {
        // Spy the service so we can assert which keys the hook flags human.
        $spy = new class extends LangPipelineService
        {
            /** @var list<array{0:string,1:string,2:string}> */
            public array $marked = [];

            public function __construct()
            {
                // F0: real service now backs the seam. Give un-overridden methods
                // (page-load bootstrap(), coverage()) a dead base path so they read
                // an empty tree and return natural empties instead of hitting an
                // uninitialised $basePath.
                parent::__construct(sys_get_temp_dir().'/lp_stub_nonexistent');
            }

            public function markHuman(string $code, string $key, string $value): void
            {
                $this->marked[] = [$code, $key, $value];
            }
        };
        app()->instance(LangPipelineService::class, $spy);

        // Seed the pre-edit `lang` group for `de` WITHOUT reading disk (addLines marks
        // the group loaded so the loader is skipped), and intercept File::put so the
        // real core/lang tree is never written to.
        Lang::addLines(['lang.greeting' => 'Old greeting', 'lang.save' => 'Save'], 'de');
        File::partialMock()->shouldReceive('put')->andReturnTrue();

        $de = Language::updateOrCreate(['code' => 'de'], ['name' => 'German', 'direction' => 'ltr', 'active' => 1]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.languages.translates.update', $de->id), [
            'translates' => ['lang' => ['greeting' => 'New greeting', 'save' => 'Save']],
        ])->assertStatus(302);

        // Only the CHANGED key gets human provenance; the unchanged one is left alone.
        $this->assertContains(['de', 'greeting', 'New greeting'], $spy->marked);
        $this->assertNotContains(['de', 'save', 'Save'], $spy->marked);
    }

    public function test_inline_script_carries_a_nonce(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'))->getContent();

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $blocks, PREG_SET_ORDER);
        $ours = 0;
        foreach ($blocks as [$full, $attrs, $body]) {
            // WI-10: the legacy AppMenu sidebar script went with the retired admin
            // stack; the page's own inline script (the progress poller) is the one
            // that must carry the nonce now.
            if (! str_contains($body, 'lang-pipeline-progress')) {
                continue;
            }
            $ours++;
            $this->assertStringContainsString('nonce=', $attrs, 'Pipeline inline <script> is missing a CSP nonce: <script'.$attrs.'>');
        }
        $this->assertGreaterThanOrEqual(1, $ours);
    }
}
