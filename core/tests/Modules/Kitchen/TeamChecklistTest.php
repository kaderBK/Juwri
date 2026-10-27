<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Services\Team\ChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Team\Models\ShiftNote;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN T-P2: checklists + shift notes.
 */
class TeamChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_team_comms' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    public function test_run_generation_is_idempotent(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        ChecklistTemplate::create(['store_id' => $r->id, 'name' => 'Open', 'schedule' => 'opening', 'items' => ['Unlock', 'Lights', 'Grill']]);
        $svc = new ChecklistService;
        $svc->generateForDate(now()->toDateString(), $r->id);
        $svc->generateForDate(now()->toDateString(), $r->id);
        $this->assertSame(1, ChecklistRun::where('store_id', $r->id)->count());
        $this->assertCount(3, ChecklistRun::first()->item_states);
    }

    public function test_toggle_tracks_completion(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $t = ChecklistTemplate::create(['store_id' => $r->id, 'name' => 'Open', 'schedule' => 'opening', 'items' => ['A', 'B']]);
        $svc = new ChecklistService;
        $run = $svc->ensureRun($t, now()->toDateString());
        $svc->toggleItem($run, 0, 5, true);
        $run->refresh();
        $this->assertTrue($run->item_states[0]['done']);
        $this->assertSame(5, $run->item_states[0]['by']);
        $this->assertSame(50, $run->progress());
    }

    public function test_page_renders_and_note_stores(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.team.checklists').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Checklists'));
        $this->actingAs($owner)->post(route('stores.team.notes.store').'?store_id='.$r->id, ['date' => now()->toDateString(), 'body' => 'Fridge broke'])->assertRedirect();
        $this->assertSame(1, ShiftNote::where('store_id', $r->id)->count());
    }

    public function test_command_runs(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        ChecklistTemplate::create(['store_id' => $r->id, 'name' => 'Close', 'schedule' => 'closing', 'items' => ['Lock']]);
        $this->artisan('checklists:generate')->assertExitCode(0);
        $this->assertSame(1, ChecklistRun::count());
    }
}
