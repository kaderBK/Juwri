<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Providers;

use App\Models\StoreTable;
use App\Services\Backup\StoreBackupService;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Hotel\Console\Commands\HotelNoShowSweep;
use Juwri\Modules\Hotel\Console\Commands\HotelPruneStays;
use Juwri\Modules\Hotel\Console\HotelSchedule;
use Juwri\Modules\Hotel\Database\Seeders\HotelDemoSeeder;
use Juwri\Modules\Hotel\Database\Seeders\HotelShowcaseSeeder;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Edition\HotelRoomCharge;

/**
 * Hotel module provider (P1-08e).
 */
class HotelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        HotelSchedule::register(); // P1-13: its console schedule entries (were in app/Console/Kernel)
        // P1-13: the store backup's hotel table owned here (was listed in StoreBackupService).
        StoreBackupService::extend('Hotel', 'drivers', [
            'hotel_stays' => ['model' => HotelStay::class, 'scope' => 'store_id', 'dataset' => 'hotel', 'pii' => true],
        ]);
        // P1-13 13c: the guest service requests table (was listed in StoreBackupService, floor & service section).
        StoreBackupService::extend('Hotel/requests', 'check_payments', [
            'service_requests' => ['model' => ServiceRequest::class, 'scope' => 'store_id', 'dataset' => 'floor_service', 'pii' => false],
        ]);
        StoreDataRegistry::register('Hotel', new HotelStoreData);
        $this->app->singleton(RoomCharge::class, HotelRoomCharge::class);

        // P1-13: hotel relations on Core/Tables models (were methods on Store and StoreTable).
        Store::resolveRelationUsing('hotelStays', fn (Store $store) => $store->hasMany(HotelStay::class, 'store_id'));
        // Hotel (T1): guest service requests for this restaurant.
        Store::resolveRelationUsing('serviceRequests', fn (Store $store) => $store->hasMany(ServiceRequest::class, 'store_id'));
        // Hotel (T1): stays that used this row as a room (`hotel_stays.table_id`; a room is an area_type='room' row).
        StoreTable::resolveRelationUsing('stays', fn (StoreTable $room) => $room->hasMany(HotelStay::class, 'table_id'));
        StoreTable::resolveRelationUsing('activeStay', fn (StoreTable $room) => $room->hasOne(HotelStay::class, 'table_id')->where('status', 'checked_in'));
        // HPMS A1: the sellable room type this room belongs to (nullable).
        StoreTable::resolveRelationUsing('roomType', fn (StoreTable $room) => $room->belongsTo(RoomType::class, 'room_type_id'));
        ModuleSeeders::register('Hotel', optional: [HotelDemoSeeder::class, HotelShowcaseSeeder::class]);
        ModuleRoutes::register('Hotel', __DIR__.'/../Routes');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([HotelNoShowSweep::class, HotelPruneStays::class]);
        }
    }
}
