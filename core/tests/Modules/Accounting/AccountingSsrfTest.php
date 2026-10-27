<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * S7 — the accounting BYOK base_url/token_url are POSTed customer PII by the
 * SyncInvoice job, so they must be public HTTPS endpoints. Private/loopback/
 * metadata/non-https URLs are rejected at connect time.
 */
class AccountingSsrfTest extends TestCase
{
    use RefreshDatabase;

    private function ownerRestaurant(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    private function connect(User $owner, Store $r, array $data): TestResponse
    {
        $this->withoutMiddleware();

        return $this->actingAs($owner)->post(
            route('stores.accounting.connect').'?store_id='.$r->id,
            array_merge(['provider' => 'quickbooks', 'access_token' => 'AT'], $data)
        );
    }

    public function test_private_base_url_is_rejected(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->connect($owner, $r, ['base_url' => 'http://127.0.0.1/invoices'])
            ->assertSessionHasErrors('base_url');
        $this->assertNull(Credential::where('store_id', $r->id)->where('feature', 'accounting')->first());
    }

    public function test_metadata_base_url_is_rejected(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->connect($owner, $r, ['base_url' => 'https://169.254.169.254/latest/meta-data'])
            ->assertSessionHasErrors('base_url');
        $this->assertNull(Credential::where('store_id', $r->id)->where('feature', 'accounting')->first());
    }

    public function test_non_https_base_url_is_rejected(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        // A public host over plain http must still be rejected.
        $this->connect($owner, $r, ['base_url' => 'http://8.8.8.8/invoices'])
            ->assertSessionHasErrors('base_url');
    }

    public function test_public_https_base_url_saves(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        // Literal public IP over https → no DNS, passes the guard.
        $this->connect($owner, $r, ['base_url' => 'https://8.8.8.8/invoices'])
            ->assertSessionDoesntHaveErrors('base_url')
            ->assertRedirect();
        $this->assertNotNull(Credential::where('store_id', $r->id)->where('feature', 'accounting')->first());
    }

    public function test_private_token_url_is_rejected(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->connect($owner, $r, ['base_url' => 'https://8.8.8.8/invoices', 'token_url' => 'http://127.0.0.1/token'])
            ->assertSessionHasErrors('token_url');
    }
}
