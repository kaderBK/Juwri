<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyHistoryFixture as History;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyProdShapeFixture as Shape;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\DepositChecks;
use Juwri\Modules\LegacyCompat\Import\RowLoader;
use Juwri\Modules\LegacyCompat\Import\ScopeMaps;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyHistoryReadOnly;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

/**
 * J59g/J59h importer scope (SYNTHETIC rows, J19): live hotel business (rate plans, room rates, open stays with their
 * folio balance, future bookings and their nights), future / open reservations and catering with held deposits (which
 * must reconcile with their payment, else the store is blocked), push subscriptions, storefront keys (hashed only),
 * page views with hashed IPs, and the platform blog / pages / FAQ / testimonials / announcements / languages.
 */
class LegacyScopeImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_scope_source';

    private Source $src;

    private Shape $shape;

    private History $history;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.source_app_key' => '',
        ]);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->history = (new History(DB::connection(self::SOURCE)))->createSchema();
        $this->shape = (new Shape(DB::connection(self::SOURCE)))->laterColumns()->laterTenancy()->laterContent()->laterScope();
        $s = $this->src;
        $s->platformOption('currency_code', 'USD');
        $s->user(1);
        $s->user(2);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->store(20, 2);
        $s->storeOption(20, 'currency_code', 'KWD');
        $s->branch(500, 10);
        $s->branch(600, 20);
        $s->customer(300, 10);
        $s->customer(400, 20);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function import(int $expectedExit = 0): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** @param list<string> $tables */
    private function assertTenancy(array $tables): void
    {
        foreach ($tables as $table) {
            foreach (DB::table($table)->get(['account_id', 'store_id']) as $row) {
                $this->assertSame((int) DB::table('stores')->where('id', $row->store_id)->value('account_id'), (int) $row->account_id, $table);
            }
            $this->assertSame(DB::table($table)->count(), DB::table('legacy_import_rows')->where('target_table', $table)->count(), $table);
        }
    }

    private function day(int $offset): string
    {
        return now()->utc()->addDays($offset)->format('Y-m-d');
    }

    private function at(int $offsetDays): string
    {
        return now()->utc()->addDays($offsetDays)->format('Y-m-d H:i:s');
    }

    /** Source ledger tables (back-office, never imported): read only for an open stay's folio balance. */
    private function sourceLedger(): void
    {
        $schema = DB::connection(self::SOURCE)->getSchemaBuilder();
        $schema->create('ledger_accounts', static function (Blueprint $t): void {
            $t->id();
            $t->string('owner_type', 32);
            $t->unsignedBigInteger('owner_id')->default(0);
            $t->string('kind', 32);
            $t->string('currency', 8);
        });
        $schema->create('ledger_entries', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->decimal('amount', 15, 2);
            $t->string('ref', 191)->nullable();
        });
    }

    private function balance(string $ownerType, int $ownerId, string $kind, string $currency): float
    {
        $account = LedgerAccount::query()->withoutGlobalScopes()->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->where('kind', $kind)->where('currency', $currency)->first();
        $this->assertNotNull($account, "$ownerType#$ownerId $kind");

        return app(Ledger::class)->accountBalance($account);
    }

    public function test_hotel_rate_plans_rates_open_stays_and_future_bookings_move_with_their_store(): void
    {
        $sh = $this->shape;
        $this->sourceLedger();
        $this->src->table(800, 10, 'k800');
        $this->src->table(801, 10, 'k801');
        $this->src->table(900, 20, 'k900');
        $sh->roomType(50, 10, ['base_rate' => 100]);
        $sh->roomType(60, 20);
        $sh->row('rate_plans', ['id' => 1, 'restaurant_id' => 10, 'name' => 'Best rate']);
        $sh->row('rate_plans', ['id' => 2, 'restaurant_id' => 10, 'name' => 'Member', 'parent_id' => 1, 'modifier_type' => 'percent', 'modifier_value' => 10]);
        $sh->row('rate_plans', ['id' => 3, 'restaurant_id' => 10, 'name' => 'Flat', 'modifier_type' => 'fixed', 'modifier_value' => 25.5]);
        $sh->row('room_rates', ['id' => 1, 'restaurant_id' => 10, 'room_type_id' => 50, 'rate_plan_id' => 1, 'date' => '2030-01-01', 'price' => 120.5]);
        $sh->row('room_rates', ['id' => 2, 'restaurant_id' => 10, 'room_type_id' => 60, 'rate_plan_id' => null, 'date' => '2030-01-01', 'price' => 99]);
        $sh->row('hotel_stays', ['id' => 1, 'restaurant_id' => 10, 'table_id' => 800, 'guest_name' => 'Guest A', 'status' => 'checked_in', 'token' => 'stay-token-synthetic-1', 'folio_account_id' => 501]);
        $sh->row('hotel_stays', ['id' => 2, 'restaurant_id' => 10, 'table_id' => 801, 'guest_name' => 'Guest B', 'status' => 'checked_out', 'checked_out_at' => '2025-01-02 10:00:00']);
        $sh->row('hotel_stays', ['id' => 3, 'restaurant_id' => 10, 'table_id' => 900, 'guest_name' => 'Guest C', 'status' => 'checked_in']);
        DB::connection(self::SOURCE)->table('ledger_accounts')->insert(['id' => 501, 'owner_type' => 'hotel_stay', 'owner_id' => 1, 'kind' => 'hotel_folio', 'currency' => 'SAR']);
        foreach ([[1, 40.25], [2, 10.00], [3, -5.25]] as [$id, $amount]) {
            DB::connection(self::SOURCE)->table('ledger_entries')->insert(['id' => $id, 'account_id' => 501, 'amount' => $amount]);
        }
        $booking = ['restaurant_id' => 10, 'guest_name' => 'Guest', 'room_type_id' => 50, 'status' => 'confirmed', 'check_in' => $this->day(10), 'check_out' => $this->day(12)];
        $sh->row('hotel_bookings', ['id' => 1, 'code' => 'BK0001', 'token' => 'booking-token-synthetic-1', 'room_id' => 800, 'rate_plan_id' => 2, 'customer_id' => 300, 'quoted_total' => 241, 'currency' => 'SAR'] + $booking);
        $sh->row('hotel_bookings', ['id' => 2, 'code' => 'BK0002', 'check_in' => '2020-01-01', 'check_out' => '2020-01-02'] + $booking);
        $sh->row('hotel_bookings', ['id' => 3, 'code' => 'BK0003', 'status' => 'cancelled'] + $booking);
        $sh->row('hotel_bookings', ['id' => 4, 'code' => 'BK0004', 'room_id' => 900, 'customer_id' => 400] + $booking);
        $sh->row('hotel_booking_nights', ['id' => 1, 'booking_id' => 1, 'room_id' => 800, 'night' => $this->day(10)]);
        $sh->row('hotel_booking_nights', ['id' => 2, 'booking_id' => 1, 'room_id' => 800, 'night' => $this->day(11)]);
        $sh->row('hotel_booking_nights', ['id' => 3, 'booking_id' => 2, 'room_id' => 800, 'night' => '2020-01-01']);
        $sh->row('hotel_booking_nights', ['id' => 4, 'booking_id' => 1, 'room_id' => 900, 'night' => $this->day(10)]);

        $report = $this->import();

        $plans = DB::table('rate_plans')->orderBy('id')->get()->keyBy('id');
        $this->assertSame([1, 2, 3], $plans->keys()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, (int) $plans[2]->parent_id);
        $this->assertEquals(10, (float) $plans[2]->modifier_value);
        $this->assertNull($plans[2]->modifier_amount_minor);
        $this->assertSame(2550, (int) $plans[3]->modifier_amount_minor);
        $this->assertNull($plans[3]->modifier_value);
        $this->assertSame('SAR', $plans[3]->currency_code);
        $this->assertSame(12050, (int) DB::table('room_rates')->where('id', 1)->value('price_minor'));
        $this->assertFalse(DB::table('room_rates')->where('id', 2)->exists()); // another store's room type

        // Open stays only; the token is kept; the folio opens with the source balance (40.25 + 10.00 - 5.25).
        $this->assertSame([1], DB::table('hotel_stays')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $stay = DB::table('hotel_stays')->where('id', 1)->first();
        $this->assertSame('stay-token-synthetic-1', $stay->token);
        $this->assertNotNull($stay->folio_account_id);
        $this->assertSame(45.0, $this->balance('hotel_stay', 1, 'hotel_folio', 'SAR'));
        $this->assertSame((int) $stay->folio_account_id, (int) LedgerAccount::query()->withoutGlobalScopes()->where('owner_type', 'hotel_stay')->where('owner_id', 1)->value('id'));
        $this->assertSame(1, $report['counters']['hotel_stays_folio_opened']);

        // Future bookings that hold a room; code and token verbatim; a room of another store is dropped (reported).
        $bookings = DB::table('hotel_bookings')->orderBy('id')->get()->keyBy('id');
        $this->assertSame([1, 4], $bookings->keys()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['BK0001', 'booking-token-synthetic-1', 24100, 'SAR', 800, 2, 300], [
            $bookings[1]->code, $bookings[1]->token, (int) $bookings[1]->quoted_total_minor, $bookings[1]->currency,
            (int) $bookings[1]->room_id, (int) $bookings[1]->rate_plan_id, (int) $bookings[1]->customer_id,
        ]);
        $this->assertNull($bookings[4]->room_id);
        $this->assertNull($bookings[4]->customer_id);
        $this->assertSame([1, 2], DB::table('hotel_booking_nights')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, $report['tables']['hotel_bookings']['skipped']);
        $this->assertSame(2, $report['tables']['hotel_stays']['skipped']);
        $this->assertTenancy(['rate_plans', 'room_rates', 'hotel_stays', 'hotel_bookings', 'hotel_booking_nights']);
        $this->assertSame(2, $report['decisions']['J59g_scope']['hotel_bookings_future']);
    }

    public function test_future_reservations_and_catering_keep_their_tokens_and_held_deposits_reconcile_into_the_ledger(): void
    {
        $h = $this->history;
        $sh = $this->shape;
        $h->billingTransaction(70, 1, 50, 'SAR', ['transaction_method' => 'reservation_deposit', 'product_id' => 0, 'transaction_gatway' => 'stripe', 'payment_id' => 'pi_dep_70']);
        $h->billingTransaction(71, 1, 30, 'SAR', ['transaction_method' => 'catering_deposit', 'product_id' => 0, 'transaction_gatway' => 'stripe', 'payment_id' => 'pi_dep_71']);
        $h->billingTransaction(72, 1, 9, 'USD'); // the owner's plan payment: read-only history
        $sh->row('reservations', ['id' => 1, 'restaurant_id' => 10, 'branch_id' => 500, 'name' => 'R1', 'party_size' => 4, 'reserved_at' => $this->at(5),
            'status' => 'confirmed', 'deposit_amount' => 50, 'deposit_status' => 'paid', 'deposit_transaction_id' => 70, 'currency' => 'SAR', 'token' => 'res-token-synthetic-1', 'rwg_booking_id' => 'rwg-1']);
        $sh->row('reservations', ['id' => 2, 'restaurant_id' => 10, 'name' => 'R2', 'reserved_at' => '2024-01-01 19:00:00', 'status' => 'seated', 'token' => 'res-token-synthetic-2']);
        $sh->row('reservations', ['id' => 3, 'restaurant_id' => 10, 'branch_id' => 600, 'name' => 'R3', 'reserved_at' => $this->at(3), 'status' => 'pending']);
        $sh->row('catering_requests', ['id' => 1, 'restaurant_id' => 10, 'name' => 'C1', 'event_at' => $this->at(20), 'status' => 'accepted', 'token' => 'cat-token-synthetic-1',
            'items' => '[{"menu_id":1,"qty":10}]', 'quote_total' => 300, 'deposit_amount' => 30, 'deposit_status' => 'paid', 'deposit_transaction_id' => 71, 'currency' => 'SAR']);
        $sh->row('catering_requests', ['id' => 2, 'restaurant_id' => 10, 'name' => 'C2', 'event_at' => '2024-02-01 12:00:00', 'status' => 'accepted']);

        $report = $this->import();

        $res = DB::table('reservations')->orderBy('id')->get()->keyBy('id');
        $this->assertSame([1, 3], $res->keys()->map(fn ($v) => (int) $v)->all());
        $this->assertSame([5000, 'paid', 70, 'SAR', 'res-token-synthetic-1', 'rwg-1', 500], [
            (int) $res[1]->deposit_amount_minor, $res[1]->deposit_status, (int) $res[1]->deposit_transaction_id, $res[1]->currency,
            $res[1]->token, $res[1]->rwg_booking_id, (int) $res[1]->branch_id,
        ]);
        $this->assertNull($res[3]->branch_id); // a branch of another store
        $cat = DB::table('catering_requests')->where('id', 1)->first();
        $this->assertSame([30000, 3000, 'cat-token-synthetic-1', 71], [(int) $cat->quote_total_minor, (int) $cat->deposit_amount_minor, $cat->token, (int) $cat->deposit_transaction_id]);
        $this->assertFalse(DB::table('catering_requests')->where('id', 2)->exists());

        // The deposit payments are the store's now (refunds go through its gateway), and the held money is on the
        // owner's merchant account exactly as a deposit payment books it here.
        $this->assertSame([10, 10], DB::table('transaction')->whereIn('id', [70, 71])->orderBy('id')->pluck('store_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(80.0, $this->balance('merchant', 1, 'merchant', 'SAR'));
        $this->assertSame(1, $report['counters']['reservations_deposits_held']);
        $this->assertSame(1, $report['counters']['catering_requests_deposits_held']);
        $this->assertSame(1, DB::table('legacy_import_marks')->where('target_table', 'reservations')->where('mark', ScopeMaps::DEPOSIT_HELD)->count());
        $this->assertTenancy(['reservations', 'catering_requests']);

        // Live deposit payments stay refundable here (refund bookkeeping on the payment); other imported billing stays
        // read-only history.
        LegacyCompatServiceProvider::activate($this->app);
        $deposit = Transaction::query()->withoutGlobalScopes()->findOrFail(70);
        $deposit->forceFill(['details' => ['refunds' => ['synthetic']], 'status' => 'refunded'])->save();
        $this->assertStringContainsString('synthetic', (string) DB::table('transaction')->where('id', 70)->value('details'));
        $this->assertSame('refunded', DB::table('transaction')->where('id', 70)->value('status'));
        // Its money and owner stay locked.
        foreach (['amount' => 1, 'currency_code' => 'USD', 'user_id' => 2, 'store_id' => 20] as $column => $value) {
            try {
                Transaction::query()->withoutGlobalScopes()->findOrFail(71)->forceFill([$column => $value])->save();
                $this->fail($column.' of a live payment changed');
            } catch (LegacyHistoryReadOnly) {
                // locked
            }
        }
        $this->assertSame(3000, (int) DB::table('transaction')->where('id', 71)->value('amount_minor'));
        $this->assertSame(10, (int) DB::table('transaction')->where('id', 71)->value('store_id'));
        $this->assertSame(ScopeMaps::LIVE_PAYMENT, DB::table('legacy_import_marks')->where('target_table', 'transaction')->where('target_id', '71')->value('mark'));
        $billing = Transaction::query()->withoutGlobalScopes()->findOrFail(72);
        $this->expectException(LegacyHistoryReadOnly::class);
        $billing->forceFill(['details' => ['x' => 1]])->save();
    }

    public function test_a_held_deposit_that_does_not_match_its_payment_blocks_the_store(): void
    {
        $this->history->billingTransaction(70, 1, 40, 'SAR', ['transaction_method' => 'reservation_deposit', 'product_id' => 0]);
        $this->shape->row('reservations', ['id' => 1, 'restaurant_id' => 10, 'name' => 'R1', 'reserved_at' => $this->at(5), 'status' => 'confirmed',
            'deposit_amount' => 50, 'deposit_status' => 'paid', 'deposit_transaction_id' => 70, 'currency' => 'SAR']);
        $this->shape->row('reservations', ['id' => 2, 'restaurant_id' => 20, 'name' => 'R2', 'reserved_at' => $this->at(5), 'status' => 'confirmed']);

        $report = $this->import(1);

        $this->assertFalse(DB::table('stores')->where('id', 10)->exists());
        $this->assertTrue(DB::table('stores')->where('id', 20)->exists());
        $this->assertSame([2], DB::table('reservations')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['store#10 reservations#1'], $report['conflicts']['blocked: stores: '.DepositChecks::REASON]['samples']);
        $this->assertFalse(DB::table('legacy_import_rows')->where('target_table', 'stores')->where('target_id', '10')->exists());
    }

    public function test_push_subscriptions_storefront_keys_and_page_views_with_hashed_ips(): void
    {
        $sh = $this->shape;
        $this->src->order(5000, 10);
        $this->src->order(6000, 20);
        $sh->row('customer_push_subscriptions', ['id' => 1, 'order_id' => 5000, 'restaurant_id' => 10, 'endpoint' => 'https://push.example.test/a', 'endpoint_hash' => str_repeat('0', 64), 'p256dh' => 'k', 'auth' => 'a']);
        $sh->row('customer_push_subscriptions', ['id' => 2, 'order_id' => 6000, 'restaurant_id' => 10, 'endpoint' => 'https://push.example.test/b', 'endpoint_hash' => str_repeat('1', 64)]);
        $sh->row('push_subscriptions', ['id' => 1, 'restaurant_id' => 10, 'user_id' => 1, 'endpoint' => 'https://push.example.test/owner', 'endpoint_hash' => 'x']);
        $sh->row('push_subscriptions', ['id' => 2, 'restaurant_id' => 10, 'user_id' => 2, 'endpoint' => 'https://push.example.test/other', 'endpoint_hash' => 'y']);
        $hashed = hash('sha256', 'sf-live-key-synthetic-1');
        $sh->row('storefront_tokens', ['id' => 1, 'post_id' => 10, 'token' => $hashed, 'label' => 'Site', 'scopes' => '["menu:read"]']);
        $sh->row('storefront_tokens', ['id' => 2, 'post_id' => 10, 'token' => 'sf-plain-key-synthetic-2', 'label' => 'Old']);
        $sh->row('post_views', ['id' => 1, 'post_id' => 10, 'ip' => '203.0.113.5', 'device' => 'mobile', 'lang' => 'en', 'date' => '2025-06-01 10:00:00']);
        $sh->row('post_views', ['id' => 2, 'post_id' => 10, 'ip' => '203.0.113.5', 'device' => 'mobile', 'lang' => 'en', 'date' => '2025-06-02 10:00:00']);
        $sh->row('post_views', ['id' => 3, 'post_id' => 10, 'ip' => null, 'date' => '2025-06-02 11:00:00']);
        $sh->row('post_views', ['id' => 4, 'post_id' => 20, 'ip' => '198.51.100.7', 'date' => '2025-06-02 11:00:00']);
        $sh->row('post_view_daily', ['id' => 1, 'post_id' => 10, 'date' => '2025-06-01', 'device' => 'mobile', 'lang' => 'en', 'scans' => 5]);

        $report = $this->import();

        $this->assertSame([1], DB::table('customer_push_subscriptions')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(hash('sha256', 'https://push.example.test/a'), DB::table('customer_push_subscriptions')->value('endpoint_hash'));
        $push = DB::table('push_subscriptions')->orderBy('id')->get()->keyBy('id');
        $this->assertSame(1, (int) $push[1]->user_id);
        $this->assertNull($push[2]->user_id); // not the store owner
        $this->assertSame(hash('sha256', 'https://push.example.test/other'), $push[2]->endpoint_hash);

        $tokens = DB::table('storefront_tokens')->orderBy('id')->pluck('token', 'id');
        $this->assertSame($hashed, $tokens[1]);
        $this->assertSame(hash('sha256', 'sf-plain-key-synthetic-2'), $tokens[2]); // never stored in plaintext
        $this->assertSame(1, $report['counters']['storefront_tokens_hashed_at_import']);

        $views = DB::table('store_page_views')->orderBy('id')->get()->keyBy('id');
        $this->assertSame(ScopeMaps::hashIp('203.0.113.5'), $views[1]->ip);
        $this->assertSame($views[1]->ip, $views[2]->ip); // one visitor, one hash (repeat-visitor counts)
        $this->assertNotSame('203.0.113.5', $views[1]->ip);
        $this->assertSame(64, strlen((string) $views[1]->ip));
        $this->assertNull($views[3]->ip);
        $this->assertSame(10, (int) $views[1]->store_id);
        $this->assertSame(20, (int) $views[4]->store_id);
        $this->assertSame(0, DB::table('store_page_views')->where('ip', 'like', '%.%')->count());
        $this->assertSame(5, (int) DB::table('store_page_view_daily')->value('scans'));
        $this->assertSame(3, $report['decisions']['J59h_views']['ips_hashed']);
        // The side-table checksum never carries a plain IP (it could be brute-forced back from the other columns).
        foreach (DB::connection(self::SOURCE)->table('post_views')->get() as $source) {
            $row = (array) $source;
            $stored = DB::table('legacy_import_rows')->where('source_table', 'post_views')->where('source_id', (string) $row['id'])->value('source_checksum');
            $this->assertSame(RowLoader::checksum(['ip' => ScopeMaps::hashIp($row['ip'])] + $row), $stored);
            if ($row['ip'] !== null) {
                $this->assertNotSame(RowLoader::checksum($row), $stored);
            }
        }
        $this->assertTenancy(['customer_push_subscriptions', 'push_subscriptions', 'storefront_tokens', 'store_page_views', 'store_page_view_daily']);
    }

    public function test_platform_blog_pages_faq_testimonials_announcements_and_languages_keep_their_ids(): void
    {
        $sh = $this->shape;
        $sh->row('blog_categories', ['id' => 3, 'title' => 'News', 'slug' => 'news', 'active' => '1']);
        $sh->row('blog', ['id' => 7, 'author' => 1, 'title' => 'Hello', 'slug' => 'hello', 'status' => 'publish', 'created_at' => '2025-01-01 10:00:00']);
        $sh->row('blog', ['id' => 8, 'author' => 99, 'title' => 'Orphan', 'slug' => 'orphan', 'status' => 'pending']);
        $sh->row('blog_cat_relation', ['id' => 1, 'blog_id' => 7, 'category_id' => 3]);
        $sh->row('blog_cat_relation', ['id' => 2, 'blog_id' => 9, 'category_id' => 3]);
        $sh->row('pages', ['id' => 5, 'slug' => 'about', 'title' => 'About', 'type' => '0', 'content' => '<p>x</p>']);
        $sh->row('faq_entries', ['id' => 4, 'faq_title' => 'Q', 'faq_content' => 'A', 'translation_lang' => 'en']);
        $sh->row('testimonials', ['id' => 1, 'name' => 'N', 'content' => 'Short and kind.']);
        $sh->row('testimonials', ['id' => 2, 'name' => 'M', 'content' => str_repeat('x', 300)]);
        $sh->row('announcements', ['id' => 2, 'title' => 'Maintenance', 'starts_at' => '2025-01-01']);
        $sh->row('languages', ['id' => 1, 'code' => 'en', 'name' => 'English']);
        $sh->row('languages', ['id' => 2, 'code' => 'xx', 'name' => 'Synthetic', 'direction' => 'ltr']);
        DB::connection(self::SOURCE)->getSchemaBuilder()->create('support_tickets', static function (Blueprint $t): void {
            $t->id();
        });
        DB::connection(self::SOURCE)->getSchemaBuilder()->create('disputes', static function (Blueprint $t): void {
            $t->id();
        });
        $languages = DB::table('languages')->count();
        $testimonials = DB::table('testimonials')->count(); // this install's own samples stay

        $report = $this->import();

        $this->assertSame(1, (int) DB::table('blog_posts')->where('id', 7)->value('author'));
        $this->assertNull(DB::table('blog_posts')->where('id', 8)->value('author'));
        $this->assertSame([[7, 3]], DB::table('blog_category_post')->get()->map(fn ($r) => [(int) $r->blog_id, (int) $r->category_id])->all());
        $this->assertSame('about', DB::table('pages')->where('id', 5)->value('slug'));
        $this->assertSame('Q', DB::table('faq_entries')->where('id', 4)->value('faq_title'));
        $this->assertSame($testimonials + 1, DB::table('testimonials')->count());
        $this->assertSame('Short and kind.', DB::table('testimonials')->orderByDesc('id')->value('content'));
        $this->assertSame($languages + 1, DB::table('languages')->count());
        $this->assertSame('Synthetic', DB::table('languages')->where('code', 'xx')->value('name'));
        $this->assertStringContainsString('J59g', $report['skipped_by_decision']['support_tickets']);
        $this->assertStringContainsString('J59g', $report['skipped_by_decision']['disputes']);
        $this->assertNotContains('blog', $report['unmapped_source_tables']);
        $this->assertArrayHasKey('J59k_google_wallet', $report['decisions']);
        $this->assertFalse($report['decisions']['J59k_google_wallet']['issuer_configured']);

        // Announcements are an Enterprise table (Platform): the import writes nothing into them without the package.
        if (! Schema::hasTable('announcements')) {
            $this->markTestSkipped('Community build: the Enterprise package is not installed.');
        }
        $this->assertSame('Maintenance', DB::table('announcements')->where('id', 2)->value('title'));
    }
}
