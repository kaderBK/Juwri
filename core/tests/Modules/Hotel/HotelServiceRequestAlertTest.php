<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C6 — a guest service request used to be fire-and-forget: no staff alert, no status back
 * to the guest. requestStore now dispatches the owner's chosen channels (defaults: notif
 * ON, WhatsApp OFF, web push OFF), returns the created request, and the guest layer shows
 * the room's request status chips on re-scan.
 */
class HotelServiceRequestAlertTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $roomLabel = 'Room 204'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1', 'allow_ordering' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        foreach ([
            'store_template' => 'crave',
            'restaurant_on_table_order' => '1',
            'hotel_mode' => '1',
        ] as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1']);
        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => $roomLabel,
        ]);

        return [$store, $room];
    }

    private function submit(Store $store, StoreTable $room, array $extra = [])
    {
        return $this->postJson(route('hotel.guest.request', $store->slug), array_merge([
            'type' => 'housekeeping',
            'table' => $room->key,
            'note' => 'Please bring towels',
        ], $extra));
    }

    // ------------------------------------------------------------- toggle matrix

    public function test_notification_dispatched_by_default(): void
    {
        Bus::fake();
        [$store, $room] = $this->store();

        $this->submit($store, $room)->assertOk();

        Bus::assertDispatched(CreateNotificationJob::class, function ($job) {
            return $job->type === 'hotel_request';
        });
    }

    public function test_notification_not_dispatched_when_toggled_off(): void
    {
        Bus::fake();
        [$store, $room] = $this->store();
        StoreSettings::set($store->id, 'hotel_alerts_notif', '0');

        $this->submit($store, $room)->assertOk();

        Bus::assertNotDispatched(CreateNotificationJob::class);
    }

    public function test_whatsapp_not_dispatched_by_default(): void
    {
        Bus::fake();
        [$store, $room] = $this->store();

        $this->submit($store, $room)->assertOk();

        Bus::assertNotDispatched(SendWhatsAppJob::class);
    }

    public function test_whatsapp_dispatched_when_enabled_with_a_reception_number(): void
    {
        Bus::fake();
        [$store, $room] = $this->store();
        StoreSettings::set($store->id, 'hotel_alerts_whatsapp', '1');
        StoreSettings::set($store->id, 'hotel_settings', json_encode([
            'contact' => ['whatsapp' => '+1 (555) 010-2222'],
        ]));

        $this->submit($store, $room)->assertOk();

        Bus::assertDispatched(SendWhatsAppJob::class, function ($job) {
            return $job->feature === 'hotel_request' && trim($job->to) !== '';
        });
    }

    public function test_whatsapp_enabled_without_a_number_dispatches_nothing(): void
    {
        Bus::fake();
        [$store, $room] = $this->store();
        StoreSettings::set($store->id, 'hotel_alerts_whatsapp', '1');

        $this->submit($store, $room)->assertOk();

        Bus::assertNotDispatched(SendWhatsAppJob::class);
    }

    // ------------------------------------------------------------- markdown escape

    public function test_notification_title_escapes_markdown_control_chars(): void
    {
        Bus::fake();
        [$store, $room] = $this->store('Suite [A](x)');

        $this->submit($store, $room)->assertOk();

        Bus::assertDispatched(CreateNotificationJob::class, function ($job) {
            return str_contains($job->title, '\\[') && str_contains($job->title, '\\(');
        });
    }

    // ------------------------------------------------------------- response contract

    public function test_response_returns_the_created_request(): void
    {
        [$store, $room] = $this->store();

        $this->submit($store, $room)
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('request.type', 'housekeeping')
            ->assertJsonPath('request.status', 'open');
    }

    // ------------------------------------------------------------- guest-side chips

    // ------------------------------------------------------------- C9 department routing

    private function mapDepartment(Store $store, string $type, int $stationId): void
    {
        StoreSettings::set($store->id, 'hotel_settings', json_encode(['departments' => [$type => $stationId]]));
    }

    private function lastRequestDepartment(Store $store): ?int
    {
        $id = ServiceRequest::where('store_id', $store->id)->latest('id')->value('department_id');

        return $id !== null ? (int) $id : null;
    }

    public function test_request_routes_to_the_mapped_active_station(): void
    {
        [$store, $room] = $this->store();
        $grill = KitchenStation::create(['store_id' => $store->id, 'name' => 'Housekeeping', 'active' => true]);
        $this->mapDepartment($store, 'housekeeping', $grill->id);

        $this->submit($store, $room)->assertOk();

        $this->assertSame($grill->id, $this->lastRequestDepartment($store));
    }

    public function test_request_falls_back_to_first_active_when_mapped_station_is_deactivated(): void
    {
        [$store, $room] = $this->store();
        // The first active station (lowest id) is the fallback target.
        $active = KitchenStation::create(['store_id' => $store->id, 'name' => 'Front desk', 'active' => true]);
        $dead = KitchenStation::create(['store_id' => $store->id, 'name' => 'Old dept', 'active' => false]);
        $this->mapDepartment($store, 'housekeeping', $dead->id);

        $this->submit($store, $room)->assertOk();

        $this->assertSame($active->id, $this->lastRequestDepartment($store), 'a deactivated mapping must route to the first active station, never the dead one');
    }

    public function test_request_with_a_deactivated_mapping_and_no_active_station_is_unrouted_not_500(): void
    {
        [$store, $room] = $this->store();
        $dead = KitchenStation::create(['store_id' => $store->id, 'name' => 'Old dept', 'active' => false]);
        $this->mapDepartment($store, 'housekeeping', $dead->id);

        $this->submit($store, $room)->assertOk();

        $this->assertNull($this->lastRequestDepartment($store), 'with no active station the request is unrouted, never routed to a dead one');
    }

    public function test_request_without_a_mapping_stays_unrouted(): void
    {
        [$store, $room] = $this->store();
        KitchenStation::create(['store_id' => $store->id, 'name' => 'Kitchen', 'active' => true]);
        // No departments map at all.

        $this->submit($store, $room)->assertOk();

        $this->assertNull($this->lastRequestDepartment($store));
    }

    public function test_guest_layer_renders_status_chips_on_rescan(): void
    {
        [$store, $room] = $this->store();
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        ServiceRequest::create([
            'store_id' => $store->id, 'table_id' => $room->id, 'stay_id' => $stay->id,
            'type' => 'housekeeping', 'status' => 'in_progress',
        ]);

        // The current stay's requests show to a verified room (the printed QR carries the room token).
        $html = $this->get('/'.$store->slug.'?table='.$room->key.'&rt='.HotelRoomToken::mint($room))->assertOk()->getContent();

        $this->assertStringContainsString('id="mbHotelReqList"', $html, 'the requests card must render on re-scan');
        $this->assertStringContainsString('In progress', $html, 'the status chip label must render');
        $this->assertStringContainsString('mb-hotel-chip--in_progress', $html, 'the chip must carry its status modifier');
    }
}
