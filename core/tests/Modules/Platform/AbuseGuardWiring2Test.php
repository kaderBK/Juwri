<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Models\AccountUsage;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AbuseHold;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Media\Services\StorageQuota;
use Tests\TestCase;

/**
 * Abuse Guard follow-up wiring (round 2): API-token hold freeze, storage backfill,
 * abuse_events retention prune.
 */
class AbuseGuardWiring2Test extends TestCase
{
    use RefreshDatabase;

    public function test_events_prune_deletes_old_rows(): void
    {
        AbuseEvent::create(['guard' => 'ai', 'action' => 'x', 'mode' => 'warn', 'created_at' => now()->subDays(120)]);
        AbuseEvent::create(['guard' => 'ai', 'action' => 'x', 'mode' => 'warn', 'created_at' => now()->subDays(2)]);

        Artisan::call('abuse:events-prune', ['--days' => 90]);

        $this->assertSame(1, AbuseEvent::count());
    }

    public function test_events_prune_zero_days_keeps_all(): void
    {
        AbuseEvent::create(['guard' => 'ai', 'action' => 'x', 'mode' => 'warn', 'created_at' => now()->subDays(400)]);
        Artisan::call('abuse:events-prune', ['--days' => 0]);
        $this->assertSame(1, AbuseEvent::count());
    }

    public function test_storage_backfill_sums_media_assets(): void
    {
        if (! Schema::hasTable('media_assets') || ! Schema::hasTable('stores')) {
            $this->markTestSkipped('media_assets/posts not present');
        }

        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'R', 'slug' => 'r-'.$owner->id,
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $storeId = $store->id;
        DB::table('media_assets')->insert([
            ['store_id' => $storeId, 'account_id' => $store->account_id, 'filename' => 'a.jpg', 'original_name' => 'a.jpg', 'size_bytes' => 1000, 'folder' => 'x'],
            ['store_id' => $storeId, 'account_id' => $store->account_id, 'filename' => 'b.jpg', 'original_name' => 'b.jpg', 'size_bytes' => 2000, 'folder' => 'x'],
        ]);

        Artisan::call('abuse:storage-backfill');

        $this->assertSame(3000, StorageQuota::used($owner->id));
        // P1-05: the counter lives in account_usage (not a dynamic platform setting) and joins the owner's account.
        $this->assertSame((int) $store->account_id, (int) AccountUsage::query()->where('user_id', $owner->id)->value('account_id'));
        $this->assertFalse(PlatformSettings::has('abuse_storage_bytes_'.$owner->id));
    }

    public function test_api_hold_freezes_writes_but_allows_reads(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'title' => 'R', 'slug' => 'r-'.$user->id,
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id, 'name' => 'T',
            'token' => hash('sha256', $raw), 'abilities' => ['read', 'write'],
        ]);

        AbuseHold::set($user->id, true);

        // WRITE → 423
        $this->withToken($raw)
            ->postJson(route('api.v1.menu.items.store', $r->id), ['name' => 'X', 'price' => 1])
            ->assertStatus(423);

        // READ → still allowed (not 423)
        $this->withToken($raw)
            ->getJson(route('api.v1.stores.menu', $r->id))
            ->assertStatus(200);
    }
}
