<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/** Privacy notice §8: inactive marketing contacts are removed after 24 months (P6-86). */
class MarketingContactRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create();

        return Store::create(['user_id' => $owner->id, 'slug' => 'mc-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
    }

    private function customer(Store $s, string $name, int $monthsAgo, bool $consent = true, array $extra = []): int
    {
        $at = now()->subMonths($monthsAgo)->subDay();

        return DB::table('customers')->insertGetId($extra + ['store_id' => $s->id, 'account_id' => $s->fresh()->account_id, 'name' => $name,
            'phone' => UniquePhone::next('05'), 'marketing_consent' => $consent, 'sms_consent' => $consent,
            'consent_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_subscribers_are_kept_while_the_flag_is_off(): void
    {
        config(['legal.prune_subscribers' => false]);
        DB::table('newsletter_subscribers')->insert(['email' => 'old@example.test', 'joined' => now()->subMonths(40)->toDateString()]);

        $this->artisan('marketing:prune-inactive-contacts')->assertSuccessful();

        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
    }

    public function test_subscribers_joined_over_24_months_are_deleted_and_newer_kept_when_flag_on(): void
    {
        config(['legal.prune_subscribers' => true]);
        DB::table('newsletter_subscribers')->insert([
            ['email' => 'old@example.test', 'joined' => now()->subMonths(25)->toDateString()],
            ['email' => 'new@example.test', 'joined' => now()->subMonths(23)->toDateString()],
        ]);

        $this->artisan('marketing:prune-inactive-contacts')->assertSuccessful();

        $this->assertSame(['new@example.test'], DB::table('newsletter_subscribers')->pluck('email')->all());
    }

    public function test_dry_run_changes_nothing(): void
    {
        config(['legal.prune_subscribers' => true]);
        DB::table('newsletter_subscribers')->insert(['email' => 'old@example.test', 'joined' => now()->subMonths(30)->toDateString()]);
        $s = $this->store();
        $c = $this->customer($s, 'Old', 30);

        $this->artisan('marketing:prune-inactive-contacts', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('newsletter_subscribers')->count());
        $this->assertTrue((bool) DB::table('customers')->where('id', $c)->value('marketing_consent'));
    }

    public function test_inactive_customer_optin_is_switched_off_but_row_orders_and_consent_proof_kept(): void
    {
        $s = $this->store();
        $stale = $this->customer($s, 'Stale', 25, true, ['orders_count' => 3]);
        $within = $this->customer($s, 'Within', 23);
        $ordered = $this->customer($s, 'Ordered', 30, true, ['last_order_at' => now()->subMonths(2), 'updated_at' => now()->subMonths(30)]);
        $this->customer($s, 'NoConsent', 30, false);
        $proof = DB::table('customers')->where('id', $stale)->value('consent_at');

        $this->artisan('marketing:prune-inactive-contacts')->assertSuccessful();

        $row = DB::table('customers')->where('id', $stale)->first();
        $this->assertFalse((bool) $row->marketing_consent);
        $this->assertFalse((bool) $row->sms_consent);
        $this->assertSame(3, (int) $row->orders_count);
        $this->assertSame($proof, $row->consent_at);
        $this->assertTrue((bool) DB::table('customers')->where('id', $within)->value('marketing_consent'));
        $this->assertTrue((bool) DB::table('customers')->where('id', $ordered)->value('marketing_consent'));
        $this->assertSame(4, DB::table('customers')->count());
    }

    public function test_each_store_is_judged_on_its_own_customers(): void
    {
        $a = $this->store();
        $b = $this->store();
        $stale = $this->customer($a, 'A stale', 26);
        $fresh = $this->customer($b, 'B fresh', 1);

        $this->artisan('marketing:prune-inactive-contacts')->assertSuccessful();

        $this->assertFalse((bool) DB::table('customers')->where('id', $stale)->value('marketing_consent'));
        $this->assertTrue((bool) DB::table('customers')->where('id', $fresh)->value('marketing_consent'));
    }
}
