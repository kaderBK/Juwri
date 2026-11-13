<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Tests\Support\BookStoreScenario;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-11: the view model of a store on the booking engine is the contract's — every page of the flow (store page, service sheet,
 * choose a time, confirm, pay, the booking page, reschedule) validates against viewmodel.schema.json in en and ar; a service is an
 * item card with a duration; ids are public ids; times carry the store's zone.
 */
class BookingViewModelTest extends TestCase
{
    use BookStoreScenario;
    use RefreshDatabase;

    /** @var list<array{view: string, vm: array<string, mixed>}> */
    private array $seen = [];

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
        View::composer(['store_templates.book.index', 'store_templates.book.time', 'store_templates.book.details', 'store_templates.book.pay', 'store_templates.book.manage', 'store_templates.book.reschedule'], function ($view): void {
            $data = $view->getData();
            if (isset($data['vm'])) {
                $this->seen[] = ['view' => $view->name(), 'vm' => $data['vm']];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_page_of_the_flow_is_a_valid_view_model_in_en_and_ar(): void
    {
        ['store' => $store, 'services' => $services] = $this->bookScenarioStore();
        $records = $this->bookScenarioRecords($store, $services);
        $slug = $store->slug;
        $svc = (string) $services[0]->public_id;
        $names = [];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $kit = app(KitStorefront::class);
            View::make('store_templates.book.index', $kit->home($store, Request::create('/'.$slug), 'book'))->render();
            View::make('store_templates.book.index', $kit->home($store, Request::create('/'.$slug, 'GET', ['item' => $svc]), 'book'))->render();
            $this->bookStep(fn ($c, $r) => $c->times($slug, $r), ['services' => [$svc], 'date' => '2026-10-05']);
            $this->bookStep(fn ($c, $r) => $c->details($slug, $records['hold'], $r));
            $this->bookStep(fn ($c, $r) => $c->pay($slug, $records['pending']));
            $this->bookStep(fn ($c, $r) => $c->manage($slug, $records['confirmed']));
            $this->bookStep(fn ($c, $r) => $c->rescheduleTimes($slug, $records['confirmed'], $r), ['date' => '2026-10-06']);
        }

        $this->assertCount(16, $this->seen);
        foreach ($this->seen as $i => $row) {
            $names[] = $row['view'];
            $errors = ViewModelScenario::errors($row['vm']);
            $this->assertSame([], $errors, $row['view'].' #'.$i.': '.implode("\n", array_slice($errors, 0, 8)));
        }
        $this->assertContains('store_templates.book.manage', $names);
    }

    public function test_a_service_is_a_card_with_a_duration_and_the_item_page_names_its_rule(): void
    {
        ['store' => $store, 'services' => $services] = $this->bookScenarioStore();
        $scenario = new ViewModelScenario($store, 'book');
        $home = $scenario->build('en');

        $this->assertSame(['appointment'], array_column($home['context']['order_types'], 'key'));
        $this->assertCount(3, $home['catalog']['items']);
        $card = $home['catalog']['items'][(string) $services[0]->public_id];
        $this->assertSame(45, $card['duration_min']);
        $this->assertStringContainsString('45 min', $card['meta_label']);
        $this->assertTrue($card['venue']);
        $this->assertFalse($card['at_home']);
        $this->assertSame(['Maha', 'Lina'], array_column($home['context']['staff'], 'name'));
        $this->assertTrue($home['store']['status']['accepting_bookings']);
        $this->assertArrayHasKey('quote', $home['endpoints']);

        $item = $scenario->build('en', 'item', ['item' => (string) $services[0]->public_id])['page']['item'];
        $this->assertSame([], $item['option_groups']);
        $this->assertStringContainsString('reschedule up to 24 h', $item['policy_note']);
        $this->assertArrayHasKey('deposit', $item);

        $other = $scenario->build('en', 'item', ['item' => (string) Str::uuid()]);
        $this->assertSame('states', $other['page']['kind']);
    }

    public function test_a_store_without_bookable_services_has_the_ordering_view_model(): void
    {
        $store = $this->servicesStore();
        StoreOption::updateStoreOption($store->id, 'store_template', 'book');
        $home = (new ViewModelScenario($store, 'book'))->build('en');

        $this->assertArrayNotHasKey('times', $home['endpoints']);
        $this->assertArrayNotHasKey('accepting_bookings', $home['store']['status']);
    }
}
