<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\MultiVendor;

use App\Services\Entitlements;
use App\Services\OrderApiService;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Marketplace\ShopperBag;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\City;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Store\StoreStatus;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Ordering\Models\CartLine;

/**
 * GAPS-RUN-2 B12-P1 → Marketplace P0-3: cross-store cart. Storage moved from
 * the session to DB carts/cart_lines keyed by a long-lived guest cookie token
 * (mk_cart_token, 30 days) so the cart survives session rotation; T1-2 later
 * merges a guest cart into the shopper's on login. Public API unchanged —
 * OrderHub keeps working as before. Checkout still splits into N per-store
 * orders via OrderApiService::create, each tenant-scoped, sequential,
 * report-per-store — NOT all-or-nothing.
 */
class CrossCartService
{
    public const TOKEN_COOKIE = 'mk_cart_token';

    private const TOKEN_SESSION = 'mk_cart_token';

    private const TTL_DAYS = 30;

    /** Per-request memo: restaurant id => is its owner entitled to the key. */
    private array $entitledStores = [];

    /**
     * FIX-1 F1-5 (gap audit): `multivendor_cart` is `plan_gated` in the feature
     * catalog (Premium, or the $3.99 add-on/app) — but every gate here read
     * `feature_enabled()`, which is the KILL SWITCH ONLY. The paid flag was
     * therefore dead: any store could be dropped into a cross-store bag.
     *
     * Two lanes, in the platform's standard order:
     *  1. kill veto — platform OFF wins over every grant, no context needed,
     *  2. entitlement — but the SUBJECT here is the MERCHANT, not the shopper.
     *     /order-hub and /fusion are public shopper surfaces with no session
     *     user, so "the current user's plan" is meaningless; what the catalog
     *     actually sells is a STORE's right to participate in a multi-vendor
     *     bag. So the per-store owner is the subject, checked wherever a
     *     restaurant is in scope.
     *
     * Dead end (do not retry): attaching `feature:multivendor_cart` to the
     * order-hub route group resolves the subject via `dashboard_owner()`,
     * which is null for a signed-out shopper — that 403s the entire
     * marketplace bag for every visitor.
     */
    private function guard(?int $storeId = null): void
    {
        if (! feature_enabled('multivendor_cart')) {
            throw ValidationException::withMessages(['cart' => 'Multi-vendor cart is not available.']);
        }

        if ($storeId !== null) {
            $this->assertStoreEntitled($storeId);
        }
    }

    /**
     * 403 unless this store's owner is entitled to `multivendor_cart`. A no-op
     * while the key is not `plan_gated` (pre-flip installs keep kill-only
     * behavior exactly).
     */
    public function assertStoreEntitled(int $storeId): void
    {
        if (! $this->storeEntitled($storeId)) {
            abort(403, ___('This store is not available for multi-store ordering.'));
        }
    }

    /** Is this store's owner entitled to the cross-store cart? */
    public function storeEntitled(int $storeId): bool
    {
        if (array_key_exists($storeId, $this->entitledStores)) {
            return $this->entitledStores[$storeId];
        }

        if (! EnsureFeatureEnabled::planGated('multivendor_cart')) {
            return $this->entitledStores[$storeId] = true;
        }

        // D-M5 (FM-05): on a reseller Fusion, the RESELLER covers multi-cart — every
        // store of that reseller participates and the per-merchant multivendor_cart
        // entitlement is waived. This is the ENTITLEMENT lane only; the platform kill
        // switch (guard(): feature_enabled('multivendor_cart')) still vetoes first
        // everywhere (kill ≠ entitlement — the F1-5 lesson).
        $tenant = mk_tenant();
        if ($tenant
            && app(ResellerContext::class)->entitled($tenant, 'own_fusion')
            && (int) Store::where('id', $storeId)->value('mk_reseller_id') === (int) $tenant->getKey()) {
            return $this->entitledStores[$storeId] = true;
        }

        $ownerId = (int) Store::where('id', $storeId)->value('user_id');
        $owner = $ownerId ? User::find($ownerId) : null;

        return $this->entitledStores[$storeId] = (bool) $owner
            && Entitlements::allows($owner, 'multivendor_cart');
    }

    /**
     * The guest cart token: request cookie first (survives new sessions),
     * session fallback (first request before the cookie round-trips), else
     * mint one and queue the cookie.
     */
    public function token(): string
    {
        $token = (string) (request()->cookie(self::TOKEN_COOKIE) ?: session(self::TOKEN_SESSION, ''));
        if ($token === '') {
            $token = Str::random(40);
        }
        session([self::TOKEN_SESSION => $token]);
        Cookie::queue(Cookie::make(self::TOKEN_COOKIE, $token, self::TTL_DAYS * 24 * 60));

        return $token;
    }

    /**
     * T1-2: cart ownership scope. A guest owns carts by the cookie token; an
     * authenticated shopper (guard 'shopper') owns carts by shopper_id OR the
     * current token, so a fresh device's guest additions and the durable
     * account carts resolve together.
     */
    private function scopeOwned($query)
    {
        $token = $this->token();
        $shopperId = app(ShopperBag::class)->shopperId(); // P1-13 13d: guard 'shopper' is the Marketplace module's
        if ($shopperId) {
            // P6-262: a signed-in shopper owns carts by shopper_id ONLY. Any guest carts this device built before
            // sign-in (or a remember-me resume that never hit the OTP verify merge) are folded in first, so one
            // store never has two carts and a device token can never reach shopper-owned rows.
            $this->absorbGuestCarts($shopperId, $token, mk_tenant_id());
            $query->where('shopper_id', $shopperId);
        } else {
            $query->where('guest_token', $token)->whereNull('shopper_id');
        }

        // FM-03: fence carts to the current marketplace tenant (0 = platform). A token
        // replayed on another host resolves a disjoint set of carts — no resurrection.
        return $query->where('mk_reseller_id', mk_tenant_id());
    }

