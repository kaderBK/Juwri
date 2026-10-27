<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-17 part 2c — characterisation of today's customer search, pinned before the switch to Scout.
 *
 * Every customer search matches name, phone and email as a case-insensitive substring and is scoped to the caller's
 * store(s):
 *   - POS register (`pos.customers`): one store, ordered by name, at most 20; no query = the 10 most recent by
 *     last order (nulls last);
 *   - owner customer list + CSV export (`customers.index` / `customers.export`): the owner's stores (or the selected
 *     one), 20 per page, most recent order first;
 *   - API v1 (`GET /api/v1/stores/{id}/customers?search=`): one owned store, paginated;
 *   - owner dashboard command palette (`customers` group): the owner's stores, ranked in PHP by the label.
 */
class CustomerSearchTest extends TestCase
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

    /** @return array{0: User, 1: Store, 2: StaffMember} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$owner, $store, $staff];
    }

    private function customer(Store $store, string $name, ?string $phone = null, ?string $email = null, ?string $lastOrder = null): Customer
    {
        return Customer::create([
            'store_id' => $store->id, 'name' => $name, 'phone' => $phone, 'email' => $email,
            'last_order_at' => $lastOrder,
        ]);
    }

    /** @return list<int> */
    private function pos(StaffMember $staff, Store $store, string $q): array
    {
        return $this->actingAs($staff, 'staff')
            ->getJson(route('pos.customers', ['store' => $store->id, 'q' => $q]))
            ->assertOk()->json('*.id') ?? [];
    }

    // ── POS register ──────────────────────────────────────────────────────────

    public function test_pos_matches_name_phone_and_email_case_insensitively_ordered_by_name(): void
    {
        [, $store, $staff] = $this->store();
        $zed = $this->customer($store, 'Zed Karim', '0790001111');
        $amal = $this->customer($store, 'Amal', '0791112222', 'KARIM.family@mail.test');
        $bassam = $this->customer($store, 'Bassam KARIMI');
        $this->customer($store, 'Other Person', '0000');

        $this->assertSame([$amal->id, $bassam->id, $zed->id], $this->pos($staff, $store, 'karim'));
        $this->assertSame([$zed->id], $this->pos($staff, $store, '0001'));
        $this->assertSame([$amal->id], $this->pos($staff, $store, 'FAMILY@'));
    }

    public function test_pos_caps_the_result_at_twenty(): void
    {
        [, $store, $staff] = $this->store();
        for ($i = 1; $i <= 25; $i++) {
            $this->customer($store, sprintf('Guest %02d', $i));
        }

        $names = Customer::whereIn('id', $this->pos($staff, $store, 'guest'))->orderBy('name')->pluck('name')->all();

        $this->assertCount(20, $names);
        $this->assertSame('Guest 01', $names[0]);
        $this->assertSame('Guest 20', $names[19]);
    }

    public function test_pos_with_no_query_lists_the_ten_most_recent_by_last_order(): void
    {
        [, $store, $staff] = $this->store();
        $never = $this->customer($store, 'Never Ordered');
        $ids = [];
        for ($i = 1; $i <= 11; $i++) {
            $ids[$i] = $this->customer($store, 'Guest '.$i, null, null, now()->subDays($i)->toDateTimeString())->id;
        }

        $out = $this->pos($staff, $store, '');

        $this->assertCount(10, $out);
        $this->assertSame($ids[1], $out[0]);
        $this->assertNotContains($never->id, $out);
        $this->assertNotContains($ids[11], $out);
    }

    public function test_pos_never_returns_another_stores_customers_sharing_the_words(): void
    {
        [, $storeA, $staffA] = $this->store();
        [, $storeB] = $this->store();
        $mine = $this->customer($storeA, 'Ahmed Saleh', '0795550000');
        $this->customer($storeB, 'Ahmed Saleh', '0795550000');
        $this->customer($storeB, 'Saleh Ahmed');

        $this->assertSame([$mine->id], $this->pos($staffA, $storeA, 'ahmed'));
        $this->assertSame([$mine->id], $this->pos($staffA, $storeA, '555'));
    }

    public function test_pos_arabic_name_is_found_by_an_arabic_word(): void
    {
        [, $store, $staff] = $this->store();
        $hit = $this->customer($store, 'أحمد العلي');
        $this->customer($store, 'سارة');

        $this->assertSame([$hit->id], $this->pos($staff, $store, 'العلي'));
    }

    /**
     * Regression (P1-17): the register escaped `%`/`_` with a backslash, which sqlite does not treat as a LIKE escape,
     * so "50%" found nothing there. The Scout search makes them literal on every database.
     */
    public function test_pos_treats_percent_and_underscore_as_literal_characters(): void
    {
        [, $store, $staff] = $this->store();
        $percent = $this->customer($store, '50% Club');
        $this->customer($store, '500 Club');
        $underscore = $this->customer($store, 'Team a_b');
        $this->customer($store, 'Team axb');

        $this->assertSame([$percent->id], $this->pos($staff, $store, '50%'));
        $this->assertSame([$underscore->id], $this->pos($staff, $store, 'a_b'));
    }

    // ── Owner list + export ───────────────────────────────────────────────────

    public function test_owner_list_and_export_filter_by_name_phone_or_email_in_the_owners_stores_only(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();
        $old = $this->customer($store, 'Maya Haddad', null, null, now()->subDays(9)->toDateTimeString());
        $recent = $this->customer($store, 'Omar', '0790', 'maya.o@mail.test', now()->subDay()->toDateTimeString());
        $this->customer($store, 'Lina');
        $this->customer($other, 'Maya Haddad');

        $page = $this->actingAs($owner)->get(route('customers.index', ['q' => 'MAYA']))->assertOk()->viewData('customers');

        $this->assertSame([$recent->id, $old->id], collect($page->items())->pluck('id')->all());
        $this->assertSame(2, $page->total());

        $csv = $this->actingAs($owner)->get(route('customers.export', ['q' => 'maya']))->assertOk()->streamedContent();
        $this->assertStringContainsString('maya.o@mail.test', $csv);
        $this->assertStringContainsString('Maya Haddad', $csv);
        $this->assertStringNotContainsString('Lina', $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1, 'header + 2 rows');
    }

    public function test_owner_list_pages_twenty_at_a_time(): void
    {
        [$owner, $store] = $this->store();
        for ($i = 1; $i <= 23; $i++) {
            $this->customer($store, 'Regular '.$i);
        }

        $page = $this->actingAs($owner)->get(route('customers.index', ['q' => 'regular']))->assertOk()->viewData('customers');

        $this->assertCount(20, $page->items());
        $this->assertSame(23, $page->total());
    }

    // ── API v1 ────────────────────────────────────────────────────────────────

    public function test_api_v1_search_matches_name_phone_or_email_in_the_owned_store_only(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);
        config(['settings.admin_allow_crm_suite' => 1]);
        EntitlementWriter::grant($owner, 'crm_suite', 'admin', null, '1', null, 'test-fixture');
        FeatureAccess::flush();
        $byPhone = $this->customer($store, 'Rami', '0797771234');
        $byEmail = $this->customer($store, 'Hala', null, 'rami@mail.test');
        $this->customer($store, 'Nour');
        $this->customer($other, 'Rami', '0797771234');

        $ids = $this->getJson('/api/v1/stores/'.$store->id.'/customers?search=rami', ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing([$byPhone->id, $byEmail->id], $ids);

        $ids = $this->getJson('/api/v1/stores/'.$store->id.'/customers?search=7771', ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->json('data.*.id');
        $this->assertSame([$byPhone->id], $ids);
    }

    // ── Owner dashboard command palette ───────────────────────────────────────

    /**
     * The palette matches name/phone/email in SQL, then ranks by the LABEL (name, or phone when there is no name) and
     * drops a row whose label scores nothing — so a named customer found only by phone does not show.
     */
    public function test_dashboard_palette_finds_customers_by_label_in_the_owners_stores_only(): void
    {
        [$owner, $store] = $this->store();
        [, $other] = $this->store();
        $mine = $this->customer($store, 'Yara Nasser', '0798884444');
        $theirs = $this->customer($other, 'Yara Nasser', '0798884444');
        $this->customer($store, 'Dana', '0798884445');

        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'nasser']))->assertOk()->json();
        $urls = array_column(collect($json['groups'])->firstWhere('key', 'customers')['items'] ?? [], 'url');

        $this->assertSame([route('customers.show', $mine->id)], $urls);
        $this->assertNotContains(route('customers.show', $theirs->id), $urls);

        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => '8884']))->assertOk()->json();
        $this->assertSame([], collect($json['groups'])->firstWhere('key', 'customers')['items'] ?? []);
    }
}
