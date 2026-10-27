<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\TranslatorService;
use Juwri\Core\Services\LangPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Lang-pipeline F0 INTEGRATION. The REAL LangPipelineService runs end-to-end
 * through the admin HTTP layer against a TEMP lang tree (never core/lang), with
 * the AI provider chain replaced by a container-bound FAKE TranslatorService —
 * so NO real AI call is ever made. Proves the whole loop: bootstrap on page
 * load → auto-translate job (inline via sync queue) → QA gates split machine
 * writes from review candidates → human approve writes the row to lang.php.
 */
class LangPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Pin the pipeline SOURCE locale to en, and run dispatched jobs inline so
        // the translate POST processes within the request (queue_worker_alive() is
        // true when queue.default=sync → dispatch_job_reliably() runs synchronously).
        config(['settings.lang' => 'en', 'app.default_language' => 'en', 'queue.default' => 'sync']);

        // Admin-chrome view shares (same null-returning settings double as the
        // other admin render tests — the layout/sidebar read many optional keys).
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
        view()->share(['admin_languages' => collect(), 'notifications' => collect(), 'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0]);

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        // Temp lang tree: en source (3 keys, one with a :placeholder) + fa target
        // holding an English placeholder row.
        $this->tmp = sys_get_temp_dir().'/lp_integ_'.uniqid();
        File::ensureDirectoryExists($this->tmp.'/en');
        File::ensureDirectoryExists($this->tmp.'/fa');
        $this->writeLang('en', ['greeting' => 'Hello :name', 'save' => 'Save', 'welcome' => 'Welcome']);
        $this->writeLang('fa', ['welcome' => 'Welcome']); // English placeholder

        // REAL service on the temp tree — bound so the controller + job both use it.
        app()->instance(LangPipelineService::class, new LangPipelineService($this->tmp));

        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1, 'position' => 1]);
        Language::updateOrCreate(['code' => 'fa'], ['name' => 'Persian', 'direction' => 'rtl', 'active' => 1, 'position' => 2]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function writeLang(string $code, array $map): void
    {
        File::put($this->tmp.'/'.$code.'/lang.php', "<?php\n\nreturn ".var_export($map, true).";\n");
    }

    /** @return array<string,mixed> */
    private function readLang(string $code): array
    {
        $file = $this->tmp.'/'.$code.'/lang.php';

        return File::exists($file) ? (array) include $file : [];
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    /** A fake translator that never calls a provider; drops :placeholders to force a QA fail. */
    private function bindFakeTranslator(): void
    {
        app()->instance(TranslatorService::class, new class extends TranslatorService
        {
            public function __construct() {}

            public function translateBatch(array $records, string $targetLang, array $opts = []): array
            {
                $out = [];
                foreach ($records as $ref => $fields) {
                    $text = (string) ($fields['t'] ?? '');
                    // Simulate a bad MT that eats the placeholder → QA placeholders fails.
                    $translated = str_contains($text, ':')
                        ? trim('[fa] '.preg_replace('/:\w+/', '', $text))
                        : '[fa] '.$text;
                    $out[$ref] = ['t' => $translated, '_lowconf' => false];
                }

                return $out;
            }
        });
    }

    public function test_pipeline_page_shows_real_key_statuses_without_writing_a_sidecar(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'));

        $response->assertStatus(200);
        // Real keys() through HTTP: 'greeting' is absent in fa, 'welcome' is a
        // placeholder — statuses are derived live (statusFor heuristic).
        $response->assertSee('greeting');
        $response->assertSee('welcome');
        $response->assertSee('Hello :name');

        // A GET is safe: the page derives status without writing a sidecar.
        $this->assertFileDoesNotExist($this->tmp.'/fa/meta.json');
    }

    public function test_auto_translate_splits_machine_writes_from_review_via_qa(): void
    {
        $this->bindFakeTranslator();
        $admin = $this->admin();

        // Bootstrap first (page load), then run the translate job inline via HTTP.
        $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'))->assertStatus(200);
        $this->actingAs($admin)->post(route('admin.languages.pipeline.translate', 'fa'))
            ->assertRedirect();

        $fa = $this->readLang('fa');

        // Clean translations (no placeholder) passed QA → written as machine.
        $this->assertSame('[fa] Save', $fa['save'] ?? null);
        $this->assertSame('[fa] Welcome', $fa['welcome'] ?? null);
        // The placeholder-dropping translation FAILED QA → NOT written to lang.php.
        $this->assertArrayNotHasKey('greeting', $fa);

        // It sits in the review queue with the placeholder reason.
        $meta = json_decode(File::get($this->tmp.'/fa/meta.json'), true);
        $this->assertSame('review', $meta['greeting']['s']);
        $this->assertSame('placeholders', $meta['greeting']['why']);
        $this->assertSame('[fa] Hello', $meta['greeting']['cand']);
    }

    public function test_review_candidate_approved_through_http_lands_in_lang_file(): void
    {
        $this->bindFakeTranslator();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'))->assertStatus(200);
        $this->actingAs($admin)->post(route('admin.languages.pipeline.translate', 'fa'))->assertRedirect();

        // The review queue surfaces the candidate through HTTP.
        $this->actingAs($admin)->get(route('admin.languages.pipeline', 'fa'))
            ->assertSee('[fa] Hello');

        // Approve it → candidate is written to lang.php, sidecar flips to machine.
        $this->actingAs($admin)->post(route('admin.languages.pipeline.approve', 'fa'), ['key' => 'greeting'])
            ->assertRedirect();

        $this->assertSame('[fa] Hello', $this->readLang('fa')['greeting'] ?? null);
        $meta = json_decode(File::get($this->tmp.'/fa/meta.json'), true);
        $this->assertSame('machine', $meta['greeting']['s']);
    }

    public function test_pipeline_page_for_source_locale_writes_no_sidecar(): void
    {
        // Regression: a page-load bootstrap of the SOURCE locale must not create
        // en/meta.json (which would also break the legacy translate() view that
        // foreach-es every file in the locale dir → RouteSmokeSweep 500).
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.languages.pipeline', 'en'))
            ->assertStatus(200);

        $this->assertFileDoesNotExist($this->tmp.'/en/meta.json');
    }

    public function test_source_locale_is_never_machine_translated(): void
    {
        $this->bindFakeTranslator();
        $admin = $this->admin();

        // en is the source — the translate control must refuse it.
        $this->actingAs($admin)->post(route('admin.languages.pipeline.translate', 'en'))
            ->assertRedirect();

        // en lang.php is untouched (no machine rows, no meta sidecar).
        $this->assertSame(['greeting' => 'Hello :name', 'save' => 'Save', 'welcome' => 'Welcome'], $this->readLang('en'));
        $this->assertFileDoesNotExist($this->tmp.'/en/meta.json');
    }
}
