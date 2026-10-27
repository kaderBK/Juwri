<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Biolink\Jobs\TranslateBioBlocksJob;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\Ai\BioBlockTranslationService;
use Juwri\Modules\Content\Services\Blog\Autopilot\FakeAutopilotAi;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TEMPLATES-RUN (Lane B · T2-2) — HTTP + execution tests for the bulk
 * "Translate page" feature. The HTTP endpoints are proven with Queue::fake (the
 * heavy job must be dispatched, not run inline); the actual translation (service
 * + job) is proven against a bound FakeAutopilotAi — never a live AI call.
 */
class BioTemplateTranslateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_biolink' => true, 'settings.admin_allow_ai_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function bindAi(): FakeAutopilotAi
    {
        $ai = new FakeAutopilotAi;
        $this->app->instance(AiService::class, $ai);

        return $ai;
    }

    /** Force exactly this active-language set, so target locales are deterministic. */
    private function onlyLocales(array $codes): void
    {
        Language::query()->update(['active' => 0]);
        $pos = 1;
        foreach ($codes as $code) {
            Language::updateOrCreate(
                ['code' => $code],
                ['name' => strtoupper($code), 'direction' => $code === 'ar' ? 'rtl' : 'ltr', 'active' => 1, 'position' => $pos++]
            );
        }
        app()->forgetInstance('memo.active_languages');
    }

    /** @return array{0: User, 1: Store} */
    private function storeOnPlan(array $planSettings = ['ai_biolink' => '1', 'biolink' => '1']): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => $planSettings + ['restaurant_limit' => 5, 'menu_limit' => 999, 'bio_blocks_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function staff(Store $store, array $permissions = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ] + ($permissions ? ['permissions' => $permissions] : []));
    }

    /* ─────────────────────────── endpoint (Queue::fake) ────────────────────── */

    public function test_owner_starts_a_translate_job_on_the_heavy_queue(): void
    {
        Queue::fake();
        [$owner, $store] = $this->storeOnPlan();

        $this->actingAs($owner)->postJson(route('biolink.translate'))
            ->assertOk()
            ->assertJson(['success' => true, 'progress_key' => TranslateBioBlocksJob::progressKey($store->id)]);

        Queue::assertPushed(TranslateBioBlocksJob::class, function ($job) use ($store, $owner) {
            return $job->storeId === $store->id
                && $job->userId === $owner->id
                && $job->queue === 'heavy';           // heavy queue (SPEC T2-2)
        });
    }

    public function test_translate_progress_reads_the_cache(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        Cache::put(TranslateBioBlocksJob::progressKey($store->id), ['status' => 'running', 'done' => 1, 'total' => 2, 'locale' => 'ar'], 60);

        $this->actingAs($owner)->getJson(route('biolink.translate.progress'))
            ->assertOk()
            ->assertJson(['success' => true, 'progress' => ['status' => 'running', 'done' => 1, 'total' => 2, 'locale' => 'ar']]);
    }

    public function test_translate_progress_idle_when_no_run(): void
    {
        [$owner, $store] = $this->storeOnPlan();

        $this->actingAs($owner)->getJson(route('biolink.translate.progress'))
            ->assertOk()
            ->assertJson(['progress' => ['status' => 'idle']]);
    }

    public function test_translate_is_killed_by_the_switch(): void
    {
        Queue::fake();
        [$owner, $store] = $this->storeOnPlan();
        config(['settings.admin_allow_ai_biolink' => false]);

        $this->actingAs($owner)->postJson(route('biolink.translate'))->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_translate_requires_the_plan_entitlement(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        Queue::fake();
        [$owner, $store] = $this->storeOnPlan(['biolink' => '1']);   // no ai_biolink

        $this->actingAs($owner)->postJson(route('biolink.translate'))->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_translate_is_tenant_scoped_idor(): void
    {
        Queue::fake();
        [$ownerA] = $this->storeOnPlan();
        [, $postB] = $this->storeOnPlan();

        $this->actingAs($ownerA)->postJson(route('biolink.translate', ['store_id' => $postB->id]))->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_staff_without_biolink_permission_cannot_translate(): void
    {
        Queue::fake();
        [$owner, $store] = $this->storeOnPlan();
        $staff = $this->staff($store, ['biolink' => false]);

        $this->actingAs($owner, 'web')->withSession(['staff_session_id' => $staff->id])
            ->postJson(route('biolink.translate'))->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_demo_mode_blocks_translate(): void
    {
        Queue::fake();
        User::factory()->create();   // owner is not user id 1 (demo-exempt)
        [$owner, $store] = $this->storeOnPlan();
        config(['app.demo_mode' => true]);

        $this->actingAs($owner)->postJson(route('biolink.translate'));
        Queue::assertNothingPushed();
    }

    /* ─────────────────────────── service / job execution ──────────────────── */

    public function test_service_fills_i18n_for_target_locales(): void
    {
        $this->onlyLocales(['en', 'ar']);
        [$owner, $store] = $this->storeOnPlan();

        $block = BioBlock::create([
            'store_id' => $store->id, 'branch_id' => null, 'type' => 'link',
            'label' => 'Order online', 'url' => 'https://x.test', 'config' => [], 'position' => 0, 'active' => true,
        ]);

        $service = app(BioBlockTranslationService::class);
        $targets = $service->targetLocales($store->id);
        $this->assertNotEmpty($targets);          // en+ar minus the base leaves ≥1
        $target = $targets[0];

        $ai = $this->bindAi();
        // One AI pass per target locale (same base string, deduped) → provide enough.
        $ai->pushJson(['s0' => 'ترجمة'])->pushJson(['s0' => 'ترجمة'])->pushJson(['s0' => 'ترجمة']);

        app(BioBlockTranslationService::class)->translateStore($store->id, $owner->id);

        $config = $block->fresh()->config;
        $this->assertSame('ترجمة', $config['_i18n'][$target]['label']);

        // Metered as bio_translate (SPEC T2-2).
        $this->assertSame('bio_translate', $ai->callsFor('json')[0]['feature']);
    }

    public function test_service_never_overwrites_an_owner_filled_override(): void
    {
        $this->onlyLocales(['en', 'ar']);
        [$owner, $store] = $this->storeOnPlan();

        $service = app(BioBlockTranslationService::class);
        $target = $service->targetLocales($store->id)[0];

        // The owner already translated this block's label into the target locale.
        $block = BioBlock::create([
            'store_id' => $store->id, 'branch_id' => null, 'type' => 'link',
            'label' => 'Order online', 'url' => 'https://x.test',
            'config' => ['_i18n' => [$target => ['label' => 'OWNER TEXT']]],
            'position' => 0, 'active' => true,
        ]);

        $ai = $this->bindAi();   // no responses scripted — nothing should be translated

        app(BioBlockTranslationService::class)->translateStore($store->id, $owner->id);

        // Untouched, and the AI was never asked (skip non-empty).
        $this->assertSame('OWNER TEXT', $block->fresh()->config['_i18n'][$target]['label']);
        $this->assertCount(0, $ai->callsFor('json'));
    }

    public function test_job_writes_progress_and_completes(): void
    {
        $this->onlyLocales(['en', 'ar']);
        [$owner, $store] = $this->storeOnPlan();
        BioBlock::create([
            'store_id' => $store->id, 'branch_id' => null, 'type' => 'announcement',
            'label' => 'Open late', 'config' => ['text' => 'Until 2am'], 'position' => 0, 'active' => true,
        ]);

        $ai = $this->bindAi();
        $ai->pushJson(['s0' => 'أ', 's1' => 'ب'])->pushJson(['s0' => 'أ', 's1' => 'ب']);

        new TranslateBioBlocksJob($store->id, $owner->id)->handle(app(BioBlockTranslationService::class));

        $progress = Cache::get(TranslateBioBlocksJob::progressKey($store->id));
        $this->assertSame('done', $progress['status']);
        $this->assertSame($store->id, $progress['store_id']);
        $this->assertNotEmpty($progress['result']['locales']);
    }

    public function test_job_records_error_status_on_failure(): void
    {
        $this->onlyLocales(['en', 'ar']);
        [$owner, $store] = $this->storeOnPlan();
        BioBlock::create([
            'store_id' => $store->id, 'branch_id' => null, 'type' => 'link',
            'label' => 'Order online', 'url' => 'https://x.test', 'config' => [], 'position' => 0, 'active' => true,
        ]);

        $this->bindAi()->failAll();   // provider outage → the batch throws

        try {
            new TranslateBioBlocksJob($store->id, $owner->id)->handle(app(BioBlockTranslationService::class));
        } catch (\Throwable $e) {
            // tries=1: the failure propagates; the progress key still records 'error'.
        }

        $this->assertSame('error', Cache::get(TranslateBioBlocksJob::progressKey($store->id))['status']);
    }
}
