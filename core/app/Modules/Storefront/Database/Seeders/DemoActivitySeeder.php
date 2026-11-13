<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\CustomerWalletService;
use App\Services\Loyalty\LoyaltyAccrual;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;

/**
 * J62-1 (job 4): the demo's ACTIVITY layer — the customers, the orders in every state,
 * the loyalty ledger and the wallet balance the owner clicks through in the dashboard,
 * the kitchen display and the reports.
 *
 * Runs LAST in `php artisan juwri:demo-seed`: it needs the stores and their catalogues
 * to exist and it never creates them. A store that is missing (or has no menu yet) is
 * SKIPPED with a warning — never a crash — so a partial demo still seeds.
 *
 * Idempotent: every row keys on its own deterministic natural key — an order on its
 * track token (`demo-owner-<store-slug>-<n>`), a customer on store + phone, a loyalty
 * row on its order, the wallet top-up on its ledger ref — so a second run never
 * duplicates a row and never moves a balance twice.
 *
 * Money is written in minor units (`*_minor`) of the order's own currency, exactly as
 * the app stores it (P1-05). Synthetic data only (J19).
 */
class DemoActivitySeeder extends Seeder
{
    /** The order status values of the Ordering enum, as plain strings (Storefront may only import Ordering models). */
    private const STATUS_NEW = 'new';

    private const STATUS_PREPARING = 'preparing';

    private const STATUS_READY = 'ready';

    private const STATUS_DELIVERED = 'delivered';

    /** The wallet top-up the demo customer carries, in minor units of the store currency (SAR → 10000 = 100.00). */
    private const WALLET_TOPUP_MINOR = 10000;

    /** The ledger ref of that top-up — the ledger's own idempotency key. */
    private const WALLET_TOPUP_REF = 'demo-activity:wallet';

    /** The reason the top-up is booked under (shown on the wallet statement). */
    private const WALLET_TOPUP_REASON = 'Top-up 100.00 SAR';

    /** When the demo customers verified their phone — fixed, so a rerun never moves it. */
    private const CUSTOMER_VERIFIED_AT = '2026-10-20 09:00:00';

    /** The five synthetic customers: the store they belong to, their phone, name (English / Arabic) and e-mail. */
    private const CUSTOMERS = [
        ['store' => 'restaurant', 'phone' => '966500990001', 'name' => 'Sara Alahmad / سارة الأحمد', 'email' => 'demo-customer-1@demo.test'],
        ['store' => 'restaurant', 'phone' => '966500990002', 'name' => 'Mohammed Alqahtani / محمد القحطاني', 'email' => 'demo-customer-2@demo.test'],
        ['store' => 'restaurant', 'phone' => '966500990003', 'name' => 'Nora Alotaibi / نورة العتيبي', 'email' => 'demo-customer-3@demo.test'],
        ['store' => 'branches', 'phone' => '966500990004', 'name' => 'Fahad Aldosari / فهد الدوسري', 'email' => 'demo-customer-4@demo.test'],
        ['store' => 'branches', 'phone' => '966500990005', 'name' => 'Laila Alzahrani / ليلى الزهراني', 'email' => 'demo-customer-5@demo.test'],
    ];

    /**
     * The orders every demo store gets: one per state, plus the extra delivered ones so
     * that all three of the restaurant store's loyalty customers have a paid order to
     * earn their points on.
     *
     * `state` drives the status and the timestamps, `type` the fulfilment mode, `payment`
     * the tender, `paid` whether the money was collected, `table` whether the order sits
     * on a store table, `customer` the index into the store's customer pool and `hours`
     * how long ago the order was placed.
     */
    private const ORDER_SPECS = [
        ['suffix' => '1', 'state' => 'new', 'type' => 'on-table', 'payment' => 'cash', 'paid' => false, 'table' => true, 'customer' => 0, 'hours' => 0.5],
        ['suffix' => '2', 'state' => 'preparing', 'type' => 'takeaway', 'payment' => 'card', 'paid' => false, 'table' => false, 'customer' => 1, 'hours' => 2],
        ['suffix' => '3', 'state' => 'ready', 'type' => 'delivery', 'payment' => 'card', 'paid' => false, 'table' => false, 'customer' => 2, 'hours' => 5],
        ['suffix' => '4', 'state' => 'delivered', 'type' => 'on-table', 'payment' => 'cash', 'paid' => true, 'table' => true, 'customer' => 0, 'hours' => 26],
        ['suffix' => '5', 'state' => 'delivered', 'type' => 'takeaway', 'payment' => 'card', 'paid' => true, 'table' => false, 'customer' => 1, 'hours' => 32],
        ['suffix' => '6', 'state' => 'delivered', 'type' => 'takeaway', 'payment' => 'cash', 'paid' => true, 'table' => false, 'customer' => 2, 'hours' => 38],
        ['suffix' => '7', 'state' => 'refunded', 'type' => 'delivery', 'payment' => 'card', 'paid' => true, 'table' => false, 'customer' => 0, 'hours' => 50],
    ];

