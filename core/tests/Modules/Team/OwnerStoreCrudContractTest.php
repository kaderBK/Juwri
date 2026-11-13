<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09e characterisation of the owner store screens in User\StoreController:
 * index / create / store / show / edit / update / destroy. Pins the persisted
 * columns, the default post options of a new store, flashes, ownership 404s
 * and the JSON shape of a delete.
 */
class OwnerStoreCrudContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function owner(int $limit = 5, bool $verified = true): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 200,
            'settings' => (object) ['restaurant_limit' => $limit], 'status' => 1,
        ]);

        return User::factory()->create([
            'status' => 1, 'email_verified_at' => $verified ? now() : null, 'group_id' => (string) $plan->id,
        ]);
    }

    private function storeOf(User $user): Store
    {
        return Store::create([
            'user_id' => $user->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Mine', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function validPayload(array $extra = []): array
    {
        return array_merge([
            'color' => '#123456',
            'title' => 'Olive Table',
            'sub_title' => 'Fresh food',
            'description' => 'A restaurant',
            'address' => '1 Main St',
            'timing' => '9-5',
            'phone' => '555',
            'store_template' => app(StorefrontThemeService::class)->defaultSlug(),
            'link_label1' => 'Site', 'link1' => 'https://a.test',
            'link_label10' => 'Ten', 'link10' => 'https://j.test',
            'bio_title' => 'Bio', 'never_show_title' => 'Never', 'bio_description' => 'About',
            'bio_color_1' => '#010101', 'bio_color_12' => '#121212',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
        ], $extra);
    }

    public function test_index_renders_the_store_list_view(): void
    {
        $this->actingAs($this->owner())->get(route('stores.index'))
            ->assertOk()->assertViewIs('owner.stores.index');
    }

    public function test_create_at_the_limit_goes_back_with_the_spotlight_flag(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $user = $this->owner(1);
        $this->storeOf($user);

        $this->actingAs($user)->from('/somewhere')->get(route('stores.create'))
            ->assertRedirect('/somewhere')
            ->assertSessionHas('spotlight_limit', ['type' => 'restaurant', 'used' => 1, 'limit' => 1]);
        $this->assertSame(___('Limit exceeded, please upgrade your membership.'), session('app_alert_message'));
    }

    public function test_create_passes_templates_and_theme_sets(): void
    {
        $this->actingAs($this->owner())->get(route('stores.create'))
            ->assertOk()->assertViewIs('owner.stores.create')
            ->assertViewHas('templates', fn ($t) => is_array($t) && $t !== [])
            ->assertViewHas('templateSets', fn ($s) => is_array($s))
            ->assertViewHas('verticalThemeSets', fn ($s) => array_key_exists('restaurant', $s))
            ->assertViewHas('studioDraftPending', false);
    }

    public function test_store_persists_columns_and_default_options(): void
    {
        config(['settings.currency_code' => 'EUR', 'settings.currency_sign' => '€', 'settings.currency_pos' => '0']);
        $user = $this->owner();

        $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $this->validPayload())
            ->assertRedirect(route('stores.index'));
        $this->assertSame(___('Your store has been created.'), session('app_alert_message'));

        $store = Store::where('user_id', $user->id)->sole();
        // The stores list gets the confirmation payload (which store, what next).
        $created = session('store_created');
        $this->assertIsArray($created);
        $this->assertSame($store->id, (int) $created['id']);
        $this->assertSame('Olive Table', $created['title']);

        $this->assertSame('olive-table', $store->slug);
        $this->assertSame('restaurant', $store->store_type);
        foreach ([
            'color' => '#123456', 'title' => 'Olive Table', 'sub_title' => 'Fresh food', 'description' => 'A restaurant',
            'address' => '1 Main St', 'timing' => '9-5', 'phone' => '555',
            'link_label1' => 'Site', 'link1' => 'https://a.test', 'link_label10' => 'Ten', 'link10' => 'https://j.test',
            'bio_title' => 'Bio', 'never_show_title' => 'Never', 'bio_description' => 'About',
            'bio_color_1' => '#010101', 'bio_color_12' => '#121212',
        ] as $column => $value) {
            $this->assertSame($value, $store->getAttribute($column), $column);
        }
        foreach (['link_label2', 'link5', 'bio_color_6'] as $column) {
            $this->assertNull($store->getAttribute($column), $column);
        }
        $this->assertNotEmpty($store->main_image);
        $this->assertNotEmpty($store->cover_image);

        $options = StoreSettings::legacyMap((int) $store->id);
        $expected = [
            'store_template' => app(StorefrontThemeService::class)->defaultSlug(),
            'allow_call_waiter' => '1', 'allow_multi_branch_switch' => '1', 'allow_slider_popup' => '0',
            'allow_auto_user_info' => '1', 'allow_auto_popup' => '1', 'open_close_store' => '1',
            'prevent_order_on_close' => '1', 'show_lang_switch_on_bio_page' => '1',
            'show_background_img_on_bio_page' => '1', 'show_logo_img_on_bio_page' => '1',
            'show_title_on_bio_page' => '1', 'show_discription_on_bio_page' => '1',
            'show_timing_on_bio_page' => '1', 'show_social_on_bio_page' => '1', 'show_links_on_bio_page' => '1',
            'show_continue_btn_on_bio_page' => '1', 'show_never_show_on_bio_page' => '1',
            'show_open_close_store' => '0', 'show_open_bio_close_store' => '0',
            'restaurant_on_table_order' => '1', 'store_submit_order_notification' => '1',
            'currency_code' => 'EUR', 'currency_sign' => '€', 'currency_pos' => '0',
            // P6-108b: the platform currency is a placeholder until the store names its country.
            'currency_default_from' => 'EUR',
        ];
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $options, $key);
            $this->assertSame($value, (string) $options[$key], $key);
        }
    }

    public function test_store_keeps_an_explicit_slug(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->post(route('stores.store'), $this->validPayload(['slug' => 'my-own-slug']));

        $this->assertSame('my-own-slug', Store::where('user_id', $user->id)->sole()->slug);
    }

    /** BL-043: a store title another account already uses gives a new store its own slug, never the same one. */
    public function test_store_with_another_accounts_title_gets_its_own_slug(): void
    {
        $this->actingAs($this->owner())->post(route('stores.store'), $this->validPayload())->assertRedirect(route('stores.index'));
        $first = Store::query()->withoutGlobalScopes()->sole();
        $this->assertSame('olive-table', $first->slug);

        $second = $this->owner(); // a first-store owner: no account yet
        $this->actingAs($second)->post(route('stores.store'), $this->validPayload())->assertRedirect(route('stores.index'));
        $mine = Store::query()->withoutGlobalScopes()->where('user_id', $second->id)->sole();
        $this->assertStringStartsWith('olive-table-', $mine->slug);

        // An explicit slug another account holds is refused (any case-sensitivity of the driver aside).
        $third = $this->owner();
        $this->actingAs($third)->from(route('stores.create'))->post(route('stores.store'), $this->validPayload(['slug' => 'olive-table']))
            ->assertRedirect(route('stores.create'));
        $this->assertSame(0, Store::query()->withoutGlobalScopes()->where('user_id', $third->id)->count());
    }

    /** BL-043: the settings save derives a slug from a title another account uses — its own, never the same one. */
    public function test_update_with_another_accounts_title_keeps_slugs_unique(): void
    {
        $this->storeOf($this->owner())->update(['title' => 'Olive Table', 'slug' => 'olive-table']);
        $user = $this->owner();
        $store = $this->storeOf($user);

        $base = ['color' => '#111', 'title' => 'Olive Table', 'description' => 'D', 'address' => '1 St',
            'store_template' => app(StorefrontThemeService::class)->defaultSlug(), 'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => '0'];
        $this->actingAs($user)->put(route('stores.update', $store->id), $base)->assertRedirect();
        $this->assertSame('success', session('app_alert_type'), (string) session('app_alert_message'));
        $this->assertStringStartsWith('olive-table-', $store->fresh()->slug);

        $before = $store->fresh()->slug;
        $this->actingAs($user)->put(route('stores.update', $store->id), $base + ['slug' => 'olive-table'])
            ->assertRedirect()->assertSessionHas('app_alert_type', 'error');
        $this->assertStringContainsString('slug', (string) session('app_alert_message'));
        $this->assertSame($before, $store->fresh()->slug);
        $this->assertSame(1, Store::query()->withoutGlobalScopes()->where('slug', 'olive-table')->count());
    }

    public function test_store_validation_failure_flashes_every_message_joined(): void
    {
        $user = $this->owner();
        $payload = $this->validPayload();
        unset($payload['title'], $payload['address']);

        $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $payload)
            ->assertRedirect(route('stores.create'))
            ->assertSessionHasInput('color', '#123456');

        $message = (string) session('app_alert_message');
        $this->assertSame(1, substr_count($message, '<br>'), $message);
        $this->assertStringContainsString('title', $message);
        $this->assertStringContainsString('address', $message);
        $this->assertLessThan(strpos($message, 'address'), strpos($message, 'title'));
        $this->assertSame(0, Store::where('user_id', $user->id)->count());
    }

    public function test_store_requires_a_verified_email_unless_allowed(): void
    {
        config(['settings.non_active_allow' => 0]);
        $user = $this->owner(5, false);

        $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $this->validPayload())
            ->assertRedirect(route('stores.create'));
        $this->assertSame(___('Verify your email address to post any content.'), session('app_alert_message'));
        $this->assertSame(0, Store::where('user_id', $user->id)->count());
    }

    public function test_show_redirects_to_the_menu(): void
    {
        $user = $this->owner();
        $store = $this->storeOf($user);

        $this->actingAs($user)->get(route('stores.show', $store->id))
            ->assertRedirect(route('stores.menu', $store->id));
    }

    public function test_edit_is_owner_only_and_passes_the_editor_data(): void
    {
        $user = $this->owner();
        $store = $this->storeOf($user);

        $this->actingAs($user)->get(route('stores.edit', $store))
            ->assertOk()->assertViewIs('owner.stores.edit')
            ->assertViewHas('store', fn ($p) => $p->id === $store->id)
            ->assertViewHas('user', fn ($u) => $u->id === $user->id)
            ->assertViewHas('postOptions')
            ->assertViewHas('templates', fn ($t) => is_array($t))
            ->assertViewHas('cuisines')
            ->assertViewHas('postCuisineIds', []);

        $this->actingAs($this->owner())->get(route('stores.edit', $store))->assertNotFound();
    }

    public function test_update_is_owner_only_and_checks_email_verification(): void
    {
        $user = $this->owner();
        $store = $this->storeOf($user);

        $this->actingAs($this->owner())->put(route('stores.update', $store), ['title' => 'Hijack'])->assertNotFound();
        $this->assertSame('Mine', $store->fresh()->title);

        config(['settings.non_active_allow' => 0]);
        $unverified = $this->owner(5, false);
        $theirs = $this->storeOf($unverified);
        $this->actingAs($unverified)->from('/edit')->put(route('stores.update', $theirs), ['title' => 'New'])
            ->assertRedirect('/edit');
        $this->assertSame(___('Verify your email address to post any content.'), session('app_alert_message'));
    }

    public function test_update_validation_failure_flashes_and_goes_back(): void
    {
        $user = $this->owner();
        $store = $this->storeOf($user);

        $this->actingAs($user)->from('/edit')->put(route('stores.update', $store), ['title' => ''])
            ->assertRedirect('/edit');
        $this->assertNotSame('', (string) session('app_alert_message'));
        $this->assertSame('Mine', $store->fresh()->title);
    }

    public function test_destroy_deletes_only_an_own_store_and_always_answers_success(): void
    {
        $user = $this->owner();
        $store = $this->storeOf($user);
        $other = $this->storeOf($this->owner());

        $this->actingAs($user)->deleteJson(route('stores.destroy', $other))
            ->assertNotFound(); // BL-043: a stranger's request is refused by the owner tenant scope (404)
        $this->assertNotNull(Store::find($other->id));

        $this->actingAs($user)->deleteJson(route('stores.destroy', $store))
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertNull(Store::find($store->id));
    }
}
