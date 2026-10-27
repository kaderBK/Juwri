<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services\Privacy;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Services\Account\AccountDeletionService;
use App\Support\StoreClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Modules\Customers\Mail\ShopperAccountErased;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\LedgerAccount;

/**
 * P6-91: a storefront shopper's own "download my data" and "delete my account" (GDPR art. 15/17/20, PDPL art. 4/18).
 *
 * Scope: ONE Customer row. A Customer belongs to exactly one store (customers.store_id), so a shopper who buys from
 * several stores on the platform has one separate account per store; exporting or deleting in one store never reads
 * or touches another store's account (each store is its own controller of that data).
 *
 * Every column of the schema that points at a customer must be classified in {@see COLUMNS} — pinned by
 * ShopperPrivacyCompletenessTest, so a new customer-linked table cannot ship without an export / erase decision:
 *  - delete:    the rows are the shopper's own data (addresses, reviews, follows, loyalty, …) — exported, then deleted;
 *  - anonymise: the rows are the store's sales / tax / liability records (orders: 6-year tax retention, the same rule
 *               as {@see AccountDeletionService::RETAINED_TABLES}; return requests, gift cards, hotel bookings) —
 *               exported, then KEPT with the shopper's personal data stripped and the link to the customer cut;
 *  - ignore:    the column does not name a storefront shopper (a reseller's billing customer, a gateway id).
 * Fiscal documents (ZATCA / e-invoices) are kept untouched: they are signed and hash-chained, the law requires the
 * issued document as issued, and they only ever carry the order's buyer fields (stripped on the order).
 * Store credit ledger accounts are kept (money records); the remaining balance and loyalty points are forfeited, as
 * the confirmation screen says.
 *
 * P6-209 guest orders: a checkout without an account leaves an order with customer_id NULL and the buyer's phone on
 * `orders.phone_number` (orders carry no e-mail column). Such orders are the same person's data when the phone is
 * PROVEN: (a) an account whose phone is verified (`phone_verified_at`) also exports and anonymises the store's guest
 * orders with exactly that phone; (b) the owner's "privacy request by phone" ({@see GuestPrivacyController}) exports
 * and anonymises them after a one-time code sent to that phone was entered. Matching is exact after E.164-style
 * normalisation — never a fuzzy / suffix match, so another person's orders are never released or stripped. Erasure
 * applies the same ORDER_PII rule as the account path: amounts, lines, tax and fiscal documents are kept (6-year
 * retention), only the buyer's personal fields are stripped.
 */
final class ShopperPrivacyService
{
    public const EXPORTS_PER_DAY = 3;

    /** Order statuses after which an order may be anonymised (as {@see blocker()}). */
    private const CLOSED_ORDER = ['completed', 'cancelled', 'delivered'];

    /** @var array<string, list<int>> "sid|phone" => guest order ids (per service instance) */
    private array $guestIdMemo = [];

    /** @var array<string, array<string, 'delete'|'anonymise'|'ignore'>> table => customer column => decision */
    public const COLUMNS = [
        'customer_addresses' => ['customer_id' => 'delete'],
        'customer_feedback' => ['customer_id' => 'delete'],
        'customer_notifications' => ['customer_id' => 'delete'],
        'customer_passkeys' => ['customer_id' => 'delete'],
        'customer_tokens' => ['customer_id' => 'delete'],
        'campaign_recipients' => ['customer_id' => 'delete'],
        'coupon_redemptions' => ['customer_id' => 'delete'],
        'loyalty_accounts' => ['customer_id' => 'delete'],
        'meal_plans' => ['customer_id' => 'delete'],
        'menu_reviews' => ['customer_id' => 'delete'],
        'shopper_customers' => ['customer_id' => 'delete'],
        'skin_profiles' => ['customer_id' => 'delete'],
        'stock_alerts' => ['customer_id' => 'delete'],
        'store_follows' => ['customer_id' => 'delete'],
        'store_reviews' => ['customer_id' => 'delete'],
        'wishlist_items' => ['customer_id' => 'delete'],
        'orders' => ['customer_id' => 'anonymise'],
        'return_requests' => ['customer_id' => 'anonymise'],
        'hotel_bookings' => ['customer_id' => 'anonymise'],
        'gift_cards' => ['purchaser_customer_id' => 'anonymise'],
        'reseller_outgoing_invoices' => ['customer_id' => 'ignore'], // a reseller's billing customer, not a shopper
        'reseller_customer_subscriptions' => ['gateway_customer_id' => 'ignore'], // a payment gateway's id
    ];

