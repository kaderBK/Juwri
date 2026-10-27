<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Console\Commands\StudioSectionPr;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Ai\E2eStubAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Storefront\Models\AiSectionDraft;
use Juwri\Modules\Storefront\Services\ThemeStudio\AiSectionAuthor;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\SectionGates;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\StubSectionAuthor;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Tests\TestCase;

/**
 * Theme Studio phase E1: the AI section author — stub generation → gates →
 * stored draft, malicious drafts rejected by the gates, the repair loop,
 * the admin review queue (auth + feature gate, approve/reject), and
 * `studio:section-pr --dry-run` into a temp library that then passes
 * `studio:library-check`. Never calls a real AI provider.
 */
class AiSectionAuthorTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals([], true);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->app->instance(AiService::class, new E2eStubAiService($this->app->make(AiProviderFactory::class)));
        config([
            'theme_studio.section_author.browser' => false, // never launch Chrome in the suite
            'settings.admin_allow_ai_section_author' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->tmp) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function malicious(): array
    {
        return [
            'type' => 'evil-promo',
            'liquid' => '<section class="mbs-evil-promo" aria-label="promo"><script>alert(1)</script>'
                .'<a href="https://evil.example/x" onclick="steal()">{{ section.settings.heading }}</a></section>',
            'css' => 'body { background: red; } .mbs-evil-promo { margin-left: 4px; background: url(https://evil.example/x.png); }',
            'schema' => ['name' => 'Evil', 'settings' => [['id' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi']], 'presets' => [['name' => 'Evil']]],
            'locales' => ['en' => [], 'ar' => []],
            'fixture_notes' => '',
        ];
    }

    /** An AiService double answering from a queue and recording every call's messages. */
    private function fakeAi(array $answers): AiService
    {
        return new class($this->app->make(AiProviderFactory::class), $answers) extends AiService
        {
            /** @var list<array<mixed>> */
            public array $calls = [];

            public function __construct(AiProviderFactory $factory, private array $answers)
            {
                parent::__construct($factory);
            }

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                $this->calls[] = $messages;
                $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
                if ($validator) {
                    $validator($answer);
                }

                return $answer;
            }
        };
    }

    /** @return list<string> every gate error in a report */
    private function errors(array $report): array
    {
        return array_merge(...array_map(fn ($g) => $g['errors'], $report['gates']));
    }

    public function test_stub_generation_runs_every_gate_and_stores_a_passing_draft(): void
    {
        $draft = app(AiSectionAuthor::class)->author('A combo builder rail with step chips for mains, sides and drinks.', ['store'], ['type' => 'combo-builder', 'user_id' => 7]);

        $this->assertSame(AiSectionDraft::STATUS_PASSED, $draft->status, json_encode($draft->gate_report));
        $this->assertSame('combo-builder', $draft->type);
        $this->assertSame(0, $draft->repair_rounds);
        $this->assertCount(1, $draft->attempts);
        $this->assertStringContainsString('class="mbs-combo-builder"', $draft->liquid);
        $this->assertNotEmpty($draft->locales['ar']);

        $gates = collect($draft->gate_report['gates'])->keyBy('id');
        $this->assertSame(['shape', 'markup', 'lint', 'contract', 'css', 'render', 'browser'], $gates->keys()->all());
        foreach (['shape', 'markup', 'lint', 'contract', 'css', 'render'] as $id) {
            $this->assertSame('pass', $gates[$id]['status'], $id);
        }
        $this->assertSame('skipped', $gates['browser']['status']);
        $this->assertStringContainsString('disabled by config', $gates['browser']['meta']['reason']);
        $this->assertGreaterThan(8, $gates['render']['meta']['renders'], 'every vertical × en/ar + features on/off + empty store');
        $this->assertSame(7, $draft->created_by);
    }

    public function test_a_malicious_draft_is_rejected_by_the_gates(): void
    {
        $report = new SectionGates(SectionLibrary::forVersion('1'))->run($this->malicious(), ['store']);

        $this->assertFalse($report['passed']);
        $all = implode("\n", $this->errors($report));
        $this->assertStringContainsString('<script> tag', $all);
        $this->assertStringContainsString('inline event handler', $all);
        $this->assertStringContainsString('external URL not allowed: https://evil.example/x', $all);
        $this->assertStringContainsString("selector 'body' must start with '.mbs-evil-promo'", $all);
        $this->assertStringContainsString('physical property "margin-left"', $all);
        $this->assertStringContainsString('url() is not allowed', $all);
        $this->assertStringContainsString('raw colour', $all);
        $this->assertStringContainsString('unescaped-setting-output', $all);

        $statuses = collect($report['gates'])->pluck('status', 'id');
        foreach (['markup', 'lint', 'css'] as $id) {
            $this->assertSame('fail', $statuses[$id], $id);
        }
        $this->assertSame('skipped', $statuses['browser']);
    }

    public function test_an_existing_library_type_or_unreadable_answer_fails_the_shape_gate(): void
    {
        $gates = new SectionGates(SectionLibrary::forVersion('1'));

        $existing = StubSectionAuthor::respond([['role' => 'user', 'content' => "<<<REQUEST\n".json_encode(['request' => 'faq'])."\nREQUEST"]]);
        $existing['type'] = 'faq';
        $report = $gates->run($existing, ['store']);
        $this->assertFalse($report['passed']);
        $this->assertStringContainsString('already exists in section library 1', implode(' ', $this->errors($report)));

        $report = $gates->run(['type' => 'x', 'liquid' => ''], ['store']);
        $this->assertFalse($report['passed']);
        $this->assertNull($report['type']);
    }

    public function test_a_draft_that_never_passes_is_stored_as_failed_after_three_repair_rounds(): void
    {
        $ai = $this->fakeAi([$this->malicious()]);
        $draft = new AiSectionAuthor($ai)->author('Evil promo', ['store']);

        $this->assertSame(AiSectionDraft::STATUS_FAILED, $draft->status);
        $this->assertCount(1 + AiSectionAuthor::MAX_REPAIR_ROUNDS, $draft->attempts);
        $this->assertSame(AiSectionAuthor::MAX_REPAIR_ROUNDS, $draft->repair_rounds);
        $this->assertCount(4, $ai->calls);
        foreach ($draft->attempts as $attempt) {
            $this->assertFalse($attempt['report']['passed']);
        }
    }

    public function test_the_repair_loop_feeds_gate_errors_back_to_the_model(): void
    {
        $good = StubSectionAuthor::respond([['role' => 'user', 'content' => "<<<REQUEST\n".json_encode(['request' => 'Weekend picks', 'type' => 'weekend-picks'])."\nREQUEST"]]);
        $bad = $good;
        $bad['css'] = str_replace('margin-inline-start', 'margin-left', $bad['css']);
        $bad['liquid'] = str_replace('{{ heading | escape }}', '{{ section.settings.heading }}', $bad['liquid']);

        $ai = $this->fakeAi([$bad, $good]);
        $result = new AiSectionAuthor($ai)->run('Weekend picks', ['retail']);

        $this->assertTrue($result['passed']);
        $this->assertSame(1, $result['repair_rounds']);
        $this->assertCount(2, $ai->calls);

        $repair = end($ai->calls[1]);
        $this->assertSame('user', $repair['role']);
        $this->assertStringContainsString('GATE REPORT', $repair['content']);
        $this->assertStringContainsString('margin-left', $repair['content']);
        $this->assertStringContainsString('unescaped-setting-output', $repair['content']);
        $previous = $ai->calls[1][count($ai->calls[1]) - 2];
        $this->assertSame('assistant', $previous['role']);
        $this->assertStringContainsString('margin-left', $previous['content'], 'the previous answer is replayed');

        // The deterministic stub does the same when the request carries the flaw marker.
        $stub = app(AiSectionAuthor::class)->run('Seasonal picks '.StubSectionAuthor::FLAW_MARKER, ['store']);
        $this->assertTrue($stub['passed']);
        $this->assertSame(1, $stub['repair_rounds']);
        $this->assertFalse($stub['attempts'][0]['report']['passed']);
    }

    public function test_admin_pages_are_admin_only_and_behind_the_feature_gate(): void
    {
        foreach (['index', 'store', 'show', 'shot', 'approve', 'reject'] as $name) {
            $middleware = Route::getRoutes()->getByName('admin.section-author.'.$name)->gatherMiddleware();
            $this->assertContains('admin', $middleware, $name);
            $this->assertContains('csp.dashboard', $middleware, $name);
            $this->assertContains('feature:ai_section_author', $middleware, $name);
        }
        $this->assertStringEndsWith('/theme-studio/sections', route('admin.section-author.index'));

        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($owner)->postJson(route('admin.section-author.store'), ['request' => 'A combo builder rail please']);
        $this->assertSame(0, AiSectionDraft::count(), 'a non-admin cannot generate');
        $this->assertNotSame(200, $this->actingAs($owner)->get(route('admin.section-author.index'))->getStatusCode());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.section-author.index'))->assertOk()->assertSee('Review queue');

        config(['settings.admin_allow_ai_section_author' => 0]);
        $this->actingAs($admin)->get(route('admin.section-author.index'))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.section-author.store'), ['request' => 'A combo builder rail please'])->assertForbidden();
        $this->assertSame(0, AiSectionDraft::count());
    }

    public function test_generate_endpoint_then_detail_page(): void
    {
        $admin = $this->admin();
        $res = $this->actingAs($admin)->postJson(route('admin.section-author.store'), [
            'request' => 'Trust badges row with a few popular items.',
            'verticals' => ['restaurant', 'pharmacy'],
            'type' => 'trust-badges',
        ])->assertOk();

        $this->assertSame('passed', $res->json('status'));
        $draft = AiSectionDraft::findOrFail($res->json('id'));
        $this->assertSame(['restaurant', 'pharmacy'], $draft->verticals);
        $this->assertSame((int) $admin->id, (int) $draft->created_by);

        $this->actingAs($admin)->get($res->json('url'))->assertOk()
            ->assertSee('trust-badges')
            ->assertSee('Gate report')
            ->assertSee('core/resources/liquid-library/1/sections/trust-badges.liquid')
            ->assertSee('Approve');

        $this->actingAs($admin)->postJson(route('admin.section-author.store'), ['request' => 'short'])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.section-author.store'), ['request' => 'A long enough request', 'verticals' => ['spaceship']])->assertStatus(422);
        $this->actingAs($admin)->get(route('admin.section-author.shot', ['draft' => $draft->id, 'key' => 'en-mobile']))->assertNotFound();
    }

    public function test_approve_and_reject(): void
    {
        $admin = $this->admin();
        $draft = app(AiSectionAuthor::class)->author('Highlights with popular items', ['store'], ['type' => 'highlights-rail']);

        $this->actingAs($admin)->postJson(route('admin.section-author.reject', $draft->id), [])->assertStatus(422); // notes required
        $this->actingAs($admin)->postJson(route('admin.section-author.approve', $draft->id), ['notes' => 'Looks good'])->assertOk()->assertJson(['status' => 'approved']);
        $draft->refresh();
        $this->assertSame(AiSectionDraft::STATUS_APPROVED, $draft->status);
        $this->assertSame('Looks good', $draft->review_notes);
        $this->assertSame((int) $admin->id, (int) $draft->reviewed_by);
        $this->assertNotNull($draft->reviewed_at);
        $this->assertNull(SectionLibrary::forVersion('1')->sectionSource('highlights-rail'), 'approval never writes to the library');

        $this->actingAs($admin)->get(route('admin.section-author.show', $draft->id))->assertOk()->assertSeeHtml('studio:section-pr '.$draft->id);

        $this->actingAs($admin)->post(route('admin.section-author.reject', $draft->id), ['notes' => 'Changed my mind'])
            ->assertRedirect(route('admin.section-author.show', $draft->id));
        $this->assertSame(AiSectionDraft::STATUS_REJECTED, $draft->refresh()->status);

        $failed = new AiSectionAuthor($this->fakeAi([$this->malicious()]))->author('Evil promo', ['store']);
        $this->actingAs($admin)->postJson(route('admin.section-author.approve', $failed->id))->assertStatus(422);
        $this->assertSame(AiSectionDraft::STATUS_FAILED, $failed->refresh()->status);
    }

    /** A temp library 1: the real snippets + locales + one existing section. */
    private function tempLibrary(): array
    {
        $this->tmp = storage_path('framework/testing/ai-section-pr-'.bin2hex(random_bytes(4)));
        $lib = $this->tmp.'/library';
        $real = resource_path('liquid-library/1');
        File::copyDirectory($real.'/snippets', $lib.'/1/snippets');
        File::copyDirectory($real.'/locales', $lib.'/1/locales');
        File::ensureDirectoryExists($lib.'/1/sections');
        File::copy($real.'/sections/faq.liquid', $lib.'/1/sections/faq.liquid');
        File::ensureDirectoryExists($this->tmp.'/tools/src/sections');
        config(['platform.liquid_library.path' => $lib]);

        return [$lib, $this->tmp.'/tools'];
    }

    public function test_section_pr_dry_run_writes_into_a_temp_library_that_passes_the_library_check(): void
    {
        [$lib, $tools] = $this->tempLibrary();
        $draft = app(AiSectionAuthor::class)->author('Amenities strip with featured rooms', ['hotel'], ['type' => 'amenities-strip']);
        $this->assertSame(AiSectionDraft::STATUS_PASSED, $draft->status);

        // Not approved yet → refused.
        $this->assertSame(1, Artisan::call('studio:section-pr', ['id' => $draft->id, '--dry-run' => true, '--tools' => $tools]));
        $this->assertStringContainsString('only an approved draft', Artisan::output());

        $draft->forceFill(['status' => AiSectionDraft::STATUS_APPROVED, 'reviewed_by' => 1])->save();
        $code = Artisan::call('studio:section-pr', ['id' => $draft->id, '--dry-run' => true, '--tools' => $tools]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('git and gh skipped', $out);

        $this->assertFileExists($lib.'/1/sections/amenities-strip.liquid');
        $this->assertStringContainsString('{% schema %}', File::get($lib.'/1/sections/amenities-strip.liquid'));
        $this->assertFileExists($tools.'/src/sections/amenities-strip.css');
        $this->assertFileExists($lib.'/1/locales/en/ai-amenities-strip.json');
        $ar = json_decode(File::get($lib.'/1/locales/ar/ai-amenities-strip.json'), true);
        // assertEquals: the JSON column (MySQL / PostgreSQL) does not keep object key order.
        $this->assertEquals($draft->locales['ar'], $ar);

        // The section now renders through the library itself.
        $library = SectionLibrary::forVersion('1');
        $this->assertContains('amenities-strip', $library->sectionTypes());
        $check = Artisan::call('studio:library-check', ['--library' => '1']);
        $checkOut = Artisan::output();
        $this->assertSame(0, $check, $checkOut);
        $this->assertStringContainsString('2 sections', $checkOut);

        // A second run refuses: the type now exists.
        $this->assertSame(1, Artisan::call('studio:section-pr', ['id' => $draft->id, '--dry-run' => true, '--tools' => $tools]));
        $this->assertStringContainsString('already exists in library 1', Artisan::output());
    }

    public function test_section_pr_refuses_a_type_the_library_already_ships(): void
    {
        [, $tools] = $this->tempLibrary();
        $answer = StubSectionAuthor::respond([['role' => 'user', 'content' => "<<<REQUEST\n".json_encode(['request' => 'x', 'type' => 'faq-extra'])."\nREQUEST"]]);
        $draft = AiSectionDraft::create([
            'prompt' => 'faq clone', 'verticals' => ['all'], 'library_version' => '1', 'status' => AiSectionDraft::STATUS_APPROVED,
            'type' => 'faq', 'liquid' => str_replace('faq-extra', 'faq', $answer['liquid']), 'css' => str_replace('faq-extra', 'faq', $answer['css']),
            'schema' => $answer['schema'], 'locales' => $answer['locales'], 'fixture_notes' => '',
        ]);

        $this->assertSame(1, Artisan::call('studio:section-pr', ['id' => $draft->id, '--dry-run' => true, '--tools' => $tools]));
        $this->assertStringContainsString('Section type "faq" already exists in library 1', Artisan::output());
        $this->assertFileDoesNotExist($tools.'/src/sections/faq.css');
    }

    public function test_only_a_published_seller_theme_freezes_a_library_version(): void
    {
        $cmd = new StudioSectionPr;
        $frozen = new \ReflectionMethod($cmd, 'frozen');
        [$lib] = $this->tempLibrary();

        $theme = Theme::create(['name' => 'Seller', 'slug' => 'seller-pin', 'status' => 'published', 'engine' => 'liquid', 'seller_id' => 7]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'semver' => '1', 'manifest' => ['library' => '1'], 'checksum' => str_repeat('0', 64), 'status' => 'review']);
        $this->assertFalse($frozen->invoke($cmd, '1'), 'a version still in review does not freeze the library');

        $version->forceFill(['status' => 'published'])->save();
        $this->assertTrue($frozen->invoke($cmd, '1'), 'a published seller theme pinned to 1 freezes it');
        $this->assertFalse($frozen->invoke($cmd, '2'));

        $platform = Theme::create(['name' => 'Platform', 'slug' => 'platform-pin', 'status' => 'published', 'engine' => 'liquid']);
        ThemeVersion::create(['theme_id' => $platform->id, 'semver' => '1', 'manifest' => ['library' => '3'], 'checksum' => str_repeat('0', 64), 'status' => 'published']);
        $this->assertFalse($frozen->invoke($cmd, '3'), 'first-party themes do not freeze the library');
    }

    public function test_frozen_library_writes_into_the_next_minor_version(): void
    {
        [$lib, $tools] = $this->tempLibrary();
        File::put($lib.'/1/FROZEN', 'pinned by a published theme');
        // studio:library-check needs the version's built stylesheet (normally
        // written by build.mjs, which the test mode never runs).
        $css = public_path('assets/liquid-library/1.1/library.css');
        if (! is_file($css)) {
            File::ensureDirectoryExists(dirname($css));
            File::copy(public_path('assets/liquid-library/1/library.css'), $css);
            $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(dirname($css)));
        }
        $draft = app(AiSectionAuthor::class)->author('Service kits rail', ['carparts'], ['type' => 'service-kits']);
        $draft->forceFill(['status' => AiSectionDraft::STATUS_APPROVED])->save();

        $code = Artisan::call('studio:section-pr', ['id' => $draft->id, '--dry-run' => true, '--tools' => $tools]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('writing into library 1.1', $out);
        $this->assertFileExists($lib.'/1.1/sections/service-kits.liquid');
        $this->assertFileExists($lib.'/1.1/sections/faq.liquid', 'the new minor starts as a copy');
        $this->assertFileDoesNotExist($lib.'/1.1/FROZEN');
        $this->assertFileDoesNotExist($lib.'/1/sections/service-kits.liquid', 'the frozen version is untouched');
    }

    public function test_eval_command_reports_gate_pass_rates_on_the_stub(): void
    {
        $this->assertGreaterThanOrEqual(8, count(glob(base_path('tests/Fixtures/section-prompts/*.json'))));

        $code = Artisan::call('studio:eval-sections', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertSame('stub', $report['summary']['mode']);
        $this->assertSame(100.0, (float) $report['summary']['final_pass_rate']);
        $this->assertLessThan(100.0, (float) $report['summary']['first_try_pass_rate'], 'stub_flaw prompts exercise the repair loop');
        $this->assertSame(1, $report['summary']['max_repair_rounds']);
        $this->assertSame(100.0, (float) $report['summary']['gates_final']['render']);
    }
}
