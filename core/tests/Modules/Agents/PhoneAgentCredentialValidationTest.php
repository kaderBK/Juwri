<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PhoneAgentCredentialValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
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
        $this->withoutMiddleware();
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

    private const SID = 'AC0123456789abcdef0123456789ABCDEF';

    private const SK = 'SK0123456789abcdef0123456789ABCDEF';

    private function savePhone(string $provider, array $extra, ?string $locale = null)
    {
        if ($locale) {
            app()->setLocale($locale);
        }
        [$owner, $r] = $this->ownerRestaurant();
        Http::fake();
        $res = $this->actingAs($owner)->post(route('stores.phoneagent.save'), array_merge([
            'provider' => $provider, 'number' => '+1555'.random_int(1000000, 9999999), 'store_id' => $r->id,
        ], $extra));

        return [$res, $r];
    }

    public function test_valid_twilio_and_telnyx_formats_pass_and_are_stored(): void
    {
        [$res, $r] = $this->savePhone('twilio', ['account_sid' => self::SID, 'api_key' => self::SK, 'auth_token' => 't']);
        $res->assertSessionHasNoErrors();
        $this->assertSame(self::SID, Credential::where('store_id', $r->id)->where('feature', 'twilio')->first()->secrets['account_sid']);

        $key = base64_encode(str_repeat('k', 32));
        foreach (['1234567890123', '3fa85f64-5717-4562-b3fc-2c963f66afa6'] as $cid) {
            [$res] = $this->savePhone('telnyx', ['connection_id' => $cid, 'public_key' => $key]);
            $res->assertSessionHasNoErrors();
        }
    }

    public static function invalid(): array
    {
        return [
            'sid prefix' => ['twilio', 'account_sid', 'XX0123456789abcdef0123456789ABCDEF'],
            'sid short' => ['twilio', 'account_sid', 'AC123'],
            'sid non-hex' => ['twilio', 'account_sid', 'AC0123456789abcdef0123456789ABCDEG'],
            'sk short' => ['twilio', 'api_key', 'SK123'],
            'sk wrong prefix' => ['twilio', 'api_key', 'AC0123456789abcdef0123456789ABCDEF'],
            'connection id junk' => ['telnyx', 'connection_id', 'not-an-id'],
            'public key not base64' => ['telnyx', 'public_key', '!!!not base64!!!'],
            'public key wrong length' => ['telnyx', 'public_key', 'YWJj'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_values_fail_with_friendly_english_message(string $provider, string $field, string $value): void
    {
        [$res, $r] = $this->savePhone($provider, [$field => $value], 'en');
        $res->assertSessionHasErrors($field);
        $msg = session('errors')->first($field);
        $this->assertStringNotContainsString('validation.', $msg);
        $this->assertMatchesRegularExpression('/Twilio|Telnyx/', $msg);
        $this->assertSame(0, Credential::where('store_id', $r->id)->count());
    }

    #[DataProvider('invalid')]
    public function test_invalid_values_fail_with_arabic_message_different_from_english(string $provider, string $field, string $value): void
    {
        [$res] = $this->savePhone($provider, [$field => $value], 'en');
        $en = session('errors')->first($field);
        app('session.store')->flush();
        [$res] = $this->savePhone($provider, [$field => $value], 'ar');
        $res->assertSessionHasErrors($field);
        $ar = session('errors')->first($field);
        $this->assertNotSame($en, $ar);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar);
    }

    public function test_blank_values_keep_existing_secrets(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Http::fake();
        Credential::create(['store_id' => $r->id, 'feature' => 'twilio', 'provider' => 'twilio', 'secrets' => ['account_sid' => self::SID, 'api_key' => self::SK], 'active' => true]);

        $this->actingAs($owner)->post(route('stores.phoneagent.save'), [
            'provider' => 'twilio', 'number' => '+15550009999', 'store_id' => $r->id,
            'account_sid' => '', 'api_key' => '', 'auth_token' => '',
        ])->assertSessionHasNoErrors();

        $secrets = Credential::where('store_id', $r->id)->where('feature', 'twilio')->first()->secrets;
        $this->assertSame(self::SID, $secrets['account_sid']);
        $this->assertSame(self::SK, $secrets['api_key']);
    }

    public function test_recording_enabled_without_region_fails_in_english_and_creates_no_line(): void
    {
        [$res, $r] = $this->savePhone('twilio', [
            'account_sid' => self::SID, 'api_key' => self::SK, 'recording_enabled' => 1,
        ], 'en');

        $res->assertSessionHasErrors('recording_region');
        $this->assertSame('Recording region is required when recording is on.', session('errors')->first('recording_region'));
        $this->assertSame(0, PhoneLine::where('store_id', $r->id)->count());
    }

    public function test_recording_enabled_without_region_fails_with_arabic_message(): void
    {
        $extra = ['account_sid' => self::SID, 'api_key' => self::SK, 'recording_enabled' => 1];
        $this->savePhone('twilio', $extra, 'en');
        app('session.store')->flush();
        [$res] = $this->savePhone('twilio', $extra, 'ar');

        $res->assertSessionHasErrors('recording_region');
        $this->assertSame('منطقة التسجيل مطلوبة عند تفعيل التسجيل.', session('errors')->first('recording_region'));
    }

    public function test_recording_enabled_with_region_is_saved(): void
    {
        [$res, $r] = $this->savePhone('twilio', [
            'account_sid' => self::SID, 'api_key' => self::SK, 'recording_enabled' => 1, 'recording_region' => 'eu',
        ], 'en');

        $res->assertSessionHasNoErrors();
        $line = PhoneLine::where('store_id', $r->id)->first();
        $this->assertNotNull($line);
        $this->assertTrue((bool) $line->recording_enabled);
        $this->assertSame('eu', $line->recording_region);
    }

    public function test_recording_disabled_without_region_is_saved(): void
    {
        [$res, $r] = $this->savePhone('twilio', [
            'account_sid' => self::SID, 'api_key' => self::SK, 'recording_enabled' => 0,
        ], 'en');

        $res->assertSessionHasNoErrors();
        $line = PhoneLine::where('store_id', $r->id)->first();
        $this->assertNotNull($line);
        $this->assertFalse((bool) $line->recording_enabled);
        $this->assertNull($line->recording_region);
    }
}
