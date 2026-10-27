<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Http\Controllers\User\Concerns\BuildsReorderPayload;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\GuestOrderSession;
use App\Services\OrderClaimService;
use App\Services\StorefrontToggleMask;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Http\Requests\CustomerAddressRequest;
use Juwri\Modules\Customers\Mail\CustomerContactChange;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Modules\Customers\Services\CustomerAccountClaimService;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Payments\Models\LedgerEntry;

class CustomerAuthController extends Controller
{
    use BuildsReorderPayload;

    /** P6-302: account order-history page size for the cursor-paginated list. */
    private const ORDER_PAGE_SIZE = 20;

    public function login(Request $request, string $store)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'store_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $store = $this->urlStore($request, $store);
        if (! $store) {
            return response()->json(['success' => false, 'message' => ___('Store not found.')], 422);
        }

        $credentials = $request->only('email', 'password');
        $credentials['store_id'] = (int) $store->id;

        if (Auth::guard('customer')->attempt($credentials)) {
            $request->session()->regenerate();

            // GOL B1: attach any guest orders this browser placed before login.
            $claimed = OrderClaimService::claimSessionOrders(Auth::guard('customer')->user());
            $this->flashClaimed($request, $claimed);

            return response()->json([
                'success' => true,
                'message' => ___('Logged in successfully'),
                'claimed_count' => $claimed,
            ]);
        }

