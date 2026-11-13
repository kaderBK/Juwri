<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Models\TableMergeGroup;
use Juwri\Modules\Tables\Models\TableMergeMember;
use Juwri\Modules\Tables\Services\SplitBillService;
use Juwri\Modules\Tables\Services\TableMergeService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T4 — merging tables onto one bill.
 *
 * The load-bearing assertions here are the two that a merge could get
 * catastrophically wrong:
 *
 *  1. it must MOVE orders, never re-create them — order ids, item rows and row
 *     counts are all pinned before/after;
 *  2. the merged bill must equal the sum of the participating orders' own
 *     `Order::getTotalAttribute()` values to the cent, because that attribute is
 *     the server-side price authority (tax on the bare subtotal, discount last).
 */
class TableMergeTest extends TestCase
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
        config(['settings.admin_allow_table_merge' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        // The service's per-request memos are process statics; RefreshDatabase
        // rolls the rows back underneath them.
        TableMergeService::flushMemo();

        /* Storefront templates extend a layout that reads $settings/$activeTheme from a
           view()->composer which ViewServiceProvider only registers when APP_INSTALLED is
           set — and phpunit.xml deliberately leaves it unset. Rendering a template
           therefore dies on "Undefined variable $settings" unless the composer's output
           is shared by hand (same stub StorefrontRoomServiceOptionTest uses). */
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
    }

    /** @return array{0: Store, 1: StaffMember, 2: User} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            // table_qr must be entitled or StoreController never resolves ?table= at
            // all, which would make the storefront case below silently vacuous.
            'settings' => [
                'restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1',
                'pos_enabled' => '1', 'table_qr' => '1',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(8).'@t.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'active' => 1,
        ]);

        return [$r, $staff, $owner];
    }

    private function table(Store $r, int $no, ?int $branchId = null, array $extra = []): StoreTable
    {
        $t = new StoreTable;
        $t->forceFill(array_merge([
            'store_id' => $r->id, 'table_no' => $no, 'label' => "T$no", 'seats' => 4,
            'status' => 'free', 'branch_id' => $branchId, 'key' => Str::random(10),
        ], $extra))->save();

        return $t;
    }

    /** An open dine-in order with one line of $qty × $price. */
    private function orderAt(Store $r, StoreTable $t, float $price = 10.0, int $qty = 1, array $extra = []): Order
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Item '.Str::random(4), 'price' => $price, 'active' => '1']);

        $o = new Order;
        $o->forceFill(array_merge([
            'store_id' => $r->id, 'type' => 'on-table', 'table_number' => $t->table_no,
            'status' => 'new', 'is_paid' => 0, 'held_at' => null, 'branch_id' => $t->branch_id,
            'source' => 'pos', 'track_token' => Str::random(32), 'created_at' => now(),
        ], $extra))->save();

        OrderItem::create(['order_id' => $o->id, 'item_id' => $menu->id, 'quantity' => $qty]);

        return $o->fresh();
    }

    /* ===================================================================== */

    public function test_merge_repoints_open_orders_without_creating_any(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 5);
        $b = $this->table($r, 7);
        $oa = $this->orderAt($r, $a, 10);
        $ob = $this->orderAt($r, $b, 25);

        $ordersBefore = Order::count();
        $itemsBefore = OrderItem::count();

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        // No order was created and no line item was copied — the merge MOVED.
        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame($itemsBefore, OrderItem::count());

        $this->assertSame((string) $a->table_no, (string) $ob->fresh()->table_number);
        $this->assertSame((int) $b->id, (int) $ob->fresh()->merged_from_table_id);
        // The primary's own order is untouched — no phantom merge origin.
        $this->assertSame((string) $a->table_no, (string) $oa->fresh()->table_number);
        $this->assertNull($oa->fresh()->merged_from_table_id);

        $this->assertTrue($group->isActive());
        $this->assertSame([(int) $b->id], $group->secondaryTableIds());
        $this->assertSame('T5 + T7', TableMergeService::label($group->fresh('members')));
    }

    /**
     * The bill a merged party is quoted must be, to the cent, the sum of what
     * the server would charge for those orders individually. Tax lands on the
     * item subtotal after the discount (P6-271) — this asserts against
     * `Order::getTotalAttribute()` itself rather than a re-derived figure, so it
     * fails the moment the two disagree.
     */
    public function test_merged_bill_equals_the_sum_of_order_totals(): void
    {
        [$r] = $this->store();
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '15');
        StoreOption::updateStoreOption($r->id, 'restaurant_tax_type', 'percentage');

        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $oa = $this->orderAt($r, $a, 13.33, 3, ['tip_amount' => 2.5]);
        $ob = $this->orderAt($r, $b, 7.77, 2, ['discount_amount' => 4]);

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        $bill = TableMergeService::groupBill($group);

        $expected = round((float) $oa->fresh()->total + (float) $ob->fresh()->total, 4);
        $this->assertSame($expected, round($bill['total'], 4));
        $this->assertSame(2, $bill['order_count']);

        // …and the discount lowered the tax base of its own order (P6-271: tax after the discount).
        $ob = $ob->fresh();
        $subtotal = 7.77 * 2;
        $this->assertEqualsWithDelta(
            $subtotal + round(($subtotal - 4) * 0.15, 2) - 4,
            (float) $ob->total,
            0.0001
        );

        // amount_due subtracts TENDERS, not discounts.
        $this->assertSame(
            round((float) $oa->fresh()->amount_due + (float) $ob->amount_due, 4),
            round($bill['amount_due'], 4)
        );
    }

    public function test_merging_across_stores_is_refused(): void
    {
        [$rA] = $this->store();
        [$rB] = $this->store();
        $a = $this->table($rA, 1);
        $foreign = $this->table($rB, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('That table does not belong to this restaurant.');
        TableMergeService::merge((int) $rA->id, (int) $a->id, [(int) $foreign->id]);
    }

    public function test_merging_across_branches_is_refused(): void
    {
        [$r] = $this->store();
        $one = Branch::create(['store_id' => $r->id, 'name' => 'One', 'active' => 1, 'position' => 1]);
        $two = Branch::create(['store_id' => $r->id, 'name' => 'Two', 'active' => 1, 'position' => 2]);

        $a = $this->table($r, 1, $one->id);
        $b = $this->table($r, 2, $two->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tables in different branches cannot be merged.');
        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);
    }

    /**
     * Legacy NULL-branch rows belong to the DEFAULT branch (ScopesToBranch), so
     * a NULL table and a default-branch table are the SAME branch and must merge
     * — the naive `branch_id === branch_id` check would have refused this.
     */
    public function test_legacy_null_branch_table_merges_with_the_default_branch(): void
    {
        [$r] = $this->store();
        // Creating a Post already provisions its default branch — the FIRST one by
        // id is what a NULL branch_id resolves to, not the newest.
        $default = Branch::where('store_id', $r->id)->orderBy('id')->firstOrFail();

        $a = $this->table($r, 1, $default->id);
        $b = $this->table($r, 2, null);

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);
        $this->assertTrue($group->isActive());
    }

    /**
     * Two branches may both have a "Table 1". Resolving an order's table by
     * number alone would let a settlement in branch B release a merge in
     * branch A, so the branch is part of the identity.
     */
    public function test_same_numbered_tables_in_two_branches_do_not_cross_contaminate(): void
    {
        [$r] = $this->store();
        $a = Branch::where('store_id', $r->id)->orderBy('id')->firstOrFail(); // default
        $b = Branch::create(['store_id' => $r->id, 'name' => 'Two', 'active' => 1, 'position' => 2]);

        $a1 = $this->table($r, 1, $a->id);
        $a2 = $this->table($r, 2, $a->id);
        $b1 = $this->table($r, 1, $b->id);

        $group = TableMergeService::merge((int) $r->id, (int) $a1->id, [(int) $a2->id]);

        // Branch B's "Table 1" is untouched and free to lead its own party.
        $this->assertNull(TableMergeService::activeGroupFor((int) $b1->id));

        // Settling an order on branch B's Table 1 must not release branch A's merge.
        $this->orderAt($r, $b1, 10)->forceFill(['is_paid' => 1])->save();
        $this->assertNull($group->fresh()->released_at);

        // …and a posted "1" in branch B stays "1" rather than being routed into
        // branch A's party.
        $this->assertSame('1', (string) TableMergeService::normalizeTableNumber((int) $r->id, 1, $b->id));
    }

    public function test_a_split_bill_in_progress_blocks_the_merge(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $this->orderAt($r, $a, 10);
        $ob = $this->orderAt($r, $b, 20);

        SplitBillService::createEvenSplit($ob->fresh(), 2);

        try {
            TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);
            $this->fail('A table mid split-bill must not be mergeable.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Finish or cancel the split bill before merging this table.', $e->getMessage());
        }

        $this->assertSame(0, TableMergeGroup::count());
        // The order stayed exactly where it was.
        $this->assertSame((string) $b->table_no, (string) $ob->fresh()->table_number);
    }

    public function test_a_table_cannot_join_two_parties(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $c = $this->table($r, 3);

        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('One of these tables is already merged.');
        TableMergeService::merge((int) $r->id, (int) $c->id, [(int) $b->id]);
    }

    public function test_an_existing_group_can_be_extended_with_a_third_table(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $c = $this->table($r, 3);
        $oc = $this->orderAt($r, $c, 12);

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);
        $again = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $c->id]);

        $this->assertSame($group->id, $again->id, 'Extending must reuse the live group, not open a second one.');
        $this->assertSame(1, TableMergeGroup::count());
        $this->assertSame(3, $again->fresh('members')->members->count());
        $this->assertSame((string) $a->table_no, (string) $oc->fresh()->table_number);
    }

    public function test_hotel_rooms_cannot_be_merged(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $room = $this->table($r, 2, null, ['area_type' => 'room']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hotel rooms cannot be merged.');
        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $room->id]);
    }

    public function test_unmerge_restores_each_order_to_its_own_table(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $oa = $this->orderAt($r, $a, 10);
        $ob = $this->orderAt($r, $b, 20);

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);
        TableMergeService::unmerge($group);

        $ob = $ob->fresh();
        $this->assertSame((string) $b->table_no, (string) $ob->table_number);
        $this->assertNull($ob->merged_from_table_id);
        $this->assertSame((string) $a->table_no, (string) $oa->fresh()->table_number);

        $this->assertNotNull($group->fresh()->released_at);
        $this->assertSame('manual', $group->fresh()->release_reason);
        $this->assertNull(TableMergeService::activeGroupFor((int) $b->id));
    }

    /** Releasing keeps the record: the members survive so the audit stays readable. */
    public function test_a_released_group_keeps_its_membership_history(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);
        TableMergeService::unmerge($group);

        $this->assertSame(2, TableMergeMember::where('group_id', $group->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'table.merge', 'store_id' => $r->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'table.unmerge', 'store_id' => $r->id]);
    }

    public function test_the_party_auto_releases_once_the_primary_settles(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $oa = $this->orderAt($r, $a, 10);
        $ob = $this->orderAt($r, $b, 20);

        $group = TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        // First order paid → the party is still eating, the merge holds.
        $oa->fresh()->forceFill(['is_paid' => 1])->save();
        $this->assertNull($group->fresh()->released_at);

        // Last one paid → the tables go back to being tables.
        $ob->fresh()->forceFill(['is_paid' => 1])->save();
        $this->assertNotNull($group->fresh()->released_at);
        $this->assertSame('settled', $group->fresh()->release_reason);
        // A settled order keeps its merged history rather than hopping tables.
        $this->assertSame((string) $a->table_no, (string) $ob->fresh()->table_number);
    }

    /**
     * A runner sent to "T5" would walk past half a merged party, so the KOT and
     * the receipt name every table in it. Ordinary orders are unchanged.
     */
    public function test_the_kitchen_ticket_names_the_whole_party(): void
    {
        [$r, $staff] = $this->store();
        $a = $this->table($r, 5);
        $b = $this->table($r, 7);
        $lone = $this->table($r, 9);
        $order = $this->orderAt($r, $a, 10);
        $this->orderAt($r, $b, 10);
        $plain = $this->orderAt($r, $lone, 10);

        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        $this->assertSame('T5 + T7', TableMergeService::partyLabelForOrder($order->fresh()));
        $this->assertNull(TableMergeService::partyLabelForOrder($plain->fresh()));

        $this->actingAs($staff, 'staff')
            ->get(route('pos.orders.receipt', ['id' => $order->id, 'store' => $r->id, 'format' => 'kitchen', 'lang' => 'en']))
            ->assertOk()
            ->assertSee('T5 + T7');

        $this->actingAs($staff, 'staff')
            ->get(route('pos.orders.receipt', ['id' => $plain->id, 'store' => $r->id, 'format' => 'kitchen', 'lang' => 'en']))
            ->assertOk()
            ->assertDontSee('T5 + T7');
    }

    /* ── QR resolution ─────────────────────────────────────────────────── */

    public function test_a_scan_on_a_merged_secondary_resolves_to_the_primary(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        $this->assertSame((int) $a->id, (int) TableMergeService::resolvePrimaryTable($b->fresh())->id);
        $this->assertSame((int) $a->id, (int) TableMergeService::resolvePrimaryTable($a->fresh())->id);
        // The posted table number is normalised the same way.
        $this->assertSame((string) $a->table_no, (string) TableMergeService::normalizeTableNumber((int) $r->id, $b->table_no));
        // Unknown numbers pass through untouched — this can never reject an order.
        $this->assertSame('99', (string) TableMergeService::normalizeTableNumber((int) $r->id, '99'));
    }

    public function test_the_storefront_menu_page_swaps_a_merged_secondary_for_its_primary(): void
    {
        [$r] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        $res = $this->get('/'.$r->slug.'?table='.$b->key);
        $res->assertOk();
        // The view is handed the PRIMARY row, so every theme's readonly table
        // field carries the primary's number.
        $this->assertSame((int) $a->id, (int) $res->viewData('table')->id);
    }

    /* ── POS endpoints ─────────────────────────────────────────────────── */

    public function test_the_register_can_merge_and_unmerge(): void
    {
        [$r, $staff] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $ob = $this->orderAt($r, $b, 20);

        $res = $this->actingAs($staff, 'staff')->postJson(route('pos.tables.merge', ['store' => $r->id]), [
            'primary_table_id' => $a->id,
            'table_ids' => [$b->id],
        ])->assertStatus(201);

        $groupId = $res->json('group.id');
        $this->assertSame(20.0, (float) $res->json('group.total'));
        $this->assertSame(8, (int) $res->json('group.seats')); // capacity sums
        $this->assertSame((string) $a->table_no, (string) $ob->fresh()->table_number);

        $state = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.tables.merges', ['store' => $r->id]))->assertOk();
        $this->assertTrue($state->json('enabled'));
        $this->assertCount(1, $state->json('groups'));

        $this->actingAs($staff, 'staff')
            ->deleteJson(route('pos.tables.unmerge', ['group' => $groupId, 'store' => $r->id]))
            ->assertOk()->assertJson(['released' => true]);

        $this->assertSame((string) $b->table_no, (string) $ob->fresh()->table_number);
    }

    public function test_the_register_table_screen_reports_merge_state(): void
    {
        [$r, $staff] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);
        $this->orderAt($r, $a, 10);
        $this->orderAt($r, $b, 20);
        TableMergeService::merge((int) $r->id, (int) $a->id, [(int) $b->id]);

        $byNo = collect(
            $this->actingAs($staff, 'staff')
                ->getJson(route('pos.tables', ['store' => $r->id]))->assertOk()->json()
        )->keyBy('table_no');

        $this->assertSame('occupied', $byNo[1]['status']);
        $this->assertTrue($byNo[1]['merge_is_primary']);
        $this->assertSame('T1 + T2', $byNo[1]['merge_label']);
        $this->assertSame(8, $byNo[1]['merge_seats']);
        // Both orders are on the primary now: the party's bill, not one ticket.
        $this->assertSame(30.0, (float) $byNo[1]['order_total']);
        $this->assertCount(2, $byNo[1]['merge_order_ids']);

        // The secondary is linked, not seatable, and holds no bill of its own.
        $this->assertSame('merged', $byNo[2]['status']);
        $this->assertFalse($byNo[2]['merge_is_primary']);
        $this->assertNull($byNo[2]['order_total']);
    }

    /** Two open orders on one (unmerged) table no longer hide each other's money. */
    public function test_an_unmerged_table_still_reports_its_single_order(): void
    {
        [$r, $staff] = $this->store();
        $a = $this->table($r, 1);
        $o = $this->orderAt($r, $a, 10);

        $byNo = collect(
            $this->actingAs($staff, 'staff')
                ->getJson(route('pos.tables', ['store' => $r->id]))->assertOk()->json()
        )->keyBy('table_no');

        $this->assertSame((int) $o->id, (int) $byNo[1]['order_id']);
        $this->assertSame(10.0, (float) $byNo[1]['order_total']);
        $this->assertNull($byNo[1]['merge_group_id']);
    }

    public function test_the_register_merge_endpoints_refuse_when_the_feature_is_killed(): void
    {
        [$r, $staff] = $this->store();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);

        config(['settings.admin_allow_table_merge' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->actingAs($staff, 'staff')->postJson(route('pos.tables.merge', ['store' => $r->id]), [
            'primary_table_id' => $a->id, 'table_ids' => [$b->id],
        ])->assertForbidden();

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.tables.merges', ['store' => $r->id]))
            ->assertOk()->assertJson(['enabled' => false, 'groups' => []]);

        $this->assertSame(0, TableMergeGroup::count());
    }

    public function test_staff_without_the_orders_permission_cannot_merge(): void
    {
        [$r, $staff] = $this->store();
        // Every POS-capable role carries `orders` by default, so the gate is only
        // observable through an explicit per-staff override.
        $staff->forceFill(['role' => 'cashier', 'permissions' => ['pos' => true, 'orders' => false]])->save();
        $a = $this->table($r, 1);
        $b = $this->table($r, 2);

        $this->actingAs($staff->fresh(), 'staff')->postJson(route('pos.tables.merge', ['store' => $r->id]), [
            'primary_table_id' => $a->id, 'table_ids' => [$b->id],
        ])->assertForbidden();
    }

    /* ── Owner floor-map endpoints ─────────────────────────────────────── */

    public function test_the_owner_can_merge_from_the_floor_map(): void
    {
        [$r, , $owner] = $this->store();
        $a = $this->table($r, 3);
        $b = $this->table($r, 4);
        $ob = $this->orderAt($r, $b, 42);

        $res = $this->actingAs($owner)
            ->postJson(route('floor-map.merge', $r->id), ['primary_table_id' => $a->id, 'table_ids' => [$b->id]])
            ->assertStatus(201);

        $this->assertSame('T3 + T4', $res->json('group.label'));
        $this->assertSame(42.0, (float) $res->json('group.total'));
        $this->assertSame((string) $a->table_no, (string) $ob->fresh()->table_number);

        $this->actingAs($owner)->getJson(route('floor-map.merges', $r->id))
            ->assertOk()->assertJsonCount(1, 'groups');

        $this->actingAs($owner)
            ->deleteJson(route('floor-map.unmerge', [$r->id, $res->json('group.id')]))
            ->assertOk();
        $this->assertSame((string) $b->table_no, (string) $ob->fresh()->table_number);
    }

    /**
     * The floor map is where an owner actually merges, so the page has to
     * RENDER — and it must not offer a control whose endpoint would 403.
     */
    public function test_the_floor_map_offers_merge_only_while_the_feature_is_live(): void
    {
        [$r, , $owner] = $this->store();
        $this->table($r, 1);

        $this->actingAs($owner)->get(route('floor-map.index', $r->id))->assertOk()->assertSeeHtml('id="fm-merge"')->assertSeeHtml('id="fm-merge-bar"');

        config(['settings.admin_allow_table_merge' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->actingAs($owner)->get(route('floor-map.index', $r->id))->assertOk()->assertDontSeeHtml('id="fm-merge"');
    }

    public function test_the_owner_cannot_merge_another_owners_tables(): void
    {
        [$rA, , $ownerA] = $this->store();
        [$rB] = $this->store();
        $a = $this->table($rA, 1);
        $foreign = $this->table($rB, 1);

        $this->actingAs($ownerA)
            ->postJson(route('floor-map.merge', $rA->id), ['primary_table_id' => $a->id, 'table_ids' => [$foreign->id]])
            ->assertStatus(422);

        // …and cannot reach into another restaurant's floor map at all.
        $this->actingAs($ownerA)
            ->postJson(route('floor-map.merge', $rB->id), ['primary_table_id' => $foreign->id, 'table_ids' => [$a->id]])
            ->assertNotFound();
    }

    /** P6-180: staff of store A cannot release store B's party. */
    public function test_register_cannot_unmerge_another_stores_group(): void
    {
        [$rA, $staffA] = $this->store();
        [$rB] = $this->store();
        $group = TableMergeService::merge((int) $rB->id, (int) $this->table($rB, 1)->id, [(int) $this->table($rB, 2)->id]);

        $this->actingAs($staffA, 'staff')
            ->deleteJson(route('pos.tables.unmerge', ['group' => $group->id, 'store' => $rB->id]))
            ->assertStatus(404);
        $this->assertNull($group->fresh()->released_at);
    }

    /** P6-180: staff pinned to branch X cannot merge or unmerge branch Y, even with a crafted ?branch=. */
    public function test_branch_pinned_staff_cannot_touch_another_branch(): void
    {
        [$r, $staff] = $this->store();
        $x = Branch::create(['store_id' => $r->id, 'name' => 'X', 'active' => 1, 'position' => 1]);
        $y = Branch::create(['store_id' => $r->id, 'name' => 'Y', 'active' => 1, 'position' => 2]);
        $staff->forceFill(['branch_id' => $x->id])->save();

        $y1 = $this->table($r, 1, $y->id);
        $y2 = $this->table($r, 2, $y->id);
        $group = TableMergeService::merge((int) $r->id, (int) $y1->id, [(int) $y2->id]);
        $before = $group->fresh()->toArray();

        $this->actingAs($staff->fresh(), 'staff')
            ->deleteJson(route('pos.tables.unmerge', ['group' => $group->id, 'store' => $r->id, 'branch' => $y->id]))
            ->assertStatus(404);
        $this->assertEquals($before, $group->fresh()->toArray());

        $y3 = $this->table($r, 3, $y->id);
        $y4 = $this->table($r, 4, $y->id);
        $this->actingAs($staff->fresh(), 'staff')
            ->postJson(route('pos.tables.merge', ['store' => $r->id, 'branch' => $y->id]), ['primary_table_id' => $y3->id, 'table_ids' => [$y4->id]])
            ->assertStatus(404);

        // Own branch still works.
        $x1 = $this->table($r, 5, $x->id);
        $x2 = $this->table($r, 6, $x->id);
        $res = $this->actingAs($staff->fresh(), 'staff')
            ->postJson(route('pos.tables.merge', ['store' => $r->id]), ['primary_table_id' => $x1->id, 'table_ids' => [$x2->id]])
            ->assertStatus(201);
        $this->actingAs($staff->fresh(), 'staff')
            ->deleteJson(route('pos.tables.unmerge', ['group' => $res->json('group.id'), 'store' => $r->id]))
            ->assertOk();
    }
}
