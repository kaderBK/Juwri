<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Team\Models\TeamChannel;
use Juwri\Modules\Team\Services\TeamChatService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN T-P1: team chat — membership scope, read receipts, ack, XSS escape.
 */
class TeamChatTest extends TestCase
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

    private function scenario(): array
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
        $staff = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Sam', 'email' => 's'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true]);

        return [$owner, $r, $staff];
    }

    public function test_read_receipts_and_unread_count(): void
    {
        [$owner, $r, $staff] = $this->scenario();
        $svc = new TeamChatService;
        $channel = $svc->createChannel($r->id, 'Team', 'team', [$staff->id]);
        $m1 = $svc->post($channel, 'Hello', $staff->id);
        $m2 = $svc->post($channel, 'Second', $staff->id);

        // Owner has 2 unread.
        $this->assertSame(2, $svc->unreadCount($channel, null, true));
        $svc->markRead($m1, null, true);
        $this->assertSame(1, $svc->unreadCount($channel, null, true));
    }

    public function test_acknowledgement_tracking(): void
    {
        [$owner, $r, $staff] = $this->scenario();
        $svc = new TeamChatService;
        $channel = $svc->createChannel($r->id, 'Notice', 'announcement', [$staff->id], true);
        $m = $svc->post($channel, 'Read this', null, true);
        $svc->markRead($m, $staff->id, false, true);
        $this->assertCount(1, $svc->acknowledgers($m));
    }

    public function test_thread_escapes_xss_and_is_tenant_scoped(): void
    {
        [$owner, $r, $staff] = $this->scenario();
        [$other, $otherR] = $this->scenario();
        $svc = new TeamChatService;
        $channel = $svc->createChannel($r->id, 'Team', 'team', [$staff->id]);
        $svc->post($channel, '<script>alert(1)</script>', $staff->id);
        $foreign = $svc->createChannel($otherR->id, 'X', 'team', []);

        $this->withoutMiddleware();
        $res = $this->actingAs($owner)->get(route('stores.team.thread', $channel->id).'?store_id='.$r->id)->assertOk();
        $res->assertDontSeeHtml('<script>alert(1)</script>');
        $res->assertSeeHtml('&lt;script&gt;');

        $this->actingAs($owner)->get(route('stores.team.thread', $foreign->id).'?store_id='.$r->id)->assertNotFound();
    }

    public function test_owner_can_create_and_post(): void
    {
        [$owner, $r, $staff] = $this->scenario();
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.team.channels.store').'?store_id='.$r->id, [
            'name' => 'Ops', 'type' => 'group', 'staff_ids' => [$staff->id],
        ])->assertRedirect();
        $channel = TeamChannel::where('store_id', $r->id)->first();
        $this->actingAs($owner)->post(route('stores.team.post', $channel->id).'?store_id='.$r->id, ['body' => 'Hi team'])->assertRedirect();
        $this->assertSame(1, $channel->messages()->count());
    }
}
