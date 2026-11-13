<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C7 — room identity on the kitchen board. A room_service order stores the human room
 * label in service_location, but the KDS payload omitted it and the card hardcoded a
 * "Table" prefix, so a room-204 order read as "Table 204" (or blank) and a room labelled
 * "Penthouse" was unidentifiable. The payload now carries service_location and the card
 * prefixes room orders with "Room".
 */
class HotelKdsRoomIdentityTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function ownerAndStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'Plan '.Str::random(6), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    public function test_poll_payload_carries_the_room_service_location(): void
    {
        [$owner, $store] = $this->ownerAndStore();
        Order::create([
            'store_id' => $store->id, 'status' => 'new', 'type' => 'room_service',
            'customer_name' => 'Sam', 'table_number' => 204, 'service_location' => 'Room 204',
            'created_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson(route('kds.poll', ['store_id' => $store->id]))
            ->assertOk()
            ->assertJsonFragment(['type' => 'room_service', 'service_location' => 'Room 204']);
    }

    public function test_scheduled_payload_carries_the_room_service_location(): void
    {
        [$owner, $store] = $this->ownerAndStore();
        Order::create([
            'store_id' => $store->id, 'status' => 'new', 'type' => 'room_service',
            'customer_name' => 'Sam', 'table_number' => 204, 'service_location' => 'Room 204',
            'scheduled_at' => now()->addHours(8), 'fire_at' => now()->addHours(7),
            'created_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson(route('kds.scheduled', ['store_id' => $store->id]))
            ->assertOk()
            ->assertJsonFragment(['type' => 'room_service', 'service_location' => 'Room 204']);
    }

    public function test_kds_card_prefixes_room_orders_with_room(): void
    {
        [$owner, $store] = $this->ownerAndStore();

        $html = $this->actingAs($owner)->get(route('kds.index'))->assertOk()->getContent();

        // The live and scheduled card labels both branch on the room_service type.
        $this->assertStringContainsString("o.type === 'room_service'", $html, 'live card must label room orders by room');
        $this->assertStringContainsString("s.type === 'room_service'", $html, 'scheduled card must label room orders by room');
        $this->assertStringContainsString('o.service_location', $html, 'the card must read the room label from the payload');
    }
}