    /** Columns written on the kept rows: the shopper's personal data stripped, the customer link cut. */
    private const ANONYMISE = [
        'return_requests' => ['customer_id' => null, 'reason' => null],
        'hotel_bookings' => ['customer_id' => null, 'guest_name' => '', 'guest_phone' => null, 'notes' => null, 'token' => null],
        'gift_cards' => ['purchaser_customer_id' => null, 'purchaser_email' => null, 'message' => null],
    ];

    /** Secrets and internal keys never written to the export. */
    private const NOT_EXPORTED = [
        'customers' => ['password', 'pending_password', 'oauth_uid', 'remember_token'],
        'customer_passkeys' => ['credential_id', 'public_key', 'credential', 'sign_count'],
        'customer_feedback' => ['token'],
        'orders' => ['track_token', 'pod_otp'],
        'hotel_bookings' => ['token'],
        'skin_profiles' => ['session_token'],
        'store_reviews' => ['session_hash'],
        'menu_reviews' => ['session_hash'],
        'stock_alerts' => ['contact_hash'],
        'wishlist_items' => ['guest_key'],
    ];

    /** Rows of other tables reached through the customer's rows: table => [column, parent table]. */
    private const CHILDREN = [
        'order_items' => ['order_id', 'orders'],
        'nps_responses' => ['order_id', 'orders'],
        'loyalty_ledger_entries' => ['loyalty_account_id', 'loyalty_accounts'],
        'store_review_media' => ['review_id', 'store_reviews'],
        'menu_review_media' => ['review_id', 'menu_reviews'],
    ];

    /** Sales records left untouched by the erasure (no shopper personal data of their own; kept for tax). */
    public const UNTOUCHED = ['order_items', 'fiscal_documents'];

    // ── export ───────────────────────────────────────────────────────────────

    /**
     * Writes the shopper's data of THIS store as one JSON document to $out (a stream), table by table, rows read in
     * chunks — never the whole history in memory.
     *
     * @param  resource  $out
     */
    public function writeJson(Customer $customer, $out): void
    {
        $store = $this->storeOf($customer);
        fwrite($out, '{"format":"juwri.shopper-export.v1",');
        fwrite($out, '"generated_at":'.json_encode(now()->toIso8601String()).',');
        fwrite($out, '"store":'.json_encode(['id' => (int) $store->id, 'name' => (string) $store->title]).',');
        fwrite($out, '"note":'.json_encode('Your data held by this store only. Accounts you have with other stores on the platform are separate.').',');
        fwrite($out, '"customer":'.json_encode($this->clean('customers', (array) DB::table('customers')->where('id', $customer->id)->first())).',');
        fwrite($out, '"tables":{');
        $first = true;
        foreach ($this->exportTables() as $table) {
            fwrite($out, ($first ? '' : ',').json_encode($table).':[');
            $first = false;
            $n = 0;
            foreach ($this->rows($customer, $table)->lazyById(500) as $row) {
                fwrite($out, ($n++ ? ',' : '').json_encode($this->clean($table, (array) $row)));
            }
            fwrite($out, ']');
        }
        fwrite($out, '},"store_credit":'.json_encode($this->storeCredit($customer)).'}');
    }

