<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WA-D4: read-logging on the legacy WhatsApp options.
 *
 * This is the evidence Lane E rests on. Before E3 deletes whatsapp_number we want
 * to be able to say "nothing has read one" rather than hope so — and the counter
 * has to be trustworthy, which means throttled and impossible to crash an order.
 */
class WhatsAppLegacyReadLogTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-' . Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Read Log Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    private function readCount(): int
    {
        return (int) (PlatformSettings::stored('whatsapp_legacy_read_count') ?: 0);
    }

    public function test_a_legacy_read_is_recorded(): void
    {
        Cache::flush();
        $store = $this->store();

        whatsapp_log_legacy_read($store->id, 'whatsapp_number');

        $this->assertSame(1, $this->readCount());
        $this->assertNotNull(PlatformSettings::stored('whatsapp_legacy_read_last_at'));
    }

    public function test_it_is_throttled_to_once_per_store_per_key_per_day(): void
    {
        Cache::flush();
        $store = $this->store();

        // A busy storefront must not flood the log or inflate the counter.
        for ($i = 0; $i < 10; $i++) {
            whatsapp_log_legacy_read($store->id, 'whatsapp_number');
        }

        $this->assertSame(1, $this->readCount());
    }

    public function test_different_stores_are_counted_separately(): void
    {
        Cache::flush();
        $a = $this->store();
        $b = $this->store();

        whatsapp_log_legacy_read($a->id, 'whatsapp_number');
        whatsapp_log_legacy_read($b->id, 'whatsapp_number');

        $this->assertSame(2, $this->readCount());
    }

    public function test_a_clean_platform_reports_zero(): void
    {
        // The state Lane E is waiting for.
        $this->assertSame(0, $this->readCount());
    }

    public function test_logging_never_throws(): void
    {
        Cache::flush();

        // Telemetry must never be able to break an order — a nonsense id included.
        whatsapp_log_legacy_read(0, 'whatsapp_number');
        whatsapp_log_legacy_read(999999999, 'whatsapp_number');

        $this->assertTrue(true, 'no exception escaped');
    }
}
