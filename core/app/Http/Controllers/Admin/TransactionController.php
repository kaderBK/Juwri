<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use App\Support\SearchTerm;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Money\CurrencyTotals;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Fiscal\Models\Tax;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\SettlementHandlerMissing;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Contracts\TransactionFulfiller;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Admin › Transactions (J22): payments for plans, add-ons, top-ups and apps, and the business's own customer payments
 * (gift cards, deposits, store credit). Paid and pending payments are listed; an offline (pending) payment can be
 * marked paid, which runs the normal settlement. P1-13 13g: Community (the platform-billing parts — plan names,
 * refunds, invoices — come from Billing, ee, when it is active).
 */
class TransactionController extends Controller
{
    use AnswersAdminPanel;

    /** Statuses the list and the details panel show. */
    private const LISTED = [Transaction::STATUS_SUCCESS, Transaction::STATUS_PENDING];

    /** P1-13 13g: refunds are Billing's (RefundService, ee): is this payment refundable / refund it. */
    public const REFUNDABLE_SLOT = 'billing.admin.transaction.refundable';

    public const REFUND_SLOT = 'billing.admin.transaction.refund';

    /** @var array<int, Model|null> */
    private array $plans = [];

    public function __construct()
    {
        $this->authorizeResource(Transaction::class, 'transaction');
    }

    /**
     * MC-02 — durable charge history. Order payments are consumed (not deleted)
     * on settlement and their receipts land here, so an admin can find any past
     * charge by clicking rather than by SQL. Optional currency filter.
     */
    public function receipts(Request $request): View
    {
        $query = BillingReceipt::query()->latest();

        if ($request->filled('currency')) {
            $query->where('currency_code', strtoupper((string) $request->get('currency')));
        }

        $currencies = BillingReceipt::query()
            ->select('currency_code')
            ->distinct()
            ->whereNotNull('currency_code')
            ->orderBy('currency_code')
            ->pluck('currency_code');

        return view('admin.billing.transactions.receipts', [
            'receipts' => $query->paginate(30)->withQueryString(),
            'currencies' => $currencies,
        ]);
    }

    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $since30 = now()->subDays(30);
        /* Bug batch 7 · 3: never summed across currencies — the tile totals the platform currency (legacy rows
           without one count in it) and every other currency is reported apart (code => amount). */
        $platform = app(Ledger::class)->defaultCurrency();
        $revenueRows = Transaction::query()->where('status', Transaction::STATUS_SUCCESS)->where('created_at', '>=', $since30)
            ->groupBy('currency_code')->selectRaw('currency_code, SUM(amount_minor) as m')->toBase()->get()
            ->map(fn ($row) => [$row->currency_code, (int) $row->m]);
        [$revenue30d, $revenue30dOther] = CurrencyTotals::split(CurrencyTotals::minorByCurrency($revenueRows, $platform), $platform);
        $stats = [
            'revenue30d' => $revenue30d,
            'revenue30dOther' => $revenue30dOther,
            'success30d' => Transaction::query()->where('status', Transaction::STATUS_SUCCESS)->where('created_at', '>=', $since30)->count(),
            'pending' => Transaction::query()->where('status', Transaction::STATUS_PENDING)->count(),
            'failed7d' => Transaction::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        $transactions = $this->filtered($request)->paginate(25);

        $planIds = $transactions->pluck('product_id')->filter()->unique()->map(fn ($id) => (string) $id)->all();
        $plans = [];
        foreach (app(PlanCatalog::class)->plans() as $plan) {
            if (in_array((string) $plan->getKey(), $planIds, true)) {
                $plans[$plan->getKey()] = $plan;
            }
        }

        $gateways = Transaction::query()->whereNotNull('transaction_gatway')->where('transaction_gatway', '!=', '')
            ->distinct()->orderBy('transaction_gatway')->pluck('transaction_gatway');

        return view('admin.billing.transactions.index', compact('transactions', 'stats', 'plans', 'gateways'));
    }

    /**
     * P3-6 (feature-platform): refund a supported purchase — reversal
     * transaction, immediate grant/capacity revoke, wallet credit, commission
     * reversal. All-or-nothing and idempotent inside RefundService; types the
     * rail cannot reverse answer 422 there (and the control is not shown).
     */
    public function refund(Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        $response = Hooks::answer(self::REFUND_SLOT, $transaction);
        abort_unless($response instanceof RedirectResponse, 404);

        return $response;
    }

    /** Can this payment be refunded here (the list shows the control)? Only with Billing's refunds. */
    public static function refundable(Transaction $transaction): bool
    {
        return Route::has('admin.transactions.refund') && Hooks::answer(self::REFUNDABLE_SLOT, $transaction) === true;
    }

    /** Details side panel: what was bought and the taxes on it. */
    public function show(Transaction $transaction): View
    {
        abort_unless(in_array($transaction->status, self::LISTED, true), 404);

        return view('admin.billing.transactions.details', [
            'transaction' => $transaction,
            'title' => $this->title($transaction),
            'applied_taxes' => $this->appliedTaxes($transaction),
        ]);
    }

