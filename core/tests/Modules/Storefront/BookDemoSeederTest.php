<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Storefront\Database\Seeders\BookDemoSeeder;
use Tests\TestCase;

/** P3-11: the demo salon on the booking engine — services, staff, hours and settings from the demo content; renders the `book` theme. */
class BookDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_is_an_on_demand_seeder(): void
    {
        $this->assertContains(BookDemoSeeder::class, array_merge(...array_values(ModuleSeeders::all())));
    }

    public function test_the_salon_gets_its_services_staff_and_hours_and_renders_the_book_theme(): void
    {
        $this->seed(BookDemoSeeder::class);
        $this->seed(BookDemoSeeder::class); // idempotent
        $store = Store::where('slug', 'sidra-salon-demo')->firstOrFail();

        $this->assertSame(23, BookableService::where('store_id', $store->id)->count());
        $this->assertSame(5, BookingStaff::where('store_id', $store->id)->count());
        $this->assertSame(6, BookingHour::where('store_id', $store->id)->count(), 'open Sunday to Friday, closed Saturday');
        $this->assertSame(12000, (int) BookableService::where('store_id', $store->id)->where('name', 'Haircut & blow-dry')->value('price_minor'));
        $this->assertSame('amount', BookableService::where('store_id', $store->id)->where('name', 'Haircut & blow-dry')->value('deposit_type'));
        $this->assertSame('percent', BookableService::where('store_id', $store->id)->where('name', 'Blow-dry')->value('deposit_type'));

        $html = $this->get('/sidra-salon-demo')->assertOk()->getContent();
        $this->assertStringContainsString('data-theme-slug="book"', $html);
        $this->assertStringContainsString('Haircut &amp; blow-dry', $html);
        $this->assertStringContainsString('At home', $html);

        $service = BookableService::where('store_id', $store->id)->where('name', 'Gel manicure')->firstOrFail();
        $times = $this->get('/sidra-salon-demo/appointments/times?services[]='.$service->public_id.'&date=2026-10-05')->assertOk()->getContent();
        $this->assertStringContainsString('class="c-slot', $times);
    }
}