    /** @var array<string, Store|null> store slug => the store (cached; null when it does not exist) */
    private array $stores = [];

    /** @var array<string, array<int, Customer>> store slug => the demo customers of that store */
    private array $customers = [];

    public function run(): void
    {
        $this->seedCustomers();
        $this->seedStoreOrders();
        $this->seedCouponRedemption();
        $this->seedLoyalty();
        $this->seedWalletTopUp();
        $this->seedHotelOrder();
        $this->refreshCustomerTotals();

        $this->command?->info('DemoActivitySeeder: demo activity ready.');
    }

    /** The store of that slug, or null when the demo did not create it. */
    private function store(string $slug): ?Store
    {
        if (! array_key_exists($slug, $this->stores)) {
            $this->stores[$slug] = Store::where('slug', $slug)->first();
        }

        return $this->stores[$slug];
    }

    /** A warning on the console — a missing store is skipped, never fatal. */
    private function warn(string $message): void
    {
        $this->command?->warn($message);
    }

    /** The store's own currency, upper-cased (the demo stores are SAR). */
    private function currency(Store $store): string
    {
        $code = store_options($store->id, 'currency_code');

        return strtoupper((string) ($code ?: 'SAR'));
    }

    /**
     * The five synthetic customers. Their natural key is store + phone, so a rerun
     * updates the same five rows instead of creating new ones.
     */
    private function seedCustomers(): void
    {
        foreach (self::CUSTOMERS as $spec) {
            $slug = DemoOwnerPlan::STORES[$spec['store']] ?? null;
            $store = $slug ? $this->store($slug) : null;
            if (! $store) {
                $this->warn(sprintf('Customer %s skipped: store "%s" does not exist.', $spec['phone'], (string) $slug));

                continue;
            }

            $customer = Customer::updateOrCreate(
                ['store_id' => $store->id, 'phone' => $spec['phone']],
                [
                    'name' => $spec['name'],
                    'email' => $spec['email'],
                    'phone_verified_at' => Carbon::parse(self::CUSTOMER_VERIFIED_AT),
                    'source' => 'seeder',
                ]
            );

            $this->customers[$slug][] = $customer;
        }
    }

    /**
     * The customers an order may belong to: the demo customers of that store, or — for a
     * store the demo customers do not sit on — the customers the showcase seeders made.
     */
    private function customerPool(Store $store, string $slug): array
    {
        if (isset($this->customers[$slug])) {
            return $this->customers[$slug];
        }

        return Customer::where('store_id', $store->id)->orderBy('id')->limit(3)->get()->all();
    }

    /**
     * The demo orders of the four ordering stores: the restaurant, the branches, the
     * cafe and the retail one. A store without a catalogue is skipped.
     */
    private function seedStoreOrders(): void
    {
        $slugs = [
            DemoOwnerPlan::STORES['restaurant'],
            DemoOwnerPlan::STORES['branches'],
            DemoOwnerPlan::STORES['cafe'],
            DemoOwnerPlan::STORES['retail'],
        ];

        foreach ($slugs as $slug) {
            $store = $this->store($slug);
            if (! $store) {
                $this->warn(sprintf('Orders skipped: store "%s" does not exist.', $slug));

                continue;
            }

            $items = $this->menuItems($store);
            if ($items === []) {
                $this->warn(sprintf('Orders skipped: store "%s" has no priced menu item.', $slug));

                continue;
            }

            $this->ensureVat($store);
            $pool = $this->customerPool($store, $slug);
            $table = $this->storeTable($store);
            $branches = $this->branchIds($store);

            foreach (self::ORDER_SPECS as $spec) {
                $this->seedOrder($store, $spec, $items, $pool, $table, $branches);
            }
        }
    }

