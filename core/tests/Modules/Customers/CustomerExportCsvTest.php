<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** The owner's customer CSV: the 11 header columns, the owner's stores only, and spreadsheet formulas neutralised. */
class CustomerExportCsvTest extends TestCase
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
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_export_has_the_header_and_only_the_owners_customers_with_formulas_neutralised(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();

        Customer::create([
            'store_id' => $store->id, 'name' => 'Alice Diner', 'email' => 'alice@diner.com',
            'phone' => '111111111', 'orders_count' => 3, 'total_spent' => 150.00,
        ]);
        Customer::create([
            'store_id' => $store->id, 'name' => '=HYPERLINK("x")', 'email' => 'formula@diner.com',
        ]);
        Customer::create(['store_id' => $other->id, 'name' => 'Secret Customer', 'email' => 'secret@diner.com']);

        $response = $this->actingAs($owner)->get(route('customers.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $csv = $response->streamedContent();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '\\');
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        $this->assertSame([
            'ID', 'Name', 'Email', 'Phone', 'Orders Count', 'Total Spent', 'Last Order At',
            'Marketing Consent', 'Tags', 'Notes', 'Joined At',
        ], $header);
        $this->assertCount(2, $rows);
        $this->assertContains('Alice Diner', array_column($rows, 1));

        $formula = collect($rows)->first(fn (array $row): bool => str_contains((string) $row[1], 'HYPERLINK'));
        $this->assertNotNull($formula);
        $this->assertSame("'=HYPERLINK(\"x\")", $formula[1]);
        $this->assertStringNotContainsString('Secret Customer', $csv);
    }
}
