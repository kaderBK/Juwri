<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyProdShapeFixture as Shape;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyTwoFactorReenrol;
use Juwri\Modules\LegacyCompat\Import\LegacyTableMaps;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\TestCase;

/**
 * J52b M4b step 3 (SYNTHETIC rows, J19): per-store allergies stay with their store (J29), the store vertical and bio
 * page, user reseller link / abuse hold, two-factor NOT carried over (J58b: off, marked, asked once at sign-in),
 * customer referral fields, and rooms / servers on tables loaded after their room types and staff (staff: PIN hash
 * only, HR fields not imported).
 */
class LegacyTenancyColumnsImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_tenancy_source';

    private Source $src;

    private Shape $shape;

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
        $this->shape = (new Shape(DB::connection(self::SOURCE)))->laterTenancy();
        $this->src->platformOption('currency_code', 'USD');
        $this->src->user(1);
        $this->src->store(10, 1);
        $this->src->storeOption(10, 'currency_code', 'SAR');
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function import(): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** @return list<string> */
    private function marks(string $table, int $id): array
    {
        return DB::table('legacy_import_marks')->where('target_table', $table)->where('target_id', (string) $id)
            ->orderBy('mark')->pluck('mark')->all();
    }

    public function test_store_allergies_stay_with_their_store_and_platform_ones_have_none(): void
    {
        $this->src->user(2);
        $this->src->store(20, 2);
        $this->src->storeOption(20, 'currency_code', 'SAR');
        $this->src->allergy(4); // platform
        $this->src->allergy(5, ['restaurant_id' => 10]);
        $this->src->allergy(6, ['restaurant_id' => 20]);
        $this->src->allergy(7, ['restaurant_id' => 99]); // store not in the source
        $this->src->item(1000, 10, null, 1.0, ['allergies' => '4,5,6,7']);

        $report = $this->import();

        $rows = DB::table('allergies')->orderBy('id')->get(['id', 'store_id', 'account_id'])->keyBy('id');
        $this->assertNull($rows[4]->store_id);
        $this->assertNull($rows[4]->account_id);
        $this->assertSame([10, 1], [(int) $rows[5]->store_id, (int) $rows[5]->account_id]);
        $this->assertSame([20, 2], [(int) $rows[6]->store_id, (int) $rows[6]->account_id]);
        $this->assertFalse($rows->has(7)); // never a platform row
        $this->assertSame('4,5', DB::table('items')->where('id', 1000)->value('allergies')); // other stores' ids dropped
        $this->assertSame(1, $report['conflicts']['allergies: source rows never reached (store not imported, or parent row missing)']['count']);
    }

    public function test_store_vertical_bio_page_and_catalogue_visibility_are_kept_unknown_vertical_reported(): void
    {
        DB::connection(self::SOURCE)->table('posts')->where('id', 10)->update([
            'store_type' => 'hotel', 'catalog_visible' => false, 'bio_builder' => 'html', 'bio_html' => '<p>Synthetic</p>',
        ]);
        $this->src->store(11, 1, ['store_type' => 'spaceship']);
        $this->src->storeOption(11, 'currency_code', 'SAR');

        $report = $this->import();

        $store = DB::table('stores')->where('id', 10)->first();
        $this->assertSame('hotel', $store->store_type);
        $this->assertFalse((bool) $store->catalog_visible);
        $this->assertSame('html', $store->bio_builder);
        $this->assertSame('<p>Synthetic</p>', $store->bio_html);
        $this->assertSame('restaurant', DB::table('stores')->where('id', 11)->value('store_type'));
        $this->assertSame(['store#11 spaceship'], $report['conflicts']['stores.store_type: type unknown to this install, default kept']['samples']);
    }

    public function test_two_factor_is_not_carried_over_and_the_owner_is_sent_to_set_it_up_once(): void
    {
        DB::connection(self::SOURCE)->table('user')->where('id', 1)->update([
            'two_factor_secret' => 'sealed-with-the-source-key', 'two_factor_recovery_codes' => 'sealed',
            'two_factor_confirmed_at' => '2025-01-01 00:00:00', 'abuse_hold' => true, 'reseller_id' => 5,
        ]);

        $report = $this->import();

        $user = User::query()->withoutGlobalScopes()->findOrFail(1);
        $this->assertNull($user->getRawOriginal('two_factor_secret'));
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertTrue((bool) $user->abuse_hold);
        $this->assertNull($user->reseller_id); // reseller not on this install: kept as a mark only
        $this->assertSame([LegacyTableMaps::RESELLER_PENDING, LegacyTableMaps::TWO_FACTOR_REENROL], $this->marks('users', 1));
        $this->assertSame(1, $report['counters']['users_two_factor_reenrol']);
        $this->assertNotContains('login_landing', $report['unknown_source_columns']['user'] ?? []);

        config(['app.installed' => true]);
        LegacyCompatServiceProvider::activate($this->app);
        $this->assertContains(LegacyTwoFactorReenrol::class, $this->app->make(Router::class)->getMiddlewareGroups()['web']);

        // An admin logged in as the owner is never redirected, and the one-time mark stays for the owner.
        $this->assertNotSame(route(LegacyTwoFactorReenrol::SETUP_ROUTE), $this->lastRedirect('/dashboard', ['quick_admin_user_id' => 99]));
        $this->assertContains(LegacyTableMaps::TWO_FACTOR_REENROL, $this->marks('users', 1));
        // Nor inside a staff session (which signs the owner in on the web guard too).
        $this->flushSession();
        $this->assertNotSame(route(LegacyTwoFactorReenrol::SETUP_ROUTE), $this->lastRedirect('/dashboard', ['staff_session_id' => 5]));

        // The owner's own session: sent to the setup page; asked once (the mark goes when the page is shown).
        $this->flushSession();
        $this->assertSame(route(LegacyTwoFactorReenrol::SETUP_ROUTE), $this->lastRedirect('/dashboard'));
        $this->actingAs($user)->get(route(LegacyTwoFactorReenrol::SETUP_ROUTE));
        $this->assertSame([LegacyTableMaps::RESELLER_PENDING], $this->marks('users', 1));
        $this->flushSession();
        $this->assertNotSame(route(LegacyTwoFactorReenrol::SETUP_ROUTE), $this->lastRedirect('/dashboard'));
    }

    /** @param array<string, mixed> $session */
    private function lastRedirect(string $uri, array $session = []): ?string
    {
        $user = User::query()->withoutGlobalScopes()->findOrFail(1);
        $response = $this->actingAs($user)->withSession($session)->get($uri);

        return $response->isRedirect() ? $response->headers->get('Location') : null;
    }

    public function test_migrated_staff_who_had_two_factor_are_told_by_the_staff_app_until_they_set_it_up(): void
    {
        $this->shape->staff(70, 10, 1, ['two_factor_secret' => 'sealed', 'two_factor_confirmed_at' => '2025-01-01 00:00:00']);
        $this->import();
        LegacyCompatServiceProvider::activate($this->app);
        $staff = StaffMember::query()->withoutGlobalScopes()->findOrFail(70);
        $token = StaffToken::issue($staff, 'test');

        $this->withToken($token)->getJson(route(LegacyTwoFactorReenrol::STAFF_PROFILE_ROUTE))
            ->assertOk()->assertJsonPath(LegacyTwoFactorReenrol::STAFF_FLAG, true)->assertJsonPath('id', 70);

        DB::table('staff_members')->where('id', 70)->update(['two_factor_secret' => Crypt::encryptString('NEWSECRET'), 'two_factor_confirmed_at' => now()]);
        $this->app->forgetInstance('api.staff');
        $this->withToken($token)->getJson(route(LegacyTwoFactorReenrol::STAFF_PROFILE_ROUTE))
            ->assertOk()->assertJsonMissingPath(LegacyTwoFactorReenrol::STAFF_FLAG);
        $this->assertSame([], $this->marks('staff_members', 70));
    }

    public function test_a_reseller_link_is_kept_only_for_the_same_imported_source_reseller(): void
    {
        // The reseller table belongs to the Enterprise package: without it there is no reseller to link a user to.
        if (! Schema::hasTable('resellers')) {
            $this->markTestSkipped('Community build: the Enterprise package is not installed.');
        }

        $this->src->user(2);
        DB::connection(self::SOURCE)->table('user')->where('id', 1)->update(['reseller_id' => 5]);
        DB::connection(self::SOURCE)->table('user')->where('id', 2)->update(['reseller_id' => 6]);
        // Reseller 5 was made by hand on this install (another tenant); reseller 6 came from the source with its id.
        foreach ([5, 6] as $id) {
            DB::table('resellers')->insert(['id' => $id, 'public_id' => (string) Str::uuid7(), 'owner_user_id' => 900 + $id, 'brand_name' => 'Synthetic '.$id]);
        }
        DB::table('legacy_import_rows')->insert(['source_table' => 'resellers', 'source_id' => '6', 'target_table' => 'resellers',
            'target_id' => '6', 'source_checksum' => str_repeat('0', 64), 'run_id' => 'earlier', 'created_at' => now()]);

        $this->import();

        $this->assertNull(DB::table('users')->where('id', 1)->value('reseller_id'));
        $this->assertContains(LegacyTableMaps::RESELLER_PENDING, $this->marks('users', 1));
        $this->assertSame(6, (int) DB::table('users')->where('id', 2)->value('reseller_id'));
        $this->assertNotContains(LegacyTableMaps::RESELLER_PENDING, $this->marks('users', 2));
    }

    public function test_customer_referral_and_message_fields_are_kept_a_pending_password_is_not(): void
    {
        $this->src->customer(300, 10, [
            'referral_code' => 'REF-SYN-300', 'referred_by_code' => 'REF-SYN-001', 'notifications_enabled' => false,
            'system_tags' => '["vip"]', 'last_winback_at' => '2025-05-01 09:00:00', 'pending_password' => 'not-a-real-hash',
        ]);

        $this->import();

        $customer = DB::table('customers')->where('id', 300)->first();
        $this->assertSame('REF-SYN-300', $customer->referral_code);
        $this->assertSame('REF-SYN-001', $customer->referred_by_code);
        $this->assertFalse((bool) $customer->notifications_enabled);
        $this->assertSame('["vip"]', $customer->system_tags);
        $this->assertStringStartsWith('2025-05-01 09:00:00', (string) $customer->last_winback_at);
        $this->assertNull($customer->pending_password);
    }

    public function test_rooms_and_servers_resolve_after_their_room_types_and_staff_and_staff_keep_only_the_pin_hash(): void
    {
        $this->src->user(2);
        $this->src->store(20, 2);
        $this->src->storeOption(20, 'currency_code', 'SAR');
        $this->shape->roomType(60, 10, ['base_rate' => 250.5, 'code' => 'DLX']);
        $this->shape->roomType(61, 20);
        $hash = Hash::make('4321');
        $this->shape->staff(70, 10, 1, ['pin_hash' => $hash, 'pin' => '4321', 'national_id' => 'SYN-ID-1', 'date_of_birth' => '1990-01-01',
            'two_factor_secret' => 'sealed', 'hourly_rate' => 12.5, 'role' => 'manager']);
        $this->shape->staff(71, 10, 2, ['pin' => '9999']); // plaintext only; owner id of another user
        $this->src->table(700, 10, 'K-ROOM-1', ['area_type' => 'room', 'room_type_id' => 60, 'server_staff_id' => 70, 'hk_status' => 'dirty', 'oo_status' => 'ooo', 'oo_until' => '2025-06-30']);
        $this->src->table(701, 10, 'K-ROOM-2', ['area_type' => 'room', 'room_type_id' => 61]); // another store's type

        $report = $this->import();

        $room = DB::table('store_tables')->where('id', 700)->first();
        $this->assertSame(60, (int) $room->room_type_id);
        $this->assertSame(70, (int) $room->server_staff_id);
        $this->assertSame(['dirty', 'ooo'], [$room->hk_status, $room->oo_status]);
        $this->assertStringStartsWith('2025-06-30', (string) $room->oo_until);
        $this->assertNull(DB::table('store_tables')->where('id', 701)->value('room_type_id'));
        $this->assertSame(25050, (int) DB::table('room_types')->where('id', 60)->value('base_rate_minor'));

        $staff = DB::table('staff_members')->where('id', 70)->first();
        $this->assertSame($hash, $staff->pin_hash);
        $this->assertNull($staff->pin);
        $this->assertNull($staff->national_id);
        $this->assertNull($staff->date_of_birth);
        $this->assertNull($staff->two_factor_secret);
        $this->assertNull($staff->hourly_rate_minor);
        $this->assertSame([1, 10, 1], [(int) $staff->owner_id, (int) $staff->store_id, (int) $staff->account_id]);
        $this->assertSame([LegacyTableMaps::TWO_FACTOR_REENROL], $this->marks('staff_members', 70));
        $plain = DB::table('staff_members')->where('id', 71)->first();
        $this->assertNull($plain->pin); // the plaintext is never stored ...
        $this->assertTrue(Hash::check('9999', (string) $plain->pin_hash)); // ... but the member keeps signing in
        $this->assertSame(1, (int) $plain->owner_id);
        $this->assertSame(1, $report['counters']['staff_pin_hashed_at_import']);
        $this->assertSame(1, $report['conflicts']['staff_members.owner_id: not the store owner in the source, store owner used']['count']);
    }
}
