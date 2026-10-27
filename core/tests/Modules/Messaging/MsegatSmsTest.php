<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Services\SmsService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N3 — Msegat SMS driver: payload shape, KSA number normalization, Arabic UTF8,
 * response-code map, merchant→platform credential resolution, no live calls.
 */
class MsegatSmsTest extends TestCase
{
    use RefreshDatabase;

    private function msegatCred(?int $storeId, string $sender = 'juwri'): void
    {
        Credential::create([
            'store_id' => $storeId,
            'feature' => 'sms',
            'provider' => 'msegat',
            'active' => true,
            'secrets' => ['username' => 'u1', 'api_key' => 'k1', 'sender' => $sender],
        ]);
    }

    public function test_number_normalization_matrix(): void
    {
        $svc = new SmsService;
        $this->assertSame('966501234567', $svc->msegatNumber('0501234567'));   // local 05x
        $this->assertSame('966501234567', $svc->msegatNumber('+966501234567')); // already intl
        $this->assertSame('966501234567', $svc->msegatNumber('00966501234567')); // 00 prefix
        $this->assertSame('966501234567', $svc->msegatNumber('501234567'));     // bare 5x
        $this->assertSame('966501234567', $svc->msegatNumber('966 50 123 4567')); // spaced
        $this->assertSame('', $svc->msegatNumber('   '));
    }

    public function test_success_sends_utf8_payload(): void
    {
        Http::fake(['*msegat.com*' => Http::response(['code' => '1', 'id' => 'BULK9'], 200)]);
        $this->msegatCred(null);

        $ok = new SmsService()->send('0501234567', 'مرحبا بك', null, 'order');

        $this->assertTrue($ok);
        Http::assertSent(function ($req) {
            $d = $req->data();

            return str_contains($req->url(), 'sendsms.php')
                && $d['userName'] === 'u1'
                && $d['apiKey'] === 'k1'
                && $d['userSender'] === 'juwri'
                && $d['numbers'] === '966501234567'
                && $d['msg'] === 'مرحبا بك'
                && $d['msgEncoding'] === 'UTF8';
        });
    }

    public function test_error_code_maps_to_failure(): void
    {
        Http::fake(['*msegat.com*' => Http::response(['code' => '1060'], 200)]); // no balance
        $this->msegatCred(null);

        $ok = new SmsService()->send('0501234567', 'hi', null, 'order');
        $this->assertFalse($ok);
    }

    public function test_merchant_override_wins_over_platform(): void
    {
        Http::fake(['*msegat.com*' => Http::response(['code' => 'M0000'], 200)]);
        $plan = TestPlans::create([
            'name' => 'P', 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);

        $this->msegatCred(null, 'PLATFORM');        // platform default
        $this->msegatCred($r->id, 'MERCHANT');      // merchant override

        $ok = new SmsService()->send('0501234567', 'hi', $r->id, 'order');
        $this->assertTrue($ok);
        Http::assertSent(fn ($req) => $req->data()['userSender'] === 'MERCHANT');
    }
}
