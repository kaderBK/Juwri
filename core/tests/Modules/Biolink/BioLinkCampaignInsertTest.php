<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TPL lane-d (T2-3): the "Insert bio link" composer control on the
 * email/SMS campaign composer and the WhatsApp broadcast composer.
 *
 * Regression guard first: a store whose bio page is dark shows the composer
 * EXACTLY as today — no button, no bio URL leaked into the page. Only a live
 * bio surfaces the control and its `?src=share` URL.
 */
class BioLinkCampaignInsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Skip ONLY the dashboard gate middleware (they redirect a test user with
        // no synced dashboard session), keeping SubstituteBindings (WhatsApp uses
        // {store} route-model binding) and ShareErrorsFromSession (the email
        // composer's @error directives need $errors). Auth still holds via actingAs.
        $this->withoutMiddleware([
            Installed::class,
            CheckUserIsBanned::class,
            Locale::class,
            Authenticate::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
            AddDashboardCspHeaders::class,
        ]);

        // Canonical layout context (stdClass via ViewServiceProvider's idiom) — not a
        // hand-rolled double, so ViewComposerContractTest does not count this file.
        $this->shareLayoutGlobals();
    }

    /** @return array{0: User, 1: Store} */
    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function goLive(Store $store): void
    {
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ]));
        StoreOption::flushOptionMemo();
    }

    /* ------------------------------------------------------- email/SMS composer */

    public function test_email_composer_hides_the_control_when_bio_dark(): void
    {
        [$owner] = $this->ownerRestaurant();

        $this->actingAs($owner)->get(route('campaigns.create'))
            ->assertOk()
            ->assertDontSee('Insert bio link')
            ->assertDontSee('src=share');
    }

    public function test_email_composer_shows_the_control_when_bio_live(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->goLive($store);

        $this->actingAs($owner)->get(route('campaigns.create'))
            ->assertOk()->assertSee('Insert bio link')->assertSeeHtml('bio?src=share');
    }

    /* -------------------------------------------------------- WhatsApp composer */

    public function test_whatsapp_composer_hides_the_control_when_bio_dark(): void
    {
        [$owner, $store] = $this->ownerRestaurant();

        $this->actingAs($owner)->get(route('stores.whatsappCampaign', $store->id))
            ->assertOk()
            ->assertDontSee('Insert bio link')
            ->assertDontSee('src=share');
    }

    public function test_whatsapp_composer_shows_the_control_when_bio_live(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->goLive($store);

        $this->actingAs($owner)->get(route('stores.whatsappCampaign', $store->id))
            ->assertOk()->assertSee('Insert bio link')->assertSeeHtml('bio?src=share');
    }

    public function test_whatsapp_composer_is_owner_scoped(): void
    {
        [$owner] = $this->ownerRestaurant();
        [, $foreign] = $this->ownerRestaurant();
        $this->goLive($foreign);

        $this->actingAs($owner)->get(route('stores.whatsappCampaign', $foreign->id))
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }
}