    /**
     * Total item quantity across the owner's live carts — the number the nav
     * bag badge renders server-side.
     *
     * READ-ONLY on purpose, and therefore NOT built on scopeOwned(): that
     * calls token(), which mints a guest token and queues a cart cookie. The
     * marketplace layout renders on every page, so routing the badge through
     * token() would hand a cart cookie to every visitor who never opened a
     * cart, just to be told the badge is empty. With no existing token and no
     * signed-in shopper there is nothing to count, so return 0 and touch
     * nothing.
     */
    public function itemCount(): int
    {
        $token = (string) (request()->cookie(self::TOKEN_COOKIE) ?: session(self::TOKEN_SESSION, ''));
        $shopperId = app(ShopperBag::class)->shopperId(); // P1-13 13d: guard 'shopper' is the Marketplace module's

        if ($token === '' && ! $shopperId) {
            return 0;
        }

        $carts = Cart::live()->where(function ($q) use ($token, $shopperId) {
            if ($shopperId) {
                $q->where('shopper_id', $shopperId);
            }
            // An empty token must never widen the match — carts are stored with
            // a real token, but a stray empty-string row would otherwise leak
            // into every anonymous visitor's count.
            if ($token !== '') {
                // P6-262: only UNCLAIMED guest rows answer to a device token; shopper rows answer to shopper_id.
                $guest = fn ($g) => $g->where('guest_token', $token)->whereNull('shopper_id');
                $shopperId ? $q->orWhere($guest) : $q->where($guest);
            }
        })->where('mk_reseller_id', mk_tenant_id()); // FM-03: badge counts this tenant only

        return (int) CartLine::whereIn('cart_id', $carts->select('id'))->sum('quantity');
    }

    /** @return array<int, array<int, array{line_id:int, item_id:int, quantity:int}>> rid => lines */
    public function cart(): array
    {
        $out = [];
        // Bag order = the order stores were added (id). Without it PostgreSQL returns an UPDATEd cart row last (setDonation /
        // setCutlery update every cart), which moved "the first store" — the one the donation rides on — at checkout.
        $carts = $this->scopeOwned(Cart::live())->with('lines')->orderBy('id')->get();
        foreach ($carts as $cart) {
            $lines = [];
            foreach ($cart->lines as $line) {
                // P3.8: line_id lets the bag target ONE configuration of an item
                // when the same menu_id sits in the bag twice with different
                // selections. checkout() ignores it (OrderApiService never sees it).
                $row = ['line_id' => (int) $line->id, 'item_id' => (int) $line->menu_id, 'quantity' => (int) $line->quantity];
                if ($line->note !== null && $line->note !== '') {
                    $row['note'] = $line->note;
                }
                if ($line->variation !== null && $line->variation !== '') {
                    $row['variation'] = $line->variation;
                }
                if (! empty($line->options)) {
                    $row['options'] = $line->options;
                }
                if (! empty($line->extras)) {
                    $row['extras'] = $line->extras;
                }
                $lines[] = $row;
            }
            if ($lines) {
                // P6-262: never overwrite — a second cart for the same store must not make lines vanish from the bag.
                $out[(int) $cart->store_id] = array_merge($out[(int) $cart->store_id] ?? [], $lines);
            }
        }

        return $out;
    }

    /**
     * P3.8: a cart line is (menu_id + normalized selection signature) — the SAME
     * item with a different variant/options/extras is a SECOND line, the same
     * item with an identical configuration increments the first. Selections are
     * re-validated against THIS item server-side (never trust the client) and
     * stored in the exact shape OrderApiService::resolveLines expects, so
     * checkout() forwards them verbatim.
     *
     * @param  ?int  $variation  MenuVariant id
     * @param  array<int, array{group_id:int, selected:array<int,string>}>  $options  choice groups (LABELS)
     * @param  array<int, int>  $extras  MenuExtra ids
     */
    public function addItem(
        int $storeId,
        int $itemId,
        int $quantity = 1,
        ?string $note = null,
        ?int $variation = null,
        array $options = [],
        array $extras = []
    ): void {
        $this->guard($storeId);
        $menu = $this->orderableMenu($storeId, $itemId);
        $this->assertSingleCurrency($storeId);
        $sel = $this->resolveSelections($menu, $variation, $options, $extras);

        $cart = $this->storeCart($storeId);
        $qty = max(1, $quantity);

        $line = $this->matchingLine($cart, (int) $menu->id, $sel);
        if ($line) {
            $line->quantity += $qty;
            if ($note !== null) {
                $line->note = $note;
            }
            $line->save();
        } else {
            $cart->lines()->create([
                'menu_id' => $menu->id,
                'quantity' => $qty,
                'note' => $note,
                'variation' => $sel['variation'],
                'options' => $sel['options'] ?: null,
                'extras' => $sel['extras'] ?: null,
            ]);
        }
        $cart->touch();
    }

    /**
     * Set a line's absolute quantity (stepper semantics); qty <= 0 removes it.
     * A non-null $note also sets/replaces the line's special request; null leaves
     * any existing note untouched.
     *
     * $lineId targets ONE exact line (P3.8, needed once an item can sit in the
     * bag under several configurations). Null keeps the pre-P3.8 behaviour —
     * first line matching menu_id, creating a bare line if there is none — so
     * the existing bag steppers are untouched.
     */
    public function setItem(int $storeId, int $itemId, int $quantity, ?string $note = null, ?int $lineId = null): void
    {
        $this->guard($storeId);

        if ($quantity <= 0) {
            $this->removeItem($storeId, $itemId, $lineId);

            return;
        }

        $menu = $this->orderableMenu($storeId, $itemId);
        $this->assertSingleCurrency($storeId);

        $cart = $this->storeCart($storeId);
        $line = $lineId
            ? $cart->lines()->whereKey($lineId)->first()
            : $cart->lines()->where('menu_id', $menu->id)->first();

        if (! $line && $lineId) {
            throw ValidationException::withMessages(['cart' => ___('That item is no longer in your bag.')]);
        }

        if ($line) {
            $line->quantity = $quantity;
            if ($note !== null) {
                $line->note = $note;
            }
            $line->save();
        } else {
            $cart->lines()->create(['menu_id' => $menu->id, 'quantity' => $quantity, 'note' => $note]);
        }
        $cart->touch();
    }

    /**
     * $lineId removes ONE configuration; without it every line of that item goes
     * (pre-P3.8 behaviour — one item, one line).
     */
    public function removeItem(int $storeId, int $itemId, ?int $lineId = null): void
    {
        $this->guard();

        $cart = $this->scopeOwned(Cart::live())
            ->where('store_id', $storeId)
            ->first();
        if (! $cart) {
            return;
        }
        if ($lineId) {
            $cart->lines()->whereKey($lineId)->delete();
        } else {
            $cart->lines()->where('menu_id', $itemId)->delete();
        }
        if ($cart->lines()->count() === 0) {
            $cart->delete();
        }
    }