    /**
     * The shopper's orders and their lines as CSV (one line per order item; an order without lines gets one line).
     *
     * @param  resource  $out
     */
    public function writeOrdersCsv(Customer $customer, $out): void
    {
        $orderCols = ['id', 'created_at', 'status', 'type', 'currency_code', 'customer_name', 'phone_number', 'address', 'is_paid'];
        $orderCols = array_values(array_filter($orderCols, fn ($c) => Schema::hasColumn('orders', $c)));
        $itemCols = array_values(array_filter(['quantity', 'unit_price_minor', 'currency_code', 'options', 'order_note'], fn ($c) => Schema::hasColumn('order_items', $c)));
        fputcsv($out, [...array_map(fn ($c) => 'order_'.$c, $orderCols), 'item_name', ...array_map(fn ($c) => 'item_'.$c, $itemCols)], ',', '"', '');
        foreach ($this->rows($customer, 'orders')->lazyById(200) as $order) {
            $base = array_map(fn ($c) => $this->csvCell($order->{$c} ?? null), $orderCols);
            $items = OrderItem::query()->withoutGlobalScopes()->where('order_id', $order->id)->orderBy('id')->get();
            if ($items->isEmpty()) {
                fputcsv($out, [...$base, '', ...array_fill(0, count($itemCols), '')], ',', '"', '');
            }
            foreach ($items as $item) {
                fputcsv($out, [...$base, $this->csvCell($item->displayName()), ...array_map(fn ($c) => $this->csvCell($item->getRawOriginal($c)), $itemCols)], ',', '"', '');
            }
        }
    }

    /** The owner-visible log line of an export (no personal data: the customer id only, cleared on erasure). */
    public function logExport(Customer $customer, string $format, string $channel): void
    {
        $this->audit($customer, 'customer.data_export', ['format' => $format, 'channel' => $channel], true);
        Log::info('shopper.data_export', ['store_id' => (int) $customer->store_id, 'customer_id' => (int) $customer->id, 'format' => $format, 'channel' => $channel]);
    }

    // ── erase ────────────────────────────────────────────────────────────────

    /** Why the account cannot be deleted right now (an order or stay still in progress), or null. */
    public function blocker(Customer $customer): ?string
    {
        $guestIds = $this->customerGuestOrderIds($customer);
        $open = DB::table('orders')->where('store_id', $customer->store_id)
            ->where(fn (Builder $q) => $q->where('customer_id', $customer->id)->orWhereIn('id', $guestIds ?: [0]))
            ->whereNotIn('status', self::CLOSED_ORDER)
            ->where(fn (Builder $q) => $q->whereNull('created_at')->orWhere('created_at', '>=', now()->subDays(60)))->exists();
        if ($open) {
            return ___('You have an order in progress. You can delete your account once it is completed or cancelled.');
        }
        if (Schema::hasTable('hotel_bookings') && DB::table('hotel_bookings')->where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)->where('check_out', '>=', StoreClock::today($customer->store_id))
            ->whereNotIn('status', ['cancelled', 'no_show', 'checked_out'])->exists()) {
            return ___('You have an upcoming stay. You can delete your account after it ends or is cancelled.');
        }

