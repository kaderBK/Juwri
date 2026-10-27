<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettingKeys;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Tests\Support\PinsMiddayClock;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-14 (D7) guard: one typed store setting per refund flow (weighed difference, deposits, returns), each OFF by
 * default; `context.refunds` in the View Model carries all three for every store; only late-payment (API-28) and
 * removed-line (API-31) refunds bypass them; and no code reads a switch around RefundSwitches (the owner settings pages
 * that write them and the booking settings object excepted).
 */
class RefundSwitchesTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    public function test_each_flow_is_a_typed_bool_store_setting(): void
    {
        $this->assertSame(['weighed_difference', 'deposits', 'returns'], array_keys(RefundSwitches::FLOWS));
        foreach (RefundSwitches::FLOWS as $flow => $key) {
            $this->assertSame('bool', StoreSettingKeys::lookup($key)[1] ?? null, $flow.' is a typed bool setting');
        }
    }

    public function test_every_switch_is_off_until_the_owner_turns_it_on_and_only_exempt_refunds_bypass_them(): void
    {
        $this->seed(KitDemoSeeder::class);
        $store = Store::where('slug', 'fresh-mart-demo')->firstOrFail();
        $id = (int) $store->id;
        foreach (RefundSwitches::FLOWS as $key) {
            StoreSettings::forget($id, $key); // a store that never saved the switch
        }

        $this->assertSame(['weighed_difference' => false, 'deposits' => false, 'returns' => false], RefundSwitches::forStore($id));
        foreach (array_keys(RefundSwitches::FLOWS) as $flow) {
            $this->assertFalse(RefundSwitches::permits($id, $flow), $flow.' off by default');
        }
        $this->assertTrue(RefundSwitches::permits($id, 'late_payment'), 'API-28');
        $this->assertTrue(RefundSwitches::permits($id, 'removed_line'), 'API-31');

        StoreSettings::set($id, 'booking_deposit_refunds', true);
        $this->assertSame(['weighed_difference' => false, 'deposits' => true, 'returns' => false], RefundSwitches::forStore($id));
        $this->assertTrue(RefundSwitches::permits($id, 'deposits'));
    }

    public function test_an_unknown_flow_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RefundSwitches::allows(1, 'everything');
    }

    public function test_context_refunds_follows_each_switch_in_the_view_model(): void
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seed(KitDemoSeeder::class);
        $scenario = new ViewModelScenario(Store::where('slug', 'fresh-mart-demo')->firstOrFail(), 'basket');
        $id = (int) $scenario->store->id;
        foreach (RefundSwitches::FLOWS as $key) {
            StoreSettings::set($id, $key, false);
        }

        $vm = $scenario->build('en');
        $this->assertSame(['weighed_difference' => false, 'deposits' => false, 'returns' => false], $vm['context']['refunds']);
        $this->assertSame([], ViewModelScenario::errors($vm));

        foreach (RefundSwitches::FLOWS as $flow => $key) {
            StoreSettings::set($id, $key, true);
            $refunds = $scenario->build('en')['context']['refunds'];
            $this->assertTrue($refunds[$flow], $flow.' on');
            StoreSettings::set($id, $key, false);
            $this->assertFalse($scenario->build('en')['context']['refunds'][$flow], $flow.' off again');
        }

        // a services store carries the same block (the `book` theme words its deposit policy from `deposits`)
        $book = new ViewModelScenario(Store::where('slug', 'sidra-salon-demo')->firstOrFail(), 'book');
        StoreSettings::set((int) $book->store->id, 'booking_deposit_refunds', true);
        $this->assertTrue($book->build('en')['context']['refunds']['deposits']);
    }

    public function test_no_code_reads_a_refund_switch_around_refund_switches(): void
    {
        $allowed = [
            'app/Core/Settings/StoreSettingKeys.php',            // the typed key list
            'app/Core/Settings/RefundSwitches.php',              // the one reader
            'app/Core/Services/Store/StoreSettingsService.php',  // store editor: saves `refunds_returns`
            'app/Modules/Ordering/Http/Controllers/User/GrocerySettingsController.php', // grocery settings: shows + saves the weighed switch
            'app/Modules/Bookings/Http/Controllers/User/BookingsOwnerController.php',   // booking settings: saves the deposits switch
            'app/Modules/Bookings/Services/BookingSettings.php', // P3-10 settings object (same key, same default)
            'app/Modules/Storefront/Database/Seeders/BookDemoSeeder.php', // P3-11 demo store: writes the deposits switch, never reads it
        ];
        $needle = '/[\'"](refunds_weighed_difference|booking_deposit_refunds|refunds_returns)[\'"]/';
        $offenders = [];
        $root = base_path();
        foreach (['app', 'ee'] as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
                if (! in_array($rel, $allowed, true) && preg_match($needle, (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = $rel;
                }
            }
        }

        $this->assertSame([], $offenders, 'read refund switches through Juwri\Core\Settings\RefundSwitches');
    }
}
