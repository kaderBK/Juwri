<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Services\Import\CustomerImporter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-228: e-mail validated and lower-cased, phones normalised with the store country, bad rows reported not fatal. */
class CustomerImportValidationTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $phone = '+966500000000'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => $phone, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_bad_email_rows_are_reported_not_fatal(): void
    {
        $s = $this->store();
        $csv = "Name,Phone,Email\nGood,,good@x.com\nBad,,not-an-email\n";
        $parsed = (new CustomerImporter)->parse($csv, $s->id);
        $this->assertCount(1, $parsed['rows']);
        $this->assertCount(1, $parsed['errors']);
        $batch = (new CustomerImporter)->import($s->id, $csv);
        $this->assertSame(1, $batch->items_created);
    }

    public function test_email_is_lowercased_and_case_duplicates_are_skipped(): void
    {
        $s = $this->store();
        Customer::create(['store_id' => $s->id, 'name' => 'Existing', 'email' => 'a@x.com']);
        $batch = (new CustomerImporter)->import($s->id, "Name,Email\nUp,A@x.com\nNew,B@X.com\n");
        $this->assertSame(1, $batch->items_created);
        $this->assertSame(1, $batch->skipped);
        $this->assertNotNull(Customer::where('store_id', $s->id)->where('email', 'b@x.com')->first());
    }

    public function test_phone_formats_dedupe_after_normalising_with_the_store_country(): void
    {
        $s = $this->store('+966500000000');
        Customer::create(['store_id' => $s->id, 'name' => 'Existing', 'phone' => '+966 50 111 2222']);
        $csv = "Name,Phone\nA,0501112222\nB,00966501112222\nC,+966501113333\n";
        $batch = (new CustomerImporter)->import($s->id, $csv);
        $this->assertSame(1, $batch->items_created, 'only +966501113333 is new');
        $this->assertSame(2, $batch->skipped);
        $this->assertNotNull(Customer::where('store_id', $s->id)->where('phone', '+966501113333')->first());
    }

    public function test_too_short_phone_is_reported(): void
    {
        $s = $this->store();
        $parsed = (new CustomerImporter)->parse("Name,Phone\nX,12\n", $s->id);
        $this->assertSame([], $parsed['rows']);
        $this->assertCount(1, $parsed['errors']);
    }

    public function test_quoted_newline_name_stays_one_row(): void
    {
        $s = $this->store();
        $csv = "Name,Phone,Email\n\"Alice\nSmith\",555-1,alice@x.com\n";
        $parsed = (new CustomerImporter)->parse($csv, $s->id);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame("Alice\nSmith", $parsed['rows'][0]['name']);
    }

    public function test_file_with_too_many_rows_is_rejected(): void
    {
        $s = $this->store();
        $csv = "Name,Phone\n".str_repeat("A,555-1\n", 20001);
        $parsed = (new CustomerImporter)->parse($csv, $s->id);
        $this->assertSame([], $parsed['rows']);
        $this->assertSame([___('The file has too many rows (maximum :max).', ['max' => 20000])], $parsed['errors']);
    }
}
