<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Models\Coupon;
use App\Models\Customer;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Loyalty\Models\CouponRedemption;

class StoreCouponController extends Controller
{
    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    public function index()
    {
        $rn = 'stores.coupons.index';
        $coupons = Coupon::where('user_id', auth()->id())
            ->orderByDesc('id')
            ->paginate(20);

        // C5: how many diners were refused an exhausted coupon today — a coupon
        // that ran out otherwise looks like "the discount silently stopped working".
        $deniedToday = (int) TenantUsageDaily::whereDate('date', now()->toDateString())
            ->where('user_id', auth()->id())
            ->where('metric', 'coupon_claim_denied')
            ->value('value');

        $storeTz = StoreClock::tz($this->storeId());

        return view('owner.marketing.coupons.index', compact('coupons', 'rn', 'deniedToday', 'storeTz'));
    }

    public function create()
    {
        $rn = 'stores.coupons.create';

        return view('owner.marketing.coupons.form', compact('rn'));
    }

    public function store(Request $request)
    {
        // Pricing follow-ups (PRICING.md §3): creating a coupon needs a plan that includes coupons.
        if (! PlanGate::allows($request->user(), PlanGate::COUPONS)) {
            return redirect()->route('stores.coupons.index')->withErrors(['plan' => PlanGate::upsell(PlanGate::COUPONS)]);
        }

        $data = $request->validate($this->rules($request));

        Coupon::create($this->payload($data) + [
            'code' => strtoupper($data['code']),
            'used' => 0,
            'user_id' => auth()->id(),
        ]);

        app_alert_success(___('Coupon created successfully.'));

        return redirect()->route('stores.coupons.index');
    }