        return response()->json(['success' => false, 'message' => ___('Invalid credentials')], 401);
    }

    /**
     * #104: Request an SMS login OTP for a phone number.
     */
    public function requestOtp(Request $request, string $store)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'store_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $store = $this->urlStore($request, $store);
        if (! $store) {
            return response()->json(['success' => false, 'message' => ___('Store not found.')], 422);
        }

        [$ok, $message] = app(SmsOtp::class)
            ->request($request->phone, OtpScope::store((int) $store->id));

        return response()->json(['success' => $ok, 'message' => $message], $ok ? 200 : 429);
    }

    /**
     * #104: Verify an SMS OTP and log the customer in (find or create by phone).
     */
    public function verifyOtp(Request $request, string $store)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'code' => 'required|string',
            'store_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $store = $this->urlStore($request, $store);

        if (! $store || ! app(SmsOtp::class)->verify($request->phone, $request->code, OtpScope::store((int) $store->id))) {
            return response()->json(['success' => false, 'message' => ___('Invalid or expired code')], 401);
        }

        $phone = app(Sms::class)->normalizePhone($request->phone);

        $customer = Customer::firstOrCreate(
            ['store_id' => (int) $store->id, 'phone' => $phone],
            ['name' => ___('Guest'), 'source' => 'sms_otp']
        );
        $customer->forceFill(['phone_verified_at' => now()])->save();

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        // GOL B2: the OTP proved this phone — fold matching passwordless shadow
        // rows in (P2) and claim any same-session guest orders (P1).
        $claimed = OrderClaimService::claimByVerifiedPhone($customer, $request->phone)
            + OrderClaimService::claimSessionOrders($customer);
        $this->flashClaimed($request, $claimed);

        return response()->json([
            'success' => true,
            'message' => ___('Logged in successfully'),
            'claimed_count' => $claimed,
        ]);
    }

    /**
     * The store of a sign-in / sign-up / phone-code request: the one in the URL (server side), never the body. The
     * body still carries `store_id` (every theme sends it); a body naming a DIFFERENT store is refused, so a customer
     * can only be authenticated in, created in, or sent a code for the store whose page is open.
     */
    private function urlStore(Request $request, string $store): ?Store
    {
        $store = Store::idOrSlug($store)->first();
        if (! $store || (int) $request->input('store_id') !== (int) $store->id) {
            return null;
        }

        return $store;
    }

    /**
     * GOL E2/E3: stash the auth-flow outcome in a session flash. The storefront
     * reloads after a successful auth, so the JSON body is gone by the time the
     * page re-renders — the shared account-claim-banner partial reads these.
     */
    private function flashClaimed(Request $request, int $claimed): void
    {
        if ($claimed > 0) {
            $request->session()->flash('gol_claimed_count', $claimed);
        }
    }

    public function register(Request $request, string $store)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'store_id' => 'required|integer',
            'phone' => 'nullable|string|max:20',
            'birthday' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $store = $this->urlStore($request, $store);
        if (! $store) {
            return response()->json(['success' => false, 'message' => ___('Store not found.')], 422);
        }
        $storeId = (int) $store->id;

        $existing = Customer::where('store_id', $storeId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $request->email))])
            ->first();

        /* GOL A3 (rev/guest-order-linking): an email match is no longer a flat
           409. It can be a passwordless "shadow" the guest-checkout capture
           created for THIS person's previous orders — 409'ing it locked them out
           of their own email (the shadow has no password, so login failed too).
           Three-way on the match; only a passworded row is a real account. */
        if ($existing && ! empty($existing->password)) {
            // (a) A real account already owns this email.
            return response()->json(['success' => false, 'message' => ___('Email already registered for this restaurant')], 409);
        }

        $profile = [
            'name' => $request->name,
            'phone' => $request->phone,
            'marketing_consent' => $request->has('marketing_consent') ? 1 : 0,
            'sms_consent' => $request->has('sms_consent') ? 1 : 0,
            'birthday' => $request->filled('birthday') ? $request->birthday : null,
        ];

        if ($existing) {
            // Passwordless shadow. Adopt it in place (keeps its id → its orders)
            // ONLY when the browser proves it placed an order tied to THIS shadow
            // (P1). Otherwise the email is unverified — send the claim link (P3)
            // instead of trusting a typed address (locked security model).
            if ($this->sessionProvesShadow($existing)) {
                // Orders already sitting on the shadow become visible the moment
                // it turns into a real account — count them as claimed too, so the
                // "N previous orders added" toast reflects the whole history, not
                // only the extra session-stamped rows.
                $adopted = Order::where('store_id', $existing->store_id)
                    ->where('customer_id', $existing->id)->count();

                $existing->forceFill(array_merge($profile, [
                    'password' => Hash::make($request->password),
                    'pending_password' => null,
                ]))->save();

                Auth::guard('customer')->login($existing);
                $request->session()->regenerate();
                $claimed = $adopted + OrderClaimService::claimSessionOrders($existing);
                $this->flashClaimed($request, $claimed);

                return response()->json([
                    'success' => true,
                    'message' => ___('Registered successfully'),
                    'claimed_count' => $claimed,
                ]);
            }

            // (c) Shadow, no session proof — verify inbox before activating.
            [, $msg] = CustomerAccountClaimService::sendClaimEmail(
                $existing,
                Hash::make($request->password)
            );
            $request->session()->flash('gol_pending_verification', $msg);

            return response()->json([
                'success' => true,
                'pending_verification' => true,
                'message' => $msg,
            ]);
        }

        /* GOL A3b: customers carry a SECOND unique key — (store_id, phone) —
           that the email three-way above never sees. A fresh email whose phone
           already belongs to a row fell straight through to the insert and 500'd
           (prod: repeat 1062s on qrtest_customers_restaurant_id_phone_unique).
           Same locked model as the email match: a typed phone proves nothing, so
           adopt only on session proof; otherwise refuse, never crash. */
        if ($request->filled('phone')) {
            $byPhone = Customer::where('store_id', $storeId)
                ->where('phone', $request->phone)
                ->first();

            // Session proof — this browser placed an order tied to that row, so
            // it is the same person. Adopt in place (keeps its id → its orders),
            // taking over the newly typed email.
            if ($byPhone && empty($byPhone->password) && $this->sessionProvesShadow($byPhone)) {
                $adopted = Order::where('store_id', $byPhone->store_id)
                    ->where('customer_id', $byPhone->id)->count();

                $byPhone->forceFill(array_merge($profile, [
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'pending_password' => null,
                ]))->save();

                Auth::guard('customer')->login($byPhone);
                $request->session()->regenerate();
                $claimed = $adopted + OrderClaimService::claimSessionOrders($byPhone);
                $this->flashClaimed($request, $claimed);

                return response()->json([
                    'success' => true,
                    'message' => ___('Registered successfully'),
                    'claimed_count' => $claimed,
                ]);
            }

            // No proof. One message for both the passworded account and the
            // unproven shadow, so the response does not say which one it hit.
            if ($byPhone) {
                return response()->json(['success' => false, 'message' => ___('Phone number already registered for this restaurant')], 409);
            }
        }

        // No match — create + log in as before, then claim any same-session
        // guest orders (covers a guest who used phone-only at checkout and now
        // registers with a fresh email in the same browser).
        try {
            $customer = Customer::create(array_merge($profile, [
                'store_id' => $storeId,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent double-submit: both requests passed the lookups above
            // and raced to the insert. The loser reports the conflict instead of
            // 500ing — the winner already made the account.
            return response()->json(['success' => false, 'message' => ___('That account already exists — please sign in')], 409);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        $claimed = OrderClaimService::claimSessionOrders($customer);
        $this->flashClaimed($request, $claimed);

        return response()->json([
            'success' => true,
            'message' => ___('Registered successfully'),
            'claimed_count' => $claimed,
        ]);
    }

    /**
     * GOL A3: does the current session hold a P1 stamp for an order that is
     * attached to THIS shadow customer? That is the same-browser proof that the
     * person registering is the guest the shadow was created for — the only
     * thing that lets register adopt (and set a password on) the shadow without
     * an email round-trip.
     */
    private function sessionProvesShadow(Customer $shadow): bool
    {
        foreach (GuestOrderSession::entries() as $entry) {
            if ((int) ($entry['store_id'] ?? 0) !== (int) $shadow->store_id) {
                continue;
            }
            $order = Order::find((int) ($entry['order_id'] ?? 0));
            if ($order && (int) $order->customer_id === (int) $shadow->id) {
                return true;
            }
        }

        return false;
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true, 'message' => ___('Logged out successfully')]);
    }

    /**
     * GOL A4 (rev/guest-order-linking): the signed link from CustomerClaimVerify.
     * The `signed` middleware has already proven the URL was minted by us and is
     * unexpired (inbox ownership, P3). Promote the parked password, verify the
     * email, log the customer in, and claim their session-stamped guest orders.
     */
    public function verifyClaim(Request $request, string $store, Customer $customer): RedirectResponse
    {
        $storeKey = $store;
        $store = Store::idOrSlug($store)->firstOrFail();

        // Tenant fence + still-a-shadow guard: a real (passworded) account, a
        // cross-restaurant customer, or a link with no parked password is refused.
        if ((int) $customer->store_id !== (int) $store->id
            || ! empty($customer->password)
            || empty($customer->pending_password)) {
            return redirect()->route('publicView', $storeKey)
                ->with('claim_error', ___('This verification link is no longer valid.'));
        }

        $customer->forceFill([
            'password' => $customer->pending_password,
            'pending_password' => null,
            'email_verified_at' => $customer->email_verified_at ?? now(),
        ])->save();

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $claimed = OrderClaimService::claimSessionOrders($customer);

        return redirect()->route('customer.account', $storeKey)
            ->with('gol_claimed_count', $claimed)
            ->with('claim_success', ___('Your account is confirmed.'));
    }

    public function account(Request $request, $store)
    {
        $storeKey = $store;
        if (! Auth::guard('customer')->check()) {
            return redirect()->route('publicView', $storeKey);
        }

        $store = Store::idOrSlug($store)->firstOrFail();

        $customer = Auth::guard('customer')->user();
        if ($customer->store_id !== $store->id) {
            Auth::guard('customer')->logout();

            return redirect()->route('publicView', $storeKey);
        }

        $customer->load(['addresses' => function ($query) {
            $query->orderByDesc('is_default')->orderBy('id');
        }]);

        // P6-302: cursor-paginated account order history (20 per page, newest first,
        // ?before=<id> for the next page). Unpaid orders whose payment window has
        // ended are abandoned and hidden; a still-open unpaid order stays visible and
        // is flagged "Payment not completed" instead of counting as a placed order.
        [$orders, $nextBefore] = $this->accountOrders($store, $customer, (int) $request->query('before'));

        // P8 account panel (Liquid themes) — the runtime fetches the logged-in
        // diner's own profile + addresses + orders as JSON to render in-app.
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'customer' => [
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'birthday' => optional($customer->birthday)->format('Y-m-d'),
                    'marketing_consent' => (bool) $customer->marketing_consent,
                    'sms_consent' => (bool) $customer->sms_consent,
                    'notifications_enabled' => (bool) $customer->notifications_enabled,
                ],
                'addresses' => $customer->addresses->map(function ($a) use ($store) {
                    return [
                        'id' => (int) $a->id,
                        'label' => $a->label,
                        'line' => $a->line,
                        'city' => $a->city,
                        'is_default' => (bool) $a->is_default,
                        'delete_url' => route('customer.addresses.destroy', [$store->id, $a->id]),
                    ];
                })->values(),
                'orders' => $orders->map(function ($o) use ($store) {
                    return [
                        'id' => $o->id,
                        'token' => $o->track_token,
                        'total' => store_money_plain($o->total, $store->id),
                        'status' => $o->status,
                        'payment_not_completed' => $o->status === 'unpaid',
                        'date' => optional($o->created_at)->format('d M Y H:i'),
                        'track_url' => $o->track_token ? route('store.orderTrack', $o->track_token) : null,
                    ];
                })->values(),
                // P6-302: cursor for the next (older) page — null when this is the last.
                'next_before' => $nextBefore,
                // Coupons & Wallets P3-4: store-credit balance + recent history.
                'store_credit' => [
                    'balance' => CustomerWalletService::balanceFor($customer),
                    'formatted' => store_money_plain(CustomerWalletService::balanceFor($customer), $store->id),
                    'entries' => LedgerEntry::where('ledger_account_id', CustomerWalletService::accountFor($customer)->id)
                        ->orderByDesc('id')->limit(20)
                        ->get(['amount_minor', 'currency_code', 'type', 'reason', 'created_at']), // P1-05: JSON keeps `amount`
                ],
            ]);
        }

        $postOptions = store_options($store->id);
        // F7-11: storefront read path — mask plan-gated toggles centrally.
        $postOptions = StorefrontToggleMask::apply($postOptions, (int) $store->id);
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;
        // F3: aurora is the fallback account view for legacy templates that ship
        // none, mirroring the trackOrder fallback (Concerns\ManagesOrders). Without
        // it a signed-in customer on a legacy theme was silently redirected to the
        // menu and could never reach saved addresses, order history, reorder or
        // wallet credit — even though every route exists.
        if (! view()->exists('store_templates.'.$theme.'.account')) {
            $theme = 'aurora';
        }
        $view = 'store_templates.'.$theme.'.account';

        if (view()->exists($view)) {
            $languages = Language::where('active', '1')->get();
            $plan = $store->user->plan();
            $storeCredit = CustomerWalletService::balanceFor($customer);
            // Owner-authored info pages that actually have content — themes link
            // to these from the account footer (empty array => no links shown).
            $infoPages = storefront_info_pages($store->id);

            return view($view, compact('store', 'customer', 'orders', 'nextBefore', 'postOptions', 'theme', 'languages', 'plan', 'storeCredit', 'infoPages'));
        }

        return redirect()->route('publicView', $storeKey);
    }

    /**
     * P6-302: one page of the customer's order history, newest first. Unpaid orders
     * whose payment window has ended are abandoned and excluded; a still-open unpaid
     * order is kept (and later flagged "Payment not completed").
     *
     * @return array{0: Collection<int, Order>, 1: int|null}
     */
    private function accountOrders(Store $store, Customer $customer, int $before = 0): array
    {
        $windowMinutes = max(1, (int) config('api.storefront.hosted_window_minutes', 15));
        $cutoff = now()->subMinutes($windowMinutes);

        $query = Order::query()
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra'])
            ->where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            // Hide an unpaid order only once its payment window has ended: hosted
            // orders carry an explicit expiry, everything else falls back to
            // created_at + the hosted window (the expiry job's constant).
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('status')
                    ->orWhere('status', '!=', 'unpaid')
                    ->orWhere(function ($unpaid) use ($cutoff) {
                        $unpaid->where('status', 'unpaid')
                            ->where(function ($window) use ($cutoff) {
                                $window->where(function ($hosted) {
                                    $hosted->whereNotNull('payment_expires_at')
                                        ->where('payment_expires_at', '>', now());
                                })->orWhere(function ($legacy) use ($cutoff) {
                                    $legacy->whereNull('payment_expires_at')
                                        ->where('created_at', '>', $cutoff);
                                });
                            });
                    });
            })
            ->orderByDesc('id');

        if ($before > 0) {
            $query->where('id', '<', $before);
        }

        $page = $query->limit(self::ORDER_PAGE_SIZE + 1)->get();

        $hasMore = $page->count() > self::ORDER_PAGE_SIZE;
        $orders = $hasMore ? $page->slice(0, self::ORDER_PAGE_SIZE)->values() : $page;
        $nextBefore = $hasMore && $orders->isNotEmpty() ? (int) $orders->last()->id : null;

        return [$orders, $nextBefore];
    }

    /**
     * Standalone account-settings page — profile, saved addresses, preferences,
     * language, appearance and info links. Split out of the orders page so
     * "profile" and "my orders" are separate screens. Renders one shared view
     * that adopts the store's theme root + tokens.
     */
    public function profilePage(Request $request, string $store): View|RedirectResponse
    {
        $storeKey = $store;
        if (! Auth::guard('customer')->check()) {
            return redirect()->route('publicView', $storeKey);
        }

        $store = Store::idOrSlug($store)->firstOrFail();

        $customer = Auth::guard('customer')->user();
        if ($customer->store_id !== $store->id) {
            Auth::guard('customer')->logout();

            return redirect()->route('publicView', $storeKey);
        }

        $customer->load(['addresses' => function ($query) {
            $query->orderByDesc('is_default')->orderBy('id');
        }]);

        $postOptions = store_options($store->id);
        $postOptions = StorefrontToggleMask::apply($postOptions, (int) $store->id);
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;

        if (! view()->exists('store_templates.'.$theme.'.profile')) {
            $theme = 'aurora';
            $postOptions->store_template = $theme;
        }

        $languages = Language::where('active', '1')->get();
        $plan = $store->user->plan();
        $storeCredit = CustomerWalletService::balanceFor($customer);
        $infoPages = storefront_info_pages($store->id);

        return view('store_templates.'.$theme.'.profile', compact('store', 'customer', 'postOptions', 'theme', 'languages', 'plan', 'storeCredit', 'infoPages'));
    }

    public function updateProfile(Request $request, $store)
    {
        [$store, $customer] = $this->customerForRestaurant($store);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'birthday' => ['nullable', 'date', 'before:today'],
        ]);

        /* P6-304: a new phone / e-mail is normalised, checked against the store's other customers, and only PARKED
           (pending_*) until it is proven; the live, verified value stays in place until then. An empty field keeps
           the current value (no silent removal of a sign-in method). */
        $phone = $request->filled('phone') ? app(Sms::class)->normalizePhone((string) $request->input('phone')) : null;
        $email = $request->filled('email') ? mb_strtolower(trim((string) $request->input('email'))) : null;
        $phoneChanged = $phone !== null && $phone !== (string) $customer->phone;
        $emailChanged = $email !== null && $email !== mb_strtolower((string) $customer->email);

        $validator->after(function ($v) use ($store, $customer, $phone, $email, $phoneChanged, $emailChanged) {
            if ($phoneChanged && self::contactTaken($store->id, $customer->id, 'phone', $phone)) {
                $v->errors()->add('phone', ___('This phone number is already used by another account.'));
            }
            if ($emailChanged && self::contactTaken($store->id, $customer->id, 'email', $email)) {
                $v->errors()->add('email', ___('This email is already used by another account.'));
            }
        });

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        $birthday = $customer->birthday;
        if ($request->has('birthday')) {
            $birthday = $request->filled('birthday') ? $request->input('birthday') : null;
        }

        $customer->update([
            'name' => $request->name,
            'marketing_consent' => $request->has('marketing_consent') ? 1 : 0,
            'sms_consent' => $request->has('sms_consent') ? 1 : 0,
            'notifications_enabled' => $request->has('notifications_enabled') ? 1 : 0,
            'birthday' => $birthday,
        ]);

        $pending = [];
        if ($phoneChanged) {
            $customer->forceFill(['pending_phone' => $phone, 'pending_contact_at' => now()])->save();
            [$sent] = app(SmsOtp::class)->request($phone, OtpScope::store((int) $store->id));
            $pending['phone'] = (bool) $sent;
            $this->noticeOldAddress($store, $customer, 'phone', $phone);
        }
        if ($emailChanged) {
            $customer->forceFill(['pending_email' => $email, 'pending_contact_at' => now()])->save();
            $url = URL::temporarySignedRoute('customer.profile.email.verify', now()->addHours(24), [
                'store' => $store->id, 'customer' => $customer->id, 'h' => hash('sha256', $email),
            ]);
            Mail::to($email)->send(new CustomerContactChange((string) $store->title, 'confirm_email', 'email', CustomerContactChange::mask('email', $email), $url, (string) $customer->name));
            $pending['email'] = true;
            $this->noticeOldAddress($store, $customer, 'email', $email);
        }

        $message = $pending === [] ? ___('Profile updated successfully')
            : ___('Profile updated. Confirm your new contact details to start using them.');

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'pending' => $pending]);
        }

        return redirect()->route('customer.account', $store->id)->with('success', $message);
    }

    /** P6-304: confirm a parked phone number with the SMS code sent to it. */
    public function verifyProfilePhone(Request $request, string $store): JsonResponse
    {
        [$store, $customer] = $this->customerForRestaurant($store);
        $request->validate(['code' => ['required', 'string', 'max:12']]);

        $phone = (string) $customer->pending_phone;
        if ($phone === '' || ! app(SmsOtp::class)->verify($phone, (string) $request->input('code'), OtpScope::store((int) $store->id))) {
            return response()->json(['success' => false, 'message' => ___('Invalid or expired code')], 422);
        }
        if (self::contactTaken($store->id, $customer->id, 'phone', $phone)) {
            $customer->forceFill(['pending_phone' => null])->save();

            return response()->json(['success' => false, 'message' => ___('This phone number is already used by another account.')], 422);
        }

        $customer->forceFill(['phone' => $phone, 'phone_verified_at' => now(), 'pending_phone' => null])->save();

        return response()->json(['success' => true, 'message' => ___('Your phone number is confirmed.')]);
    }

    /** P6-304: the signed link in the confirm-new-email mail. Valid once: the parked value must still match. */
    public function verifyProfileEmail(Request $request, string $store, Customer $customer): RedirectResponse
    {
        $storeKey = $store;
        $store = Store::idOrSlug($store)->firstOrFail();
        $pending = (string) $customer->pending_email;

        if ((int) $customer->store_id !== (int) $store->id || $pending === ''
            || ! hash_equals(hash('sha256', $pending), (string) $request->query('h'))
            || self::contactTaken($store->id, $customer->id, 'email', $pending)) {
            return redirect()->route('publicView', $storeKey)
                ->with('claim_error', ___('This verification link is no longer valid.'));
        }

        $customer->forceFill(['email' => $pending, 'email_verified_at' => now(), 'pending_email' => null])->save();

        return redirect()->route('publicView', $storeKey)->with('claim_success', ___('Your email is confirmed.'));
    }

    private static function contactTaken(int $storeId, int $customerId, string $field, string $value): bool
    {
        $q = Customer::query()->where('store_id', $storeId)->where('id', '!=', $customerId);

        return $field === 'email'
            ? $q->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->exists()
            : $q->where('phone', $value)->exists();
    }

    /** P6-304: tell the current (verified) e-mail that a change was asked for. Best-effort. */
    private function noticeOldAddress(Store $store, Customer $customer, string $field, string $newValue): void
    {
        $old = trim((string) $customer->email);
        if ($old === '') {
            return;
        }
        try {
            Mail::to($old)->send(new CustomerContactChange((string) $store->title, 'notice_old', $field, CustomerContactChange::mask($field, $newValue), null, (string) $customer->name));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * google-suite Lane A (A5) — disconnect the linked social provider from the
     * signed-in customer's account. Refuses if it is the customer's ONLY sign-in
     * method (no password and no phone) to avoid locking them out.
     */
    public function unlinkSocial(Request $request, string $store): RedirectResponse
    {
        [$store, $customer] = $this->customerForRestaurant($store);

        if (empty($customer->oauth_provider) && empty($customer->oauth_uid)) {
            return redirect()->route('customer.profile', $store->slug ?? $store->id);
        }

        $hasPassword = ! empty($customer->password);
        $hasPhone = ! empty($customer->phone);
        if (! $hasPassword && ! $hasPhone) {
            app_alert_error(___('Set a password or add a phone number before disconnecting your only sign-in method.'));

            return redirect()->route('customer.profile', $store->slug ?? $store->id);
        }

        $providerLabel = ucfirst($customer->oauth_provider ?: 'social');
        $customer->forceFill(['oauth_provider' => null, 'oauth_uid' => null])->save();
        app_alert_success(___('Disconnected your :provider account.', ['provider' => $providerLabel]));

        return redirect()->route('customer.profile', $store->slug ?? $store->id);
    }

    public function storeAddress(CustomerAddressRequest $request, $store)
    {
        [$store, $customer] = $this->customerForRestaurant($store);

        // P6-294 Customer address book: a customer may keep at most 20 saved
        // addresses. The cap is enforced here (not in the FormRequest) so the
        // web endpoint keeps its legacy validation error style — a 422 JSON
        // for AJAX/JSON callers and a back()->withErrors() redirect for plain
        // form posts, matching the controller's existing behaviour.
        if ($customer->addresses()->count() >= 20) {
            $capMessage = ___('You can save a maximum of :max addresses.', ['max' => 20]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $capMessage], 422);
            }

            return back()->withErrors(['line' => $capMessage])->withInput();
        }

        $validated = $request->validated();

        $makeDefault = $request->has('is_default') || ! $customer->addresses()->exists();

        if ($makeDefault) {
            $customer->addresses()->update(['is_default' => 0]);
        }

        $customer->addresses()->create([
            'label' => $validated['label'] ?? null,
            'line' => $validated['line'],
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'zip' => $validated['zip'] ?? null,
            'country' => $validated['country'] ?? null,
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'is_default' => $makeDefault ? 1 : 0,
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => ___('Address saved successfully')]);
        }

        return redirect()->route('customer.profile', $store->id)
            ->with('success', ___('Address saved successfully'));
    }

    public function updateAddress(Request $request, string $store, CustomerAddress $address): RedirectResponse|JsonResponse
    {
        [$store, $customer] = $this->customerForRestaurant($store);

        // Tenant fence: a customer may only edit their own address.
        if ((int) $address->customer_id !== (int) $customer->id) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'label' => ['nullable', 'string', 'max:80'],
            'line' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        // Promoting this address to default demotes the others first.
        if ($request->has('is_default')) {
            $customer->addresses()->where('id', '!=', $address->id)->update(['is_default' => 0]);
        }

        $address->update([
            'label' => $request->label,
            'line' => $request->line,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'country' => $request->country,
            'lat' => $request->lat,
            'lng' => $request->lng,
            'is_default' => $request->has('is_default') ? 1 : $address->is_default,
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => ___('Address updated successfully')]);
        }

        return redirect()->route('customer.profile', $store->id)
            ->with('success', ___('Address updated successfully'));
    }

    public function destroyAddress(Request $request, $store, CustomerAddress $address)
    {
        [$store, $customer] = $this->customerForRestaurant($store);

        if ((int) $address->customer_id !== (int) $customer->id) {
            abort(403);
        }

        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $customer->addresses()->orderBy('id')->first()?->update(['is_default' => 1]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => ___('Address removed successfully')]);
        }

        return redirect()->route('customer.profile', $store->id)
            ->with('success', ___('Address removed successfully'));
    }

    public function reorder(Request $request, $store, Order $order)
    {
        [$store, $customer] = $this->customerForRestaurant($store);

        if ((int) $order->store_id !== (int) $store->id || (int) $order->customer_id !== (int) $customer->id) {
            abort(403);
        }

        $order->loadMissing(['items.menu', 'items.variant', 'items.itemExtras.extra']);

        $items = [];
        $missingItems = [];

        foreach ($order->items as $orderItem) {
            $payload = $this->buildReorderItemPayload($orderItem, $store);

            if ($payload === null) {
                $missingItems[] = $orderItem->menu->name ?? ___('Unknown Item');

                continue;
            }

            $items[] = $payload;
        }

        if (empty($items)) {
            return response()->json([
                'success' => false,
                'message' => ___('This order has items that are no longer available.'),
                'items' => [],
                'missing_items' => array_values(array_unique($missingItems)),
            ]);
        }

        $message = ___('Order added to cart.');
        if (! empty($missingItems)) {
            $message = ___('Some unavailable items were skipped.');
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'items' => $items,
            'missing_items' => array_values(array_unique($missingItems)),
            'redirect_url' => route('publicView', $store->slug),
        ]);
    }

    private function customerForRestaurant($store): array
    {
        if (! Auth::guard('customer')->check()) {
            abort(401);
        }

        $store = Store::idOrSlug($store)->firstOrFail();
        $customer = Auth::guard('customer')->user();

        if ((int) $customer->store_id !== (int) $store->id) {
            abort(403);
        }

        return [$store, $customer];
    }
}
