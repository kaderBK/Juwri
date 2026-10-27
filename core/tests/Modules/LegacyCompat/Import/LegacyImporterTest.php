<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\StoreSettingsImporter;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Tests\TestCase;

/**
 * J52b M4: the `legacy:import` importer against a SYNTHETIC source database (rows shaped like the old platform's,
 * invented values; J19). Covers id preservation, account derivation, tenant isolation, refusal guards, dry run,
 * resume, settings map and gateway-secret re-encryption.
 */
class LegacyImporterTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_test_source';

    private Source $src;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'Asia/Riyadh',
            'legacy_compat.import.source_app_key' => '',
            'legacy_compat.import.enabled_gateways' => ['stripe', 'paypal'],
        ]);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->src->platformOption('currency_code', 'USD');
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> the decoded report
     */
    private function import(array $args = [], int $expectedExit = 0): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE] + $args);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** Two owners, one non-owner, an orphan store; synthetic catalogue, orders, loyalty. */
    private function seedTwoOwners(): void
    {
        $s = $this->src;
        $s->user(1, ['name' => 'Owner A', 'balance' => 12.5]);
        $s->user(2, ['name' => 'Owner B', 'country_code' => 'Saudi Arabia']);
        $s->user(3, ['name' => 'Not an owner']);
        $s->allergy(4);

        $s->store(10, 1, ['slug' => 'Mixed-Case-Slug']);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->store(11, 1, ['slug' => '12345']);
        $s->storeOption(11, 'currency_code', 'SAR');
        $s->store(20, 2, ['slug' => 'other-owner']);
        $s->storeOption(20, 'currency_code', 'KWD');
        $s->store(30, 99, ['slug' => 'orphan']); // owner 99 does not exist

        $s->branch(500, 10);
        $s->table(700, 10, 'K-AAAA-10', ['branch_id' => 500]);
        $s->table(701, 20, 'K-BBBB-20', ['branch_id' => 500]); // branch of store 10: foreign
        $s->category(100, 10);
        $s->category(101, 10, ['parent' => 100]);
        $s->category(200, 20);
        $s->item(1000, 10, 101, 9.99);
        $s->item(1001, 10, 100, 0.1 + 0.2);
        $s->item(2000, 20, 200, 1.2345);
        $s->item(2001, 20, 100, 3.5); // category of store 10: foreign
        $s->customer(300, 10);
        $s->customer(400, 20, ['total_spent' => 7.125]);
        $s->order(5000, 10, ['customer_id' => 300, 'branch_id' => 500, 'tip_amount' => 1.5]);
        $s->orderItem(9000, 5000, 1000, 2);
        $s->orderItem(9001, 5000, 4242, 1); // deleted menu item
        $s->order(6000, 20, ['customer_id' => 300]); // customer of store 10: foreign
        $s->orderItem(9100, 6000, 2000, 3);
        $s->loyaltyProgram(80, 10, ['reward_discount' => 2.5]);
        $s->loyaltyAccount(81, 10, 300, ['balance' => 7]);
        $s->loyaltyAccount(82, 20, 300); // customer of store 10: refused
        $s->ledger(83, 81, 7, 7, ['order_id' => 5000]);
        $s->giftCard(84, 20, 'GIFT-SYNTH-1', 'JOD', 10.0005);
        $s->unmappedRow(1, 10);
    }

    public function test_refuses_unless_both_switches_source_and_timezone_are_set(): void
    {
        $this->seedTwoOwners();

        config(['legacy_compat.enabled' => false]);
        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => self::SOURCE]));
        $this->assertStringContainsString('legacy_compat.enabled', Artisan::output());

        config(['legacy_compat.enabled' => true, 'legacy_compat.import.enabled' => false]);
        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => self::SOURCE]));
        $this->assertStringContainsString('import switch', Artisan::output());

        config(['legacy_compat.import.enabled' => true]);
        $this->assertSame(1, Artisan::call('legacy:import'));
        $this->assertStringContainsString('--source', Artisan::output());
        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => 'nope']));
        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => DB::getDefaultConnection()]));
        $this->assertStringContainsString('target database', Artisan::output());

        config(['legacy_compat.import.source_timezone' => '']);
        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => self::SOURCE]));
        $this->assertStringContainsString('time zone', Artisan::output());

        // The env-only connection has no default host: not configured until the env names one.
        config(['legacy_compat.import.source_timezone' => 'UTC']);
        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => 'legacy_import_source']));
        $this->assertStringContainsString('not configured', Artisan::output());

        $this->assertSame(0, DB::table('stores')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('legacy_import_rows')->count());
    }

    public function test_refuses_when_target_tables_hold_rows_it_did_not_write(): void
    {
        $this->seedTwoOwners();
        DB::table('allergies')->insert(['id' => 1, 'title' => 'Existing']);

        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => self::SOURCE]));
        $this->assertStringContainsString('allergies already holds 1 row(s)', Artisan::output());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_ids_are_kept_and_one_account_per_owner_is_derived(): void
    {
        $this->seedTwoOwners();
        $report = $this->import();

        // Users: ids kept; owners get account = own id; a non-owner gets none.
        $this->assertEqualsCanonicalizing([1, 2, 3], DB::table('users')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsCanonicalizing([1, 2], DB::table('accounts')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, (int) DB::table('accounts')->where('id', 1)->value('owner_user_id'));
        $this->assertSame(1, (int) DB::table('users')->where('id', 1)->value('account_id'));
        $this->assertSame(2, (int) DB::table('users')->where('id', 2)->value('account_id'));
        $this->assertNull(DB::table('users')->where('id', 3)->value('account_id'));
        $this->assertSame(1250, (int) DB::table('users')->where('id', 1)->value('balance_minor'));
        $this->assertNull(DB::table('users')->where('id', 2)->value('country_code'));

        // Stores: ids and slugs byte-for-byte; owner's stores grouped under the owner's account; the orphan refused.
        $this->assertSame([10 => 'Mixed-Case-Slug', 11 => '12345', 20 => 'other-owner'], DB::table('stores')->orderBy('id')->pluck('slug', 'id')->all());
        $this->assertSame([10 => 1, 11 => 1, 20 => 2], DB::table('stores')->orderBy('id')->pluck('account_id', 'id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, $report['conflicts']['stores: owner missing or not imported: account cannot be derived']['count']);

        // Catalogue + tables: ids, keys, parents kept; prices via the one converter in the store's currency.
        $this->assertSame('K-AAAA-10', DB::table('store_tables')->where('id', 700)->value('key'));
        $this->assertSame('free', DB::table('store_tables')->where('id', 700)->value('status'));
        $this->assertSame(500, (int) DB::table('store_tables')->where('id', 700)->value('branch_id'));
        $this->assertSame(100, (int) DB::table('categories')->where('id', 101)->value('parent'));
        $item = DB::table('items')->where('id', 1000)->first();
        $this->assertSame(999, (int) $item->price_minor);
        $this->assertSame('SAR', $item->currency_code);
        $this->assertSame(30, (int) DB::table('items')->where('id', 1001)->value('price_minor'));
        $this->assertSame(1235, (int) DB::table('items')->where('id', 2000)->value('price_minor')); // KWD: 3 decimals

        // Orders: ids kept, source time zone converted to UTC, line of a deleted item kept with item_id NULL.
        $order = DB::table('orders')->where('id', 5000)->first();
        $this->assertSame(300, (int) $order->customer_id);
        $this->assertSame(150, (int) $order->tip_amount_minor);
        $this->assertStringStartsWith('2025-03-01 17:15:00', (string) $order->created_at);
        $this->assertSame(2, (int) DB::table('order_items')->where('id', 9000)->value('quantity'));
        $this->assertNull(DB::table('order_items')->where('id', 9001)->value('item_id'));
        $this->assertSame(999, (int) DB::table('order_items')->where('id', 9000)->value('unit_price_minor')); // M5 menu snapshot
        $this->assertNull(DB::table('order_items')->where('id', 9001)->value('unit_price_minor')); // no price for a deleted item

        // Loyalty + gift card (minor units of the card's own currency).
        $this->assertSame(7, (int) DB::table('loyalty_accounts')->where('id', 81)->value('balance'));
        $this->assertSame(5000, (int) DB::table('loyalty_ledger_entries')->where('id', 83)->value('order_id'));
        $this->assertSame(250, (int) DB::table('loyalty_programs')->where('id', 80)->value('reward_discount_minor'));
        $this->assertSame(10001, (int) DB::table('gift_cards')->where('id', 84)->value('initial_value_minor'));

        // Every tenant row carries its store's account (J29-1); every written row has a side-table record.
        foreach (['branches', 'store_tables', 'categories', 'items', 'customers', 'orders', 'order_items', 'loyalty_programs', 'loyalty_accounts', 'loyalty_ledger_entries', 'gift_cards'] as $table) {
            foreach (DB::table($table)->get(['account_id', 'store_id']) as $row) {
                $this->assertSame((int) DB::table('stores')->where('id', $row->store_id)->value('account_id'), (int) $row->account_id, $table);
            }
            $this->assertSame(DB::table($table)->count(), DB::table('legacy_import_rows')->where('target_table', $table)->count(), $table);
        }
        $this->assertSame(64, strlen((string) DB::table('legacy_import_rows')->where('target_table', 'orders')->value('source_checksum')));

        // Reports: unknown/missing source columns, unmapped tables.
        $this->assertContains(Source::UNKNOWN_ITEM_COLUMN, $report['unknown_source_columns']['menu']);
        $this->assertContains('updated_at', $report['missing_source_columns']['orders']);
        $this->assertContains(Source::UNMAPPED_TABLE, $report['unmapped_source_tables']);
        $this->assertFalse($report['dry_run']);
    }

    public function test_references_never_cross_stores(): void
    {
        $this->seedTwoOwners();
        $report = $this->import();

        // Store 20 rows that pointed at store 10's rows: references dropped, the dependent row refused.
        $this->assertNull(DB::table('store_tables')->where('id', 701)->value('branch_id'));
        $this->assertNull(DB::table('items')->where('id', 2001)->value('category_id'));
        $this->assertNull(DB::table('orders')->where('id', 6000)->value('customer_id'));
        $this->assertFalse(DB::table('loyalty_accounts')->where('id', 82)->exists());
        $this->assertSame(1, $report['conflicts']['loyalty_accounts: customer_id does not name a customers row of this store']['count']);
        $this->assertSame(1, $report['conflicts']['items.category_id: categories row not in this store (or not imported), set NULL']['count']);
        $this->assertSame(1, $report['conflicts']['order_items.item_id: items row not in this store (or not imported), set NULL']['count']);
        // Store 20's account sees no row of store 10 (and vice versa).
        $this->assertSame(0, DB::table('orders')->where('account_id', 2)->where('store_id', 10)->count());
        $this->assertSame([20], DB::table('items')->where('account_id', 2)->distinct()->pluck('store_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_dry_run_writes_nothing_and_reports_what_it_would_do(): void
    {
        $this->seedTwoOwners();
        $report = $this->import(['--dry-run' => true]);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(3, $report['tables']['users']['written']);
        $this->assertSame(3, $report['tables']['stores']['written']);
        $this->assertSame(3, $report['tables']['order_items']['written']);
        foreach (['users', 'accounts', 'stores', 'items', 'orders', 'store_payment_gateways', 'legacy_import_rows'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame([], $report['sequences']);
    }

    public function test_a_second_run_resumes_without_duplicates(): void
    {
        $this->seedTwoOwners();
        $first = $this->import(['--store' => ['10']]);
        $this->assertSame(1, $first['tables']['stores']['written']);
        $this->assertSame([10], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());

        $second = $this->import();
        $this->assertSame(1, $second['counters']['stores_already_imported']);
        $this->assertSame(3, $second['counters']['users_already_imported']);
        $this->assertEqualsCanonicalizing([10, 11, 20], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(3, DB::table('users')->count());
        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('legacy_import_rows')->where('target_table', 'stores')->where('target_id', '10')->count());
    }

    public function test_settings_are_mapped_and_unknown_or_forbidden_names_are_reported_not_imported(): void
    {
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->storeOption(10, 'whatsapp_number', '+966500000000');
        $s->storeOption(10, 'restaurant_on_table_order', '1');
        $s->storeOption(10, Source::THEME_OPTION, 'classic-theme');
        $s->storeOption(10, StoreSettingsImporter::NEVER[0], '0');
        $s->storeOption(10, 'some_retired_option', 'x');
        $licence = StoreSettingsImporter::DROPPED_PREFIXES[0].'_purchase_code';
        $s->storeOption(10, $licence, 'synthetic');

        $report = $this->import();
        StoreSettings::flush();

        $this->assertSame('SAR', StoreSettings::string(10, 'currency_code'));
        $this->assertSame('+966500000000', StoreSettings::string(10, 'whatsapp_number'));
        $this->assertTrue(StoreSettings::bool(10, 'restaurant_on_table_order'));
        $this->assertSame('crave', StoreSettings::string(10, 'store_template'));
        $this->assertFalse(StoreSettings::has(10, StoreSettingsImporter::NEVER[0]));
        $this->assertFalse(StoreSettings::has(10, 'some_retired_option'));
        $this->assertSame(1, $report['unmapped_settings']['some_retired_option']);
        $this->assertSame(1, $report['dropped_settings'][StoreSettingsImporter::NEVER[0]]);
        $this->assertSame(1, $report['dropped_settings'][$licence]);
        $this->assertStringNotContainsString('+966500000000', json_encode($report));
    }

    public function test_gateway_secrets_are_reencrypted_with_this_install_key_and_deferred_gateways_stay_off(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $old = new Encrypter(base64_decode(substr($oldKey, 7)), 'AES-256-CBC');
        config(['legacy_compat.import.source_app_key' => $oldKey]);

        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->storeOption(10, Source::gatewayKey('stripe', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('stripe', 'title'), 'Card');
        $s->storeOption(10, Source::gatewayKey('tap', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('tap', 'secret_key'), 'sk_tap_synthetic_plain');
        $s->storeSecrets(10, StoreSettingsImporter::SECRET_FEATURE, $old->encryptString((string) json_encode([
            Source::gatewayKey('stripe', 'secret_key') => 'sk_test_synthetic_123',
            'meta_capi_token' => 'capi_synthetic_456',
        ])));
        $s->storeSecrets(10, 'ai', $old->encryptString('{"key":"synthetic"}'));

        $report = $this->import();
        StorePaymentGateways::forgetMemo();

        $stripe = DB::table('store_payment_gateways')->where('store_id', 10)->where('gateway', 'stripe')->first();
        $this->assertNotNull($stripe);
        $this->assertTrue((bool) $stripe->enabled);
        $this->assertSame(1, (int) $stripe->account_id);
        $this->assertSame('sk_test_synthetic_123', StorePaymentGateways::get(10, Source::gatewayKey('stripe', 'secret_key')));
        $this->assertSame('Card', StorePaymentGateways::get(10, Source::gatewayKey('stripe', 'title')));
        // Stored under THIS install's key: no plaintext, not readable with the old key.
        $this->assertStringNotContainsString('sk_test_synthetic_123', (string) $stripe->credentials);
        $this->assertSame('sk_test_synthetic_123', json_decode(Crypt::decryptString((string) $stripe->credentials), true)['secret_key']);
        try {
            $old->decryptString((string) $stripe->credentials);
            $this->fail('the old key must not decrypt the re-encrypted secret');
        } catch (DecryptException) {
            $this->addToAssertionCount(1);
        }

        // J51a: a deferred gateway is imported switched off (its secret is still kept encrypted).
        $tap = DB::table('store_payment_gateways')->where('store_id', 10)->where('gateway', 'tap')->first();
        $this->assertFalse((bool) $tap->enabled);
        $this->assertSame('0', json_decode((string) $tap->config, true)['install']);

        // Non-gateway secret into the encrypted credential row; other secret features only counted.
        $cred = DB::table('credentials')->where('store_id', 10)->where('feature', StoreSettingsImporter::SECRET_FEATURE)->first();
        $this->assertSame('capi_synthetic_456', json_decode(Crypt::decryptString((string) $cred->secrets), true)['meta_capi_token']);
        $this->assertSame(1, $report['counters']['store_credentials_not_imported:ai']);

        $json = (string) json_encode($report);
        foreach (['sk_test_synthetic_123', 'sk_tap_synthetic_plain', 'capi_synthetic_456', substr($oldKey, 7)] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_without_the_source_key_encrypted_secrets_are_reported_and_not_imported(): void
    {
        $old = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->storeOption(10, Source::gatewayKey('stripe', 'install'), '1');
        $s->storeSecrets(10, StoreSettingsImporter::SECRET_FEATURE, $old->encryptString((string) json_encode([Source::gatewayKey('stripe', 'secret_key') => 'sk_test_synthetic_789'])));

        $report = $this->import();

        $this->assertSame(1, $report['conflicts']['store secrets: source secret row could not be decrypted (no or wrong source key), not imported']['count']);
        $this->assertNull(DB::table('store_payment_gateways')->where('store_id', 10)->where('gateway', 'stripe')->value('credentials'));
        // SF2: secrets not imported => the gateway is not live with partial credentials.
        $stripe = DB::table('store_payment_gateways')->where('store_id', 10)->where('gateway', 'stripe')->first();
        $this->assertFalse((bool) $stripe->enabled);
        $this->assertSame('0', json_decode((string) $stripe->config, true)['install']);
    }

    public function test_a_gateway_whose_option_secret_cannot_be_decrypted_is_switched_off_others_stay_on(): void
    {
        $old = new Encrypter(random_bytes(32), 'AES-256-CBC'); // the source key is NOT configured
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->storeOption(10, Source::gatewayKey('stripe', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('stripe', 'secret_key'), $old->encryptString('sk_test_synthetic_enc'));
        $s->storeOption(10, Source::gatewayKey('paypal', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('paypal', 'api_secret'), 'pp_secret_synthetic_plain');

        $report = $this->import();

        $rows = DB::table('store_payment_gateways')->where('store_id', 10)->get()->keyBy('gateway');
        $this->assertFalse((bool) $rows['stripe']->enabled);
        $this->assertNull($rows['stripe']->credentials);
        $this->assertTrue((bool) $rows['paypal']->enabled);
        $this->assertSame(1, $report['conflicts']['store gateways: secrets not imported, imported switched off']['count']);
    }

    public function test_an_unset_optional_secret_keeps_the_gateway_on_and_is_not_reported(): void
    {
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->storeOption(10, Source::gatewayKey('stripe', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('stripe', 'secret_key'), 'sk_test_synthetic_set');
        $s->storeOption(10, Source::gatewayKey('stripe', 'webhook_secret'), null);
        $s->storeOption(10, Source::gatewayKey('paypal', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('paypal', 'api_secret'), '');

        $report = $this->import();

        $rows = DB::table('store_payment_gateways')->where('store_id', 10)->get()->keyBy('gateway');
        $this->assertTrue((bool) $rows['stripe']->enabled);
        $this->assertTrue((bool) $rows['paypal']->enabled);
        $this->assertSame(['secret_key' => 'sk_test_synthetic_set'], json_decode(Crypt::decryptString((string) $rows['stripe']->credentials), true));
        foreach (array_keys($report['conflicts']) as $kind) {
            $this->assertStringNotContainsString('store secrets', $kind);
            $this->assertStringNotContainsString('store gateways', $kind);
        }
    }

    public function test_an_encrypted_value_under_a_non_secret_name_is_never_decrypted_into_plain_settings(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $old = new Encrypter(base64_decode(substr($oldKey, 7)), 'AES-256-CBC');
        config(['legacy_compat.import.source_app_key' => $oldKey]);
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->storeOption(10, 'whatsapp_number', $old->encryptString('+966511111111'));
        $s->storeOption(10, Source::gatewayKey('stripe', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('stripe', 'title'), $old->encryptString('hidden-title-synthetic'));

        $report = $this->import();
        StoreSettings::flush();

        $this->assertFalse(StoreSettings::has(10, 'whatsapp_number'));
        $settings = (string) DB::table('stores')->where('id', 10)->value('settings');
        $config = (string) DB::table('store_payment_gateways')->where('store_id', 10)->where('gateway', 'stripe')->value('config');
        foreach (['+966511111111', 'hidden-title-synthetic'] as $plain) {
            $this->assertStringNotContainsString($plain, $settings.$config.json_encode($report));
        }
        $this->assertArrayNotHasKey('title', json_decode($config, true));
        $this->assertFalse((bool) DB::table('store_payment_gateways')->where('store_id', 10)->where('gateway', 'stripe')->value('enabled'));
        $this->assertSame(2, $report['conflicts']['store secrets: encrypted value under a name that is not a known secret, not imported']['count']);
    }

    public function test_guard_compares_ids_not_counts(): void
    {
        $this->seedTwoOwners();
        $this->import(['--store' => ['10']]);
        // Same count, different row: an imported allergy deleted and a foreign one added.
        DB::table('allergies')->where('id', 4)->delete();
        DB::table('allergies')->insert(['id' => 77, 'title' => 'Added by hand']);

        $this->assertSame(1, Artisan::call('legacy:import', ['--source' => self::SOURCE]));
        $this->assertStringContainsString('allergies already holds 1 row(s) not written by this importer', Artisan::output());
    }

    public function test_a_failing_insert_reports_phase_and_sql_state_but_no_row_values(): void
    {
        $this->src->user(1, ['user_type' => 'synthetic-role', 'email' => 'person-secret@example.test', 'password' => '$2y$10$hashhashhashhashhashhashhashhashhashhashhashhashhashha']);

        foreach ([[], ['--dry-run' => true]] as $args) {
            $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE] + $args);
            $output = Artisan::output();
            $this->assertSame(1, $exit, $output);
            $this->assertStringContainsString('legacy:import', $output);
            $this->assertStringContainsString('SQLSTATE', $output);
            foreach (['person-secret@example.test', 'hashhashhash', 'synthetic-role'] as $value) {
                $this->assertStringNotContainsString($value, $output);
            }
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('accounts')->count());
    }

    public function test_a_source_store_table_without_an_owner_column_derives_no_account(): void
    {
        $this->src->user(1);
        $this->src->store(10, 1);
        $this->src->db->getSchemaBuilder()->table('posts', fn ($t) => $t->dropColumn(Source::STORE_OWNER_COLUMN));

        $report = $this->import();

        $this->assertSame(0, DB::table('accounts')->count());
        $this->assertSame(0, DB::table('stores')->count());
        $this->assertSame(1, $report['conflicts']['accounts: the source store table has no owner column, no account derived']['count']);
    }

    public function test_a_resumed_run_moves_sequences_of_tables_an_earlier_run_wrote(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('ALTER TABLE would commit the test transaction (the bump is reported as skipped inside one).');
        }
        $this->seedTwoOwners();
        $this->import(['--store' => ['10']]);

        // Emulate a run that crashed before its bump: the sequences sit below the imported ids again.
        foreach (['allergies', 'stores'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("select setval(pg_get_serial_sequence('{$table}', 'id'), 1, false)");
            } else {
                DB::table('sqlite_sequence')->where('name', $table)->update(['seq' => 0]);
            }
        }

        // The resumed run writes nothing new to allergies, yet still moves its sequence.
        $report = $this->import(['--store' => ['10']]);
        $this->assertSame(0, $report['tables']['allergies']['written'] ?? 0);
        $this->assertSame(1004, $report['sequences']['allergies']);
        // Past the highest SOURCE store id (30), not only the imported one: a later run still imports up to it.
        $this->assertSame(1030, $report['sequences']['stores']);
        $this->assertSame(1004, (int) DB::table('allergies')->insertGetId(['title' => 'New after resume']));
    }

    public function test_sequences_move_past_the_imported_ids(): void
    {
        $this->seedTwoOwners();
        $report = $this->import();

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            // ALTER TABLE would commit the test transaction: inside one the bump is skipped and reported.
            $this->assertSame('skipped: transaction open', $report['sequences']['stores']);

            return;
        }
        $this->assertSame(1030, $report['sequences']['stores']); // source max id 30 (the orphan store is never imported)
        $this->assertSame(6000 + 1000, $report['sequences']['orders']);
        $id = DB::table('allergies')->insertGetId(['title' => 'New after import']);
        $this->assertSame(4 + 1000, (int) $id);
    }
}