    /**
     * The line in $cart carrying this item under this exact configuration, or
     * null. Compared on the normalized signature, not raw column equality, so a
     * client that posts extras/labels/groups in a different order does not fork
     * a duplicate line.
     */
    private function matchingLine(Cart $cart, int $menuId, array $sel): ?CartLine
    {
        $sig = $this->signature($sel['variation'], $sel['options'], $sel['extras']);

        return $cart->lines()->where('menu_id', $menuId)->get()
            ->first(fn (CartLine $l) => $this->signature($l->variation, $l->options, $l->extras) === $sig);
    }

    /**
     * Canonical selection signature. Normalization rules (both sides of every
     * comparison go through this, including values already on a stored line):
     *  - variation: null / '' / 0 all collapse to 0 (no variant);
     *  - extras: cast to int, de-duplicated, sorted ascending;
     *  - options: empty selections dropped, labels sorted within a group,
     *    groups sorted by group_id, a group posted twice keeps the last one
     *    (matching OrderApiService::resolveOptions' own indexing).
     */
    private function signature($variation, $options, $extras): string
    {
        $extraIds = array_values(array_unique(array_map('intval', (array) ($extras ?: []))));
        sort($extraIds);

        $groups = [];
        foreach ((array) ($options ?: []) as $group) {
            $group = (array) $group;
            $gid = (int) ($group['group_id'] ?? 0);
            $selected = array_values(array_filter(
                array_map(fn ($label) => (string) $label, (array) ($group['selected'] ?? [])),
                fn ($label) => $label !== ''
            ));
            sort($selected, SORT_STRING);
            if ($gid <= 0 || ! $selected) {
                continue;
            }
            $groups[$gid] = $selected;
        }
        ksort($groups);

        return (string) json_encode([
            'v' => (int) ($variation ?: 0),
            'o' => array_map(fn ($gid, $sel) => ['group_id' => $gid, 'selected' => $sel], array_keys($groups), $groups),
            'e' => $extraIds,
        ]);
    }

    /**
     * Validate posted selections against THIS menu item and return them
     * normalized (canonical order) for storage. Ids that belong to another item
     * — or labels that are not in their group's option list — throw, so a
     * tampered payload never reaches checkout. Required/min/max are NOT enforced
     * here: that is checkout's job (OrderApiService::resolveOptions), and
     * enforcing it at add-time would break reorder/quick-add of items whose
     * groups became required after the fact.
     *
     * @return array{variation: ?int, options: array<int, array{group_id:int, selected:array<int,string>}>, extras: array<int,int>}
     */
    private function resolveSelections(Menu $menu, ?int $variation, array $options, array $extras): array
    {
        $variantId = null;
        if ($variation) {
            $variant = MenuVariant::where('menu_id', $menu->id)->find($variation);
            if (! $variant) {
                throw ValidationException::withMessages(['variation' => ___('That option is not available for this item.')]);
            }
            $variantId = (int) $variant->id;
        }

        $extraIds = [];
        if ($extras) {
            $available = $menu->resolved_extras;
            foreach ($extras as $extraId) {
                $extra = $available->firstWhere('id', (int) $extraId);
                if (! $extra) {
                    throw ValidationException::withMessages(['extras' => ___('That add-on is not available for this item.')]);
                }
                $extraIds[] = (int) $extra->id;
            }
        }

        $selections = [];
        if ($options) {
            $groups = $menu->resolved_variant_options;
            foreach ($options as $posted) {
                $posted = (array) $posted;
                $gid = (int) ($posted['group_id'] ?? 0);
                $group = $groups->firstWhere('id', $gid);
                if (! $group) {
                    throw ValidationException::withMessages(['options' => ___('That choice is not available for this item.')]);
                }
                // Same decode as resolveOptions: object/array → plain label list.
                $labels = array_values((array) json_decode(json_encode($group->options), true));
                $selected = [];
                foreach ((array) ($posted['selected'] ?? []) as $label) {
                    $label = (string) $label;
                    if ($label === '') {
                        continue;
                    }
                    if (array_search($label, $labels, true) === false) {
                        throw ValidationException::withMessages([
                            'options' => ___('“:option” is not available for :group.', ['option' => $label, 'group' => $group->title]),
                        ]);
                    }
                    $selected[] = $label;
                }
                if ($selected) {
                    $selections[] = ['group_id' => $gid, 'selected' => $selected];
                }
            }
        }

        // Round-trip through the signature normalizer so what is STORED is
        // already canonical — a stored line and a fresh payload then compare equal.
        $canonical = json_decode($this->signature($variantId, $selections, $extraIds), true);

        return [
            'variation' => $variantId,
            'options' => $canonical['o'],
            'extras' => $canonical['e'],
        ];
    }

    /**
     * Human-readable resolution of one cart line's selections, for the bag UI:
     * labels + prices for the chosen variant / options / extras, plus the unit
     * price they add up to. Mirrors BagQuote::priceLines' math (variant price is
     * ABSOLUTE, options and extras are ADDITIVE) so the bag and the quote agree.
     *
     * @param  array  $line  a row from cart()
     * @return array{variant: ?array, options: array<int, array>, extras: array<int, array>, summary: string, unit_price: float}
     */
    public function describeLine(Menu $menu, array $line): array
    {
        // Bug batch 3 · A: prices in the STORE currency's decimals (the order keeps fils / whole yen), never a flat 2.
        $dec = money_decimals(store_currency((int) $menu->store_id));
        $unit = (float) $menu->price;
        $variant = null;
        if (! empty($line['variation'])) {
            $row = MenuVariant::where('menu_id', $menu->id)->find($line['variation']);
            if ($row) {
                $unit = (float) $row->price;
                $variant = ['id' => (int) $row->id, 'label' => $this->variantLabel($row, $menu), 'price' => Money::round((float) $row->price, $dec)];
            }
        }

        $optionRows = [];
        if (! empty($line['options'])) {
            $groups = $menu->resolved_variant_options->keyBy('id');
            foreach ((array) $line['options'] as $posted) {
                $posted = (array) $posted;
                $group = $groups->get((int) ($posted['group_id'] ?? 0));
                if (! $group) {
                    continue;
                }
                $labels = array_values((array) json_decode(json_encode($group->options), true));
                $prices = (array) ($group->option_prices ?? []);
                foreach ((array) ($posted['selected'] ?? []) as $label) {
                    $idx = array_search((string) $label, $labels, true);
                    if ($idx === false) {
                        continue;
                    }
                    $price = Money::round((float) ($prices[$idx] ?? 0), $dec);
                    $unit += $price;
                    $optionRows[] = ['group_id' => (int) $group->id, 'group' => $group->title, 'label' => (string) $label, 'price' => $price];
                }
            }
        }

        $extraRows = [];
        if (! empty($line['extras'])) {
            $available = $menu->resolved_extras;
            foreach ((array) $line['extras'] as $extraId) {
                $extra = $available->firstWhere('id', (int) $extraId);
                if (! $extra) {
                    continue;
                }
                $price = Money::round((float) $extra->price, $dec);
                $unit += $price;
                $extraRows[] = ['id' => (int) $extra->id, 'label' => $extra->title, 'price' => $price];
            }
        }

        $summary = array_merge(
            $variant && $variant['label'] !== '' ? [$variant['label']] : [],
            array_column($optionRows, 'label'),
            array_column($extraRows, 'label')
        );

        return [
            'variant' => $variant,
            'options' => $optionRows,
            'extras' => $extraRows,
            'summary' => implode(' · ', $summary),
            'unit_price' => Money::round($unit, $dec),
        ];
    }