        return null;
    }

    /**
     * Deletes the shopper's account in THIS store: their own rows deleted, the store's sales records kept with their
     * personal data stripped, every sign-in (app tokens, passkeys; web sessions die with the row) revoked, a
     * confirmation e-mailed and an entry (no personal data) written to the store's activity log.
     *
     * @return array<string, int> table => rows deleted or anonymised
     */
    public function erase(Customer $customer, string $channel): array
    {
        $email = (string) ($customer->email ?? '');
        $name = (string) ($customer->name ?? '');
        $store = $this->storeOf($customer);
        $files = [];
        $report = [];

        $guestIds = $this->customerGuestOrderIds($customer);

        DB::transaction(function () use ($customer, $guestIds, &$files, &$report): void {
            $id = (int) $customer->id;
            $sid = (int) $customer->store_id;

            // review photos the shopper uploaded: the asset rows and their files
            foreach (['store_review_media' => 'store_reviews', 'menu_review_media' => 'menu_reviews'] as $media => $reviews) {
                if (! Schema::hasTable($media)) {
                    continue;
                }
                $reviewIds = $this->rows($customer, $reviews)->pluck('id')->all();
                $assetIds = DB::table($media)->whereIn('review_id', $reviewIds)->pluck('media_asset_id')->all();
                foreach (DB::table('media_assets')->whereIn('id', $assetIds)->where('store_id', $sid)->get() as $asset) {
                    $files[] = StoreDataExporter::mediaPath('public:storage/menu/', (array) $asset, (string) $asset->filename);
                }
                $report[$media] = DB::table($media)->whereIn('review_id', $reviewIds)->delete();
                $report['media_assets'] = ($report['media_assets'] ?? 0) + DB::table('media_assets')->whereIn('id', $assetIds)->where('store_id', $sid)->delete();
                if ($reviews === 'store_reviews' && Schema::hasTable('store_review_votes')) {
                    DB::table('store_review_votes')->whereIn('review_id', $reviewIds)->delete();
                }
            }
            $loyalty = $this->rows($customer, 'loyalty_accounts')->pluck('id')->all();
            $report['loyalty_ledger_entries'] = DB::table('loyalty_ledger_entries')->whereIn('loyalty_account_id', $loyalty)->delete();
            $orderIds = $this->rows($customer, 'orders')->pluck('id')->all();
            if (Schema::hasTable('nps_responses')) {
                $report['nps_responses'] = DB::table('nps_responses')->where('store_id', $sid)->whereIn('order_id', $orderIds)->delete();
            }

            foreach (self::COLUMNS as $table => $columns) {
                foreach ($columns as $column => $decision) {
                    if ($decision === 'ignore' || ! Schema::hasTable($table)) {
                        continue;
                    }
                    $q = $this->scoped(DB::table($table), $table, $column, $id, $sid);
                    if ($decision === 'delete') {
                        $report[$table] = $q->delete();
                    } elseif ($table === 'orders') {
                        $report[$table] = $q->update($this->orderPii());
                        // P6-209: guest orders proven by the account's verified phone
                        $report['guest_orders'] = $guestIds === [] ? 0 : DB::table('orders')->where('store_id', $sid)
                            ->whereNull('customer_id')->whereIn('id', $guestIds)->update($this->orderPii());
                    } else {
                        $values = array_filter(self::ANONYMISE[$table], fn ($v, $col) => Schema::hasColumn($table, $col), ARRAY_FILTER_USE_BOTH);
                        $report[$table] = $q->update($values);
                    }
                }
            }

            // earlier export log lines named the customer by id: the id goes with the account
            DB::table('activity_logs')->where('store_id', $sid)->where('subject_type', Customer::class)->where('subject_id', $id)
                ->update(['subject_id' => null]);
            $report['customers'] = DB::table('customers')->where('id', $id)->where('store_id', $sid)->delete();
        });

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->guestIdMemo = [];
        $report = array_filter($report);
        ksort($report);
        $this->audit($customer, 'customer.self_erased', ['channel' => $channel, 'rows' => $report], false);
        Log::info('shopper.erased', ['store_id' => (int) $store->id, 'rows' => $report, 'channel' => $channel]);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($email)->send(new ShopperAccountErased((string) $store->title, $name, app()->getLocale()));
            } catch (\Throwable $e) {
                Log::warning('shopper.erased.mail_failed', ['store_id' => (int) $store->id, 'error' => $e->getMessage()]);
            }
        }

        return $report;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function exportTables(): array
    {
        $out = [];
        foreach (self::COLUMNS as $table => $columns) {
            if (in_array('ignore', $columns, true) || $table === 'customer_tokens' || ! Schema::hasTable($table)) {
                continue;
            }
            $out[] = $table;
        }
        foreach (array_keys(self::CHILDREN) as $table) {
            if (Schema::hasTable($table)) {
                $out[] = $table;
            }
        }

        return $out;
    }

    /** The shopper's rows of a classified or child table, in their store. */
    private function rows(Customer $customer, string $table): Builder
    {
        $id = (int) $customer->id;
        $sid = (int) $customer->store_id;
        if (isset(self::CHILDREN[$table])) {
            [$column, $parent] = self::CHILDREN[$table];

            return DB::table($table)->whereIn($column, $this->rows($customer, $parent)->select('id'))->orderBy('id');
        }
        $column = (string) array_key_first(self::COLUMNS[$table]);
        if ($table === 'orders' && ($guestIds = $this->customerGuestOrderIds($customer)) !== []) {
            return DB::table('orders')->where('store_id', $sid)
                ->where(fn (Builder $q) => $q->where('customer_id', $id)->orWhere(fn (Builder $g) => $g->whereNull('customer_id')->whereIn('id', $guestIds)))
                ->orderBy('id');
        }

        return $this->scoped(DB::table($table), $table, $column, $id, $sid)->orderBy('id');
    }

    /**
     * ORDER_PII limited to the columns this schema has (the same strip for account and guest orders).
     *
     * @return array<string, mixed>
     */
    private function orderPii(): array
    {
        return array_filter(AccountDeletionService::ORDER_PII + ['refund_request_reason' => null, 'pod_photo' => null],
            fn ($v, $col) => Schema::hasColumn('orders', $col), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * P6-209: the store's guest orders proven to be this customer's — only when the account's phone is verified.
     *
     * @return list<int>
     */
    private function customerGuestOrderIds(Customer $customer): array
    {
        if ($customer->phone_verified_at === null || trim((string) $customer->phone) === '') {
            return [];
        }

        return $this->guestOrderIds((int) $customer->store_id, (string) $customer->phone);
    }

    /**
     * P6-209: ids of the store's guest orders (customer_id NULL) whose phone equals $phone after normalisation.
     * The phone must already be proven by the caller (verified account phone or a one-time code).
     *
     * @return list<int>
     */
    public function guestOrderIds(int $storeId, string $phone): array
    {
        $want = self::normalisePhone($phone);
        if ($want === '' || strlen(ltrim($want, '+')) < 6) {
            return [];
        }
        $key = $storeId.'|'.$want;
        if (isset($this->guestIdMemo[$key])) {
            return $this->guestIdMemo[$key];
        }
        $ids = [];
        $digits = substr(ltrim($want, '+'), -4);
        DB::table('orders')->where('store_id', $storeId)->whereNull('customer_id')
            ->whereNotNull('phone_number')->where('phone_number', 'like', '%'.$digits[3].'%')
            ->select(['id', 'phone_number'])->orderBy('id')
            ->lazyById(1000)->each(function ($row) use ($want, &$ids): void {
                if (self::normalisePhone((string) $row->phone_number) === $want) {
                    $ids[] = (int) $row->id;
                }
            });

        return $this->guestIdMemo[$key] = $ids;
    }

    /** E.164-style: keep a leading +, 00 → +, digits only (as SmsService::normalizePhone). */
    public static function normalisePhone(string $phone): string
    {
        $phone = trim($phone);
        $plus = str_starts_with($phone, '+');
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if ($plus) {
            return '+'.$digits;
        }

        return str_starts_with($digits, '00') ? '+'.ltrim(substr($digits, 2), '0') : $digits;
    }

    // ── P6-209: guest (no account) privacy request, phone proven by a one-time code ──

    /** Why the guest's orders cannot be anonymised now (one still open), or null. */
    public function guestBlocker(int $storeId, string $phone): ?string
    {
        $ids = $this->guestOrderIds($storeId, $phone);
        $open = $ids !== [] && DB::table('orders')->where('store_id', $storeId)->whereIn('id', $ids)
            ->whereNotIn('status', self::CLOSED_ORDER)
            ->where(fn (Builder $q) => $q->whereNull('created_at')->orWhere('created_at', '>=', now()->subDays(60)))->exists();

        return $open ? ___('This guest has an order in progress. Erase it once the order is completed or cancelled.') : null;
    }

    /**
     * The guest's orders of THIS store (and their lines / survey answers) as one JSON document.
     *
     * @param  resource  $out
     */
    public function writeGuestJson(int $storeId, string $phone, $out): void
    {
        $store = Store::query()->withoutGlobalScopes()->findOrFail($storeId);
        $ids = $this->guestOrderIds($storeId, $phone);
        fwrite($out, '{"format":"juwri.guest-export.v1",');
        fwrite($out, '"generated_at":'.json_encode(now()->toIso8601String()).',');
        fwrite($out, '"store":'.json_encode(['id' => (int) $store->id, 'name' => (string) $store->title]).',');
        fwrite($out, '"note":'.json_encode('Orders placed at this store without an account, with this phone number.').',');
        fwrite($out, '"tables":{');
        $tables = ['orders' => DB::table('orders')->where('store_id', $storeId)->whereIn('id', $ids ?: [0])];
        foreach (['order_items', 'nps_responses'] as $child) {
            if (Schema::hasTable($child)) {
                $tables[$child] = DB::table($child)->whereIn('order_id', $ids ?: [0]);
            }
        }
        $first = true;
        foreach ($tables as $table => $q) {
            fwrite($out, ($first ? '' : ',').json_encode($table).':[');
            $first = false;
            $n = 0;
            foreach ($q->orderBy('id')->lazyById(500) as $row) {
                fwrite($out, ($n++ ? ',' : '').json_encode($this->clean($table, (array) $row)));
            }
            fwrite($out, ']');
        }
        fwrite($out, '}}');
    }

    /**
     * Anonymises the guest's orders of THIS store with the ORDER_PII rule (amounts, lines, tax and fiscal documents
     * kept) and deletes their survey answers. Writes an activity-log row with no personal data (a salted phone hash).
     *
     * @return array<string, int>
     */
    public function eraseGuest(int $storeId, string $phone, ?int $actorUserId): array
    {
        $ids = $this->guestOrderIds($storeId, $phone);
        $report = [];
        DB::transaction(function () use ($storeId, $ids, &$report): void {
            if ($ids === []) {
                return;
            }
            if (Schema::hasTable('nps_responses')) {
                $report['nps_responses'] = DB::table('nps_responses')->where('store_id', $storeId)->whereIn('order_id', $ids)->delete();
            }
            $report['guest_orders'] = DB::table('orders')->where('store_id', $storeId)->whereNull('customer_id')
                ->whereIn('id', $ids)->update($this->orderPii());
        });
        $this->guestIdMemo = [];
        $report = array_filter($report);
        $this->guestAudit($storeId, 'customer.guest_erased', $phone, $actorUserId, ['rows' => $report]);
        Log::info('shopper.guest_erased', ['store_id' => $storeId, 'rows' => $report]);

        return $report;
    }

    public function logGuestExport(int $storeId, string $phone, ?int $actorUserId): void
    {
        $this->guestAudit($storeId, 'customer.guest_export', $phone, $actorUserId, ['orders' => count($this->guestOrderIds($storeId, $phone))]);
    }

    /** @param array<string, mixed> $meta */
    private function guestAudit(int $storeId, string $action, string $phone, ?int $actorUserId, array $meta): void
    {
        // a keyed hash, not the phone: proves which request was handled without keeping the erased contact
        $meta['contact_hash'] = hash_hmac('sha256', self::normalisePhone($phone), (string) config('app.key'));
        ActivityLog::query()->withoutGlobalScopes()->insert([
            'account_id' => DB::table('stores')->where('id', $storeId)->value('account_id'),
            'store_id' => $storeId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => null,
            'subject_id' => null,
            'meta' => json_encode($meta),
            'created_at' => now(),
        ]);
    }

    private function scoped(Builder $q, string $table, string $column, int $id, int $sid): Builder
    {
        $q->where($column, $id);

        return Schema::hasColumn($table, 'store_id') ? $q->where('store_id', $sid) : $q;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function clean(string $table, array $row): array
    {
        foreach ([...(self::NOT_EXPORTED[$table] ?? []), 'account_id'] as $col) {
            unset($row[$col]);
        }
        foreach ($row as $col => $value) {
            if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                $row[$col] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }
        }

        return $row;
    }

    /** @return list<array{currency: string, balance: float}> */
    private function storeCredit(Customer $customer): array
    {
        $out = [];
        foreach (LedgerAccount::query()->where('owner_type', 'customer')->where('owner_id', $customer->id)->get() as $account) {
            $out[] = ['currency' => (string) $account->currency, 'balance' => app(Ledger::class)->accountBalance($account)];
        }

        return $out;
    }

    private function csvCell(mixed $value): string
    {
        $s = is_scalar($value) ? (string) $value : (string) json_encode($value);

        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'".$s : $s; // no spreadsheet formula injection
    }

    /** @param array<string, mixed> $meta */
    private function audit(Customer $customer, string $action, array $meta, bool $withSubject): void
    {
        ActivityLog::query()->withoutGlobalScopes()->insert([
            'account_id' => $customer->account_id,
            'store_id' => (int) $customer->store_id,
            'actor_user_id' => null,
            'action' => $action,
            'subject_type' => $withSubject ? Customer::class : null,
            'subject_id' => $withSubject ? (int) $customer->id : null,
            'meta' => json_encode($meta),
            'created_at' => now(),
        ]);
    }

    private function storeOf(Customer $customer): Store
    {
        return Store::query()->withoutGlobalScopes()->findOrFail((int) $customer->store_id);
    }
}
