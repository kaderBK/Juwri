<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Legal\LegalAcceptances;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Models\LegalAcceptance;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\AccountProvisioner;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Core\Settings\UserSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-23: sign-up acceptance of the Terms / DPA / AUP (Terms §14.13) with a business-not-consumer confirmation and an
 * unticked product-news opt-in; one acceptance row per document version (user, version, time, IP); the dashboard
 * banner for a changed version (Terms §14.2, 30 days' notice, never a lock-out); rows are tenant-scoped like the
 * user, exported with the account and never removed by a user delete.
 */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        // Only 1.0 in force and nothing announced (the shipped Terms 1.1 / J51d announcement is covered in TermsChangeNoticeTest).
        config(['legal.enabled' => true, 'legal.documents.terms.versions' => ['1.0' => '2026-10-04']]);
        $_SERVER['HTTP_USER_AGENT'] = 'legal/phpunit';
    }

    /** @param  array<string, mixed>  $extra */
    private function signup(array $extra = []): TestResponse
    {
        Mail::fake();
        $plan = TestPlans::create([
            'name' => 'Trial', 'description' => 'T', 'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => (object) ['restaurant_limit' => 3], 'status' => 1,
        ]);
        config(['settings.default_user_plan' => (string) $plan->id]);

        return $this->post(route('register'), $extra + [
            'username' => 'legalowner',
            'email' => 'legal-owner@example.test',
            'password' => 'Legal-pass-1234',
            'password_confirmation' => 'Legal-pass-1234',
        ], ['REMOTE_ADDR' => '203.0.113.7']);
    }

    public function test_the_form_shows_the_three_boxes_with_the_opt_in_unticked(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString('name="agree_for_term"', $html);
        $this->assertStringContainsString('name="business_confirm"', $html);
        $this->assertMatchesRegularExpression('~<input type="checkbox" name="marketing_optin" value="1" id="marketing_optin"\s*>~', $html, 'the product-news opt-in must not be pre-ticked');
        $this->assertStringContainsString(e(LegalDocuments::url('terms')), $html);
        $this->assertStringContainsString(e(LegalDocuments::url('dpa')), $html);
        $this->assertStringContainsString(e(LegalDocuments::url('aup')), $html);
        $this->assertStringContainsString(e(__('site.signup.business')), $html);
    }

    public function test_sign_up_needs_the_acceptance_and_the_business_confirmation(): void
    {
        $this->signup()->assertSessionHasErrors(['agree_for_term', 'business_confirm']);
        $this->signup(['agree_for_term' => '1'])->assertSessionHasErrors(['business_confirm']);
        $this->signup(['business_confirm' => '1'])->assertSessionHasErrors(['agree_for_term']);

        $this->assertDatabaseMissing('users', ['email' => 'legal-owner@example.test']);
        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    public function test_sign_up_records_each_document_version_with_time_and_ip(): void
    {
        $this->signup(['agree_for_term' => '1', 'business_confirm' => '1'])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'legal-owner@example.test')->firstOrFail();
        $rows = LegalAcceptance::query()->where('user_id', $user->id)->orderBy('document')->get();

        $this->assertSame(['aup', 'dpa', 'terms'], $rows->pluck('document')->all());
        foreach ($rows as $row) {
            $this->assertSame('1.0', $row->version);
            $this->assertSame('signup', $row->source);
            $this->assertSame('203.0.113.7', $row->ip);
            $this->assertNotNull($row->accepted_at);
        }
        $this->assertSame([], LegalAcceptances::pending($user));
        $this->assertNull(UserSettings::legacy((int) $user->id, 'marketing_optin'), 'no opt-in unless ticked');
    }

    public function test_the_product_news_opt_in_is_stored_only_when_ticked(): void
    {
        $this->signup(['agree_for_term' => '1', 'business_confirm' => '1', 'marketing_optin' => '1'])->assertRedirect();

        $user = User::where('email', 'legal-owner@example.test')->firstOrFail();
        $this->assertSame('1', UserSettings::legacy((int) $user->id, 'marketing_optin'));
        $this->assertNotNull(UserSettings::legacy((int) $user->id, 'marketing_optin_at'));
    }

    public function test_without_the_legal_pages_sign_up_keeps_the_old_form_and_records_nothing(): void
    {
        config(['legal.enabled' => false]);

        $html = $this->get(route('register'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="business_confirm"', $html);

        $this->signup()->assertRedirect(route('dashboard'));
        $this->assertSame(0, LegalAcceptance::query()->count());
    }

    public function test_a_changed_version_shows_the_banner_and_the_owner_accepts_it(): void
    {
        $user = User::factory()->create(['status' => 1]);
        LegalAcceptances::record($user, 'signup');
        $this->assertSame([], LegalAcceptances::pending($user));

        // Terms 1.1 announced for in 30 days: notice now, nobody locked out.
        $effective = now()->addDays(30)->toDateString();
        config(['legal.documents.terms.versions' => ['1.0' => '2026-10-04', '1.1' => $effective]]);
        $this->assertSame('1.0', LegalDocuments::currentVersion('terms'));

        $pending = LegalAcceptances::pending($user);
        $this->assertSame(['terms'], array_column($pending, 'document'));
        $this->assertTrue($pending[0]['upcoming']);

        $this->actingAs($user);
        $banner = Blade::render('<x-ui.shell.legal-notice />');
        $this->assertStringContainsString('data-legal-notice', $banner);
        $this->assertStringContainsString(route('legal.accept'), $banner);

        $this->post(route('legal.accept'))->assertRedirect();

        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $user->id, 'document' => 'terms', 'version' => '1.1', 'source' => 'banner']);
        $this->assertSame([], LegalAcceptances::pending($user->fresh()));
        $this->assertStringNotContainsString('data-legal-notice', Blade::render('<x-ui.shell.legal-notice />'));
    }

    public function test_an_owner_who_never_accepted_sees_the_banner_and_admins_never_do(): void
    {
        $owner = User::factory()->create(['status' => 1]); // e.g. a social sign-up
        $this->assertCount(3, LegalAcceptances::pending($owner));

        $admin = User::factory()->create(['status' => 1, 'user_type' => 'admin']);
        $this->assertSame([], LegalAcceptances::pending($admin));

        config(['legal.enabled' => false]);
        $this->assertSame([], LegalAcceptances::pending($owner));
        $this->assertSame(0, LegalAcceptances::record($owner, 'signup'));
    }

    public function test_rows_are_tenant_scoped_exported_with_the_account_and_survive_a_user_delete(): void
    {
        $this->assertTrue(TenantTables::get('legal_acceptances')['account']);
        $this->assertContains('legal_acceptances', TenantTables::userOwned());
        $registered = StoreDataRegistry::get('legal_acceptances');
        $this->assertNotNull($registered, 'legal_acceptances is not in the store-data registry');
        $this->assertTrue($registered->exported);

        $user = User::factory()->create(['status' => 1]);
        $this->assertSame(3, LegalAcceptances::record($user, 'signup'));
        $this->assertSame(3, LegalAcceptance::query()->whereNull('account_id')->count(), 'no account before the first store');

        $account = AccountProvisioner::forUser($user->fresh());
        $this->assertSame(3, LegalAcceptance::query()->where('account_id', $account->id)->count(), 'the account adopts the rows');

        $inExport = StoreDataScope::account((int) $account->id)->query(DB::connection(), $registered);
        $this->assertNotNull($inExport);
        $this->assertSame(3, $inExport->count());

        // Contract evidence: no foreign key cascade from users.
        $other = User::factory()->create(['status' => 1]);
        LegalAcceptances::record($other, 'signup');
        DB::table('users')->where('id', $other->id)->delete();
        $this->assertSame(3, DB::table('legal_acceptances')->where('user_id', $other->id)->count());
    }
}