    /** A priced variant combo's display label: its option values, e.g. "Large / Thin". */
    /**
     * A variant's `options` JSON maps groupId => the INDEX of the chosen option
     * within that group's `options` array — not the label. Reading the values
     * directly yields a bare digit ("2 · وسط"), so each index must be resolved
     * through its group. Mirrors ProductController::variants() so the bag and
     * the product page name the same variant identically.
     */
    private function variantLabel(MenuVariant $variant, ?Menu $menu = null): string
    {
        $groups = $menu ? $menu->resolved_variant_options : collect();
        $parts = [];

        foreach ((array) json_decode(json_encode($variant->options), true) as $groupId => $index) {
            $group = $groups->firstWhere('id', (int) $groupId);
            $choices = $group ? array_values((array) $group->options) : [];
            if (isset($choices[$index]) && (string) $choices[$index] !== '') {
                $parts[] = (string) $choices[$index];
            } elseif (is_scalar($index) && ! is_numeric($index) && (string) $index !== '') {
                // Legacy rows that stored the label itself rather than an index.
                $parts[] = (string) $index;
            }
        }

        return $parts ? implode(' · ', $parts) : (($variant->name ?? null) ?: ___('Option'));
    }

    /**
     * T3-2: persist the per-store fulfillment choice on the cart row.
     * $mode: 'delivery' | 'pickup' | 'dinein'; $scheduledAt: 'Y-m-d H:i' slot or
     * null (ASAP). Slot validity (window/grid/lead) is the caller's job
     * (BagController via SlotService) — this only stores the choice.
     * $tableNumber only survives for dine-in (a mode flip away clears it, so a
     * stale table can never ride into a delivery order).
     */
    public function setFulfillment(int $storeId, string $mode, ?string $scheduledAt = null, ?string $tableNumber = null): void
    {
        $this->guard($storeId);

        $cart = $this->scopeOwned(Cart::live())
            ->where('store_id', $storeId)
            ->first();
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => ___('No items from this store in your bag.')]);
        }
        $cart->fulfillment_mode = in_array($mode, ['delivery', 'pickup', 'dinein'], true) ? $mode : 'delivery';
        $cart->scheduled_at = $scheduledAt;
        $cart->table_number = $cart->fulfillment_mode === 'dinein' && $tableNumber !== null && trim($tableNumber) !== ''
            ? mb_substr(trim($tableNumber), 0, 16)
            : null;
        $cart->save();
    }

    /**
     * T3-2: per-store fulfillment map for the quote.
     *
     * @return array<int, array{mode: string, scheduled_at: ?string}> rid => choice
     */
    public function fulfillmentFor(): array
    {
        $out = [];
        foreach ($this->scopeOwned(Cart::live())->orderBy('id')->get() as $cart) {
            $out[(int) $cart->store_id] = [
                'mode' => $cart->fulfillment_mode ?: 'delivery',
                'scheduled_at' => $cart->scheduled_at?->format('Y-m-d H:i'),
                'table_number' => $cart->table_number,
            ];
        }

        return $out;
    }

    /**
     * T3-3: per-store tip. Delivery orders only — a pickup row throws (the
     * courier the tip is for does not exist). $amount is the ABSOLUTE tip the
     * shopper picked (the UI's % chips resolve to an amount client-side and
     * the server just stores what was posted, clamped >= 0); 0/null clears it.
     */
    public function setTip(int $storeId, ?float $amount): void
    {
        $this->guard($storeId);

        $cart = $this->scopeOwned(Cart::live())
            ->where('store_id', $storeId)
            ->first();
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => ___('No items from this store in your bag.')]);
        }
        if (($cart->fulfillment_mode ?? 'delivery') !== 'delivery' && $amount > 0) {
            throw ValidationException::withMessages(['tip' => ___('Tips are for delivery orders.')]);
        }
        $cart->tip_amount = $amount > 0 ? Money::round((float) $amount, money_decimals(store_currency($storeId))) : null;
        $cart->save();
    }

    /**
     * T3-3: global cutlery toggle → written uniformly to EVERY live row so
     * each store's order carries its own flag. Eco-default OFF (no cutlery).
     */
    public function setCutlery(bool $on): void
    {
        $this->guard();
        $this->scopeOwned(Cart::live())->update(['cutlery' => $on]);
    }

    /**
     * T3-3: donation round-up — whole-bag choice, stored uniformly on every
     * cart row (see the T3-3 migration docblock for the decision). Only the
     * FLAG is stored; the rounding delta is recomputed from the live quote at
     * render and at checkout, so it never goes stale as the bag changes.
     */
    public function setDonation(bool $on): void
    {
        $this->guard();
        $this->scopeOwned(Cart::live())->update(['donation' => $on]);
    }

    /**
     * 4.3: persist the coupon a shopper applied for ONE store. Only the CODE
     * (+ the id it resolved to) is stored — never a discount amount: the
     * discount is recomputed from the live subtotal on every quote and again,
     * from scratch, at checkout. Validation is the caller's job
     * (BagController via CouponService, the canonical validator); this only
     * records the choice, mirroring setFulfillment/setTip.
     */
    public function setCoupon(int $storeId, ?string $code, ?int $couponId = null): void
    {
        $this->guard($storeId);

        $cart = $this->scopeOwned(Cart::live())
            ->where('store_id', $storeId)
            ->first();
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => ___('No items from this store in your bag.')]);
        }
        $code = $code !== null ? trim($code) : null;
        $cart->coupon_code = ($code === null || $code === '') ? null : $code;
        $cart->coupon_id = $cart->coupon_code === null ? null : $couponId;
        $cart->save();
    }

    /** 4.3: drop the store's coupon. A missing cart row is a no-op (nothing to clear). */
    public function clearCoupon(int $storeId): void
    {
        $this->guard();

        $cart = $this->scopeOwned(Cart::live())
            ->where('store_id', $storeId)
            ->first();
        if (! $cart) {
            return;
        }
        $cart->coupon_code = null;
        $cart->coupon_id = null;
        $cart->save();
    }

    /**
     * 4.3: per-store applied coupon map. Codes only — the discount they are
     * worth is derived by the caller against the CURRENT subtotal.
     *
     * @return array<int, array{code: string, coupon_id: ?int}> rid => coupon
     */
    public function couponsFor(): array
    {
        $out = [];
        foreach ($this->scopeOwned(Cart::live())->orderBy('id')->get() as $cart) {
            $code = trim((string) $cart->coupon_code);
            if ($code === '') {
                continue;
            }
            $out[(int) $cart->store_id] = [
                'code' => $code,
                'coupon_id' => $cart->coupon_id !== null ? (int) $cart->coupon_id : null,
            ];
        }

        return $out;
    }

    /**
     * T3-3: per-store extras map for the quote.
     *
     * @return array<int, array{tip_amount: ?float, cutlery: bool, donation: bool}>
     */
    public function extrasFor(): array
    {
        $out = [];
        foreach ($this->scopeOwned(Cart::live())->orderBy('id')->get() as $cart) {
            $out[(int) $cart->store_id] = [
                'tip_amount' => $cart->tip_amount !== null ? Money::round((float) $cart->tip_amount, money_decimals(store_currency((int) $cart->store_id))) : null,
                'cutlery' => (bool) $cart->cutlery,
                'donation' => (bool) $cart->donation,
            ];
        }

        return $out;
    }

    public function clear(): void
    {
        $ids = $this->scopeOwned(Cart::query())->pluck('id');
        CartLine::whereIn('cart_id', $ids)->delete();
        Cart::whereIn('id', $ids)->delete();
    }

    /** FM-07: clear ONE store's mini-cart (row + lines); the others are untouched. */
    public function clearStore(int $storeId): void
    {
        $ids = $this->scopeOwned(Cart::query())->where('store_id', $storeId)->pluck('id');
        CartLine::whereIn('cart_id', $ids)->delete();
        Cart::whereIn('id', $ids)->delete();
    }

    /** FM-08: the header badge count — number of NON-EMPTY mini-carts (not items). */
    public function cartCount(): int
    {
        $token = (string) (request()->cookie(self::TOKEN_COOKIE) ?: session(self::TOKEN_SESSION, ''));
        $shopperId = app(ShopperBag::class)->shopperId(); // P1-13 13d: guard 'shopper' is the Marketplace module's
        if ($token === '' && ! $shopperId) {
            return 0;
        }

        $carts = Cart::live()->where(function ($q) use ($token, $shopperId) {
            if ($shopperId) {
                $q->where('shopper_id', $shopperId);
            }
            if ($token !== '') {
                // P6-262: only UNCLAIMED guest rows answer to a device token; shopper rows answer to shopper_id.
                $guest = fn ($g) => $g->where('guest_token', $token)->whereNull('shopper_id');
                $shopperId ? $q->orWhere($guest) : $q->where($guest);
            }
        })->where('mk_reseller_id', mk_tenant_id());

        // Only carts that still hold a line count toward the badge.
        return (int) $carts->whereHas('lines')->count();
    }

    /**
     * Split-checkout: one OrderApiService::create per store. Sequential, per-store
     * result. A store that succeeds is removed from the cart; a failed store's lines
     * stay so the customer can retry.
     *
     * 4.7 wallet v1: $useCredit is a per-store BOOLEAN intent map (rid => bool) —
     * "spend my credit at this store". Never an amount: the balance is re-derived
     * server-side at order time by OrderPricing/CustomerWalletService, so a stale
     * or inflated client number cannot over-spend. Credit only applies where the
     * shopper has an EXISTING (or just-linked) customer at that store, and a
     * Customer row is restaurant-scoped, so store A's credit can never reach
     * store B's sub-order.
     *
     * @param  array<int|string, bool>  $useCredit  rid => spend-my-credit-here
     * @return array<int, array{store_id:int, ok:bool, order_id:?int, error:?string, coupon_code:?string, coupon_applied:bool, discount:float, wallet_applied:float}>
     */
    /**
     * Bug batch 4 · 3: store id => the delivery fee the bag quotes at the contact's pin (0.0 when the bag quoted
     * none for a delivery row); [] without a marketplace context or when the quote fails.
     *
     * @param  array<string, mixed>  $contact
     * @return array<int, float>
     */
    private function quotedDeliveryFees(array $contact): array
    {
        if (! app()->bound('mk.ctx')) {
            return [];
        }
        try {
            $quote = app(ShopperBag::class)->quote($this,
                isset($contact['lat']) && is_numeric($contact['lat']) ? (float) $contact['lat'] : null,
                isset($contact['lng']) && is_numeric($contact['lng']) ? (float) $contact['lng'] : null,
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $fees = [];
        foreach ($quote['stores'] ?? [] as $store) {
            if (($store['mode'] ?? null) === 'delivery') {
                $fees[(int) $store['store_id']] = (float) ($store['fee']['amount'] ?? 0);
            }
        }

        return $fees;
    }

    /**
     * FM-02: place orders for the bag. When $onlyRestaurantIds is given, only those
     * mini-carts are checked out — the rest stay untouched (per-cart checkout). null =
     * the combined "checkout all" path (unchanged behaviour). Each placed cart's
     * client_uuid dedups a double-tapped submit at OrderApiService.
     *
     * @param  array<string,mixed>  $contact
     * @param  array<int|string,mixed>  $useCredit
     * @param  array<int>|null  $onlyRestaurantIds
     * @return array<int, array{store_id:int, ok:bool, order_id:int|null, error:string|null, coupon_code:string|null, coupon_applied:bool, discount:float, wallet_applied:float}>
     */
    public function checkout(array $contact, array $useCredit = [], ?array $onlyRestaurantIds = null): array
    {
        $this->guard();

        $cart = $this->cart();

        if ($onlyRestaurantIds !== null) {
            $only = array_map('intval', $onlyRestaurantIds);
            $cart = array_filter($cart, fn ($rid) => in_array((int) $rid, $only, true), ARRAY_FILTER_USE_KEY);
        }
        // P6-105: a bag that mixes charge currencies (built before the add-time gate) is refused whole — no store's
        // order is placed, nothing is converted; the shopper checks out one currency at a time ($onlyRestaurantIds).
        $bagCurrencies = array_values(array_unique(array_map(fn ($rid) => self::chargeCurrency((int) $rid), array_keys($cart))));
        if (count($bagCurrencies) > 1) {
            throw ValidationException::withMessages([
                'cart' => ___('Your bag has items in :a. Empty it or check out first to order in :b.', ['a' => $bagCurrencies[0], 'b' => $bagCurrencies[1]]),
            ]);
        }
        $results = [];
        /** @var object|null $shopper T1-2: logged-in shopper gets a per-store customer link (Marketplace, ee). */
        $shopper = app(ShopperBag::class)->shopper();

        // 4.7: normalize the credit intent to int-keyed booleans — a JSON/form
        // body hands us string keys and "1"/"0"/"true" values.
        $wantsCredit = [];
        foreach ($useCredit as $rid => $on) {
            $wantsCredit[(int) $rid] = filter_var($on, FILTER_VALIDATE_BOOLEAN);
        }

        // T3-3: donation delta, computed ONCE from the live quote (never a stale
        // snapshot). Payments A has no platform custody, so the whole amount
        // rides on the FIRST store's order (single-store bag ≡ that store);
        // Phase B revisits with custody. Quote needs mk.ctx — the legacy
        // OrderHub path has none, so donation degrades to 0 there (honest: the
        // toggle only exists on the Fusion bag, which always has ctx).
        $extras = $this->extrasFor();
        $donationAmount = 0.0;
        if (collect($extras)->contains(fn ($e) => $e['donation'])) {
            try {
                // Bug batch 3 · B: the couponed bag at the shopper's pin — the donation the bag SHOWED (it is
                // rounded up from the coupon-discounted, taxed grand total), not one from an uncouponed quote.
                $summary = app(ShopperBag::class)->quoteWithCoupons(
                    isset($contact['lat']) && is_numeric($contact['lat']) ? (float) $contact['lat'] : null,
                    isset($contact['lng']) && is_numeric($contact['lng']) ? (float) $contact['lng'] : null,
                )['summary'] ?? [];
                $donationAmount = (float) ($summary['donation']['amount'] ?? 0);
            } catch (\Throwable $e) {
                $donationAmount = 0.0;
            }
        }
        $donationPending = $donationAmount > 0;

        // Bug batch 4 · 3: the delivery fee each DELIVERY store's order charges is the one the bag quoted at the
        // shopper's pin (the marketplace zone fee) — before, the order silently charged the store's own
        // restaurant_delivery_charge instead. Pre-coupon on purpose: a free-delivery coupon waives it at
        // settlement (OrderPricing → Order::deliveryCharge()), exactly as the bag waives it. A delivery row the bag
        // quoted no fee for (no zone) showed a total without one, so it is charged none. No marketplace context
        // (the legacy OrderHub path) → no quote → the store's own delivery charge, as before.
        $quotedFees = $this->quotedDeliveryFees($contact);

        foreach ($cart as $storeId => $lines) {
            $storeId = (int) $storeId;
            $ownerId = (int) Store::where('id', $storeId)->value('user_id');
            $storeDonation = 0.0; // reset OUTSIDE try — the catch reads it
            try {
                /* F1-5: the paid gate, per store. Checkout is already
                   per-store-fault-tolerant, so an unentitled store fails
                   alone (its lines stay in the bag) rather than sinking the
                   whole multi-store checkout. */
                if (! $this->storeEntitled($storeId)) {
                    throw ValidationException::withMessages([
                        'store' => ___('This store is not available for multi-store ordering.'),
                    ]);
                }

                $customerId = $shopper ? app(ShopperBag::class)->linkStoreCustomer($shopper, $storeId, $contact) : null;

                // T3-2: forward the cart row's fulfillment choice.
                // - mode → orders.type vocabulary: pickup→'takeaway', delivery→'delivery'
                //   (no stored mode keeps the caller's type, legacy OrderHub behaviour).
                // - scheduled slot → OrderApiService's 'scheduled_at_trusted' path, which
                //   sets orders.scheduled_at + fire_at WITHOUT the per-restaurant
                //   SchedulingService::validate (that path throws unless the store opted
                //   into allow_order_scheduling; marketplace slots were already validated
                //   against branch hours by SlotService at selection time). A slot that
                //   went stale (now past) degrades to ASAP rather than failing the order.
                $cartRow = $this->scopeOwned(Cart::live())
                    ->where('store_id', $storeId)->first();

                // T2-3×T3-2 closed gate: a closed store's order only goes
                // through with a valid FUTURE scheduled slot; ASAP fails this
                // store alone (its lines stay in the bag for rescheduling).
                if (! StoreStatus::isOpen($storeId)
                    && ! ($cartRow?->scheduled_at?->isFuture())) {
                    throw ValidationException::withMessages([
                        'store' => ___('This store is closed right now. Pick a later time slot to order.'),
                    ]);
                }
                $mode = $cartRow?->fulfillment_mode;
                $type = match ($mode) {
                    'pickup' => 'takeaway',
                    'dinein' => 'on-table',
                    'delivery' => 'delivery',
                    default => $contact['type'] ?? 'takeaway',
                };
                $scheduledAt = null;
                if ($cartRow?->scheduled_at && $cartRow->scheduled_at->isFuture()) {
                    $scheduledAt = $cartRow->scheduled_at->format('Y-m-d H:i:s');
                }

                // T3-3 forwarding:
                //  - tip → orders.tip_amount (existing column; Order total includes
                //    it). Delivery rows only — a pickup row forwards 0 even if a
                //    stale tip survived a late mode flip.
                //  - donation → orders.donation_amount on the FIRST store order
                //    only (see above); Order total includes it.
                //  - cutlery + donation NOTE → orders.message ('note'): orders has
                //    no meta/json column, so the order-level note is the honest
                //    meta path for the kitchen-facing flags. Eco-default: silence
                //    means NO cutlery — only an ON toggle writes a note line.
                $storeExtras = $extras[$storeId] ?? ['tip_amount' => null, 'cutlery' => false, 'donation' => false];
                // Courier tips ride on DELIVERY orders only — takeaway has no
                // courier and dine-in ('on-table') doesn't either.
                $tip = ($type === 'delivery' && $storeExtras['tip_amount'] > 0)
                    ? Money::round((float) $storeExtras['tip_amount'], money_decimals(store_currency($storeId))) : 0.0;
                if ($donationPending) {
                    // P6-105: in the store's own currency decimals (the bag is single-currency, see above).
                    $storeDonation = Money::round($donationAmount, money_decimals(store_currency($storeId)));   // first store in the loop
                    $donationPending = false;
                }
                $noteParts = [];
                if ($storeExtras['cutlery']) {
                    $noteParts[] = ___('Include cutlery');
                }
                if ($storeDonation > 0) {
                    $noteParts[] = ___('Includes round-up donation of :amount', ['amount' => number_format($storeDonation, currency_decimals(store_currency($storeId)))]);
                }

                // T3-4: forward the shopper's delivery address + pin. OrderApiService
                // stores 'address' for delivery orders only; the lat/lng columns
                // (orders.delivery_lat/lng) are set right after create — the same
                // pin the BagQuote fee math used, so driver dispatch and the fee
                // the shopper saw agree on the destination.
                // 4.3: the per-store coupon travels as INTENT only. OrderPricing
                // (behind OrderApiService) re-resolves the code from scratch against
                // the persisted order's own subtotal/customer/branch and only then
                // records discount_amount + coupon_id and increments `used`. So a
                // coupon that expired, hit its limit, or no longer clears the min
                // between apply and place simply does not apply — there is no path
                // by which a stale row discounts an order.
                // 4.7 wallet v1: store credit is a PAYMENT, not a discount — it
                // lowers orders.amount_due via orders.wallet_applied and leaves
                // the order total alone. Like the coupon above it travels as
                // INTENT only: `use_wallet` with NO `wallet_amount`, so
                // OrderPricing re-reads the live balance from the ledger and
                // CustomerWalletService::applyToOrder caps the spend at
                // min(balance, order remaining). There is no path by which a
                // client-supplied figure sets the amount. Credit needs an
                // identified customer at THIS store (account-bound, D3), which is
                // exactly $customerId — null for a guest, so a guest simply
                // spends nothing.
                $useStoreCredit = $customerId !== null && ! empty($wantsCredit[$storeId]);

                $order = OrderApiService::create($ownerId, $storeId, [
                    // FM-02: the cart's dedup token — a double-tap resolves to the same order.
                    'client_uuid' => $cartRow?->client_uuid,
                    'type' => $type,
                    // Dine-in: the table picked in the bag; OrderApiService only
                    // persists it for 'on-table' orders.
                    'table_number' => $type === 'on-table' ? $cartRow?->table_number : null,
                    'coupon_code' => $cartRow?->coupon_code ?: null,
                    'use_wallet' => $useStoreCredit,
                    'address' => $contact['address'] ?? null,
                    'scheduled_at_trusted' => $scheduledAt,
                    'tip_amount' => $tip,
                    'donation_amount' => $storeDonation,
                    'delivery_fee_trusted' => $type === 'delivery' ? ($quotedFees[$storeId] ?? null) : null,
                    'note' => $noteParts ? implode(' · ', $noteParts) : null,
                    'items' => array_map(fn ($l) => array_filter([
                        'item_id' => (int) $l['item_id'],
                        'quantity' => (int) $l['quantity'],
                        // Per-line special request rides through to OrderItem.note.
                        'note' => isset($l['note']) && $l['note'] !== '' ? (string) $l['note'] : null,
                        'variation' => $l['variation'] ?? null,
                        'options' => $l['options'] ?? null,
                        'extras' => $l['extras'] ?? null,
                    ], fn ($v) => $v !== null), $lines),
                    'customer_name' => $contact['name'] ?? $shopper?->name,
                    'phone' => $contact['phone'] ?? $shopper?->phone,
                    'customer_id' => $customerId,
                    'source' => 'marketplace',
                    // The marketplace's zones + fleet deliver it (marketplace zone fee) — not the store's own
                    // delivery, so the store plan's `delivery` key does not apply (pricing follow-ups).
                    'delivery_by_partner' => true,
                ]);
                if ($type === 'delivery'
                    && isset($contact['lat'], $contact['lng'])
                    && is_numeric($contact['lat']) && is_numeric($contact['lng'])) {
                    $order->forceFill([
                        'delivery_lat' => (float) $contact['lat'],
                        'delivery_lng' => (float) $contact['lng'],
                    ])->save();
                }

                /* A2 vesting: loyalty accrual is NOT done here. The order's payment
                   method + final due (after the Fusion wallet) are only known in
                   CheckoutController::place() — which earns COD / credit-covered orders
                   at that point and defers online orders to PaymentController::paySuccess,
                   so an abandoned online order never earns. */
                // 4.3: report what the RE-validation actually did, so a coupon that
                // silently stopped applying is visible to the caller/UI instead of
                // vanishing between the bag and the confirmation.
                $results[$storeId] = [
                    'store_id' => $storeId,
                    'ok' => true,
                    'order_id' => $order->id,
                    'error' => null,
                    'coupon_code' => $cartRow?->coupon_code ?: null,
                    'coupon_applied' => $order->coupon_id !== null,
                    // P1-05 10b: stored tenders read back in the order's own currency (fils kept on a 3-decimal store).
                    'discount' => Money::round((float) ($order->discount_amount ?? 0), Currencies::exponent($order->minorMoneyCurrency())),
                    // 4.7: what the server ACTUALLY spent (0 when the shopper did
                    // not opt in, had no link, or had no balance) — so the UI
                    // reports the real payment instead of the requested one.
                    'wallet_applied' => Money::round((float) ($order->wallet_applied ?? 0), Currencies::exponent($order->minorMoneyCurrency())),
                ];
                // Drop the succeeded store's cart; a failed store keeps its lines.
                $this->scopeOwned(Cart::query())->where('store_id', $storeId)->get()
                    ->each(function (Cart $c) {
                        $c->lines()->delete();
                        $c->delete();
                    });
            } catch (\Throwable $e) {
                // T3-3: a failed first store hands the donation to the next one.
                if ($storeDonation > 0) {
                    $donationPending = true;
                }
                $results[$storeId] = [
                    'store_id' => $storeId, 'ok' => false, 'order_id' => null, 'error' => $e->getMessage(),
                    'coupon_code' => null, 'coupon_applied' => false, 'discount' => 0.0,
                    'wallet_applied' => 0.0,
                ];
            }
        }

        return array_values($results);
    }

    /**
     * P0-4 one-currency assert: a cart may not mix stores whose countries use
     * different currencies. Only marketplace-geotagged stores (mk_city_id set)
     * participate in the country check.
     *
     * P6-105: every store ALSO compares its own charge currency (store_currency —
     * what its order is priced, taxed, stored and sent to the gateway in). Before,
     * a store without geo data was exempt, so a KWD store and a SAR store could
     * share one bag whose summary added fils to halalas. Mixed-currency bags are
     * REFUSED, never converted.
     */
    private function assertSingleCurrency(int $storeId): void
    {
        $countryCurrencyOf = function (int $rid): ?string {
            $cityId = Store::where('id', $rid)->value('mk_city_id');
            if (! $cityId) {
                return null;
            }
            $countryId = City::where('id', $cityId)->value('country_id');

            return $countryId ? Country::where('id', $countryId)->value('currency') : null;
        };

        $incoming = $countryCurrencyOf($storeId);
        $incomingCharge = self::chargeCurrency($storeId);

        $rids = $this->scopeOwned(Cart::live())
            ->where('store_id', '!=', $storeId)->pluck('store_id');
        foreach ($rids as $rid) {
            $existing = $incoming !== null ? $countryCurrencyOf((int) $rid) : null;
            if ($existing !== null && $existing !== $incoming) {
                throw ValidationException::withMessages([
                    'cart' => ___('Your bag has items in :a. Empty it or check out first to order in :b.', ['a' => $existing, 'b' => $incoming]),
                ]);
            }
            $existingCharge = self::chargeCurrency((int) $rid);
            if ($existingCharge !== $incomingCharge) {
                throw ValidationException::withMessages([
                    'cart' => ___('Your bag has items in :a. Empty it or check out first to order in :b.', ['a' => $existingCharge, 'b' => $incomingCharge]),
                ]);
            }
        }
    }

    /** P6-105: the currency a store's order is charged in (never a display currency). */
    private static function chargeCurrency(int $storeId): string
    {
        return strtoupper(store_currency($storeId));
    }

    private function orderableMenu(int $storeId, int $itemId): Menu
    {
        $menu = Menu::where('store_id', $storeId)->find($itemId);
        if (! $menu) {
            throw ValidationException::withMessages(['item' => 'Item not found for this store.']);
        }
        if ($menu->is_sold_out) {
            throw ValidationException::withMessages(['item' => 'Item is sold out.']);
        }
        // T2-3×T3-2: adding while closed is ALLOWED (browse now, schedule for
        // later). The closed gate enforces at fulfillment (ASAP 422s in
        // BagController::fulfillment) and at checkout() below — an order for a
        // closed store only goes through with a valid future scheduled slot.

        return $menu;
    }

    /** @var array<string, true> per-request memo of absorbed (shopper|token|tenant) triples */
    private array $absorbed = [];

    /**
     * P6-262: fold this device's unclaimed guest carts into the shopper's carts, per store, for ONE marketplace
     * tenant. Same store → lines merged by the canonical selection signature (same configuration adds quantity,
     * otherwise appended) and the guest row deleted; no counterpart → the row is claimed and its token cleared.
     * Only rows with this exact token, no shopper and this tenant are read — never another shopper's or token's.
     */
    public function absorbGuestCarts(int $shopperId, string $token, int $tenantId): void
    {
        $key = $shopperId.'|'.$token.'|'.$tenantId;
        if ($token === '' || isset($this->absorbed[$key])) {
            return;
        }
        $this->absorbed[$key] = true;

        $guestCarts = Cart::where('guest_token', $token)
            ->whereNull('shopper_id')
            ->where('status', 'active')
            ->where('mk_reseller_id', $tenantId)
            ->with('lines')
            ->orderBy('id')
            ->get();

        foreach ($guestCarts as $guest) {
            DB::transaction(function () use ($guest, $shopperId, $tenantId) {
                $durable = Cart::where('shopper_id', $shopperId)
                    ->where('store_id', $guest->store_id)
                    ->where('status', 'active')
                    ->where('mk_reseller_id', $tenantId)
                    ->where('id', '!=', $guest->id)
                    ->with('lines')
                    ->orderBy('id')
                    ->first();

                if (! $durable) {
                    $guest->update(['shopper_id' => $shopperId, 'guest_token' => null]);

                    return;
                }

                foreach ($guest->lines as $line) {
                    $sig = $this->signature($line->variation, $line->options, $line->extras);
                    $match = $durable->lines->first(fn (CartLine $l) => (int) $l->menu_id === (int) $line->menu_id
                        && $this->signature($l->variation, $l->options, $l->extras) === $sig);
                    if ($match) {
                        $match->quantity += (int) $line->quantity;
                        $match->save();
                        $line->delete();
                    } else {
                        $line->update(['cart_id' => $durable->id]);
                        $durable->lines->push($line);
                    }
                }

                $guest->delete();
                $durable->touch();
            });
        }
    }

    /**
     * P6-262: shopper logout / shared device. Detach any shopper row still carrying this device's token (legacy
     * rows made before P6-262), then drop the token from the session and expire the cookie so the next person on
     * this device starts with a brand-new, empty guest bag. Never mints a token.
     */
    public function forgetToken(): void
    {
        $token = (string) (request()->cookie(self::TOKEN_COOKIE) ?: session(self::TOKEN_SESSION, ''));
        if ($token !== '') {
            Cart::where('guest_token', $token)->whereNotNull('shopper_id')->update(['guest_token' => null]);
        }
        session()->forget(self::TOKEN_SESSION);
        request()->cookies->remove(self::TOKEN_COOKIE);
        Cookie::queue(Cookie::forget(self::TOKEN_COOKIE));
        $this->absorbed = [];
    }

    private function storeCart(int $storeId): Cart
    {
        $existing = $this->scopeOwned(Cart::query())
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        $shopperId = app(ShopperBag::class)->shopperId();

        return Cart::create([
            // P6-262: a shopper's cart is NOT bound to this device's token, so after logout (or on a shared
            // device) the next visitor's cookie can never resolve it.
            'guest_token' => $shopperId ? null : $this->token(),
            'shopper_id' => $shopperId,
            'client_uuid' => (string) Str::uuid(), // FM-01/02: per-cart order-dedup token
            'store_id' => $storeId,
            'mk_reseller_id' => mk_tenant_id(),       // FM-03: stamp the current tenant
            'status' => 'active',
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
    }
}