    /**
     * E5: shared validation. BOGO (buy-X-get-Y) requires its own parameters and
     * leaves `percentage` unused; the other types keep exactly their prior rules.
     *
     * @return array<string,mixed>
     */
    private function rules(Request $request, ?int $ignoreId = null): array
    {
        $isBogo = $request->input('coupon_type') === 'bogo';
        $req = fn (string $rule) => $isBogo ? $rule : 'nullable';
        // The issuer's currency: the owner's first store's ({@see Coupon::defaultMinorMoneyCurrency()}).
        $currency = (new Coupon(['user_id' => auth()->id()]))->defaultMinorMoneyCurrency();

        return [
            // P6-215: unique per owner (any case), not across every store; max 20 = the column width.
            'code' => ['required', 'string', 'max:20', Coupon::uniqueCodeRule((int) auth()->id(), null, $ignoreId)],
            'coupon_type' => ['required', 'in:percentage,fixed,bogo'],
            // A percentage coupon: 0 < x <= 100 with up to 3 decimals; a fixed-amount coupon: > 0 in the
            // currency's decimals (4.99 USD, 1.125 KWD, 500 JPY); BOGO does not use the value at all.
            'percentage' => $isBogo ? ['nullable'] : ['required', ...Coupon::valueRules((string) $request->input('coupon_type'), $currency)],
            'limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            // BL-036 C7: a real, non-past date; an unchanged stored expiry stays accepted on edit.
            'expiry_at' => Coupon::expiryDateRules(StoreClock::tz($this->storeId()), $this->storedExpiryDate($ignoreId)),
            'buy_scope' => [$req('required'), 'in:item,category'],
            // P6-59b: BOGO targets must be this owner's own items / categories (never another tenant's ids).
            'buy_id' => [$req('required'), 'integer', 'min:1', $this->ownTarget((string) $request->input('buy_scope'))],
            'buy_qty' => [$req('required'), 'integer', 'min:1'],
            'get_id' => [$req('required'), 'integer', 'min:1', $this->ownTarget('item')], // the free item is always an item
            'get_qty' => [$req('required'), 'integer', 'min:1'],
            'get_discount_percent' => [$req('required'), 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** P6-59b: an item (or, for the category scope, a category) of one of the signed-in owner's stores. */
    private function ownTarget(string $scope): Exists
    {
        return Rule::exists($scope === 'category' ? 'categories' : 'items', 'id')->whereIn('store_id', dashboard_store_ids());
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function payload(array $data): array
    {
        $isBogo = ($data['coupon_type'] ?? null) === 'bogo';

        return [
            'coupon_type' => $data['coupon_type'],
            'percentage' => $isBogo ? 0 : ($data['percentage'] ?? 0),
            // P6-353: an empty form field means "no limit"; the `coupons.limit`
            // column is a NOT NULL bigInteger, so we store the CouponService
            // sentinel 0 instead of the old default 9999.
            'limit' => empty($data['limit']) ? 0 : (int) $data['limit'],
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'expiry_at' => $this->expiryMoment($data['expiry_at'] ?? null),
            'buy_scope' => $isBogo ? $data['buy_scope'] : null,
            'buy_id' => $isBogo ? $data['buy_id'] : null,
            'buy_qty' => $isBogo ? $data['buy_qty'] : null,
            'get_id' => $isBogo ? $data['get_id'] : null,
            'get_qty' => $isBogo ? $data['get_qty'] : null,
            'get_discount_percent' => $isBogo ? $data['get_discount_percent'] : null,
        ];
    }

    public function edit(Coupon $coupon)
    {
        abort_if($coupon->user_id !== auth()->id(), 403);
        $rn = 'stores.coupons.edit';
        // M3: most recent redemptions of this code, newest first.
        $redemptions = CouponRedemption::where('coupon_id', $coupon->id)
            ->orderByDesc('id')->limit(50)->get();
        $redeemers = Customer::whereIn('id', $redemptions->pluck('customer_id')->unique())
            ->get()->keyBy('id');

        $expiryDate = StoreClock::localDate($this->storeId(), $coupon->expiry_at);

        return view('owner.marketing.coupons.form', compact('coupon', 'rn', 'redemptions', 'redeemers', 'expiryDate'));
    }

    /** The owner's first store, the one its coupons' currency and timezone come from. */
    private function storeId(): ?int
    {
        return Store::where('user_id', auth()->id())->orderBy('id')->value('id');
    }

    /**
     * P6-216: a date-only expiry means the END of that day in the store's timezone
     * (23:59:59 store-local), stored in the app timezone. CouponService treats the
     * coupon as expired from the next store-local midnight.
     */
    private function expiryMoment(?string $date): ?Carbon
    {
        return Coupon::expiryEndOfDay($date, StoreClock::tz($this->storeId()));
    }

    /** The edited coupon's expiry as the store-local date the form shows (null on create or no expiry). */
    private function storedExpiryDate(?int $couponId): ?string
    {
        if ($couponId === null) {
            return null;
        }
        $stored = Coupon::where('user_id', auth()->id())->whereKey($couponId)->value('expiry_at');

        return StoreClock::localDate($this->storeId(), $stored);
    }

    public function update(Request $request, Coupon $coupon)
    {
        abort_if($coupon->user_id !== auth()->id(), 403);

        $data = $request->validate($this->rules($request, $coupon->id));

        $payload = $this->payload($data);
        // Review fix 8: the date the form showed back unchanged keeps the stored moment (no re-stamp in today's zone).
        if (($data['expiry_at'] ?? null) !== null && $data['expiry_at'] === $this->storedExpiryDate($coupon->id)) {
            $payload['expiry_at'] = $coupon->expiry_at;
        }
        $coupon->update($payload + ['code' => strtoupper($data['code'])]);

        app_alert_success(___('Coupon updated successfully.'));

        return redirect()->route('stores.coupons.index');
    }

    public function destroy(Coupon $coupon)
    {
        abort_if($coupon->user_id !== auth()->id(), 403);
        $coupon->delete();
        app_alert_success(___('Coupon deleted.'));

        return redirect()->route('stores.coupons.index');
    }
}
