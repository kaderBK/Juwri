<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Jobs\RunThemeLighthouseGate;
use App\Models\ThemeVersion;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Storefront\LighthouseGate;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use App\Storefront\ThemeSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Theme Studio B0.9 — linter additions (rtl-physical-prop,
 * unescaped-setting-output, section-css-unscoped, img-missing-dimensions)
 * and the Lighthouse gate wired as a queued job that tolerates a host
 * without Lighthouse.
 */
class StudioLinterRulesTest extends TestCase
{
    use RefreshDatabase;

    private string $zipPath;

    private ?string $libDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->zipPath = storage_path('app/test-studio-gate.zip');
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        File::deleteDirectory(storage_path('app/marketplace-themes/studiogate'));
        File::deleteDirectory(public_path('assets/marketplace-themes/studiogate'));
        if ($this->libDir) {
            File::deleteDirectory($this->libDir);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $files */
    private function lintFiles(array $files): array
    {
        return (new ThemeLinter)->lintFiles($files + [
            'theme.json' => json_encode(['name' => 'Fx', 'slug' => 'fx', 'version' => '1.0.0']),
        ]);
    }

    private function rules(array $report, string $bucket = 'warnings'): array
    {
        return array_column($report[$bucket], 'rule');
    }

    private function byRule(array $report, string $rule): array
    {
        return array_values(array_filter($report['warnings'], fn ($w) => $w['rule'] === $rule));
    }

    // ── rtl-physical-prop ───────────────────────────────────────────────

    public function test_physical_css_properties_warn_with_logical_suggestions(): void
    {
        $report = $this->lintFiles([
            'templates/index.liquid' => '<h1>{{ restaurant.title | escape }}</h1>',
            'assets/css/theme.css' => "/* margin-left: 0 in a comment is ignored */\n"
                .".a{margin-left:4px;text-align: right}\n.b{border-left-color:red;left:0;float:left}\n.c{margin-inline-start:4px}",
        ]);

        $this->assertTrue($report['passed'], 'warnings never fail a theme');
        $rtl = $this->byRule($report, 'rtl-physical-prop');
        $this->assertCount(1, $rtl, 'one warning per file');
        $this->assertSame('assets/css/theme.css', $rtl[0]['file']);
        foreach (['margin-left → margin-inline-start', 'text-align: right → text-align: end',
            'border-left-color → border-inline-start-color', 'left → inset-inline-start', 'float: left → float: inline-start'] as $pair) {
            $this->assertStringContainsString($pair, $rtl[0]['message']);
        }
    }

    public function test_logical_css_and_look_alike_properties_are_clean(): void
    {
        $report = $this->lintFiles([
            'templates/index.liquid' => '<h1 style="padding-inline-end: 8px; text-align: start">{{ restaurant.title | escape }}</h1>',
            'assets/css/theme.css' => '.a{margin-inline:0 4px;inset-inline-start:0;border-top-left-radius:4px;'
                .'scroll-margin-left:0;transition:left .2s;text-align:center}',
        ]);

        $this->assertNotContains('rtl-physical-prop', $this->rules($report));
    }

    public function test_inline_style_physical_properties_warn(): void
    {
        $report = $this->lintFiles([
            'templates/index.liquid' => '<div style="padding-right: 8px">{{ restaurant.title | escape }}</div>',
        ]);

        $rtl = $this->byRule($report, 'rtl-physical-prop');
        $this->assertCount(1, $rtl);
        $this->assertSame('templates/index.liquid', $rtl[0]['file']);
        $this->assertStringContainsString('inline style', $rtl[0]['message']);
        $this->assertStringContainsString('padding-inline-end', $rtl[0]['message']);
    }

    // ── unescaped-setting-output ────────────────────────────────────────

    public function test_unescaped_setting_output_warns_unless_escaped_or_safe_typed(): void
    {
        $section = <<<'LIQUID'
<section style="--accent: {{ section.settings.accent }}">
  <h2>{{ section.settings.heading }}</h2>
  <p>{{ section.settings.sub | escape }}</p>
  <p>{{ section.settings.cols | plus: 1 }}</p>
  <div>{{ section.settings.about }}</div>
  {% for block in section.blocks %}<b>{{ block.settings.title }}</b><i>{{ block.settings.tone }}</i>{% endfor %}
  <a href="{{ section.settings.link | escape }}">{{ settings.brand_line }}</a>
  <em>{{ settings.brand_line | t }}</em><span>{{ settings.accent_on }}</span>
</section>
{% schema %}
{"name":"Hero","settings":[
  {"id":"accent","type":"color"},{"id":"heading","type":"text"},{"id":"sub","type":"text"},
  {"id":"cols","type":"range","min":1,"max":4},{"id":"about","type":"richtext"},{"id":"link","type":"url"}],
 "blocks":[{"type":"row","settings":[{"id":"title","type":"text"},{"id":"tone","type":"color_token"}]}]}
{% endschema %}
LIQUID;

        $report = $this->lintFiles([
            'templates/index.json' => json_encode(['sections' => ['hero' => ['type' => 'hero']], 'order' => ['hero']]),
            'sections/hero.liquid' => $section,
            'config/settings_schema.json' => json_encode([
                ['name' => 'Brand', 'settings' => [['id' => 'brand_line', 'type' => 'text'], ['id' => 'accent_on', 'type' => 'checkbox']]],
            ]),
        ]);

        $this->assertTrue($report['passed']);
        $flagged = array_map(
            fn ($w) => preg_match("/'\\{\\{ (.+?) \\}\\}'/", $w['message'], $m) ? $m[1] : $w['message'],
            $this->byRule($report, 'unescaped-setting-output')
        );
        sort($flagged);
        $this->assertSame(['block.settings.title', 'section.settings.heading', 'settings.brand_line'], $flagged);
    }

    // ── img-missing-dimensions ──────────────────────────────────────────

    public function test_images_without_both_dimensions_warn_once_per_file(): void
    {
        $report = $this->lintFiles([
            'templates/index.liquid' => '<img src="a.png" alt=""><img src="b.png" width="10" alt="">'
                .'<img src="c.png" width="10" height="10" alt=""><IMG height="3" width="4" src="d.png" alt="">',
        ]);

        $img = $this->byRule($report, 'img-missing-dimensions');
        $this->assertCount(1, $img);
        $this->assertStringStartsWith('2 <img>', $img[0]['message']);
    }

    // ── section-css-unscoped ────────────────────────────────────────────

    public function test_library_css_helper_accepts_scoped_css(): void
    {
        $css = <<<'CSS'
@charset "utf-8";
/* .stray { } in a comment is ignored */
.mbs-hero{padding-block:8px}
.mbs-hero .mbs-hero__title, .mbs-hero__cta:hover, .mbs-hero[data-size="l"] > img{margin-inline-start:0}
.mbs-hero:is(.a, .b) p{color:var(--tc-text)}
.mbs-hero--wide .mbs-hero__media{inline-size:100%}
@media (min-width: 600px){ .mbs-hero .grid{display:grid} @supports (gap:1px){ .mbs-hero .grid{gap:8px} } }
@keyframes mbs-hero-spin{from{opacity:0}50%{opacity:.5}to{opacity:1}}
CSS;

        $report = ThemeLinter::lintLibraryCss('hero', $css);

        $this->assertTrue($report['passed']);
        $this->assertSame([], $report['errors']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_library_css_helper_rejects_every_unscoped_selector(): void
    {
        $css = 'body{margin:0} .mbs-hero-cover{} .mbs-heroic{} .other .mbs-hero{} :root{--x:1}'
            .' .mbs-hero, h1{} @media (max-width:500px){ .x{} .mbs-hero .ok{} } .mbs-hero .t{text-align:left}';

        $report = ThemeLinter::lintLibraryCss('hero', $css);

        $this->assertFalse($report['passed']);
        $bad = array_map(fn ($e) => preg_match("/selector '(.+?)'/", $e['message'], $m) ? $m[1] : '', $report['errors']);
        $this->assertSame(['body', '.mbs-hero-cover', '.mbs-heroic', '.other .mbs-hero', ':root', 'h1', '.x'], $bad);
        $this->assertSame(['section-css-unscoped'], array_values(array_unique(array_column($report['errors'], 'rule'))));
        $this->assertSame('sections/hero.css', $report['errors'][0]['file']);
        // Physical properties are reported alongside, as warnings.
        $this->assertSame(['rtl-physical-prop'], array_column($report['warnings'], 'rule'));
    }

    public function test_library_lint_scans_each_section_css_file_as_errors(): void
    {
        $this->libDir = storage_path('app/test-studio-lib-'.uniqid());
        File::ensureDirectoryExists($this->libDir.'/1/sections');
        File::put($this->libDir.'/1/sections/hero.css', '.mbs-hero{} .card{}');
        File::put($this->libDir.'/1/sections/faq.css', '.mbs-faq summary{}');

        $report = (new ThemeLinter)->lintLibrary(new SectionLibrary('1', $this->libDir.'/1'));

        $this->assertFalse($report['passed']);
        $this->assertCount(1, $report['errors']);
        $this->assertSame('sections/hero.css', $report['errors'][0]['file']);
    }

    public function test_a_themes_own_unscoped_section_css_is_only_a_warning(): void
    {
        $report = $this->lintFiles([
            'templates/index.liquid' => '<h1>{{ restaurant.title | escape }}</h1>',
            'assets/sections/hero.css' => '.mbs-hero{} .hero-title{}',
        ]);

        $this->assertTrue($report['passed']);
        $unscoped = $this->byRule($report, 'section-css-unscoped');
        $this->assertCount(1, $unscoped);
        $this->assertStringContainsString("'.hero-title'", $unscoped[0]['message']);
        $this->assertNotContains('section-css-unscoped', $this->rules($report, 'errors'));
    }

    // ── shipped themes: new warnings only, never new errors ─────────────

    public function test_shipped_themes_still_pass_and_only_gain_image_dimension_warnings(): void
    {
        foreach (['starter', 'crave', 'bazaar'] as $slug) {
            $theme = new MarketplaceTheme($slug, '1', resource_path("liquid-themes/{$slug}/1"));
            $report = (new ThemeLinter)->lint($theme);

            $this->assertTrue($report['passed'], "{$slug} must still lint clean");
            $newRules = array_intersect($this->rules($report), ['rtl-physical-prop', 'unescaped-setting-output', 'section-css-unscoped', 'img-missing-dimensions']);
            $this->assertSame(['img-missing-dimensions'], array_values(array_unique($newRules)), $slug);
        }
    }

    // ── Lighthouse gate job ─────────────────────────────────────────────

    private function makeZip(bool $dirty = false): string
    {
        @unlink($this->zipPath);
        $zip = new ZipArchive;
        $zip->open($this->zipPath, ZipArchive::CREATE);
        $zip->addFromString('theme.json', json_encode(['name' => 'Studio Gate', 'slug' => 'studiogate', 'version' => '1.0.0']));
        $zip->addFromString('templates/index.liquid', $dirty
            ? '{{ restaurant.title | totally_fake_filter }}'
            : '<h1>{{ restaurant.title | escape }}</h1>');
        $zip->close();

        return $this->zipPath;
    }

    /** A gate double: scripted availability + scores, or a failing runner. */
    private function fakeGate(bool $available, array $scores = [], bool $throws = false, int $previewStatus = 200): LighthouseGate
    {
        FakeLighthouseGate::$isAvailable = $available;
        // The job probes each preview URL anonymously before auditing it.
        Http::fake(['*' => Http::response('preview', $previewStatus)]);

        return new FakeLighthouseGate($scores, $throws);
    }

    private function submitClean(): ThemeVersion
    {
        config(['platform.lighthouse.auto_gate' => false]);

        return app(ThemeSubmissionService::class)->submit($this->makeZip());
    }

    public function test_a_clean_submission_queues_the_gate_job(): void
    {
        Bus::fake();
        config(['platform.lighthouse.auto_gate' => true]);

        $version = app(ThemeSubmissionService::class)->submit($this->makeZip());

        $this->assertSame('review', $version->status);
        Bus::assertDispatched(RunThemeLighthouseGate::class, fn ($job) => $job->versionId === $version->id);
    }

    public function test_a_lint_rejected_submission_or_a_disabled_auto_gate_queues_nothing(): void
    {
        Bus::fake();
        config(['platform.lighthouse.auto_gate' => true]);
        $rejected = app(ThemeSubmissionService::class)->submit($this->makeZip(dirty: true));
        $this->assertSame('rejected', $rejected->status);

        File::deleteDirectory(storage_path('app/marketplace-themes/studiogate'));
        ThemeVersion::query()->delete();
        config(['platform.lighthouse.auto_gate' => false]);
        app(ThemeSubmissionService::class)->submit($this->makeZip());

        Bus::assertNotDispatched(RunThemeLighthouseGate::class);
    }

    public function test_missing_lighthouse_records_a_skipped_gate_and_keeps_the_version_in_review(): void
    {
        $version = $this->submitClean();

        new RunThemeLighthouseGate($version->id)->handle(app(ThemeSubmissionService::class), $this->fakeGate(false));

        $version->refresh();
        $this->assertSame('review', $version->status);
        $this->assertTrue($version->gate_report['skipped']);
        $this->assertNull($version->gate_report['passed']);
        $this->assertStringContainsString('not installed', $version->gate_report['reason']);
    }

    public function test_a_failing_lighthouse_run_is_skipped_not_a_rejection(): void
    {
        $version = $this->submitClean();

        new RunThemeLighthouseGate($version->id)->handle(app(ThemeSubmissionService::class), $this->fakeGate(true, throws: true));

        $version->refresh();
        $this->assertSame('review', $version->status);
        $this->assertTrue($version->gate_report['skipped']);
        $this->assertStringContainsString('Lighthouse run failed', $version->gate_report['reason']);
    }

    public function test_an_unreachable_preview_is_skipped_instead_of_auditing_an_error_page(): void
    {
        $version = $this->submitClean();
        $gate = $this->fakeGate(true, array_fill(0, 4, ['performance' => 99.0, 'accessibility' => 99.0]), previewStatus: 404);

        new RunThemeLighthouseGate($version->id)->handle(app(ThemeSubmissionService::class), $gate);

        $version->refresh();
        $this->assertSame('review', $version->status);
        $this->assertTrue($version->gate_report['skipped']);
        $this->assertStringContainsString('HTTP 404', $version->gate_report['reason']);
    }

    public function test_a_passing_gate_audits_the_preview_ltr_and_rtl_and_records_scores(): void
    {
        $version = $this->submitClean();
        $gate = $this->fakeGate(true, array_fill(0, 4, ['performance' => 80.0, 'accessibility' => 96.0]));
        Carbon::setTestNow(now()); // the signed URLs embed their expiry

        new RunThemeLighthouseGate($version->id)->handle(app(ThemeSubmissionService::class), $gate);

        $version->refresh();
        $this->assertSame('review', $version->status);
        $this->assertTrue($version->gate_report['passed']);
        $this->assertCount(4, $version->gate_report['runs'], '2 URLs × mobile + desktop');
        $urls = array_values(array_unique(array_column($version->gate_report['runs'], 'url')));
        $this->assertSame(RunThemeLighthouseGate::previewUrls($version), $urls);
        Carbon::setTestNow();
        // Follow-ups: temporary SIGNED previews, so the session-less auditor can
        // open a version that is still in review.
        $this->assertStringContainsString('marketplace/studiogate/preview?', $urls[0]);
        $this->assertStringContainsString('version=1.0.0', $urls[0]);
        $this->assertStringContainsString('signature=', $urls[0]);
        $this->assertStringNotContainsString('lang=', $urls[0]);
        $this->assertStringContainsString('lang=ar', $urls[1]);
    }

    public function test_a_gate_below_the_bar_auto_rejects(): void
    {
        $version = $this->submitClean();
        $gate = $this->fakeGate(true, array_fill(0, 4, ['performance' => 30.0, 'accessibility' => 95.0]));

        new RunThemeLighthouseGate($version->id)->handle(app(ThemeSubmissionService::class), $gate);

        $version->refresh();
        $this->assertSame('rejected', $version->status);
        $this->assertStringContainsString('Quality gate failed', $version->review_notes);
    }

    public function test_the_gate_never_touches_a_version_a_human_already_decided(): void
    {
        $version = $this->submitClean();
        $version->update(['status' => 'published']);

        new RunThemeLighthouseGate($version->id)->handle(app(ThemeSubmissionService::class), $this->fakeGate(false));

        $this->assertNull($version->fresh()->gate_report);
    }

    public function test_admin_queue_shows_a_skipped_gate_with_its_reason(): void
    {
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1, 'site_admin_logo' => 'logo.png'])
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
        $this->withoutMiddleware([Installed::class]);

        $version = $this->submitClean();
        RunThemeLighthouseGate::skip($version, 'Lighthouse is not installed on this host.');
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);

        $this->actingAs($admin)->get('admin/theme-reviews')
            ->assertOk()
            ->assertSee('skipped')
            ->assertSee('Lighthouse is not installed on this host.');
    }
}

/** Test double for the Lighthouse gate (static availability, scripted runs). */
class FakeLighthouseGate extends LighthouseGate
{
    public static bool $isAvailable = true;

    public function __construct(private array $scores = [], private bool $throws = false) {}

    public static function available(): bool
    {
        return static::$isAvailable;
    }

    protected function run(string $url, string $formFactor): array
    {
        if ($this->throws) {
            throw new \RuntimeException('lighthouse failed for '.$url);
        }

        return array_shift($this->scores) ?? ['performance' => 0.0, 'accessibility' => 0.0];
    }
}
