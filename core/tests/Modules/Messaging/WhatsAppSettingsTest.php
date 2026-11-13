<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Messaging\Http\Controllers\Admin\WhatsAppSettingsController;
use Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppSettingsController as UserSettings;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_user_update_persists_options_and_byok(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        $this->actingAs($owner);

        $request = Request::create('/x', 'POST', [
            'whatsapp_provider' => 'twilio',
            'whatsapp_number_mode' => 'own',
            'whatsapp_number' => '+15551234567',
            'customer_order_whatsapp' => '1',
            'whatsapp_chatbot_enable' => '1',
            'whatsapp_order_template' => 'order_confirm',
            'twilio_sid' => 'AC999', 'twilio_token' => 'tok', 'twilio_from' => '+15550000000',
        ]);

        app(UserSettings::class)->update($request, $store);

        $this->assertSame('twilio', store_options($store->id, 'whatsapp_provider'));
        $this->assertEquals('1', store_options($store->id, 'customer_order_whatsapp'));
        $this->assertEquals('0', store_options($store->id, 'customer_status_whatsapp')); // unchecked -> 0
        $this->assertSame('order_confirm', store_options($store->id, 'whatsapp_order_template'));

        $cred = Credential::where(['store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio'])->first();
        $this->assertNotNull($cred);
        $this->assertSame('AC999', $cred->secrets['sid']);
        $this->assertTrue($cred->active);
    }

    public function test_user_update_blank_keys_do_not_wipe_existing(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        Credential::create(['store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'ACold', 'auth_token' => 'old'], 'active' => true]);
        $this->actingAs($owner);

        // Re-save the form with empty key fields.
        app(UserSettings::class)->update(Request::create('/x', 'POST', ['whatsapp_provider' => 'twilio']), $store);

        $cred = Credential::where(['store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio'])->first();
        $this->assertSame('ACold', $cred->secrets['sid']); // untouched
    }

    public function test_user_cannot_edit_another_owners_restaurant(): void
    {
        $store = $this->storeFixture(User::factory()->create(['status' => 1]));
        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->expectException(HttpException::class);
        app(UserSettings::class)->update(Request::create('/x', 'POST', ['whatsapp_provider' => 'meta']), $store);
    }

    public function test_admin_update_persists_settings_and_platform_keys(): void
    {
        $request = Request::create('/x', 'POST', [
            'whatsapp_default_provider' => 'meta',
            'whatsapp_billing_mode' => 'quota_then_wallet',
            'whatsapp_markup_pct' => '0.2',
            'meta_access_token' => 'EAAtoken', 'meta_phone_number_id' => 'PN1',
        ]);

        app(WhatsAppSettingsController::class)->update($request);

        $this->assertSame('meta', PlatformSettings::get('whatsapp_default_provider', false));
        $this->assertSame('quota_then_wallet', PlatformSettings::get('whatsapp_billing_mode', false));

        $cred = Credential::where(['store_id' => null, 'feature' => 'whatsapp', 'provider' => 'meta'])->first();
        $this->assertSame('EAAtoken', $cred->secrets['access_token']);
    }
}
