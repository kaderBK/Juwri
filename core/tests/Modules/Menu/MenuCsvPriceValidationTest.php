<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Menu\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Http\Controllers\User\Concerns\ImportsMenuCsvCore;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-221: a bad price is a row error (never a free item); UTF-8 names are cut by characters; HH:MM is validated. */
class MenuCsvPriceValidationTest extends TestCase
{
    use RefreshDatabase;

    private function run_import(string $csv): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request = Request::create('/x', 'POST', [], [], ['csv_file' => UploadedFile::fake()->createWithContent('m.csv', $csv)]);
        $runner = new class
        {
            use ImportsMenuCsvCore;

            public function go(Request $r, Store $s): array
            {
                return $this->importMenuCsvInto($r, $s);
            }
        };
        $this->actingAs($owner);

        return [$runner->go($request, $store), $store];
    }

    public function test_bad_prices_are_rejected_and_good_ones_accepted(): void
    {
        $csv = "name,price\nBad,abc\nComma,\"12,50\"\nArabic,١٢\nPlain,7.25\n";
        [$result, $store] = $this->run_import($csv);

        $this->assertSame(1, $result['skipped'], 'only the "abc" row is rejected');
        $this->assertSame(3, $result['imported']);
        $this->assertNull(Menu::where('store_id', $store->id)->where('name', 'Bad')->first());
        $this->assertEquals(12.5, (float) Menu::where('store_id', $store->id)->where('name', 'Comma')->first()->price);
        $this->assertEquals(12.0, (float) Menu::where('store_id', $store->id)->where('name', 'Arabic')->first()->price);
        $this->assertStringContainsString('abc', $result['message']);
    }

    public function test_long_arabic_names_are_cut_by_characters(): void
    {
        $name = str_repeat('ب', 300);
        [, $store] = $this->run_import("name,price\n{$name},5\n");
        $stored = Menu::where('store_id', $store->id)->value('name');
        $this->assertSame(255, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    public function test_invalid_availability_time_rejects_the_row(): void
    {
        [$result, $store] = $this->run_import("name,price,available_from,available_to\nOk,5,09:00,22:30\nBadTime,5,9am,22:30\n");
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNull(Menu::where('store_id', $store->id)->where('name', 'BadTime')->first());
    }
}
