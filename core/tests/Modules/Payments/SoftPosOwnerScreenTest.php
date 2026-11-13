<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\TerminalAccount;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-29: the owner SoftPOS screen (softpos.index / card-terminals). With a provider on the
 * owner sees the "stays off until Juwri confirms your provider" hold notice, the merchant-id
 * form and the store's own callback URL; with every provider off there is no provider and no
 * form. Saved credentials are encrypted and never rendered back (an empty re-save keeps them);
 * staff dashboard sessions are refused; the screen is per-store and a guest is redirected to
 * login. The route and view are built as SoftPosAccountsController@show + owner.payments.card-terminals.
 */
class SoftPosOwnerScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function on(): void
    {
        config(['softpos.geidea.enabled' => true, 'softpos.edfapay.enabled' => true]);
    }

    /** @return array{store: Store, owner: User, cashier: StaffMember, manager: StaffMember} */
    private function make(): array
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $mk = fn (string $role) => StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => ucfirst($role).' '.$title,
            'email' => Str::random(8).'@t.test', 'password' => bcrypt('x'), 'role' => $role, 'active' => 1]);

        return ['store' => $store, 'owner' => $owner, 'cashier' => $mk('cashier'), 'manager' => $mk('manager')];
    }

    private function fresh(): static
    {
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function asOwner(array $t): static
    {
        return $this->fresh()->actingAs($t['owner'], 'web');
    }

    private function asStaff(StaffMember $s): static
    {
        return $this->fresh()->actingAs($s, 'staff');
    }

    /** A staff-dashboard session as StaffLoginController makes it: the owner on the web guard, the staff on the staff guard, staff_session_id set. */
    private function asStaffDashboard(array $t, StaffMember $s): static
    {
        return $this->fresh()->actingAs($t['owner'], 'web')->actingAs($s, 'staff')
            ->withSession(['staff_session_id' => $s->id, 'dashboard_store_id' => $t['store']->id]);
    }

    public function test_owner_sees_the_hold_notice_callback_url_and_merchant_form_when_a_provider_is_on(): void
    {
        $this->on();
        $t = $this->make();

        $this->asOwner($t)->get(route('softpos.index'))
            ->assertOk()
            ->assertSee('Card terminals stay off until Juwri confirms your provider')
            ->assertSee(route('webhook.softpos', ['provider' => 'geidea_softpos', 'store' => $t['store']->id]), false)
            ->assertSee('name="merchant_id"', false);
    }

    public function test_with_every_provider_off_the_owner_sees_no_provider_message_and_no_form(): void
    {
        $t = $this->make();

        $this->asOwner($t)->get(route('softpos.index'))
            ->assertOk()
            ->assertSee('No card terminal provider is available yet.')
            ->assertDontSee('Save card terminal');
    }

    public function test_saved_credentials_are_never_rendered_and_an_empty_resave_keeps_them(): void
    {
        $this->on();
        $t = $this->make();
        $save = fn (array $creds) => $this->asOwner($t)->postJson(route('pos.terminal.accounts.save', ['store' => $t['store']->id]),
            ['provider' => 'geidea_softpos', 'merchant_id' => 'M-'.$t['store']->id, 'enabled' => true, 'credentials' => $creds])->assertOk();
        $save(['public_key' => 'pk-SECRETPK-123456', 'api_password' => 'apipass-SECRET-987654']);

        // stored encrypted: never plaintext in the terminal_accounts row
        $raw = (string) DB::table('terminal_accounts')->where('store_id', $t['store']->id)->value('credentials');
        $this->assertStringNotContainsString('pk-SECRETPK-123456', $raw);
        $this->assertStringNotContainsString('apipass-SECRET-987654', $raw);

        // the page shows the merchant id but never the secrets, and no input carries one as its value
        $res = $this->asOwner($t)->get(route('softpos.index'))->assertOk();
        $res->assertSee('M-'.$t['store']->id, false);
        $res->assertDontSee('pk-SECRETPK-123456', false);
        $res->assertDontSee('apipass-SECRET-987654', false);
        $this->assertSame(0, preg_match('/value="[^"]*(?:SECRETPK-123456|SECRET-987654)[^"]*"/', $res->getContent()), 'no input field echoes a saved credential');

        // an empty credential re-save keeps the stored secret (P5-21 saveAccount semantics)
        $save(['public_key' => '', 'api_password' => '']);
        $account = TerminalAccount::query()->where('store_id', $t['store']->id)->first();
        $this->assertSame('pk-SECRETPK-123456', $account->credentials['public_key']);
        $this->assertSame('apipass-SECRET-987654', $account->credentials['api_password']);
        $this->assertStringNotContainsString('apipass-SECRET-987654', (string) $account->getRawOriginal('credentials'));
    }

    public function test_cashier_and_manager_sessions_are_refused_the_owner_screen(): void
    {
        $this->on();
        $t = $this->make();

        // softpos.index is unmapped in staff.dashboard, so every staff session is default-denied (403).
        $this->asStaffDashboard($t, $t['cashier'])->get(route('softpos.index'))->assertStatus(403);
        $this->asStaffDashboard($t, $t['manager'])->get(route('softpos.index'))->assertStatus(403);
    }

    public function test_the_screen_is_per_store_and_guests_are_redirected_to_login(): void
    {
        $this->on();
        $a = $this->make();
        $b = $this->make();
        $merchantA = 'M-'.$a['store']->id;
        $this->asOwner($a)->postJson(route('pos.terminal.accounts.save', ['store' => $a['store']->id]), ['provider' => 'geidea_softpos',
            'merchant_id' => $merchantA, 'enabled' => true, 'credentials' => ['public_key' => 'pk-A', 'api_password' => 'pw-A']])->assertOk();

        // owner B sees their own empty form, never owner A's merchant id
        $this->asOwner($b)->get(route('softpos.index'))
            ->assertOk()
            ->assertSee('name="merchant_id"', false)
            ->assertDontSee($merchantA, false);

        // a guest is redirected to login by the dashboard's real auth middleware
        $this->fresh()->get(route('softpos.index'))->assertRedirect();
    }
}
