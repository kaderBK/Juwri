<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\LegacyCompat\Import\HistoryImporter;
use Juwri\Modules\LegacyCompat\Import\RowLoader;
use Juwri\Modules\LegacyCompat\Import\ScopeMaps;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Models\WalletTransaction;

/**
 * Imported history is read-only (plan §4: orders, lines, payments, billing and ledgers are inserted once; no status
 * machine, no re-pricing, no re-totalling, no refunds through this install). A row is imported history when the
 * importer recorded it in its side table (`legacy_import_rows`, J52: no core column). Wired only while the module is
 * on ({@see \Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider::activate()}; the switch is re-checked per
 * write).
 *
 * Through the application models, an imported history row cannot be deleted, and an update may only touch the columns
 * listed below: display flags and the personal data a privacy erasure blanks (the customer link only to NULL). Any
 * other change (status, paid flag, amounts, quantities, currency, items) throws {@see LegacyHistoryReadOnly} before
 * the write. The one exception is a payment that is still live money (the deposit of a migrated reservation or catering
 * request, marked by the importer, J59g): its completion and refund bookkeeping may change (never its amounts, currency
 * or owner), so it is completed or refunded here like any other. Rows created on this install are never looked up beyond one indexed side-table read on update/delete.
 *
 * Writes that bypass model events (query-builder updates such as the privacy erasure, `saveQuietly()`) are not seen
 * here; the importer itself writes that way. The one core path that re-totals quietly, `Order::snapshotTotals()`
 * (`orders:snapshot-totals --force`), asks {@see Order::FROZEN_MONEY_HOOK}, which this class fills: an imported order
 * answers its STORED totals, so it is never re-snapshotted and every screen shows what was imported (not a live
 * re-total with today's tax, delivery and menu).
 */
final class LegacyHistoryGuard
{
    /** Model => columns an update of an imported row may change. */
    public const MUTABLE = [
        Order::class => [
            'seen', 'printed_at', 'updated_at', 'customer_id', 'customer_name', 'phone_number', 'address', 'delivery_lat',
            'delivery_lng', 'message', 'track_token', 'pod_otp', 'pod_photo', 'driver_note', 'vehicle_details',
        ],
        OrderItem::class => ['order_note'],
        OrderItemExtra::class => [],
        LoyaltyLedger::class => ['note', 'updated_at'],
        WalletTransaction::class => ['updated_at'],
        Transaction::class => ['billing', 'transaction_ip', 'updated_at'],
        BillingReceipt::class => ['details', 'updated_at'],
    ];

    /**
     * Tables of optional (Enterprise) models, guarded by table name so this module names no Enterprise class: table =>
     * columns an update of an imported row may change. The shopper wallet ledger (J58d) is append-only history.
     */
    public const MUTABLE_TABLES = [
        'shopper_wallet_ledger_entries' => [],
    ];

    /**
     * Extra columns a LIVE imported payment (a migrated deposit, J59g) may change: its completion and refund bookkeeping
     * (status, refund details, gateway and its reference, credit stamp). Amounts, currency and owner columns stay locked.
     */
    public const LIVE_PAYMENT_MUTABLE = ['status', 'details', 'payment_id', 'transaction_gatway', 'credited_at'];

    /** @var \WeakMap<Order, array{subtotal: float, tax: float, grand: float}|false>|null */
    private static ?\WeakMap $frozen = null;

    /** App stamp of the memo above (static-memo guard idiom): a new application starts with an empty memo. */
    private static ?int $memoApp = null;

    private static function scopeMemo(): void
    {
        $id = spl_object_id(app());
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$frozen = null;
        }
    }

    public static function register(): void
    {
        Hooks::on(Order::FROZEN_MONEY_HOOK, 'legacy-compat-history', static fn (Order $order): ?array => self::frozenMoney($order));

        foreach (self::MUTABLE as $class => $mutable) {
            $class::updating(static function (Model $model) use ($mutable): void {
                if (LegacyCompatRoutes::enabled()) {
                    self::guardUpdate($model, $mutable);
                }
            });
            $class::deleting(static function (Model $model): void {
                if (LegacyCompatRoutes::enabled() && self::imported($model)) {
                    throw LegacyHistoryReadOnly::for($model->getTable(), self::key($model), 'delete');
                }
            });
        }
        foreach (['updating', 'deleting'] as $action) {
            Event::listen('eloquent.'.$action.': *', static function (string $event, array $payload) use ($action): void {
                $model = $payload[0] ?? null;
                if (! $model instanceof Model || ! array_key_exists($model->getTable(), self::MUTABLE_TABLES) || ! LegacyCompatRoutes::enabled()) {
                    return;
                }
                if ($action === 'updating') {
                    self::guardUpdate($model, self::MUTABLE_TABLES[$model->getTable()]);
                } elseif (self::imported($model)) {
                    throw LegacyHistoryReadOnly::for($model->getTable(), self::key($model), 'delete');
                }
            });
        }
    }

    /**
     * The stored totals of an imported order (major units), null for any other order or with the module off.
     *
     * @return array{subtotal: float, tax: float, grand: float}|null
     */
    public static function frozenMoney(Order $order): ?array
    {
        if (! LegacyCompatRoutes::enabled() || ! $order->exists) {
            return null;
        }
        self::scopeMemo();
        self::$frozen ??= new \WeakMap;
        if (! isset(self::$frozen[$order])) {
            self::$frozen[$order] = self::imported($order) ? [
                'subtotal' => (float) ($order->subtotal ?? 0),
                'tax' => (float) ($order->tax_total ?? 0),
                'grand' => (float) ($order->grand_total ?? 0),
            ] : false;
        }
        $money = self::$frozen[$order];

        return $money === false ? null : $money;
    }

    /** Whether $model's row was written by the importer. */
    public static function imported(Model $model): bool
    {
        $id = self::key($model);
        if ($id === '') {
            return false;
        }

        return $model->getConnection()->table(RowLoader::SIDE_TABLE)
            ->where('target_table', $model->getTable())->where('target_id', $id)->exists();
    }

    /** @param list<string> $mutable */
    private static function guardUpdate(Model $model, array $mutable): void
    {
        $dirty = array_keys($model->getDirty());
        $locked = array_values(array_diff($dirty, $mutable));
        if (in_array('customer_id', $dirty, true) && $model->getAttribute('customer_id') !== null) {
            $locked[] = 'customer_id';
        }
        if ($locked === [] || ! self::imported($model)) {
            return;
        }
        if (self::livePayment($model)) {
            // A live deposit payment: only its completion / refund bookkeeping moves; money and owner stay locked.
            $locked = array_values(array_diff($locked, self::LIVE_PAYMENT_MUTABLE));
            if ($locked === []) {
                return;
            }
        }
        sort($locked);

        throw LegacyHistoryReadOnly::for($model->getTable(), self::key($model), 'update', $locked);
    }

    /**
     * An imported payment that is still live money (a migrated reservation / catering deposit, J59g): its completion and
     * refund bookkeeping goes on here like any payment of this install's. Never deletable (see register()).
     */
    private static function livePayment(Model $model): bool
    {
        return $model->getTable() === 'transaction' && $model->getConnection()->table(HistoryImporter::MARKS_TABLE)
            ->where('target_table', 'transaction')->where('target_id', self::key($model))->where('mark', ScopeMaps::LIVE_PAYMENT)->exists();
    }

    private static function key(Model $model): string
    {
        $key = $model->getOriginal($model->getKeyName()) ?? $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