    /**
     * P1-23 part 6: may the superadmin mark this payment as paid? Only a pending PLATFORM purchase. A payment made to a
     * store (an order, a gift card / top-up / deposit paid by wallet or bank transfer) went to the store's own account:
     * only the store owner can confirm it (Order Hub), never the platform.
     */
    public static function markablePaid(Transaction $transaction): bool
    {
        return $transaction->status === Transaction::STATUS_PENDING && ! app(StoreTransfers::class)->paidToStore($transaction);
    }

    /** "Mark as paid" for an offline payment: settles it like a gateway would. */
    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        if ($transaction->status === Transaction::STATUS_PENDING && ! self::markablePaid($transaction)) {
            app_alert_error(___('This payment was made to a store. Only the store owner can confirm it.'));

            return back();
        }
        if ($transaction->status === Transaction::STATUS_PENDING) {
            // One DB transaction: a settlement that throws (e.g. a shopper-wallet top-up while the Marketplace module
            // is not active) leaves the payment pending, so it can be marked paid again later.
            try {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => Transaction::STATUS_SUCCESS]);
                    app(TransactionFulfiller::class)->fulfil($transaction);
                });
            } catch (SettlementHandlerMissing $e) {
                // P1-13 follow-up: a clear message instead of a 500; the payment stays pending (the alert is raised).
                app_alert_error(___('This payment cannot be marked as paid while the :module module is not active. It stays pending.', ['module' => $e->module]));

                return back();
            }
        }
        app_alert_success(___('Updated Successfully'));

        return back();
    }

    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', Transaction::class);

        $reason = trim((string) $request->input('reason', ''));
        $refused = 0;
        foreach (Transaction::query()->whereKey($request->ids())->get() as $transaction) {
            if (! $this->removeOrVoid($transaction, $reason)) {
                $refused++;
            }
        }

        if ($refused > 0) {
            return response()->json([
                'success' => false,
                'message' => ___(':count paid payment(s) were kept: a paid payment cannot be deleted. Give a reason to void it instead.', ['count' => $refused]),
            ], 422);
        }

        return $this->panelDone(___('Deleted Successfully'));
    }

    /**
     * P6-246: an unpaid / failed / abandoned payment is deleted; a paid one (or a refund) is never deleted — with a
     * reason it is voided (kept, stamped with the admin, the time and the reason), without one it is refused. Both
     * write the activity log with the admin id and a summary of the row.
     *
     * @return bool false when refused (paid and no reason)
     */
    private function removeOrVoid(Transaction $transaction, string $reason): bool
    {
        $summary = [
            'transaction_id' => $transaction->id, 'user_id' => $transaction->user_id, 'status' => $transaction->status,
            'method' => $transaction->transaction_method, 'amount' => $transaction->amount, 'currency' => $transaction->currency_code,
            'gateway' => $transaction->transaction_gatway, 'payment_id' => $transaction->payment_id, 'admin_id' => auth()->id(),
        ];

        if (! $transaction->isPaidRecord()) {
            $transaction->delete();
            log_activity('admin.transaction_deleted', null, $summary, null);

            return true;
        }

        if ($reason === '') {
            return false;
        }
        if ($transaction->voided_at === null) {
            $transaction->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => mb_substr($reason, 0, 500)])->save();
            log_activity('admin.transaction_voided', null, $summary + ['reason' => $reason], null);
        }

        return true;
    }

    /** Payments are only recorded by checkout; there is no manual entry form. */
    public function create(): RedirectResponse
    {
        return redirect()->route('admin.transactions.index');
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('admin.transactions.index');
    }

    public function edit(Transaction $transaction): RedirectResponse
    {
        return redirect()->route('admin.transactions.show', $transaction);
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('deleteAny', Transaction::class);
        $paid = $transaction->isPaidRecord();
        if (! $this->removeOrVoid($transaction, trim((string) $request->input('reason', '')))) {
            return back()->with('error', ___('A paid payment cannot be deleted. Give a reason to void it instead.'));
        }

        return back()->with('success', $paid ? ___('Payment voided.') : ___('Deleted Successfully'));
    }

    /** DataTables answer (older list layout): paid + pending payments of existing accounts. */
    private function dataTable(Request $request): JsonResponse
    {
        $listed = Transaction::query()->with('user')->whereIn('status', self::LISTED)->whereHas('user');

        $sortable = [0 => 'id', 1 => 'product_name', 2 => 'user_id', 3 => 'amount_minor', 4 => 'transaction_gatway', 5 => 'status', 6 => 'created_at'];

        return DataTable::make($request, $sortable, ['id'])->respond($listed, fn (Transaction $tx) => [
            Cells::text((string) $tx->id),
            Cells::text($this->title($tx)),
            Cells::link(route('admin.users.edit', $tx->user->id), (string) $tx->user->name),
            Cells::text(price_symbol_format($tx->amount)),
            Cells::html($tx->transaction_gatway ? Cells::badge((string) $tx->transaction_gatway, 'secondary') : '<span>-</span>'),
            Cells::html($this->statusBadge((string) $tx->status)),
            Cells::text(date_formating($tx->created_at)),
            Cells::actions($this->markPaidForm($tx), $this->detailsButton($tx), $this->invoiceLink($tx)),
            Cells::checkbox($tx->id),
        ]);
    }

    /**
     * The server-rendered list: newest first with the status / gateway /
     * period / text filters of the page.
     *
     * @return Builder<Transaction>
     */
    private function filtered(Request $request): Builder
    {
        $query = Transaction::query()->with(['user', 'store'])->orderByDesc('created_at')->orderByDesc('id')
            // BL-038/BL-039: checkout rows kept only as bookkeeping (subscription pending/set-up, settled MoR checkout, abandoned) are not payments.
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Transaction::CHECKOUT_BOOKKEEPING));

        $status = $request->input('status');
        if (in_array($status, ['success', 'pending', 'failed', 'cancel'], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('gateway')) {
            $query->where('transaction_gatway', (string) $request->input('gateway'));
        }

        $days = (string) $request->input('days', 'all');
        if (in_array($days, ['7', '30', '90'], true)) {
            $query->where('created_at', '>=', now()->subDays((int) $days));
        }

        if (SearchTerm::clean((string) $request->input('search')) !== '') {
            $search = (string) $request->input('search');
            $query->where(fn (Builder $q) => $q->where(fn (Builder $w) => SearchTerm::apply($w, ['product_name'], $search))
                ->orWhereHas('user', fn (Builder $user) => SearchTerm::apply($user, ['name', 'email'], $search)));
        }

        return $query;
    }

    /** Plan payments read "<plan name> (<interval>)"; anything else its own description. */
    private function title(Transaction $transaction): string
    {
        $planId = (int) $transaction->product_id;
        if ($planId > 0 && ! array_key_exists($planId, $this->plans)) {
            $this->plans[$planId] = app(PlanCatalog::class)->find($planId);
        }
        $plan = $planId > 0 ? $this->plans[$planId] : null;

        if ($plan === null) {
            return (string) $transaction->transaction_description;
        }

        return $plan->getAttribute('name').' ('.plan_interval_text($transaction->frequency).')';
    }

    /**
     * The taxes recorded on the payment with the amount each one came to:
     * inclusive taxes are taken out of the item price first, exclusive taxes
     * are then charged on what is left.
     *
     * @return array<int, Tax>
     */
    private function appliedTaxes(Transaction $transaction): array
    {
        if (empty($transaction->taxes_ids)) {
            return [];
        }

        $taxes = Tax::query()->whereIn('id', explode(',', (string) $transaction->taxes_ids))->get()->all();
        $price = (float) ($transaction->base_amount ?? $transaction->amount);

        $amount = fn (Tax $tax, float $on): float => $tax->value_type === 'percentage' ? $on * ((float) $tax->value / 100) : (float) $tax->value;

        $inclusive = 0.0;
        foreach ($taxes as $tax) {
            if ($tax->type === 'inclusive') {
                $tax->value = $amount($tax, $price);
                $inclusive += $tax->value;
            }
        }

        foreach ($taxes as $tax) {
            if ($tax->type === 'exclusive') {
                $tax->value = $amount($tax, $price - $inclusive);
            }
        }

        return $taxes;
    }

    private function statusBadge(string $status): string
    {
        return match ($status) {
            Transaction::STATUS_PENDING => Cells::badge(___('Pending'), 'info'),
            Transaction::STATUS_SUCCESS => Cells::badge(___('Paid'), 'success'),
            'cancel' => Cells::badge(___('Cancelled'), 'warning'),
            default => '',
        };
    }

    private function markPaidForm(Transaction $tx): string
    {
        if (! self::markablePaid($tx)) {
            return '';
        }

        return '<form class="d-inline" method="POST" action="'.e(route('admin.transactions.update', $tx->id)).'"'
            .' onsubmit="return confirm(\''.e(___('Are you sure?')).'\')">'
            .method_field('PUT').csrf_field()
            .'<button class="btn btn-icon btn-primary" title="'.e(___('Mark as Paid')).'" data-tippy-placement="top"><i class="icon-feather-check"></i></button>'
            .'</form>';
    }

    private function detailsButton(Transaction $tx): string
    {
        return '<button type="button" data-url="'.e(route('admin.transactions.show', $tx->id)).'" data-toggle="slidePanel" title="'.e(___('Details')).'"'
            .' class="btn btn-default btn-icon mx-1" data-tippy-placement="top"><i class="icon-feather-list"></i></button>';
    }

    private function invoiceLink(Transaction $tx): string
    {
        if ($tx->status !== Transaction::STATUS_SUCCESS || (float) $tx->amount <= 0 || ! Route::has('invoice')) {
            return '';
        }

        return '<a href="'.e(route('invoice', $tx->id)).'" title="'.e(___('Invoice')).'" class="btn btn-default btn-icon"'
            .' data-tippy-placement="top" target="_blank" rel="noopener"><i class="icon-feather-paperclip"></i></a>';
    }
}
