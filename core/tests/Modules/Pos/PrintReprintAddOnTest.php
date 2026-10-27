<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-333 / P6-334: manual reprints and add-on KOT tickets.
 */
class PrintReprintAddOnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        config(['settings.admin_allow_print_routing' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $r, float $price = 12, ?int $stationId = null): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(4),
            'price' => $price, 'active' => 1, 'station_id' => $stationId,
        ]);
    }

    private function profile(Store $r, array $attrs = []): PrinterProfile
    {
        return PrinterProfile::create(array_merge([
            'store_id' => $r->id, 'name' => 'Front', 'transport' => 'webserial',
            'width_mm' => 80, 'events' => ['new_order'], 'station_ids' => [],
            'auto_print' => true, 'active' => true, 'copies' => 1,
        ], $attrs));
    }

    private function order(Store $r, Menu $item): Order
    {
        return OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
    }

    // ── manual reprints ──────────────────────────────────────────────────────

    public function test_a_manual_reprint_adds_a_second_job_after_the_auto_print(): void
    {
        $r = $this->storeFixture();
        $profile = $this->profile($r, ['name' => 'Receipt', 'events' => ['new_order']]);
        $order = $this->order($r, $this->item($r));

        $auto = PrintJob::where('profile_id', $profile->id)->firstOrFail();
        $this->assertSame(1, PrintJob::where('profile_id', $profile->id)->count());

        $reprint = PrintDispatcher::printOrderTo($profile, $order->fresh(), 'new_order', true);

        $this->assertNotNull($reprint);
        $this->assertSame(2, PrintJob::where('profile_id', $profile->id)->count());
        $this->assertNotSame((int) $auto->id, (int) $reprint->id);
        $this->assertStringContainsString('-re-', (string) $reprint->job_key);
    }

    public function test_calling_on_order_created_again_still_dedupes(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['events' => ['kot']]);
        $order = $this->order($r, $this->item($r));

        $this->assertSame(1, PrintJob::count());

        PrintDispatcher::onOrderCreated($order->fresh());
        PrintDispatcher::onOrderCreated($order->fresh());

        $this->assertSame(1, PrintJob::count());
    }

    public function test_reprints_share_a_job_within_the_same_five_second_bucket(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0, 'UTC'));

        $r = $this->storeFixture();
        $profile = $this->profile($r, ['events' => []]);
        $order = $this->order($r, $this->item($r));

        $first = PrintDispatcher::printOrderTo($profile, $order->fresh(), 'new_order', true);
        $second = PrintDispatcher::printOrderTo($profile, $order->fresh(), 'new_order', true);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame($first->job_key, $second->job_key);
        $this->assertSame(1, PrintJob::count());

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 6, 'UTC'));

        $third = PrintDispatcher::printOrderTo($profile, $order->fresh(), 'new_order', true);

        $this->assertNotNull($third);
        $this->assertNotSame((int) $first->id, (int) $third->id);
        $this->assertSame(2, PrintJob::count());
    }

    public function test_a_reprint_payload_is_marked_as_a_reprint(): void
    {
        $r = $this->storeFixture();
        $profile = $this->profile($r, ['events' => []]);
        $order = $this->order($r, $this->item($r));

        $job = PrintDispatcher::printOrderTo($profile, $order->fresh(), 'new_order', true);

        $this->assertNotNull($job);
        $this->assertStringContainsString('REPRINT', base64_decode((string) $job->payload));
    }

    // ── add-on KOTs ──────────────────────────────────────────────────────────

    public function test_an_appended_bar_drink_prints_only_a_bar_add_on_kot(): void
    {
        $r = $this->storeFixture();
        $foodStation = KitchenStation::create(['store_id' => $r->id, 'name' => 'Food']);
        $barStation = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar']);

        $foodProfile = $this->profile($r, [
            'name' => 'Food printer', 'events' => ['kot'], 'station_ids' => [$foodStation->id],
        ]);
        $barProfile = $this->profile($r, [
            'name' => 'Bar printer', 'events' => ['kot'], 'station_ids' => [$barStation->id],
        ]);

        $food = $this->item($r, 20, $foodStation->id);
        $drink = $this->item($r, 8, $barStation->id);

        $order = $this->order($r, $food);

        $this->assertSame(1, PrintJob::where('profile_id', $foodProfile->id)->count());
        $this->assertSame(0, PrintJob::where('profile_id', $barProfile->id)->count());

        OrderApiService::appendItems($order->fresh(), [
            ['item_id' => $drink->id, 'quantity' => 1],
        ]);

        $this->assertSame(1, PrintJob::where('profile_id', $foodProfile->id)->count());

        $barJobs = PrintJob::where('profile_id', $barProfile->id)->get();
        $this->assertCount(1, $barJobs);

        $payload = base64_decode((string) $barJobs->first()->payload);
        $this->assertStringContainsString($drink->name, $payload);
        $this->assertStringContainsString('ADD-ON', $payload);
        $this->assertStringNotContainsString($food->name, $payload);
    }

    public function test_appending_twice_creates_two_add_ons_with_different_keys(): void
    {
        $r = $this->storeFixture();
        $foodStation = KitchenStation::create(['store_id' => $r->id, 'name' => 'Food']);
        $barStation = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar']);

        $foodProfile = $this->profile($r, [
            'name' => 'Food printer', 'events' => ['kot'], 'station_ids' => [$foodStation->id],
        ]);
        $barProfile = $this->profile($r, [
            'name' => 'Bar printer', 'events' => ['kot'], 'station_ids' => [$barStation->id],
        ]);

        $food = $this->item($r, 20, $foodStation->id);
        $firstDrink = $this->item($r, 8, $barStation->id);
        $secondDrink = $this->item($r, 9, $barStation->id);

        $order = $this->order($r, $food);

        OrderApiService::appendItems($order->fresh(), [
            ['item_id' => $firstDrink->id, 'quantity' => 1],
        ]);
        OrderApiService::appendItems($order->fresh(), [
            ['item_id' => $secondDrink->id, 'quantity' => 1],
        ]);

        $addOns = PrintJob::where('profile_id', $barProfile->id)
            ->where('job_key', 'like', '%kot-add-%')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $addOns);
        $this->assertNotSame($addOns[0]->job_key, $addOns[1]->job_key);
        $this->assertSame(0, PrintJob::where('profile_id', $foodProfile->id)
            ->where('job_key', 'like', '%kot-add-%')
            ->count());
    }

    public function test_appending_to_a_held_order_prints_nothing(): void
    {
        $r = $this->storeFixture();
        $barStation = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar']);
        $this->profile($r, ['events' => ['kot'], 'station_ids' => [$barStation->id]]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'held' => true,
            'items' => [['item_id' => $this->item($r, 20, $barStation->id)->id, 'quantity' => 1]],
        ]);

        $this->assertSame(0, PrintJob::count());

        OrderApiService::appendItems($order->fresh(), [
            ['item_id' => $this->item($r, 8, $barStation->id)->id, 'quantity' => 1],
        ]);

        $this->assertSame(0, PrintJob::count());
    }
}