    /** The store's priced menu items — the demo orders are priced off the real catalogue. */
    private function menuItems(Store $store): array
    {
        return Menu::where('store_id', $store->id)
            ->where('active', '1')
            ->orderBy('id')
            ->limit(4)
            ->get()
            ->filter(fn (Menu $menu): bool => (int) $menu->price_minor > 0)
            ->values()
            ->all();
    }

    /**
     * The demo stores carry the standard Saudi VAT, so a seeded order shows a real tax
     * line and the reports have one. A store that already has a rate keeps it.
     */
    private function ensureVat(Store $store): void
    {
        if ((string) store_options($store->id, 'restaurant_taxx_charge') === '') {
            StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '15');
        }
    }

    /** A dine-in table of the store — the demo's on-table orders need one. */
    private function storeTable(Store $store): ?StoreTable
    {
        return StoreTable::where('store_id', $store->id)
            ->where('area_type', 'table')
            ->orderBy('table_no')
            ->first();
    }

    /** The store's branches — the multi-branch demo store spreads its orders over them. */
    private function branchIds(Store $store): array
    {
        return Branch::where('store_id', $store->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * One demo order: the header, its lines (the first one carries a modifier) and the
     * refund rows of the refunded state. The track token is the natural key.
     */
    private function seedOrder(Store $store, array $spec, array $items, array $pool, ?StoreTable $table, array $branches): Order
    {
        $currency = $this->currency($store);
        $token = 'demo-owner-'.$store->slug.'-'.$spec['suffix'];
        $createdAt = Carbon::now()->subHours((float) $spec['hours']);
        $customer = $pool === [] ? null : $pool[$spec['customer'] % count($pool)];
        $state = $spec['state'];
        $branchId = $branches === [] ? null : $branches[(int) $spec['suffix'] % count($branches)];

        $order = Order::firstOrNew(['store_id' => $store->id, 'track_token' => $token]);
        $order->fill([
            'branch_id' => $branchId,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'phone_number' => $customer?->phone,
            'type' => $spec['type'],
            'status' => $this->statusFor($state),
            'is_paid' => $spec['paid'],
            'payment_method' => $spec['payment'],
            'currency_code' => $currency,
            'source' => 'seeder',
            'client_uuid' => $token,
            'table_number' => $spec['table'] && $table ? (int) $table->table_no : null,
            'service_location' => $spec['table'] && $table ? ($table->label ?: 'Table '.$table->table_no) : null,
            'covers' => $spec['type'] === 'on-table' ? 4 : null,
            'address' => $spec['type'] === 'delivery' ? 'Al Olaya District, Riyadh 12211, Saudi Arabia' : null,
            'message' => $spec['type'] === 'delivery' ? 'Leave the order at the reception.' : null,
            'preparing_at' => $this->stateTime($state, ['preparing', 'ready', 'delivered', 'refunded'], $createdAt, 10),
            'ready_at' => $this->stateTime($state, ['ready', 'delivered', 'refunded'], $createdAt, 25),
            'delivered_at' => $this->stateTime($state, ['delivered', 'refunded'], $createdAt, 45),
            'completed_at' => $this->stateTime($state, ['delivered', 'refunded'], $createdAt, 50),
        ]);

        if (! $order->exists) {
            // The spread over the last three days is set once, on creation: a rerun must
            // not walk the demo's history forward.
            $order->created_at = $createdAt;
        }

        $order->save();

        $this->seedOrderLines($order, $store, $spec, $items, $currency);
        $order->snapshotTotals();

        if ($spec['paid']) {
            // The tender that settled the order (the Z-report reads it as the cash in).
            $order->forceFill(['amount_tendered_minor' => (int) $order->grand_total_minor])->save();
        }

        if ($state === 'refunded') {
            $this->seedRefund($order, $store, $currency);
        }

        return $order;
    }

    /** The order's lines: two menu items, the first one with a modifier on it. */
    private function seedOrderLines(Order $order, Store $store, array $spec, array $items, string $currency): void
    {
        foreach (array_slice($items, 0, 2) as $index => $menu) {
            $quantity = $index === 0 ? 2 : 1;
            $line = OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'item_id' => $menu->id],
                [
                    'store_id' => $store->id,
                    'quantity' => $quantity,
                    'unit_price_minor' => (int) $menu->price_minor,
                    'currency_code' => $currency,
                    'status' => $this->itemStatus($spec['state']),
                    'course' => $index === 0 ? 1 : 2,
                    'order_note' => $index === 0 ? 'Demo order note — extra spicy.' : null,
                    'started_at' => $order->preparing_at,
                    'item_ready_at' => $order->ready_at,
                    'served_at' => $order->delivered_at,
                ]
            );

            if ($index === 0) {
                $this->seedLineModifier($line, $store, $menu, $currency);
            }
        }
    }

    /** One modifier on the first line — the demo shows the modifier flow end to end. */
    private function seedLineModifier(OrderItem $line, Store $store, Menu $menu, string $currency): void
    {
        // The item's own modifier when the catalogue already has one, a demo one otherwise.
        $extra = MenuExtra::where('store_id', $store->id)->where('menu_id', $menu->id)->first()
            ?: MenuExtra::firstOrCreate(
                ['store_id' => $store->id, 'menu_id' => $menu->id, 'title' => 'Extra Portion'],
                ['price' => 3, 'is_global' => false, 'active' => 1, 'position' => 90]
            );

        OrderItemExtra::updateOrCreate(
            ['order_item_id' => $line->id, 'extra_id' => $extra->id],
            [
                'store_id' => $store->id,
                'price_minor' => (int) $extra->price_minor,
                'currency_code' => $currency,
                'quantity' => 1,
            ]
        );
    }

    /**
     * The refund of the refunded order: the flags the dashboard reads, the adjustment
     * that books the money going back and one refund line per order line.
     *
     * This is what the dashboard's own refund writes: the order stays paid (the money
     * was collected and then returned) and the refund is its own money-out row.
     */
    private function seedRefund(Order $order, Store $store, string $currency): void
    {
        $order->forceFill([
            'refund_request_status' => 'approved',
            'refund_method' => 'original_payment',
            'refund_requested_at' => $order->created_at?->copy()->addHours(2),
        ])->save();

        OrderAdjustment::updateOrCreate(
            ['order_id' => $order->id, 'idempotency_key' => 'demo-refund-'.$order->track_token],
            [
                'store_id' => $store->id,
                'type' => 'refund',
                'amount_minor' => (int) $order->grand_total_minor,
                'currency_code' => $currency,
                'method' => 'original_payment',
                'reason' => 'Refund to the original payment',
            ]
        );

        foreach ($order->items()->get() as $line) {
            $extras = (int) $line->itemExtras->sum(fn (OrderItemExtra $extra): int => (int) $extra->price_minor * (int) $extra->quantity);

            OrderRefundItem::updateOrCreate(
                ['order_id' => $order->id, 'order_item_id' => $line->id],
                [
                    'store_id' => $store->id,
                    'qty' => (int) $line->quantity,
                    'amount_minor' => (int) $line->unit_price_minor * (int) $line->quantity + $extras,
                    'currency_code' => $currency,
                    'reason' => 'Refunded to the original payment',
                ]
            );
        }
    }

    /** The order status of a demo state — the real order status vocabulary (Storefront may not import the Ordering enum). */
    private function statusFor(string $state): string
    {
        return match ($state) {
            'new' => self::STATUS_NEW,
            'preparing' => self::STATUS_PREPARING,
            'ready' => self::STATUS_READY,
            // A refunded order keeps the status it had when the money came back — the
            // refund itself is the adjustment plus the flag the dashboard reads.
            'delivered', 'refunded' => self::STATUS_DELIVERED,
            default => self::STATUS_NEW,
        };
    }

    /** The line status of a demo state — what the kitchen display shows. */
    private function itemStatus(string $state): string
    {
        return match ($state) {
            'new' => 'queued',
            'preparing' => 'preparing',
            'ready' => 'ready',
            default => 'served',
        };
    }

    /** The timestamp of a state, or null when the order never reached it. */
    private function stateTime(string $state, array $states, Carbon $createdAt, int $minutesLater): ?Carbon
    {
        return in_array($state, $states, true) ? $createdAt->copy()->addMinutes($minutesLater) : null;
    }

    /**
     * One redemption of the demo's percentage coupon on a delivered order of the
     * restaurant store: the discount on the order and the redemption row behind it.
     */
    private function seedCouponRedemption(): void
    {
        $slug = DemoOwnerPlan::STORES['restaurant'];
        $store = $this->store($slug);
        if (! $store) {
            $this->warn(sprintf('Coupon skipped: store "%s" does not exist.', $slug));

            return;
        }

        $coupon = Coupon::firstOrCreate(
            ['code' => DemoOwnerPlan::COUPON_PERCENT],
            [
                'user_id' => $store->user_id,
                'percentage' => 10,
                'coupon_type' => 'percentage',
                'limit' => 100,
                'used' => 0,
                'applies_to' => 'all',
                'expiry_at' => Carbon::now()->addYear(),
            ]
        );

        // The coupon rides on a delivered, paid order of one of the demo customers.
        $ids = array_map(fn (Customer $customer): int => (int) $customer->id, $this->customers[$slug] ?? []);
        $order = Order::where('store_id', $store->id)
            ->whereIn('customer_id', $ids)
            ->where('status', self::STATUS_DELIVERED)
            ->where('is_paid', true)
            ->whereNull('refund_request_status')
            ->orderBy('id')
            ->first();
        $customer = $order?->customer;
        if (! $order || ! $customer) {
            $this->warn(sprintf('Coupon skipped: the demo order of store "%s" is missing.', $slug));

            return;
        }

        $order->forceFill([
            'coupon_id' => $coupon->id,
            'discount_amount_minor' => (int) round((int) $order->subtotal_minor * 10 / 100),
        ])->save();
        $order->snapshotTotals();

        $redemption = CouponRedemption::firstOrCreate(
            ['coupon_id' => $coupon->id, 'customer_id' => $customer->id, 'seq' => 1],
            ['order_id' => $order->id, 'created_at' => Carbon::now()]
        );
        if ((int) $redemption->order_id !== (int) $order->id) {
            $redemption->forceFill(['order_id' => $order->id])->save();
        }

        $coupon->forceFill([
            'used' => CouponRedemption::where('coupon_id', $coupon->id)->count(),
        ])->save();
    }

    /**
     * The loyalty of the restaurant store's demo customers: the points their delivered
     * orders earned (through the app's own accrual seam) and one redemption.
     */
    private function seedLoyalty(): void
    {
        $slug = DemoOwnerPlan::STORES['restaurant'];
        $store = $this->store($slug);
        $customers = $this->customers[$slug] ?? [];
        if (! $store || $customers === []) {
            $this->warn(sprintf('Loyalty skipped: the demo customers of store "%s" are missing.', $slug));

            return;
        }

        $program = LoyaltyProgram::forRestaurant($store->id);
        // The demo program earns one point per SAR, so the seeded orders really do
        // accrue points and one of them can be redeemed. (The demo seeders leave the
        // store at one point per hundred SAR, which no demo order ever reaches.)
        $program->update([
            'type' => 'points',
            'earn_rate' => 100,
            'reward_threshold' => 100,
            'active' => true,
        ]);

        $ids = array_map(fn (Customer $customer): int => (int) $customer->id, $customers);
        $delivered = Order::where('store_id', $store->id)
            ->whereIn('customer_id', $ids)
            ->where('status', self::STATUS_DELIVERED)
            ->where('is_paid', true)
            ->whereNull('refund_request_status')
            ->orderBy('id')
            ->get();

        foreach ($delivered as $order) {
            LoyaltyAccrual::earnForOrder($order);
        }

        // One redemption: the second demo customer spends points on their delivered order.
        $redeemer = $customers[1] ?? null;
        $order = $delivered->firstWhere('customer_id', $redeemer?->id);
        if (! $redeemer || ! $order) {
            return;
        }

        $account = LoyaltyAccount::forCustomer((int) $redeemer->id, (int) $store->id);
        $delta = min(20, (int) $account->balance);
        if ($delta > 0) {
            $account->redeem($delta, 'Redeemed on demo order #'.$order->id, (int) $order->id);
        }
    }

    /** The store credit the first demo customer carries — a 100.00 SAR top-up. */
    private function seedWalletTopUp(): void
    {
        $slug = DemoOwnerPlan::STORES['restaurant'];
        $customer = ($this->customers[$slug] ?? [])[0] ?? null;
        if (! $customer) {
            $this->warn('Wallet top-up skipped: the demo customer does not exist.');

            return;
        }

        $currency = CustomerWalletService::walletCurrency($customer);
        $amount = Money::majorFromMinor(self::WALLET_TOPUP_MINOR, $currency);
        $ref = self::WALLET_TOPUP_REF.':'.$customer->id;

        try {
            CustomerWalletService::credit($customer, $amount, self::WALLET_TOPUP_REASON, $ref);
        } catch (\Throwable $e) {
            // The store may not be entitled to ISSUE store credit (the wallet is plan
            // gated): book the very same ledger legs directly, so the demo balance still
            // shows on the dashboard and at checkout.
            $this->creditWalletDirectly($customer, $amount, $ref);
        }
    }

    /** The ledger legs {@see CustomerWalletService::credit()} posts, without the entitlement gate. */
    private function creditWalletDirectly(Customer $customer, float $amount, string $ref): void
    {
        $account = CustomerWalletService::accountFor($customer);
        $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $account->currency);

        app(Ledger::class)->post([
            ['account' => $clearing, 'amount' => -$amount, 'allow_negative' => true, 'ref' => 'cw:'.$ref.':clr', 'reason' => self::WALLET_TOPUP_REASON],
            ['account' => $account, 'amount' => $amount, 'ref' => 'cw:'.$ref.':cust', 'reason' => self::WALLET_TOPUP_REASON, 'meta' => ['customer_id' => $customer->id, 'store_id' => $customer->store_id]],
        ], null, self::WALLET_TOPUP_REASON);
    }

    /** One room-service order on the hotel store, charged to the folio of room 101. */
    private function seedHotelOrder(): void
    {
        $slug = DemoOwnerPlan::STORES['hotel'];
        $store = $this->store($slug);
        if (! $store) {
            $this->warn(sprintf('Hotel order skipped: store "%s" does not exist.', $slug));

            return;
        }

        $room = StoreTable::where('store_id', $store->id)
            ->where('area_type', 'room')
            ->where('table_no', 101)
            ->first();
        $stay = $room
            ? HotelStay::where('store_id', $store->id)->where('table_id', $room->id)->where('status', 'checked_in')->first()
            : null;
        if (! $room || ! $stay) {
            $this->warn(sprintf('Hotel order skipped: room 101 of store "%s" has no checked-in stay.', $slug));

            return;
        }

        $items = $this->menuItems($store);
        if ($items === []) {
            $this->warn(sprintf('Hotel order skipped: store "%s" has no priced menu item.', $slug));

            return;
        }

        $currency = $this->currency($store);
        $token = 'demo-owner-'.$slug.'-room';
        $createdAt = Carbon::now()->subHours(4);
        $menu = $items[0];

        $this->ensureVat($store);

        $order = Order::firstOrNew(['store_id' => $store->id, 'track_token' => $token]);
        $order->fill([
            'type' => 'room_service',
            'status' => self::STATUS_DELIVERED,
            'is_paid' => false,
            'payment_method' => 'charge_to_room',
            'currency_code' => $currency,
            'source' => 'seeder',
            'client_uuid' => $token,
            'table_number' => (int) $room->table_no,
            'service_location' => 'Room '.$room->table_no,
            'customer_name' => $stay->guest_name,
            'phone_number' => $stay->guest_phone,
            'delivered_at' => $createdAt->copy()->addMinutes(30),
            'completed_at' => $createdAt->copy()->addMinutes(35),
        ]);
        if (! $order->exists) {
            $order->created_at = $createdAt;
        }
        $order->save();

        OrderItem::updateOrCreate(
            ['order_id' => $order->id, 'item_id' => $menu->id],
            [
                'store_id' => $store->id,
                'quantity' => 1,
                'unit_price_minor' => (int) $menu->price_minor,
                'currency_code' => $currency,
                'status' => 'served',
                'served_at' => $createdAt->copy()->addMinutes(25),
            ]
        );
        $order->snapshotTotals();

        // The folio charge — the path the storefront's charge-to-room takes: the ledger
        // entry carries the stay and the room, and the order is tendered to the folio.
        $result = app(RoomCharge::class)->chargeOrderForTender($order, $stay);
        if (empty($result['charged_to_room'])) {
            $this->warn('Hotel order: the folio charge did not land — the stay may be closed.');
        }
    }

    /** The CRM aggregates of the demo customers — their order count and what they spent. */
    private function refreshCustomerTotals(): void
    {
        foreach ($this->customers as $customers) {
            foreach ($customers as $customer) {
                $orders = Order::where('customer_id', $customer->id)->orderBy('created_at')->get();

                $customer->forceFill([
                    'orders_count' => $orders->count(),
                    'total_spent_minor' => (int) $orders->where('is_paid', true)->sum(fn (Order $order): int => (int) $order->grand_total_minor),
                    'last_order_at' => $orders->max('created_at'),
                ])->save();
            }
        }
    }
}
