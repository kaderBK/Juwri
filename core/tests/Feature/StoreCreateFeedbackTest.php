<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner reports (2026-10-12): creating a store "doesn't complete and sends me
 * back to the beginning". A server-side validation error must reopen the
 * wizard at the step holding the failing field with a visible error list, the
 * form must lock while it uploads, and a successful create must say which
 * store was made and what to do next.
 */
class StoreCreateFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        // Keep the real pipeline but skip the install-gate, CSRF and
        // staff-permission middlewares that would 4xx for reasons that have
        // nothing to do with the wizard feedback under test.
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
            'group_id' => (string) $plan->id,
        ]);
    }

    /**
     * A payload the wizard accepts (the field names are the ones
     * StoreController::newStoreRules() validates).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'color' => '#111111',
            'title' => 'Olive Table',
            'description' => 'A restaurant',
            'address' => '1 St',
            'store_template' => app(StorefrontThemeService::class)->defaultSlug(),
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
        ], $overrides);
    }

    /** A missing step-1 field reopens the wizard at step 1 and says why. */
    public function test_missing_step_one_field_reopens_basics_with_errors(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $this->payload(['description' => '']));

        $res->assertRedirect(route('stores.create'));
        $res->assertSessionHas('create_step', 'basics');
        $res->assertSessionHasErrors('description');
        $this->assertSame(0, Store::where('user_id', $user->id)->count());
    }

    /** A missing photo reopens the wizard at the template step, not at step 1. */
    public function test_missing_photo_reopens_the_template_step(): void
    {
        $user = $this->makeUser();
        $payload = $this->payload();
        unset($payload['main_image']);

        $res = $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $payload);

        $res->assertSessionHas('create_step', 'template');
        $res->assertSessionHasErrors('main_image');
        $this->assertSame(0, Store::where('user_id', $user->id)->count());
    }

    /** The reopened wizard shows the failing step, the errors, and the photo hint. */
    public function test_wizard_reopens_at_the_flashed_step_and_lists_errors(): void
    {
        $user = $this->makeUser();
        $payload = $this->payload();
        unset($payload['main_image']);

        // The server flashes the failing step and the error bag; the reopened
        // wizard must show both (plus the note about re-picking the photos).
        $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $payload)
            ->assertRedirect(route('stores.create'))
            ->assertSessionHas('create_step', 'template');

        $html = $this->actingAs($user)->get(route('stores.create'))->assertOk()->getContent();

        $this->assertStringContainsString("step: 'template'", $html);
        $this->assertStringContainsString('Your store was not created yet', $html);
        $this->assertStringContainsString('The main image field is required.', $html);
        $this->assertStringContainsString('choose your logo and cover photo again', $html);
    }

    /** The form locks itself while it uploads and validates each step on the way. */
    public function test_form_locks_while_submitting_and_validates_each_step(): void
    {
        $html = $this->actingAs($this->makeUser())->get(route('stores.create'))->assertOk()->getContent();

        $this->assertStringContainsString('@submit="onSubmit($event)"', $html);
        $this->assertStringContainsString("next('basics', 'template')", $html);
        $this->assertStringContainsString('data-step="basics"', $html);
        $this->assertStringContainsString('data-step="template"', $html);
        $this->assertStringContainsString('data-step="confirm"', $html);
        $this->assertStringContainsString('Creating your store…', $html);
    }

    /** A successful create lands on the store list with a clear confirmation. */
    public function test_success_redirects_with_a_clear_confirmation(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->from(route('stores.create'))
            ->post(route('stores.store'), $this->payload());

        $store = Store::where('user_id', $user->id)->sole();
        $res->assertRedirect(route('stores.index'));
        $res->assertSessionHas('store_created', fn (array $created) => $created['id'] === $store->id
            && $created['title'] === 'Olive Table'
            && $created['items'] === 0);

        $html = $this->actingAs($user)
            ->withSession(['store_created' => ['id' => $store->id, 'title' => $store->title, 'items' => 0]])
            ->get(route('stores.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Olive Table is ready.', $html);
        $this->assertStringContainsString(route('stores.menu', $store->id), $html);
        $this->assertStringContainsString('Get QR code', $html);
    }

    /** The confirmation never names a store the acting owner does not own. */
    public function test_confirmation_ignores_another_owners_store(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();

        $this->actingAs($other)->from(route('stores.create'))
            ->post(route('stores.store'), $this->payload(['title' => 'Not Yours']));
        $foreign = Store::where('user_id', $other->id)->sole();

        $html = $this->actingAs($owner)
            ->withSession(['store_created' => ['id' => $foreign->id, 'title' => $foreign->title, 'items' => 0]])
            ->get(route('stores.index'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Not Yours is ready.', $html);
    }
}
