<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * google-suite Lane A (A5) — storefront "Connected accounts": disconnect a linked
 * social provider from the signed-in customer, with a lockout guard.
 */
class CustomerSocialUnlinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
        ]);
    }

    private function createRestaurant(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'resto-'.Str::lower(Str::random(6)),
            'color' => '#ff5500',
            'title' => 'Resto',
            'sub_title' => 'x',
            'description' => 'x',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    #[Test]
    public function unlink_clears_oauth_when_another_signin_method_exists()
    {
        $store = $this->createRestaurant();
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Linked',
            'email' => 'linked@example.test',
            'password' => Hash::make('secret123'),
            'oauth_provider' => 'google',
            'oauth_uid' => 'g-1',
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.social.unlink', $store->id))
            ->assertRedirect();

        $customer->refresh();
        $this->assertNull($customer->oauth_provider);
        $this->assertNull($customer->oauth_uid);
    }

    #[Test]
    public function unlink_refused_when_social_is_the_only_signin_method()
    {
        $store = $this->createRestaurant();
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'OnlySocial',
            'email' => 'only@example.test',
            'oauth_provider' => 'google',
            'oauth_uid' => 'g-2',
            // no password, no phone
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.social.unlink', $store->id))
            ->assertRedirect();

        $customer->refresh();
        // Still linked — refused to avoid lockout.
        $this->assertSame('google', $customer->oauth_provider);
        $this->assertSame('g-2', $customer->oauth_uid);
    }

    #[Test]
    public function unlink_allowed_when_only_phone_present()
    {
        $store = $this->createRestaurant();
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'PhoneOnly',
            'phone' => '15551234',
            'oauth_provider' => 'google',
            'oauth_uid' => 'g-3',
        ]);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.social.unlink', $store->id))
            ->assertRedirect();

        $customer->refresh();
        $this->assertNull($customer->oauth_provider);
    }

    #[Test]
    public function unlink_requires_a_signed_in_customer()
    {
        $store = $this->createRestaurant();

        $this->post(route('customer.social.unlink', $store->id))
            ->assertStatus(401);
    }

    #[Test]
    public function connected_accounts_section_renders_for_linked_customer()
    {
        $store = $this->createRestaurant();
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Linked',
            'email' => 'shown@example.test',
            'password' => Hash::make('secret123'),
            'oauth_provider' => 'google',
            'oauth_uid' => 'g-9',
        ]);

        $html = view('store_templates._partials.account-extras', [
            'store' => $store,
            'customer' => $customer,
            'postOptions' => (object) ['store_template' => 'crave'],
            'theme' => 'crave',
            'infoPages' => [],
            'errors' => new ViewErrorBag, // normally shared by web middleware
        ])->render();

        $this->assertStringContainsString(___('Connected accounts'), $html);
        $this->assertStringContainsString('Connected via Google', $html);
        $this->assertStringContainsString(route('customer.social.unlink', $store->id), $html);
    }
}
