{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       picnic — vivid consumer ordering app · data preparation
       Faithful rebuild of rev/picnic/demo.* wired to real menu data.
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $items      = collect($total_menus)->values();

    /* Two-tier category nav (primary parent chips + a secondary sub-category row
       that swaps per active parent — the Talabat/UberEats pattern, ported from
       plate). A menu item keys off its OWN category_id, which may be a subcategory
       id, so each JS item carries BOTH ids: 'cat' = its real category_id (drives
       sub-chip filtering) and 'root' = its top-level ancestor (drives parent-chip
       filtering, so a parent chip shows every descendant). $rootOf maps any
       category id → its top-level ancestor; $catNameOf keeps every category's own
       name. $categories / $subCatsOf / $jsCats are built AFTER the jsItems loop,
       once the per-category item counts are known. */
    $rootOf    = [];
    $catNameOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id]    = $parentCat->id;
        $catNameOf[$parentCat->id] = $parentCat->name;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id]    = $parentCat->id;
            $catNameOf[$subCat->id] = $subCat->name;
        }
    }
    $rootItemCount = [];   // top-level id => descendant item count (parent chips)
    $catItemCount  = [];   // real cat id  => own item count (sub-chips)

    $curSign = cur_isolate(@$postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);   // 1 = sign before number
    $curGap  = currency_symbol_gap($postOptions->currency_sign ?? '$');     // U+20C3 UAE Dirham only
    // server-side money formatter — same shape as fmt() in script.js, and the same
    // per-store decimals the totals contract requires.
    $fmtMoney = function ($v) use ($curSign, $curPos, $curGap, $postOptions) {
        $n = number_format((float) $v, currency_decimals(@$postOptions->currency_code ?: null), '.', '');
        return $curPos === '1' ? $curSign.$curGap.$n : $n.' '.$curSign;
    };

    $deliveryCharge = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge      = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    // TOTALS CONTRACT: the theme must reproduce Order::computeTax() exactly — that
    // means the store's OWN tax mode and the store's OWN currency decimals, not the
    // app defaults. picnic previously shipped neither (no tax at all in the client
    // total, and app-global decimals), so it quoted less than the server charged.
    $taxType        = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $curDecimals    = currency_decimals(@$postOptions->currency_code ?: null);

    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)
    $taxLabel       = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    $minOrderValue  = (float) (@$postOptions->restaurant_minimum_order ?: 0);
    $allowCoupons   = (int) (@$postOptions->allow_coupons ?? 1) === 1;
    $allowTips      = (int) (@$postOptions->allow_tips ?? 1) === 1;
    $allowWaiter    = !empty($postOptions->allow_call_waiter);
    $schedEnabled   = !empty($postOptions->allow_order_scheduling);
    $allowDietary   = !isset($postOptions->allow_dietary_filters) || (int) $postOptions->allow_dietary_filters === 1;
    $showBranches   = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);
    // Reservations / waitlist are REAL, opt-in server features (publicView.reservation.book
    // aborts 404 unless enable_reservations is on). picnic used to render the booking sheet
    // unconditionally and write "Table booked" to localStorage — a booking the restaurant
    // never received. Gate the UI on the same flags the endpoints enforce.
    $resEnabled     = (bool) (@$postOptions->enable_reservations ?? false);
    $wlEnabled      = (bool) (@$postOptions->enable_waitlist ?? false);
    $prepEta        = trim((string) (@$postOptions->prep_eta_static ?: ''));
    // bespoke splash imagery (first few real-photo dishes) for the onboarding burst
    $splashImgs     = $items->filter(fn ($m) => $m->image && $m->image !== 'default.png')->take(3)->values();
    // Customizer v2: `splash.enabled` says whether there is a splash at all and
    // `splash.style` picks which one, so the bespoke intro and the shared layer
    // (store_templates._partials.splash, wired in layout) can never stack.
    // Defaults (enabled + theme) render picnic's own onboarding unchanged.
    $ownSplashOn = (bool) theme_setting($store->id, 'splash.enabled', true)
        && theme_setting($store->id, 'splash.style', 'theme') === 'theme';

    /* Customizer v2 section visibility (same contract as scoop/aurora): schema
       defaults first, overlaid by the legacy menu_sections row, overlaid by the
       customizer — theme_section_visible() is the newest source of truth. */
    $defaultSections = [
        ['id' => 'catbar',      'visible' => true],
        ['id' => 'bestsellers', 'visible' => true],
        ['id' => 'mealplan',    'visible' => true],
        ['id' => 'rewards',     'visible' => true],
    ];
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $sectionVisible = collect($defaultSections)->pluck('visible', 'id')->all();
    if (is_array($savedSections)) {
        foreach ($savedSections as $saved) {
            if (isset($saved['id'], $sectionVisible[$saved['id']])) {
                $sectionVisible[$saved['id']] = isset($saved['visible']) ? (bool) $saved['visible'] : true;
            }
        }
    }
    $sectionVisible = array_replace($sectionVisible, theme_section_visible($store->id));

    /* Customizer texts. Empty (unset) keeps the theme's own copy: both headlines
       are data-i18n nodes that applyLang() rewrites at boot, so a bare
       theme_setting(..., ___('fallback')) would be clobbered by JS — instead a
       non-empty custom title swaps in a plain span JS never touches. */
    $onbTitleCustom  = trim((string) theme_setting($store->id, 'texts.onboarding_title', ''));
    $homeTitleCustom = trim((string) theme_setting($store->id, 'texts.home_title', ''));

    $isRtl    = current_language()->direction === 'rtl';
    // Open/closed status chip (R4 parity): the store's manual open flag. '1' or empty
    // (unset default) = open; '0' = the owner has switched the storefront to closed.
    $isOpen          = store_open_now($store);
    $customer = auth('customer')->user();
    // Customer-auth entry: optional/required stores get a Login pill on the
    // profile me-card + an #authSheet; guest_only stores render neither.
    $authMode = @$postOptions->customer_auth_mode ?? '';
    // Order-lookup wording gate (global backend option, orbit/crave/matcha/aurora
    // parity): only the copy changes here — the lookup endpoint itself already
    // accepts/rejects an order number server-side regardless of this flag.
    $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false);
    // Saved delivery addresses (logged-in): tappable chips at checkout that fill the
    // address field + hidden coords; the default one pre-fills the input on load.
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    $storeCredit = 0.0;
    try { $storeCredit = $customer ? (float) \App\Services\CustomerWalletService::balanceFor($customer) : 0.0; } catch (\Throwable $e) {}

    // Brand/follow row (crave F2 parity): store identity + follow CTA state.
    $customerApiToken = storefront()->customerApiToken($customer, 'picnic');
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
    $followerCount   = method_exists($store, 'follows') ? (int) $store->follows()->count() : 0;
    $isFollowing     = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;

    /* B8 · store-review display (avg rating + up to 2 approved cards). Dormant
       until the directory/reviews module is on (dark today) AND the store has
       approved reviews — else both blocks are omitted, so this ships safely dark. */
    $aboutReviews = collect();
    $aboutRating  = null;
    if (\Juwri\Modules\Storefront\Services\StoreReviewsDisplay::enabledFor($store->id)) {
        $approvedReviews = \App\Models\StoreReview::approved()->forStore($store->id)->latest()->get(['reviewer_name', 'stars', 'body']);
        if ($approvedReviews->isNotEmpty()) {
            $aboutRating  = round((float) $approvedReviews->avg('stars'), 1);
            $aboutCount   = $approvedReviews->count();
            $aboutReviews = $approvedReviews->take(6);
        }
    }

    // Placeholder for the client-side rewards showcase. The real balance is
    // fetched signed-in from publicView.loyalty.balance and overwrites this;
    // there is no loyalty_points attribute on Customer to seed from server-side.
    $loyaltyPoints = 0;

    // The promo tile advertises a REAL dish (daily special → most popular → first
    // item with a photo), not the demo's "Fresh & Popular" slogan over an arbitrary
    // photo whose CTA opened an unrelated item.
    $featured   = $items->first(fn ($m) => $m->is_daily_special)
        ?? $items->first(fn ($m) => $m->is_popular)
        ?? $items->first(fn ($m) => $m->image && $m->image !== 'default.png')
        ?? $items->first();
    $featuredKicker = $featured && $featured->is_daily_special
        ? ___('Daily special')
        : (($featured && $featured->is_popular) ? ___('Popular') : ___('Our Menu'));
    $featuredImg = $featured ? media_url('storage/menu/', $featured->image, $store->id ?? null) : media_url('storage/menu/', 'default.png', $store->id ?? null);
    $defaultImg = media_url('storage/menu/', 'default.png', $store->id ?? null);
    $logoImg    = media_url('storage/store/logo/', $store->main_image, $store->id ?? null);

    /* Category chip emoji. It used to be `$catEmoji[$index % 16]` — a positional
       palette, so on the lane store "Sides" got 🍕, "Drinks" got 🍟 and "Desserts"
       got 🥤. A picture that contradicts its own label is worse than no picture, so
       the name is matched first (EN + AR keywords) and the positional palette is
       only the fallback for categories nothing matches. */
    $catEmoji = ['🍽️','🥘','🍲','🥙','🌮','🍜','🥗','🍢'];
    $catEmojiFor = function ($nm, $i) use ($catEmoji) {
        $n = mb_strtolower((string) $nm);
        $map = [
            '🍗' => ['chicken','broast','wing','دجاج','بروست'],
            '🥩' => ['grill','steak','beef','meat','مشوي','لحم'],
            '🍔' => ['burger','sandwich','برجر','ساندوتش'],
            '🍕' => ['pizza','بيتزا'],
            '🍟' => ['side','fries','starter','appetiz','مقبلات','جانبي'],
            '🥤' => ['drink','beverage','juice','cold','مشروب','عصير'],
            '☕'  => ['coffee','tea','hot','قهوة','شاي','ساخن'],
            '🍰' => ['dessert','sweet','cake','حلو','كيك'],
            '🍦' => ['ice cream','gelato','آيس'],
            '🍝' => ['pasta','noodle','معكرون'],
            '🥗' => ['salad','سلطة'],
            '🍞' => ['bread','bakery','خبز','مخبوز'],
            '🍚' => ['rice','ارز','أرز'],
            '🍲' => ['soup','شوربة'],
            '🐟' => ['fish','seafood','سمك','بحري'],
            '🧒' => ['kid','child','أطفال'],
        ];
        foreach ($map as $emoji => $words) {
            foreach ($words as $w) { if ($w !== '' && mb_strpos($n, $w) !== false) { return $emoji; } }
        }
        return $catEmoji[$i % count($catEmoji)];
    };

    // human label for a variant combination
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS item map — array in menu order.
       groups: variant group (radio, absolute price) + extras group (check, additive). */
    $jsItems = [];
    /* approved product reviews, grouped per menu item for the product view */
    $reviewsByMenu = \App\Models\MenuReview::query()
        ->where('store_id', $store->id)
        ->where('status', 'approved')
        ->latest()
        ->get()
        ->groupBy('menu_id');
    foreach ($items as $m) {
        $soldOut = (bool) $m->is_sold_out
            || ((int) ($m->track_inventory ?? 0) === 1 && ($m->stock_qty ?? null) !== null && (int) ($m->stock_qty ?? 0) <= 0);

        $itemAllergies = empty($m->allergies)
            ? collect()
            : $allergies->whereIn('id', explode(',', $m->allergies));

        // 'root' = the item's top-level ancestor (parent-chip filter); 'cat' stays the
        // item's real category id (sub-chip filter). Orphan → falls back to self.
        $rootCat = $rootOf[$m->category_id] ?? $m->category_id;
        $rootItemCount[$rootCat] = ($rootItemCount[$rootCat] ?? 0) + 1;
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;

        $gallery = [];
        if (!empty($m->gallery_images)) {
            foreach ((array) $m->gallery_images as $f) { $gallery[] = media_url('storage/menu/', $f, $store->id ?? null); }
        }
        if (empty($gallery)) { $gallery[] = media_url('storage/menu/', $m->image, $store->id ?? null); }

        $groups = [];
        if (isset($m->variants) && count($m->variants)) {
            $opts = [];
            foreach ($m->variants as $v) {
                $opts[] = ['name' => $variantLabel($m, $v), 'abs' => (float) $v->price, 'variantId' => (int) $v->id];
            }
            $groups[] = ['id' => 'variant', 'type' => 'radio', 'required' => true, 'variant' => true,
                         'name' => ___('Choose one'), 'opts' => $opts];
        }
        if (isset($m->extras) && count($m->extras)) {
            $opts = [];
            foreach ($m->extras as $e) {
                $opts[] = ['name' => $e->title, 'delta' => (float) $e->price, 'extraId' => (int) $e->id];
            }
            $groups[] = ['id' => 'extras', 'type' => 'check', 'required' => false, 'extra' => true,
                         'name' => ___('Extras'), 'opts' => $opts];
        }
        // Global option-groups (reusable modifier groups). Additive pricing; single-select
        // (max 1) renders as a radio, otherwise a checkbox list. Carries the real group id +
        // option index so the server can re-derive price and record the selection.
        foreach ($m->globalGroups ?? [] as $g) {
            $opts = [];
            foreach ((array) $g->options as $gi => $label) {
                $opts[] = ['name' => (string) $label, 'delta' => (float) ($g->option_prices[$gi] ?? 0), 'optIndex' => (int) $gi];
            }
            if (empty($opts)) { continue; }
            $max = $g->max_select !== null ? (int) $g->max_select : null;
            $groups[] = [
                'id'       => 'g' . $g->id,
                'groupId'  => (int) $g->id,
                'type'     => ($max === 1 ? 'radio' : 'check'),
                'required' => (bool) $g->required,
                'global'   => true,
                'min'      => (int) ($g->min_select ?? 0),
                'max'      => $max,
                'name'     => (string) $g->title,
                'opts'     => $opts,
            ];
        }

        $spiceMap = ['mild' => 1, 'medium' => 2, 'hot' => 2, 'extra_hot' => 3];
        $prep = 6 + ((int) $m->id % 18); // decorative prep-minutes (no real field in schema)

        $jsItems[] = [
            'id'      => (int) $m->id,
            'cat'     => (int) $m->category_id,
            'root'    => (int) $rootCat,
            'name'    => (string) $m->name,
            'desc'    => trim(html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES)),
            'price'   => (float) $m->price,
            // K8: pre-schedule reference price for a strike-through (MenuScheduleService::decorate)
            'original_price' => (!is_null($m->original_price ?? null) && (float) $m->original_price > (float) $m->price) ? (float) $m->original_price : null,
            // Happy-hour label for the card offer ribbon (was never serialized → badge could not render)
            'schedule_label' => $m->schedule_label ?? null,
            'gallery' => $gallery,
            'rating'  => ($m->rating_avg && $m->rating_avg > 0) ? (float) number_format($m->rating_avg, 1) : 0,
            'reviews' => (int) ($m->rating_count ?? 0),
            // Written-review total for the drawer meta chip — 'reviews' above is
            // rating_count (every quick star tap); labelling that number "reviews"
            // over one written review reads as fabricated social proof.
            'reviewTotal' => collect($reviewsByMenu->get($m->id, collect()))->count(),
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            'time'    => $prep,
            'kcal'    => $m->calories ? (int) $m->calories : 0,
            'spice'   => (int) ($spiceMap[$m->spice_level] ?? 0),
            'veg'         => $m->type !== 'nonveg',
            'vegan'       => (bool) ($m->is_vegan ?? false),
            'halal'       => (bool) ($m->is_halal ?? false),
            'glutenFree'  => (bool) ($m->is_gluten_free ?? false),
            'popular'     => (bool) ($m->is_popular ?? false),
            'special'     => (bool) ($m->is_daily_special ?? false),
            'soldOut'     => $soldOut,
            'allergies'   => $itemAllergies->map(fn ($a) => ['id' => (int) $a->id, 'title' => (string) $a->title])->values(),
            'groups'      => $groups,
        ];
    }

    // Primary chips: top-level parents that hold at least one descendant item
    // (pure containers / empty parents drop, so no chip opens an empty grid).
    $categories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    // Secondary chips: per parent, only the subcategories that own items.
    $subCatsOf = [];
    foreach ($categories as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) { $subCatsOf[$c->id] = $subs; }
    }

    // JS chip data: each parent carries id + name + emoji + its item-bearing subs.
    $jsCats = [];
    foreach ($categories as $ci => $c) {
        $jsCats[] = [
            'id'    => (int) $c->id,
            'name'  => (string) $c->name,
            'emoji' => $catEmojiFor($c->name, $ci),
            // Real category photo (falls back to the emoji when unset/broken).
            'image' => $c->image ? media_url('storage/menu/', $c->image, $store->id ?? null) : '',
            'subs'  => collect($subCatsOf[$c->id] ?? [])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->values(),
        ];
    }

    // allowed order types (demo keys: delivery|pickup|dinein|roomservice)
    // NB: these are THEME-LOCAL names; script.js maps them to the server's
    // ordering-type values through OT_MAP (roomservice → room_service).
    $otAllowed = [];
    if ($allow_delivery) { $otAllowed[] = 'delivery'; }
    if ($allow_takeaway) { $otAllowed[] = 'pickup'; }
    if ($allow_on_table) { $otAllowed[] = 'dinein'; }
    if ($allow_room_service) { $otAllowed[] = 'roomservice'; }
    if (empty($otAllowed)) { $otAllowed[] = 'delivery'; }

    // UI i18n — current language only, baked via ___() (auto-registers on first render)
    $i18n = [
        'skip' => ___('Skip'), 'remove' => ___('Remove'),
        'onb1_a' => ___('Fresh food,'), 'onb1_b' => ___('zero fuss.'),
        // Onboarding copy is built from ALREADY-TRANSLATED keys and real store data, so
        // the Arabic slides do not fall back to English. (The demo's slide-3 promise of
        // "Zero delivery fees" was translated — and false: this store charges one.)
        'onb1_sub' => trim((string) ($store->address ?: '')) ?: ___('Full Menu'),
        'get_started' => ___('Get started'),
        // Demo copy that was NOT true of a real restaurant is gone: "Let's get started
        // saving food!", "Zero delivery fees. Cancel any time." (this store charges a
        // delivery fee), "Order From The Best Of Snacks", "Fresh & Popular".
        'onb2_h' => ___('Welcome'),
        'continue_guest' => ___('Continue'),
        'onb3_h' => ___('Order now'),
        'onb3_sub' => implode(' · ', array_filter([
            $allow_delivery ? ___('Delivery') : null,
            $allow_takeaway ? ___('Pickup') : null,
            $allow_on_table ? ___('Dine-in') : null,
        ])),
        'start_ordering' => ___('Start ordering'), 'guest_ok' => ___('Welcome!'),
        'greeting' => ___('Welcome 👋'), 'home_t1' => ___('Order now'), 'home_t2' => ___('Full Menu'),
        'buy_now' => ___('Order now'), 'full_menu' => ___('Full Menu'),
        'quick_plan' => ___('Order scheduling'), 'quick_book' => ___('Book a table'),
        'best' => ___('Our menu'), 'see_all' => ___('See all'), 'daily_special' => ___('Daily special'),
        'popular' => ___('Popular'),
        'stories' => ___('Stories'), 'order_now' => ___('Order now'),
        'search_ph' => ___('Search the menu…'), 'so_cancel' => ___('Cancel'), 'so_hint' => ___('Try one of these'),
        'so_empty' => ___('Nothing found — try another word'), 'min_short' => ___('min'),
        'foods' => ___('Foods'), 'all_foods' => ___('All foods'), 'all_cats' => ___('All Categories'), 'items' => ___('items'),
        'detail' => ___('Details'), 'food_kicker' => ___('FOOD'), 'read_more' => ___('Read more…'), 'read_less' => ___('Read less'),
        'add_cart' => ___('Add to cart'), 'm_delivery' => ___('min prep'), 'm_reviews' => ___('Reviews'), 'm_rating' => ___('Rating'),
        'kcal' => ___('kcal'), 'prep' => ___('min prep'), 'vegan' => ___('Vegan'), 'halal' => ___('Halal'), 'gf' => ___('Gluten-free'),
        'related' => ___('You may also like'), 'required' => ___('Required'), 'optional' => ___('Optional'),
        'sold_out' => ___('Sold out'),
        'meal_plan' => ___('Order scheduling'), 'mp_q' => ___('Choose a time'),
        'mp_time' => ___('Delivery slot'), 'lunch' => ___('Lunch'), 'dinner' => ___('Dinner'), 'continue' => ___('Continue'),
        'mp_done' => ___('Scheduled'),
        'rewards' => ___('Rewards'), 'pts_head2' => ___('Points'), 'pts_unit' => ___('Points'),
        'pts_earn' => ___('Earn points with every order you place'),
        'pts_ready' => ___('You have enough points for a reward — ask us at the counter'),
        'my_bag' => ___('My Bag'), 'clear' => ___('Clear'), 'bag_empty' => ___('Your bag is empty — let’s fix that'), 'browse' => ___('Browse menu'),
        'order_type' => ___('Order type'), 'ot_delivery' => ___('Delivery'), 'ot_pickup' => ___('Pickup'), 'ot_dinein' => ___('Dine-in'),
        'ot_roomservice' => ___('Room service'), 'room_hint' => ___('Brought straight to your room'),
        'table' => ___('Table'), 'choose_table' => ___('Pick your table'),
        'pickup_hint' => ___('Ready for pickup shortly'), 'delivery_hint' => ___('Delivered to your door'),
        'when' => ___('When'), 'asap' => ___('ASAP'), 'later' => ___('Later'), 'plan_tag' => ___('Meal plan'),
        'coupon_ph' => ___('Promo code'), 'apply' => ___('Apply'),
        'coupon_ok' => ___('Coupon applied — you save'), 'coupon_bad' => ___('That code doesn’t work'),
        'add_note' => ___('Add note'), 'item_note' => ___('Item note'), 'note_ph' => ___('No onions please…'), 'save' => ___('Save'), 'reward' => ___('Reward'),
        'subtotal' => ___('Subtotal'), 'discount' => ___('Discount'), 'tax' => $taxLabel, 'service' => ___('Service fee'),
        'delivery_fee' => ___('Delivery fee'), 'tip_label' => ___('Tip'), 'total' => ___('Total'),
        'your_order' => ___('Your order'), 'edit' => ___('Edit'),
        'call_waiter' => ___('Call waiter'), 'request_bill' => ___('Request bill'),
        'waiter_ok' => ___('A waiter is on the way'), 'bill_ok' => ___('Bill requested — one moment'),
        'wa_order' => ___('Order via WhatsApp'), 'wa_demo' => ___('Opening WhatsApp…'),
        'checkout' => ___('Checkout'), 'contact' => ___('Contact'), 'your_name' => ___('Your name'), 'phone' => ___('Phone'),
        'delivery_address' => ___('Delivery address'), 'addr_ph' => ___('Street, building, floor…'), 'dinein_at' => ___('Dine-in · Table'),
        'use_location' => ___('Use my location'), 'loc_unavailable' => ___('Location unavailable'),
        'loc_denied' => ___('Location denied'), 'no_address' => ___('No address found'),
        'pay_with' => ___('Pay with'), 'wallet_sub' => ___('Balance'),
        'card_note' => ___('You’ll be taken to our secure payment page after you place the order.'),
        'tip' => ___('Add a tip'), 'no_tip' => ___('None'), 'terms' => ___('I accept the terms & privacy policy'),
        'place_order' => ___('Place Order'), 'fill_contact' => ___('Add your name & phone first'),
        'fill_addr' => ___('Add a delivery address'), 'accept_terms' => ___('Please accept the terms'),
        'min_order' => ___('Minimum order is'),
        'confirmed' => ___('Order Confirmed'), 'confirm_sub' => ___('Your order is being packed'), 'est_at' => ___('Ready in'),
        'track_order' => ___('Track Order'), 'back_menu' => ___('Back to menu'),
        'order_no' => ___('Order'), 'eta' => ___('Arrives in'), 'contact_store' => ___('Help'),
        'full_receipt' => ___('Receipt'),
        'stage_placed' => ___('Order placed'), 'stage_kitchen' => ___('In the kitchen'), 'stage_way' => ___('On the way'),
        'stage_done' => ___('Delivered'), 'stage_ready' => ___('Ready for pickup'), 'stage_picked' => ___('Picked up'),
        'stage_served' => ___('Served to your table'), 'stage_enjoy' => ___('Enjoy your meal'),
        'stage_cancelled' => ___('Cancelled'), 'stage_unpaid' => ___('Awaiting Payment'),
        'rate_q' => ___('How was your order?'), 'rate_google' => ___('Rate us on Google'),
        'rate_thanks' => ___('Thanks for the feedback!'),
        'favorites' => ___('Favorites'), 'fav_empty' => ___('Tap the heart on any dish to save it'),
        'profile' => ___('Profile'), 'guest_sub' => ___('Member'), 'my_points' => ___('My points'), 'wallet_bal' => ___('Wallet balance'),
        'reservations' => ___('Reservations'), 'preferences' => ___('Preferences'), 'language' => ___('Language'), 'currency' => ___('Currency'),
        'appearance' => ___('Appearance'), 'mode_auto' => ___('Auto'), 'mode_light' => ___('Light'), 'mode_dark' => ___('Dark'),
        'my_orders' => ___('My orders'), 'orders_empty' => ___('Orders you place appear here'), 'wa_us' => ___('Chat with us on WhatsApp'),
        'lookup_ph' => $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout'),
        'lookup_go' => ___('Find'), 'lookup_none' => ___('No orders found for that phone/email.'),
        'action_failed' => ___('Action failed.'),
        'reorder' => ___('Reorder'), 'this_device' => ___('Order'),
        'book_table' => ___('Book a table'), 'date' => ___('Date'), 'time' => ___('Time'), 'party' => ___('Party size'),
        'res_confirm' => ___('Confirm reservation'), 'res_ok' => ___('Your reservation request has been submitted!'),
        'today' => ___('Today'),
        'wl_q' => ___('Prefer to walk in?'), 'wl_sub' => ___('Join the queue and we’ll text you when a table frees up.'),
        'wl_join' => ___('Join waitlist'), 'wl_ok' => ___('You’re on the waitlist'), 'wl_pos_n' => ___('Position'),
        'wl_tag' => ___('Waitlist'), 'guests' => ___('guests'),
        'added' => ___('Added to bag'), 'removed' => ___('Removed'), 'scheduled' => ___('Scheduled'),
        'reviews_title' => ___('Reviews'), 'reviews_empty' => ___('No reviews yet.'),
        'rate_dish' => ___('Rate this dish'), 'thanks' => ___('Thanks!'),
        'order_failed' => ___('Something went wrong — please try again'),
        'net_failed' => ___('Network error.'),
    ];

    // WhatsApp order number (QuickOrder / restaurant phone)
    $waNumber = preg_replace('/\D+/', '', (string) @$postOptions->quick_order_number)
        ?: storefront_whatsapp_number($store); /* WA-T2: picnic alone prefers quick_order_number */
@endphp

@push('style_at_top')
    {{-- No web-font CDN: the platform CSP blocks fonts.googleapis.com (every link
         cost a blocked request + a csp-report and loaded nothing — measured:
         document.fonts.check('700 16px Poppins') === false). picnic's type now
         rides a self-contained system stack, see --font-ui in style.css. --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- Appearance is applied before first paint so a dark-mode diner never gets a
         white flash. `auto` (the default) leaves it to prefers-color-scheme. --}}
    <script nonce="{{ csp_nonce() }}">
      (function () {
        try {
          var k = "picnic_" + @json($store->slug) + "_mode";
          var m = JSON.parse(localStorage.getItem(k) || '"auto"');
          if (m === "dark" || m === "light") document.documentElement.setAttribute("data-picnic-mode", m);
        } catch (e) {}
      })();
    </script>
@endpush

@section('content')
<div class="stage">
  <div class="phone" id="phone">
    {{-- Device-frame headroom only. The demo drew a fake iOS status bar here
         (9:41 · full signal · full battery) and picnic shipped it verbatim onto a
         live storefront, where it sat above the customer's REAL status bar and
         lied about the time. Now it is an empty spacer that only exists at the
         widths where the phone frame is actually drawn. --}}
    <div class="statusbar" aria-hidden="true"></div>

    {{-- A store with ordering switched off is a VIEW-ONLY menu. picnic never checked
         $allow_order anywhere, so such a store still rendered a cart tab, a "+" on every
         card, an "Add to cart" CTA and a Place Order button that the server rejects.
         `.no-order` removes every ordering affordance; script.js guards the routes too. --}}
    <div class="app {{ $allow_order ? '' : 'no-order' }}" id="app">

      <!-- ══════════ ONBOARDING (picnic's bespoke splash) ══════════ -->
      {{-- Suppressed when the store has no splash at all (splash.enabled) or the owner
           picked the shared splash layer (splash.style), so the two never stack. Elements
           stay in the DOM (JS binds listeners); the is-done class just hides it up front. --}}
      <div class="onb {{ $ownSplashOn ? '' : 'is-done' }}" id="onb">
        <button class="onb-skip" id="onbSkip" data-i18n="skip">Skip</button>
        <div class="onb-track" id="onbTrack">

          <section class="onb-slide ob1">
            <div class="ob1-stickers" aria-hidden="true">
              <span class="stk s-a">🍕</span><span class="stk s-b">🍔</span>
              <span class="stk s-c">🍟</span><span class="stk s-d">🥗</span>
            </div>
            <div class="ob-brand">{{ $store->title }} <span class="ob-basket">🧺</span></div>
            {{-- texts.onboarding_title (customizer): a custom title renders as a plain
                 span (no data-i18n) so applyLang() can't overwrite it; unset keeps the
                 theme's own translated two-line headline. --}}
            @if($onbTitleCustom !== '')
            <h1 class="ob1-title"><span data-tc-text="texts.onboarding_title">{{ $onbTitleCustom }}</span></h1>
            @else
            <h1 class="ob1-title"><span data-i18n="onb1_a">Fresh food,</span><span class="accent" data-i18n="onb1_b">zero fuss.</span></h1>
            @endif
            <p class="ob-sub" data-i18n="onb1_sub"></p>
            <button class="ob-cta ink" id="obNext1"><span data-i18n="get_started">Get started</span><span class="arr">→</span></button>
          </section>

          <section class="onb-slide ob2">
            <h1 class="ob2-title" data-i18n="onb2_h">Welcome</h1>
            <div class="ob2-burst" aria-hidden="true">
              @if((string) ($featuredImg) !== '')<img src="{{ $featuredImg }}" alt="" data-csp-error="fallback" data-csp-fallback="{{ $defaultImg }}">@endif
              {{-- bespoke splash imagery: the next real-photo dishes orbit the hero --}}
              @foreach($splashImgs->slice(1) as $si)
                @if((string) (media_url('storage/menu/', $si->image, $store->id ?? null)) !== '')<img class="ob-chip ob-chip-{{ $loop->index }}" src="{{ media_url('storage/menu/', $si->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
              @endforeach
              <span class="bit b-1">🥬</span><span class="bit b-2">🍅</span>
              <span class="bit b-3">🧀</span><span class="bit b-4">🥓</span><span class="bit b-5">🧅</span>
            </div>
            {{-- The demo's three "Continue with Apple / Facebook / Email" buttons
                 authenticated nobody: every one of them just toasted "Welcome!" and
                 advanced the carousel. They are replaced by the platform's REAL social
                 sign-in partial (renders nothing unless the owner enabled a provider —
                 picnic was one of four themes missing this include entirely) plus an
                 honest guest CTA. No button here claims a session it did not create. --}}
            <div class="ob-auth">
              @include('store_templates.partials.social-login-buttons', ['store' => $store, 'socialContext' => 'login'])
              <button class="auth email" id="obGuest"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg><span data-i18n="continue_guest">Continue</span></button>
            </div>
          </section>

          <section class="onb-slide ob3">
            <svg class="rider" viewBox="0 0 260 200" aria-hidden="true">
              <ellipse class="cl" cx="42" cy="32" rx="24" ry="9"/>
              <ellipse class="cl" cx="214" cy="22" rx="28" ry="10"/>
              <g class="speed"><path d="M12 126h36"/><path d="M2 142h32"/><path d="M16 158h28"/></g>
              <ellipse class="shadow" cx="140" cy="184" rx="82" ry="9"/>
              <g class="scoot">
                <rect class="box" x="50" y="84" width="48" height="42" rx="10"/>
                <text class="box-emoji" x="74" y="114" text-anchor="middle">🧺</text>
                <path class="frame" d="M98 122c14 2 26 8 34 20l10 12h26l16-38c3-7 9-11 17-11h10"/>
                <path class="deck" d="M118 150l54 2-8 14h-36z"/>
                <path class="frame" d="M198 94l14-18M208 76h16"/>
                <g class="wheel"><circle cx="118" cy="168" r="17"/><circle class="hub" cx="118" cy="168" r="6"/></g>
                <g class="wheel"><circle cx="206" cy="168" r="17"/><circle class="hub" cx="206" cy="168" r="6"/></g>
                <circle class="helmet" cx="168" cy="64" r="15"/>
                <path class="visor" d="M177 60a15 15 0 0 0-9-11"/>
                <path class="suit" d="M158 78c-8 12-10 22-6 34l6 14h18l-4-20 10-16c3-6 0-12-6-14z"/>
                <path class="arm" d="M170 86l30-8"/>
              </g>
            </svg>
            <h1 class="ob3-title" data-i18n="onb3_h">Order now</h1>
            <p class="ob-sub light" data-i18n="onb3_sub"></p>
            <button class="ob-cta white" id="obStart"><span data-i18n="start_ordering">Start ordering</span><span class="arr">→</span></button>
          </section>
        </div>
        <div class="onb-dots" id="onbDots"><i class="is-on"></i><i></i><i></i></div>
      </div>

      <!-- ══════════ VIEWS ══════════ -->
      <main class="views" id="views">

        <!-- HOME -->
        <section class="view is-active" data-view="home">
          @php
              $__pcHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
              $pcCoverUrl = $__pcHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
              $pcShowCover = $__pcHasCover && theme_setting($store->id, 'brand.show_cover', true);
          @endphp
          {{-- Collapsing header: slides in once the cover band leaves the scroll
               container (picnic's chips are not sticky, so no overlap guard). --}}
          <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
            <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
            <b class="cv-condensed-name">{{ $store->title }}</b>
            <span class="cv-condensed-actions">
              @if(count($menu_languages ?? []) > 1)
              <button class="iconbtn" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
              </button>
              @endif
              @if(storefront_catering_enabled($store->id))
              <button class="iconbtn" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
              </button>
              @endif
              <button class="iconbtn" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
              </button>
            </span>
          </div>
          <div class="view-scroll">
            @if($pcShowCover)
            {{-- Cover as a rounded-bottom band (warm, park-day); chrome sits over it
                 in white, the brand row overlaps its rounded bottom edge. --}}
            <div class="pc-cover">
              <div class="pc-cover-media" aria-hidden="true">
                @if((string) ($pcCoverUrl) !== '')<img class="pc-cover-img" src="{{ $pcCoverUrl }}" alt="" data-csp-error="hide">@endif
                <span class="pc-cover-scrim"></span>
              </div>
            @endif
            <header class="home-top">
              {{-- crave-parity header: the avatar opens the customer's PROFILE, so it
                   shows the customer (initial signed-in, person glyph as guest) — not
                   the store logo (that lives on the brand row below). Beside it, a
                   branch selector like crave's .loc: the active branch name + chevron
                   opening the branch sheet (multi-branch), else a "Welcome to" label. --}}
              <button class="avatar{{ $customer ? ' avatar--me' : '' }}" data-go="profile" aria-label="{{ ___('Profile') }}" type="button">
                @if($customer)
                  {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->name ?: $customer->phone ?: '?', 0, 1)) }}
                @else
                  <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                @endif
              </button>
              @if($showBranches)
                <button class="loc" type="button" data-csp-open-sheet="mbBranchSheet" data-csp-scrim="scrim" aria-label="{{ ___('Choose branch') }}">
                  <span class="loc-label">{{ ___('Branch') }}</span>
                  <span class="loc-value">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <b>{{ $activeBranch->name ?? $branches->first()->name ?? $store->title }}</b>
                    <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                  </span>
                </button>
              @else
                <span class="loc">
                  <span class="loc-label">{{ ___('Welcome to') }}</span>
                  <span class="loc-value"><b>{{ $customer->name ?? $store->title }}</b></span>
                </span>
              @endif
              <button class="pts-pill" id="ptsPillBtn" data-go="points" aria-label="Points" hidden>
                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>
                <b id="ptsPill">0</b>
              </button>
              @if(count($menu_languages ?? []) > 1)
              {{-- Language: a globe opening the sheet below, so the header holds one
                   control whether the store runs two languages or eleven. The shared
                   language-switch partial delegates on [data-lang-code]. --}}
              <button class="iconbtn" id="langBtn" type="button" aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
              </button>
              @endif
              @if(storefront_catering_enabled($store->id))
              {{-- Catering & Events: opens the #cvCateringSheet bottom-sheet (wired in
                   script.js), so the form is reachable from the header instead of only
                   at the bottom of the menu. --}}
              <button class="iconbtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
              </button>
              @endif
              @if(theme_setting($store->id, 'search.enabled', true))
              <button class="iconbtn" id="searchBtn" aria-label="Search">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
              </button>
              @endif
              {{-- Shared notification-center bell (rev/notif-center-spec.md); panel + JS from _partials/notif-bell. --}}
              <button class="iconbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                <i id="mbNotifDot" hidden></i>
              </button>
              {{-- Light/dark quick toggle (Lane B) — drives picnic's native appearance
                   system (data-picnic-mode on :root), same store as Profile's segment. --}}
              <button class="iconbtn" id="picnicThemeToggle" type="button"
                      aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
                <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
                <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
              </button>
              <style nonce="{{ csp_nonce() }}">
                #mbNotifBell #mbNotifDot{position:absolute;top:9px;inset-inline-end:10px;width:9px;height:9px;border-radius:50%;background:var(--orange);outline:2.5px solid var(--paper)}
                #mbNotifBell #mbNotifDot[hidden]{display:none}
              </style>
            </header>
            @if($pcShowCover)
            </div>{{-- /.pc-cover --}}
            @endif
{{-- MC-34: display-currency estimate notice mounts here (shared partial). Kept
     OUTSIDE .pc-cover — inside it the notice sat over the hero image/scrim; as an
     in-flow section right below the hero it reads on the page background. --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


            {{-- brand / follow row (crave F2 parity): store identity + follow CTA. --}}
            @if($sectionVisible['brand_row'] ?? true)
            <div class="brand-row{{ $pcShowCover ? ' brand-row--hero' : '' }}" id="pcBrandRow" data-mb-hotel-chip-slot>
              <span class="brand-logo">
                @if((string) ($logoImg) !== '')<img src="{{ $logoImg }}" alt="" data-csp-error="remove">@endif
              </span>
              <span class="brand-info">
                <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                @if(trim((string)($store->sub_title ?? '')) !== '')
                <span class="brand-tagline">{{ $store->sub_title }}</span>
                @endif
                @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true), 'countId' => 'pcFollowerCount'])
                {{-- Store open/closed + today's hours (crave parity). The .open-chip
                     styles already shipped; only this markup was missing on picnic. --}}
                <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
              </span>
              {{-- Following needs a customer account: on a guest_only store no auth
                   sheet is rendered, so a tap could not do anything — hide the
                   control instead of shipping a dead button. --}}
              @if($authMode !== 'guest_only' || $customer)
              <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="pcFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                <span class="follow-label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                <span class="following-label">{{ ___('Following') }}</span>
              </button>
              @endif
            </div>
            @endif

            {{-- B8 · store-review display — avg rating + up to 2 approved cards.
                 Self-gated: renders only when the reviews module is on and the
                 store has approved reviews (dark today), so it ships dormant. --}}
            @if($aboutRating !== null)
            <div class="pc-reviews" id="pcReviews" data-mb-reviews-end>
              <div class="pc-rating"><span class="pc-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
              @if($aboutReviews->isNotEmpty())
              <div class="pc-review-list">
                @foreach($aboutReviews as $rev)
                <div class="pc-review">
                  <div class="pc-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="pc-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                  @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                </div>
                @endforeach
              </div>
              @endif
            </div>
            @endif

            {{-- texts.home_title (customizer): same pattern as the onboarding title —
                 a custom headline must skip data-i18n or applyLang() replaces it. --}}
            @if($homeTitleCustom !== '')
            <h1 class="home-headline"><span data-tc-text="texts.home_title">{{ $homeTitleCustom }}</span></h1>
            @else
            <h1 class="home-headline"><span data-i18n="home_t1">Order From The</span><span data-i18n="home_t2">Best Of Snacks</span></h1>
            @endif

            <div class="promo-duo">
              <button class="promo-photo" id="promoHero" @if($featured) data-open="{{ $featured->id }}" @endif>
                @if((string) ($featuredImg) !== '')<img src="{{ $featuredImg }}" alt="" data-csp-error="fallback" data-csp-fallback="{{ $defaultImg }}">@endif
                <span class="pp-shade"></span>
                @if($featured && $featured->rating_avg > 0)<span class="pp-rate">{{ number_format($featured->rating_avg,1) }}★</span>@endif
                <span class="pp-copy">
                  <small class="pp-kicker">{{ $featuredKicker }}</small>
                  <b>{{ $featured->name ?? ___('Our menu') }}</b>
                  <span class="pp-buy">{{ ___('Order now') }}@if($featured) · {{ $fmtMoney($featured->price) }}@endif</span>
                </span>
              </button>
              <button class="promo-menu" data-go="foods">
                <svg class="pm-icon" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/></svg>
                <b data-i18n="full_menu">Full Menu</b>
                <span class="pm-arrow"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
              </button>
            </div>

            {{-- Both tiles are gated on the feature actually being available: scheduling
                 on allow_order_scheduling, booking on enable_reservations (the endpoint
                 404s without it). A tile that opens a sheet the server will refuse is a
                 dead control, and the booking one used to fake a confirmed table. --}}
            {{-- The scheduling tile additionally honours the customizer's `mealplan`
                 section toggle — the week-calendar view it opens is gated on it too. --}}
            @php $mealplanOn = $schedEnabled && ($sectionVisible['mealplan'] ?? true); @endphp
            @if($mealplanOn || $resEnabled)
            <div class="quick-duo {{ ($mealplanOn && $resEnabled) ? '' : 'solo' }}">
              @if($mealplanOn)
              <button class="quick plan" data-go="mealplan">
                <span class="q-emoji">📅</span><span data-i18n="quick_plan">Order scheduling</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
              @if($resEnabled)
              <button class="quick book" id="reserveBtn">
                <span class="q-emoji">🪑</span><span data-i18n="quick_book">Book a table</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
            </div>
            @endif

            {{-- admin menu banners (real, optional) --}}
            @if(!empty($menuBanners) && count($menuBanners))
            <div class="banners" id="banners">
              @foreach($menuBanners as $banner)
                <a class="banner" href="{{ storefront_localized_link($banner->link_url) ?: '#' }}" @if($banner->link_url) target="_blank" rel="noopener" @endif>
                  @if($banner->image)
                  <span class="banner-img">@if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="" data-csp-error="closest-hide" data-csp-closest=".banner-img">@endif</span>
                  @endif
                  <span class="banner-copy">
                    @if($banner->subtitle)<small>{{ $banner->subtitle }}</small>@endif
                    <b>{{ $banner->title }}</b>
                    <span class="banner-cta">{{ ___('Order now') }}
                      <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                  </span>
                </a>
              @endforeach
            </div>
            @endif

            {{-- Stories rail — JS-populated from the public stories feed. Stays hidden
                 (and contributes no gap, see the .stories[hidden] guard) when empty. --}}
            <div class="stories" id="storiesRail" aria-label="{{ ___('Stories') }}" hidden></div>

            @if($sectionVisible['catbar'] ?? true)
            <nav class="cats" id="cats" aria-label="Categories" data-mb-grid-lead data-mbt-anchor></nav>
            {{-- Secondary sub-category row: revealed by JS for the active parent chip
                 (renderSubChips). Uses [hidden] so CSS display: rules don't force it open. --}}
            <nav class="subcats" id="subCats" aria-label="Sub-categories" hidden data-mb-grid-lead></nav>
            @endif

            @if($sectionVisible['bestsellers'] ?? true)
            <section class="grid-block">
              <div class="section-head" data-mb-grid-lead>
                <h2 id="bestTitle" data-i18n="best">Best Sellers</h2>
                <div class="head-tools">
                  @if($allowDietary)
                  <button class="linkbtn filter-btn" id="filterBtn" type="button">
                    <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 8h14M5 12h10M5 16h6"/></svg>
                    <span>{{ ___('Filters') }}</span><i class="filter-dot" id="filterDot" hidden></i>
                  </button>
                  @endif
                  <button class="linkbtn" data-go="foods" data-i18n="see_all">See all</button>
                </div>
              </div>
              <div class="bs-grid" id="grid"></div>
            </section>
            @endif
            <div class="tail-space"></div>
          </div>
        </section>

        <!-- FOODS LIST -->
        <section class="view view-sub" data-view="foods">
          <div class="view-scroll">
            <header class="page-head sub">
              <button class="fab back inline" data-back aria-label="Back"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 data-i18n="full_menu">Full Menu</h1>
              <button class="fab inline push-end" id="foodsSearch" aria-label="Search"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg></button>
            </header>
            <div class="food-rows" id="foodRows"></div>
            <div class="tail-space"></div>
          </div>
        </section>

        <!-- PRODUCT DETAIL -->
        <section class="view view-sub" data-view="product">
          <div class="view-scroll">
            <header class="page-head sub">
              <button class="fab back inline" data-back aria-label="Back"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 data-i18n="detail">Details</h1>
              <button class="fab inline push-end fav" id="pFav" aria-label="Favorite"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
            </header>

            <div class="hero-card" id="heroCard">
              <span class="hero-kicker" data-i18n="food_kicker">FOOD</span>
              <h1 class="hero-title" id="pName">—</h1>
              <div class="hero-stars" id="pStars" aria-hidden="true"></div>
              <div class="hero-photo-row">
                <button class="hero-qty" data-q="-1" aria-label="Decrease"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                <div class="hero-photo" id="pPhoto">
                  {{-- R8: prev/next gallery arrows for multi-image items (shown by JS
                       only when the dish has >1 photo). The dots stay as the indicator. --}}
                  <button class="gal-arr prev" id="pGalPrev" type="button" aria-label="{{ ___('Previous') }}" hidden><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                  <img id="pImg" alt=""><span class="hero-qty-badge">×<output id="pQtyVal">1</output></span>
                  <button class="gal-arr next" id="pGalNext" type="button" aria-label="{{ ___('Next') }}" hidden><svg class="ic" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></button>
                </div>
                <button class="hero-qty" data-q="1" aria-label="Increase"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
              </div>
              <div class="hero-meta" id="pMeta"></div>
              <div class="hero-desc-wrap">
                <p class="hero-desc" id="pDesc"></p>
                <button class="read-more" id="readMore" data-i18n="read_more">Read more…</button>
              </div>
              <div class="hero-add-row">
                <button class="hero-add" id="addToCart"><span data-i18n="add_cart">Add to cart</span><b id="pTotal">$0.00</b><span class="ha-plus">+</span></button>
                {{-- shown by script.js (syncBadge) once the bag holds something: a direct jump to the cart --}}
                <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                  <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
                </button>
              </div>
            </div>
            <div class="page-dots" id="pDots"></div>

            <div class="p-tags" id="pTags"></div>
            <div class="p-groups" id="pGroups"></div>

            <section class="rel-block">
              <div class="section-head"><h2 data-i18n="related">You may also like</h2></div>
              <div class="rail" id="relRail"></div>
            </section>
            <div class="tail-space tall"></div>
          </div>
        </section>

        {{-- SCHEDULED ORDER (was "Meal Plan"): the demo's weekly meal-plan subscription
             does not exist in this product. What DOES exist is pre-order scheduling —
             SchedulingService validates `scheduled_at` server-side against lead time,
             horizon, opening hours and slot capacity. So this screen is now exactly
             that: pick a day + slot, and the chosen time rides on the order. It no
             longer stuffs two arbitrary items into the bag, and the "History" button
             that always toasted "No past plans yet" is gone. --}}
        @if($sectionVisible['mealplan'] ?? true)
        <section class="view view-sub" data-view="mealplan">
          <div class="view-scroll">
            <header class="page-head sub">
              <button class="fab back inline" data-back aria-label="Back"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 data-i18n="meal_plan">Order scheduling</h1>
            </header>

            <div class="mp-head">
              <h2 class="mp-title" data-i18n="mp_q">Choose a time</h2>
            </div>

            <div class="week">
              <div class="week-head">
                <button class="week-arr" id="weekPrev" aria-label="Previous week"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                <b id="weekLabel">—</b>
                <button class="week-arr" id="weekNext" aria-label="Next week"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></button>
              </div>
              <div class="days" id="dayRow"></div>
            </div>

            <div class="mp-times">
              <span class="mp-times-label" data-i18n="mp_time">Delivery slot</span>
              <div class="chiprow" id="mpTimes">
                <button data-slot="12:30" class="is-on">☀️ <span data-i18n="lunch">Lunch</span> 12:30</button>
                <button data-slot="19:30">🌙 <span data-i18n="dinner">Dinner</span> 19:30</button>
              </div>
            </div>

            <button class="cta ink wide" id="mpContinue"><span data-i18n="continue">Continue</span><span class="arr">→</span></button>
            <div class="tail-space tall"></div>
          </div>
        </section>
        @endif

        {{-- LOYALTY — real program only.
             The demo shipped an entire invented points economy: a localStorage balance,
             a reward catalogue priced at `max(20, price*10)`, and a "Get Now" button
             that dropped a ZERO-PRICED line into the cart. The server charges full price
             for that line, so a redeemed "reward" was quoted $0.00 and billed $4.50, and
             the points never left the browser. It is replaced by the platform's real
             loyalty program (publicView.loyalty.balance) — read-only, signed-in only,
             and the whole surface hides itself when the restaurant runs no program. --}}
        @if($sectionVisible['rewards'] ?? true)
        <section class="view view-sub" data-view="points">
          <div class="view-scroll">
            <header class="page-head sub">
              <button class="fab back inline" data-back aria-label="Back"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 data-i18n="rewards">Rewards</h1>
            </header>

            <div class="pts-headrow">
              <h2 class="pts-head"><b id="ptsNum">0</b> <span data-i18n="pts_head2">Points</span></h2>
            </div>
            <div class="pts-bar" id="ptsBar" hidden><i id="ptsBarFill"></i></div>
            <p class="earn-hint"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg><span id="ptsHint" data-i18n="pts_earn">Earn points with every order you place</span></p>

            <div class="rw-ledger" id="rwLedger"></div>
            <div class="tail-space tall"></div>
          </div>
        </section>
        @endif

        <!-- CART / BAG -->
        <section class="view" data-view="cart">
          <div class="view-scroll">
            <header class="page-head">
              <h1 data-i18n="my_bag">My Bag</h1>
              <button class="ghostbtn sm" id="clearCart" data-i18n="clear">Clear</button>
            </header>
            <div id="cartList" class="cart-list"></div>
            <div class="grid-empty" id="cartEmpty" hidden>
              <span class="empty-emoji">🧺</span>
              <p data-i18n="bag_empty">Your bag is empty — let&rsquo;s fix that</p>
              <button class="ghostbtn" data-go="home" data-i18n="browse">Browse menu</button>
            </div>

            <div id="cartTools" class="cart-tools">
              <section class="ordertype">
                <h3 data-i18n="order_type">Order type</h3>
                <div class="seg" id="orderTypeSeg">
                  @if($allow_delivery)<button data-ot="delivery"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span data-i18n="ot_delivery">Delivery</span></button>@endif
                  @if($allow_takeaway)<button data-ot="pickup"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span data-i18n="ot_pickup">Pickup</span></button>@endif
                  @if($allow_on_table)<button data-ot="dinein"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span data-i18n="ot_dinein">Dine-in</span></button>@endif
                  {{-- Hotel in-room dining. $allow_room_service is 1 only when hotel mode is on
                       AND the scanned QR resolved to an area_type='room' table, so this never
                       renders for an ordinary restaurant. data-ot is the theme-local name;
                       OT_MAP in script.js sends it to the server as `room_service`. --}}
                  @if($allow_room_service)<button data-ot="roomservice"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span data-i18n="ot_roomservice">Room service</span></button>@endif
                </div>
                <div class="ot-fields" id="otFields"></div>
                @if($schedEnabled)
                <div class="schedule-row" id="schedRow">
                  <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg><span data-i18n="when">When</span></span>
                  <div class="seg mini" id="whenSeg">
                    <button data-when="asap" class="is-on" data-i18n="asap">ASAP</button>
                    <button data-when="later" data-i18n="later">Later</button>
                  </div>
                  <select aria-label="{{ ___('Schedule for later') }}" id="whenTime" class="when-time" hidden></select>
                </div>
                @endif
                <div class="plan-chip" id="planChip" hidden></div>
              </section>

              @if($allowCoupons)
              <section class="coupon">
                <label class="coupon-field">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                  <input id="couponInput" data-i18n-ph="coupon_ph" placeholder="Promo code">
                  <button class="minibtn" id="couponApply" data-i18n="apply">Apply</button>
                </label>
                <p class="coupon-msg" id="couponMsg" hidden></p>
              </section>
              @endif

              <section class="totals" id="totalsBox"></section>

              <div class="waiter-row" id="waiterRow" hidden>
                @if($allowWaiter)<button class="ghostbtn" id="callWaiter"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span data-i18n="call_waiter">Call waiter</span></button>@endif
                <button class="ghostbtn" id="requestBill"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span data-i18n="request_bill">Request bill</span></button>
              </div>

              {{-- Was a bare <button> with no handler/form — a dead "Order via
                   WhatsApp" CTA. It is the click-to-chat link the theme already
                   uses at #waProfile, so make it the same real anchor. --}}
              @if($waNumber)
              <a class="wa-row" id="waOrder" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                <span data-i18n="wa_order">Order via WhatsApp</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </a>
              @endif

              {{-- Share this menu — native profile row (like the WhatsApp .wa-row),
                   not the floating pill. Gated on the same share.fallback_button
                   toggle; data-web-share makes _partials/share stand down. --}}
              @if(theme_setting($store->id, 'share.fallback_button'))
              <button class="wa-row share-btn" id="mbShareRow" type="button" data-web-share
                      aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
                <span>{{ ___('Share this menu') }}</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
            </div>
            <div class="tail-space"></div>
          </div>
          <footer class="p-cta root" id="cartCta" hidden>
            <button class="cta ink wide" id="goCheckout"><span data-i18n="checkout">Checkout</span><b id="cartCtaTotal">$0.00</b></button>
          </footer>
        </section>

        <!-- CHECKOUT -->
        <section class="view view-sub" data-view="checkout">
          <div class="view-scroll">
            <header class="page-head sub">
              <button class="fab back inline" data-back aria-label="Back"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 data-i18n="checkout">Checkout</h1>
            </header>

            <section class="contact">
              <h3 data-i18n="contact">Contact</h3>
              <div class="field-duo">
                <input aria-label="{{ ___('Your Name') }}" id="custName" data-i18n-ph="your_name" placeholder="Your name" autocomplete="name" value="{{ $customer->name ?? '' }}">
                <input aria-label="{{ ___('Phone') }}" id="custPhone" data-i18n-ph="phone" placeholder="Phone" inputmode="tel" autocomplete="tel" value="{{ $customer->phone ?? '' }}">
              </div>
            </section>

            <section class="addr" id="addrBlock">
              <h3 data-i18n="delivery_address">Delivery address</h3>
              <div class="map" aria-hidden="true">
                <svg class="map-art" viewBox="0 0 320 110" preserveAspectRatio="xMidYMid slice">
                  <path class="mr" d="M-10 36 C60 26 90 62 160 56 S 280 26 340 40"/>
                  <path class="mr" d="M60 -10 C56 30 78 60 66 120"/>
                  <path class="mr" d="M210 -10 C218 40 196 76 220 120"/>
                  <path class="mr thin" d="M-10 82 C80 76 200 94 330 80"/>
                  <path class="mr thin" d="M130 -10 C126 30 148 60 134 120"/>
                  <rect class="mb" x="30" y="14" width="24" height="14" rx="3"/>
                  <rect class="mb" x="96" y="66" width="22" height="16" rx="3"/>
                  <rect class="mb" x="176" y="18" width="28" height="13" rx="3"/>
                  <rect class="mb" x="252" y="58" width="24" height="16" rx="3"/>
                </svg>
                <span class="pin"><svg viewBox="0 0 24 24"><path d="M12 22s-7.5-6-7.5-11.5a7.5 7.5 0 1 1 15 0C19.5 16 12 22 12 22Z"/><circle cx="12" cy="10.4" r="2.8"/></svg><i class="pin-pulse"></i></span>
              </div>
              {{-- Saved addresses: logged-in customers get their address book; guests get
                   a list JS builds (renderRecentAddrs) from addresses used before on this
                   device (localStorage). Either way, tapping a chip fills the field +
                   coords (handler wired once, delegated on #savedAddrs). --}}
              @if($customerAddresses->count())
              <div class="saved-addrs" id="savedAddrs">
                @foreach($customerAddresses as $sa)
                <button class="saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                        data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                  <span class="sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                </button>
                @endforeach
              </div>
              @else
              <div class="saved-addrs" id="savedAddrs" hidden></div>
              @endif
              {{-- address autocomplete + use-my-location (geo.autocomplete / geo.reverse) --}}
              <div class="addr-field" id="custAddrField">
                <input aria-label="{{ ___('Delivery address') }}" id="custAddr" data-i18n-ph="addr_ph" placeholder="Street, building, floor…" autocomplete="off" autocorrect="off" spellcheck="false"
                       value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
                <input type="hidden" id="custLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                <input type="hidden" id="custLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                <button class="uselocbtn" id="custUseLocation" type="button" title="{{ ___('Use my location') }}" aria-label="{{ ___('Use my location') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                </button>
                <div class="addr-suggest" id="custAddrSuggest" hidden></div>
              </div>
              <p class="geo-attrib" id="custGeoAttrib" hidden></p>
            </section>

            <div class="dine-chip" id="dineChip" hidden></div>

            @if($allowTips)
            <section class="tips">
              <h3 data-i18n="tip">Add a tip</h3>
              <div class="tip-row" id="tipRow">
                <button data-tip="0" class="is-on" data-i18n="no_tip">None</button>
                <button data-tip="5">5%</button>
                <button data-tip="10">10%</button>
                <button data-tip="15">15%</button>
              </div>
            </section>
            @endif

            <section class="paymethods">
              <h3 data-i18n="pay_with">Pay with</h3>
              <div class="pm-list" id="pmList"></div>
              {{-- The demo's inline card form (cardholder / MM-YY / CVC / "4242 4242 4242
                   4242") collected a full PAN into fields that were never read and never
                   sent anywhere — submitOrder only takes `pay_via`, and the real card entry
                   happens on the gateway page it redirects to. Asking a diner to type a
                   card number into a dead input is the worst kind of fake control, so it
                   is replaced by an honest note about where payment is taken. --}}
              <p class="pm-note" id="cardNote" hidden data-i18n="card_note">You&rsquo;ll be taken to our secure payment page after you place the order.</p>
            </section>

            {{-- Order summary — the customer should see WHAT they are paying for at
                 the point of commitment, not just a number. Rendered by renderCheckout. --}}
            <section class="ck-items" id="ckItems" hidden></section>
            <section class="totals" id="checkoutTotals"></section>
            <label class="terms"><input type="checkbox" id="termsChk" checked><span data-i18n="terms">I accept the terms &amp; privacy policy</span></label>
            <div class="tail-space tall"></div>
          </div>
          <footer class="p-cta">
            <button class="cta ink wide" id="placeOrder"><span class="po-label" data-i18n="place_order">Place Order</span><b id="checkoutCtaTotal">$0.00</b><span class="spinner" hidden></span></button>
          </footer>
        </section>

        <!-- CONFIRMED -->
        <section class="view view-sub view-center" data-view="confirmed">
          <div class="confirm-wrap">
            <div class="confetti" id="confetti" aria-hidden="true"></div>
            <div class="basket-art" aria-hidden="true">
              <svg class="basket" viewBox="0 0 220 180">
                <path class="bk-handle" d="M66 74C66 40 84 22 110 22s44 18 44 52"/>
                <path class="bk-body" d="M38 74h144l-14 74a12 12 0 0 1-12 10H64a12 12 0 0 1-12-10Z"/>
                <path class="bk-weave" d="M46 96h128M52 120h116M58 144h104"/>
                <path class="bk-weave" d="M74 76l10 80M110 76v82M146 76l-10 80"/>
                <rect class="bk-lip" x="32" y="66" width="156" height="16" rx="8"/>
                <g class="check-badge"><circle cx="168" cy="52" r="24"/><path class="check" d="M157 52.5l7.5 7.5L179 45" pathLength="1"/></g>
              </svg>
            </div>
            <h1 class="confirm-title" data-i18n="confirmed">Order Confirmed</h1>
            <p class="confirm-sub"><span data-i18n="confirm_sub">Your order is being packed</span><br><span class="confirm-eta" id="confirmEta"></span></p>
            {{-- The reference chip used to print `"#" + Math.floor(1000+random*9000)` —
                 a number that matched no row in `orders`. It now shows only what the
                 server returns, and stays hidden when the server returns nothing
                 (submitOrder currently sends no order id — see BLOCKED-SHARED). --}}
            <div class="confirm-code" id="confirmCode" hidden></div>
            <div class="confirm-total" id="confirmTotal"></div>
            <div class="confirm-actions">
              <a class="cta ink wide" id="confirmTrack" href="#"><span data-i18n="track_order">Track Order</span></a>
              <button class="ghostbtn" id="backToMenu"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span data-i18n="back_menu">Back to menu</span></button>
            </div>
          </div>
        </section>

        <!-- TRACK -->
        <section class="view view-sub" data-view="track">
          <div class="view-scroll">
            <header class="page-head sub">
              <button class="fab back inline" data-back aria-label="Back"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 data-i18n="track_order">Track Order</h1>
            </header>
            {{-- The timeline is driven by the REAL order status, polled from the
                 server track page (which answers JSON to an XHR). It used to be a
                 setInterval that walked to "Delivered" 21 seconds after checkout no
                 matter what the kitchen was doing — measured on order #99590, which
                 was still `pending` while this screen said the food had arrived.
                 The "Your courier" row is gone with it: it showed the restaurant's
                 own logo and claimed it was the rider. --}}
            <div class="track-card">
              <div class="track-top">
                <div><p class="tk-label" data-i18n="order_no">Order</p><b id="trackCode">—</b></div>
                <div class="tk-eta"><p class="tk-label" data-i18n="eta">Arrives in</p><b id="trackEta">—</b></div>
              </div>
              <div class="courier" id="courierRow">
                @if((string) ($logoImg) !== '')<img src="{{ $logoImg }}" alt="" data-csp-error="fallback" data-csp-fallback="{{ $defaultImg }}">@endif
                <div><b>{{ $store->title }}</b><p class="tk-label" data-i18n="contact_store">Help</p></div>
                @if($store->phone)<a class="iconbtn solid" href="tel:{{ $store->phone }}" aria-label="Call"><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg></a>@endif
                @if($waNumber)<a class="iconbtn wa" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg></a>@endif
              </div>
              <ol class="timeline" id="timeline"></ol>
              <section class="totals" id="trackTotals"></section>
              <a class="ghostbtn wide" id="trackFull" href="#" hidden data-i18n="full_receipt">Receipt</a>
            </div>

            <div class="review-card" id="reviewCard">
              <p class="rev-q" data-i18n="rate_q">How was your order?</p>
              <div class="stars-row" id="revStars"></div>
              <a class="cta google wide" id="revGoogle" href="{{ @$postOptions->google_review_link ?: '#' }}" target="_blank" rel="noopener" hidden><span class="g-badge">G</span><span data-i18n="rate_google">Rate us on Google</span></a>
              <p class="rev-thanks" id="revThanks" hidden data-i18n="rate_thanks">Thanks for the feedback!</p>
            </div>
            <div class="tail-space"></div>
          </div>
        </section>

        <!-- FAVORITES -->
        <section class="view" data-view="favs">
          <div class="view-scroll">
            <header class="page-head"><h1 data-i18n="favorites">Favorites</h1></header>
            <div class="bs-grid" id="favGrid"></div>
            <div class="grid-empty" id="favEmpty" hidden>
              <span class="empty-emoji">💚</span>
              <p data-i18n="fav_empty">Tap the heart on any dish to save it</p>
            </div>
            <div class="tail-space"></div>
          </div>
        </section>

        <!-- PROFILE -->
        <section class="view" data-view="profile">
          <div class="view-scroll">
            <header class="page-head"><h1 data-i18n="profile">Profile</h1></header>
            <div class="me-card">
              @if($customer)
              <span class="me-avatar">{{ Str::upper(Str::substr($customer->name, 0, 1)) }}</span>
              <div><b>{{ $customer->name }}</b><p class="tk-label">{{ $customer->phone ?? $customer->email }}</p></div>
              @else
              @if((string) ($logoImg) !== '')<img src="{{ $logoImg }}" alt="" data-csp-error="fallback" data-csp-fallback="{{ $defaultImg }}">@endif
              <div>
                <b>{{ $authMode !== 'guest_only' ? ___('Guest') : $store->title }}</b>
                @if($authMode !== 'guest_only')
                <p class="tk-label">{{ ___('Sign in to keep your orders and addresses together.') }}</p>
                @else
                <p class="tk-label" data-i18n="guest_sub">Member</p>
                @endif
              </div>
              @endif
              @if($authMode !== 'guest_only')
                @if($customer)<button class="auth-pill" id="logoutBtn" type="button">{{ ___('Logout') }}</button>
                @else<button class="auth-pill" id="meAuthBtn" type="button">{{ ___('Login') }}</button>@endif
              @endif
              <button class="pts-pill" id="profPtsBtn" data-go="points" hidden><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg><b id="profPts">0</b></button>
            </div>

            @if($customer)
            <div class="wallet-card">
              <div><p class="wc-label" data-i18n="wallet_bal">Wallet balance</p><b class="wallet-amt" id="walletAmt">$0.00</b></div>
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h12v3M4 7v10a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H4"/><circle cx="16.5" cy="14" r="1.3" fill="currentColor" stroke="none"/></svg>
            </div>
            @endif

            <section class="prof-links">
              @if($customer)
              <a class="prof-row" href="{{ route('customer.profile', $store->id) }}">
                <span class="pr-ic"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></span><span>{{ ___('Profile & settings') }}</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </a>
              @endif
              <button class="prof-row" data-go="points" id="ptsRow" hidden>
                <span class="pr-ic">⭐</span><span data-i18n="my_points">My points</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @if($mealplanOn)
              <button class="prof-row" data-go="mealplan">
                <span class="pr-ic">📅</span><span data-i18n="meal_plan">Order scheduling</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
              {{-- Opener gated on reservations OR waitlist: the sheet it opens
                   holds the only waitlist control (#wlJoin), so a waitlist-only
                   store needs this entry too, else the waitlist is unreachable. --}}
              @if($resEnabled || $wlEnabled)
              <button class="prof-row" id="reserveRow">
                <span class="pr-ic">🪑</span><span data-i18n="{{ $resEnabled ? 'reservations' : 'wl_tag' }}">{{ $resEnabled ? 'Reservations' : 'Waitlist' }}</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
              @if($showBranches)
              <button class="prof-row" id="nearestBranchBtn" type="button">
                <span class="pr-ic">📍</span><span>{{ ___('Find nearest branch') }}</span>
                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
            </section>
            <div class="res-list" id="resList"></div>

            <section class="pref">
              <h3 data-i18n="preferences">Preferences</h3>
              @if(count($menu_languages ?? []) > 1)
              <div class="pref-row">
                <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg><span data-i18n="language">Language</span></span>
                <div class="seg mini" id="langSeg">
                  @foreach($menu_languages as $language)
                    <button data-lang-code="{{ $language->code }}" class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ $language->name }}</button>
                  @endforeach
                </div>
              </div>
              @endif
              {{-- picnic shipped no dark mode at all (zero prefers-color-scheme rules).
                   Auto follows the OS; Light/Dark pin it, and the choice persists. --}}
              <div class="pref-row">
                <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6L17 7M7 17l-1.4 1.4"/></svg><span data-i18n="appearance">Appearance</span></span>
                <div class="seg mini" id="themeSeg">
                  <button data-mode="auto" data-i18n="mode_auto">Auto</button>
                  <button data-mode="light" data-i18n="mode_light">Light</button>
                  <button data-mode="dark" data-i18n="mode_dark">Dark</button>
                </div>
              </div>
              {{-- Currency switcher (display-only): repaints every price converted, ≈
                   on non-base. Store-scoped persistence via the shared mbCurrency helper.
                   Hidden unless the owner enabled multi-currency AND rates exist. Not
                   gated on $allow_order — a view-only menu still shows prices. --}}
              @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
              <div class="pref-row">
                <span><svg class="ic" viewBox="0 0 24 24"><path d="M12 3v18M8 7.5C8 6 9.6 5 12 5s4 1 4 2.5S14.5 10 12 10s-4 1-4 2.5S9.5 15 12 15s4 1 4 2.5-1.6 2.5-4 2.5-4-1-4-2.5"/></svg><span data-i18n="currency">Currency</span></span>
                <div class="seg mini" id="curSeg">
                  <button data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1" class="is-on" type="button">{{ config('settings.currency_code', 'USD') }}</button>
                  @foreach($mcRates as $code => $rate)<button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>@endforeach
                </div>
              </div>
              @endif
            </section>

            {{-- Order history is now REAL. Two sources, no invention:
                 (1) orders confirmed on this device — recorded with the server's own
                     track_url and the amount the server actually charged;
                 (2) a phone/email lookup against restaurant.orderLookup, which returns
                     the store's real order ids, totals and statuses.
                 The old list rendered localStorage rows whose "#4821" reference matched
                 no row in `orders` and whose status came from a 7-second timer. --}}
            <section class="orders-block">
              <h3 data-i18n="my_orders">My orders</h3>
              <p class="lookup-help">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
              <label class="coupon-field lookup-field">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input id="lookupInput" inputmode="tel" autocomplete="tel" data-i18n-ph="lookup_ph" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
                <button class="minibtn" id="lookupGo" data-i18n="lookup_go">Find</button>
              </label>
              <p class="coupon-msg" id="lookupMsg" hidden></p>
              <div id="ordersList" class="orders-list"></div>
              <p class="orders-empty" id="ordersEmpty" data-i18n="orders_empty">Orders you place appear here</p>
            </section>

            @if($waNumber)
            <a class="wa-row" id="waProfile" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
              <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
              <span data-i18n="wa_us">Chat with us on WhatsApp</span>
              <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            @endif
            <p class="demo-foot">{{ $store->title }}</p>
            <div class="tail-space"></div>
          </div>
        </section>

      </main>

      @include('store_templates._partials.notif-bell')

      <!-- ══════════ BOTTOM NAV ══════════ -->
      <nav class="tabbar" id="tabbar" aria-label="Main">
        <button class="tab is-on" data-tab="home" aria-label="Home"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg></span></button>
        <button class="tab" data-tab="menu" aria-label="{{ ___('Menu') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span></button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="tab" data-tab="search" aria-label="{{ ___('Search') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg></span></button>
        @endif
        <button class="tab" data-tab="cart" aria-label="Bag"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><i class="badge" id="cartBadge" hidden>0</i></span></button>
        <button class="tab" data-tab="favs" aria-label="Favorites"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></span></button>
        <button class="tab" data-tab="profile" aria-label="Profile"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></span></button>
      </nav>

      <!-- ══════════ SEARCH OVERLAY ══════════ -->
      <div class="search-ov" id="searchOv" hidden>
        <div class="so-bar">
          <label class="so-field">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <input aria-label="{{ ___('Search the menu') }}" id="soInput" type="search" data-i18n-ph="search_ph" placeholder="Search the menu…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
          </label>
          <button class="so-cancel" id="soClose" data-i18n="so_cancel">Cancel</button>
        </div>
        <div class="so-suggest" id="soSuggest">
          <p data-i18n="so_hint">Try one of these</p>
          <div class="chiprow" id="soChips"></div>
        </div>
        <div class="so-results" id="soResults"></div>
      </div>

      <!-- ══════════ STORY VIEWER ══════════ -->
      {{-- full-screen story viewer overlay (opened from the home stories rail) --}}
      <div class="story-view" id="storyView" hidden>
        <div class="sv-progress" id="svProgress"></div>
        <button class="sv-close" id="svClose" type="button" aria-label="{{ ___('Close') }}">
          <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="sv-stage" id="svStage">
          <img class="sv-media" id="svMedia" alt="">
          <button class="sv-nav prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
          <button class="sv-nav next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
          <div class="sv-foot">
            <p class="sv-caption" id="svCaption"></p>
            <a class="cta wide sv-cta" id="svCta" target="_blank" rel="noopener" hidden><span></span></a>
          </div>
        </div>
      </div>

      <!-- ══════════ SHEETS & OVERLAYS ══════════ -->
      <div class="scrim" id="scrim" hidden></div>

      {{-- Real booking. It POSTs to publicView.reservation.book, which creates a
           Reservation row the restaurant sees in its dashboard. Previously the sheet
           pushed an object into localStorage and told the diner "Table booked" —
           nobody at the restaurant ever knew. It also invented a "20:00 is full" slot
           and offered a waitlist for it; real availability is not exposed to the
           storefront, so every offered slot is simply requestable, and the waitlist
           is its own opt-in feature (enable_waitlist) rather than a fake-full trap. --}}
      @if($resEnabled || $wlEnabled)
      <div class="sheet" id="reserveSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (top corner); wired in JS (picnic closes via the scrim). --}}
        <button class="sheet-x" type="button" aria-label="{{ ___('Close') }}">
          <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2 data-i18n="book_table">Book a table</h2>
        <div class="field-duo">
          <input aria-label="{{ ___('Your Name') }}" id="resName" data-i18n-ph="your_name" placeholder="Your name" autocomplete="name" value="{{ $customer->name ?? '' }}">
          <input aria-label="{{ ___('Phone') }}" id="resPhone" data-i18n-ph="phone" placeholder="Phone" inputmode="tel" autocomplete="tel" value="{{ $customer->phone ?? '' }}">
        </div>
        @if($resEnabled)
        <h4 data-i18n="date">Date</h4>
        <div class="chiprow" id="resDates"></div>
        <h4 data-i18n="time">Time</h4>
        <div class="chiprow" id="resTimes"></div>
        @endif
        <h4 data-i18n="party">Party size</h4>
        <div class="party">
          <button class="qty-btn" id="resMinus" aria-label="Fewer"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
          <output id="resParty">2</output>
          <button class="qty-btn" id="resPlus" aria-label="More"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
        </div>
        <p class="coupon-msg" id="resMsg" hidden></p>
        @if($resEnabled)
        @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
        <button class="cta ink wide" id="resConfirm" data-i18n="res_confirm">Confirm reservation</button>
        @endif
        @if($wlEnabled)
        <div class="wl-box">
          <p><b data-i18n="wl_q">Prefer to walk in?</b><br><span data-i18n="wl_sub">Join the queue and we&rsquo;ll text you when a table frees up.</span></p>
          <button class="cta green" id="wlJoin" data-i18n="wl_join">Join waitlist</button>
        </div>
        @endif
      </div>
      @endif

      <div class="sheet" id="noteSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 data-i18n="item_note">Item note</h2>
        <textarea aria-label="{{ ___('Any special requests?') }}" id="noteText" rows="3" data-i18n-ph="note_ph" placeholder="No onions please…"></textarea>
        @include('store_templates._partials.voice-note-recorder')
        <button class="cta ink wide" id="noteSave" data-i18n="save">Save</button>
      </div>

      {{-- Catering & Events sheet (opened by #cvCateringBtn / the condensed
           data-cv-proxy="catering" button). cateringInline=true keeps the shared
           form here instead of self-relocating to the bottom of the menu. --}}
      @if(storefront_catering_enabled($store->id))
      <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
      </div>
      @endif

      @if($authMode !== 'guest_only')
      <div class="sheet" id="authSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Login') }} / {{ ___('Register') }}</h2>
        <div class="seg mini auth-tabs" id="authTabs">
          <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
          <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
          <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <p class="err-box auth-err" id="authErr" hidden></p>
        <div class="auth-form" data-auth="login">
          <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
          <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
          <button class="cta ink wide" id="loginBtn" type="button">{{ ___('Login') }}</button>
          @include('store_templates.partials.social-login-buttons', ['store' => $store])
          @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="auth-form" data-auth="otp" hidden>
          <div id="otpStep1">
            <p class="auth-sub">{{ ___("We'll text you a one-time code") }}</p>
            <input aria-label="{{ ___('Phone number') }}" id="otpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
            <button class="cta ink wide" id="otpSend" type="button">{{ ___('Send code') }}</button>
          </div>
          <div id="otpStep2" hidden>
            <p class="auth-sub">{{ ___('Enter the code we sent you') }}</p>
            <div class="otp-boxes" id="otpBoxes"><input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric"></div>
            <button class="cta ink wide" id="otpVerify" type="button">{{ ___('Verify') }}</button>
          </div>
        </div>
        <div class="auth-form" data-auth="register" hidden>
          <input aria-label="{{ ___('Your name') }}" id="regName" placeholder="{{ ___('Your name') }}" autocomplete="name">
          <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
          <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
          <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
          <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
          <button class="cta ink wide" id="registerBtn" type="button">{{ ___('Register') }}</button>
          @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
      </div>
      @endif

      {{-- the "Points history" sheet listed a localStorage ledger of points this
           theme minted itself; the real program's ledger now renders inline in the
           Rewards view, straight from the server response. --}}

      {{-- FILTER sheet: dietary / spice / allergens (gated on allow_dietary_filters).
           passFilters() composes these with the two-tier nav in homeItems()/search. --}}
      @if($allowDietary)
      <div class="sheet" id="filterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Filters') }}</h2>
        <h4>{{ ___('Dietary') }}</h4>
        <div class="chiprow" id="dietRow">
          <button data-diet="vegan" type="button">{{ ___('Vegan') }}</button>
          <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
          <button data-diet="gluten_free" type="button">{{ ___('Gluten-free') }}</button>
        </div>
        <h4>{{ ___('Spice level') }}</h4>
        <div class="chiprow" id="spiceRow">
          <button data-spice="1" type="button">🌶</button>
          <button data-spice="2" type="button">🌶🌶</button>
          <button data-spice="3" type="button">🌶🌶🌶</button>
        </div>
        @if(count($allergies ?? []))
        <h4>{{ ___('Exclude allergens') }}</h4>
        <div class="chiprow" id="allergenRow">
          @foreach($allergies as $a)
            <button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>
          @endforeach
        </div>
        @endif
        <div class="filter-actions">
          <button class="ghostbtn" id="filterReset" type="button">{{ ___('Reset') }}</button>
          <button class="cta ink" id="filterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="filterCount"></b></button>
        </div>
      </div>
      @endif

      {{-- nearest-branch map picker (self-hosted Leaflet, geo branch switcher) --}}
      @if($showBranches)
      @if(count($menu_languages ?? []) > 1)
      <div class="sheet" id="langSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Language') }}</h2>
        <div class="branch-list">
          @foreach($menu_languages as $language)
            <button class="branch-row {{ get_lang() === $language->code ? 'is-on' : '' }}" data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button">
              <b>{{ $language->name }}</b><span class="branch-check"></span>
            </button>
          @endforeach
        </div>
      </div>
      @endif

      <div class="sheet" id="branchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Nearest branch') }}</h2>
        {{-- R3: a plain, server-rendered list of EVERY branch (navigates by
             ?branch_id=<id> via the layout's data-csp-branch-switch handler). The geo
             map below is a nicety; this select is the reachable fallback so a branch
             with no lat/lng is still selectable. --}}
        @include('store_templates.partials.branch-switcher')
        <div id="branchMap" style="height:280px;border-radius:16px;overflow:hidden;margin:6px 0"></div>
        <p id="branchMapAttrib" style="font-size:11px;opacity:.55;margin:6px 2px"></p>
        <div class="branch-list" id="branchGeoList"></div>
      </div>

      {{-- lean branch picker opened by the header .loc button (crave parity): a plain
           list of every branch, switching via ?branch_id= (no map/geo dependency). --}}
      <div class="sheet" id="mbBranchSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Choose branch') }}</h2>
        <div class="branch-list">
          @foreach($branches as $branch)
            <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
              <b>{{ $branch->name }}</b><span class="branch-check"></span>
            </a>
          @endforeach
        </div>
      </div>
      @endif

      <div class="toast" id="toast" role="status"></div>
      <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
    </div><!-- /.app -->
  </div><!-- /.phone -->
</div>

{{-- Catering & Events now lives in #cvCateringSheet (opened from the header
     button / condensed proxy); the form is included there with cateringInline=true
     so it does not self-relocate back to the bottom of the menu. --}}

{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endsection

@push('scripts_at_bottom')
{{-- One key per weekday, Sunday-first to match JS getDay(). NOT single Latin letters:
     'T' can't serve Tuesday+Thursday and 'S' can't serve Sunday+Saturday outside English,
     and the old ___('T') was shared with the floor map's table prefix. Locales without a
     single-letter convention carry their shortest natural abbreviation instead. --}}
@php $pcDowLetters = [___('dow_sun'),___('dow_mon'),___('dow_tue'),___('dow_wed'),___('dow_thu'),___('dow_fri'),___('dow_sat')]; @endphp
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
window.PICNIC = {
    storeId:   @json($store->id),
    slug:           @json($store->slug),
    leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
    branchesGeo:    @json($showBranches ? route('geo.branches', $store->slug) : null),
    {{-- FEATURES-5 Track A — enabled social providers for the JS-built auth sheet --}}
    {{-- {!! !!} + json_encode instead of @json: Blade's compileJson comma-explodes multi-arg expressions (broke these 4 themes' pages) --}}
    socialLogin:    {!! json_encode(collect(app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($store->id))->map(fn($p)=>['provider'=>$p,'label'=>ucfirst($p),'url'=>route('customer.social.redirect',[$store->id,$p])])->values(), 15) !!},
    {{-- ZERO-TOUCH A3.2 — agent chat data seam (theme-built UI; no forced partial). enabled:false when owner/plan off --}}
    agentChat:      @json(\App\Support\AgentChatWidget::seam($store)),
    authMode:       @json($authMode),
    isLoggedIn:     @json((bool) $customer),
    loginUrl:       @json(route('customer.login', $store->id)),
    registerUrl:    @json(route('customer.register', $store->id)),
    logoutUrl:      @json(route('customer.logout', $store->id)),
    otpRequestUrl:  @json(route('customer.otp.request', $store->id)),
    otpVerifyUrl:   @json(route('customer.otp.verify', $store->id)),
    orderUrl:       @json(route('store.submitOrder', $store->id)),
    couponUrl:      @json(route('store.applyCoupon', $store->id)),
    waiterUrl:      @json(route('store.callTheWaiter', $store->id)),
    storiesUrl:     @json(route('stories.public', $store->slug)),
    {{-- brand/follow row (F2 parity) — bearer token for the CustomerToken-guarded
         follow endpoint + the store's current follow state for the button --}}
    customerToken:  @json($customerApiToken),
    followUrl:      @json(url('r/'.$store->slug.'/follow')),
    isFollowing:    @json((bool) $isFollowing),
    followerCount:  @json((int) $followerCount),
    {{-- submitNps resolves the store by SLUG only (Post::where('slug',…)); passing
         the id here would 404 the rating silently. orderLookup accepts either. --}}
    npsUrl:         @json(route('store.submitNps', $store->slug)),
    lookupUrl:      @json(route('store.orderLookup', $store->slug)),
    reserveUrl:     @json($resEnabled ? route('publicView.reservation.book', $store->slug) : null),
    waitlistUrl:    @json($wlEnabled ? route('publicView.waitlist.join', $store->slug) : null),
    loyaltyUrl:     @json($customer ? route('publicView.loyalty.balance', $store->id) : null),
    loyaltyRedeemUrl: @json($customer ? route('publicView.loyalty.redeem', $store->id) : null),
    geoAutocomplete: @json(route('geo.autocomplete')),
    geoReverse:      @json(route('geo.reverse')),
    prepEta:        @json($prepEta),
    csrf:           @json(csrf_token()),
    curSign:        @json($curSign),
    curGap:         @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
    curPos:         @json($curPos),
    curDecimals:    @json($curDecimals),
    moneyDecimals: @json(money_decimals(@$postOptions->currency_code ?: null)), {{-- bug batch 10 · 4 --}}
    decimalsByCode: @json(currency_decimals_map()),
    baseCurrency:   @json(config('settings.currency_code', 'USD')),
    currencyRates:  @json($mcRates),
    deliveryCharge: @json($deliveryCharge),
    taxCharge:      @json($taxCharge),
    taxType:        @json($taxType),
    taxLabel:       @json($taxLabel),
    minOrder:       @json($minOrderValue),
    allowOrder:     @json((bool) $allow_order),
    allowPayment:   @json((bool) $allow_payment),
    orderTypes:     @json($otAllowed),
    forceTable:     @json($table->table_no ?? null),
    isRtl:          @json($isRtl),
    lang:           @json(get_lang()),
    schedEnabled:   @json($schedEnabled),
    storeCredit:    @json($storeCredit),
    loyaltyPoints:  @json($loyaltyPoints),
    defaultImg:     @json($defaultImg),
    cats:           @json($jsCats),
    items:          @json($jsItems),
    i18n:           @json($i18n),
    dow:            @json($pcDowLetters),
};
</script>
{{-- P6-301 — consume the previous-order payload the account page wrote to
     localStorage (cv_reorder_<slug>) and add each line to picnic's cart. The
     shared helper (assets/store_templates/_shared/js/reorder-pickup.js) is
     loaded by store_templates.layout, but the call must run AFTER the theme's script.js (loaded by
     store_templates.layout) so window.__picnicPickup + window.mbToast are
     defined. `load` fires after every sync script on the document. --}}
<script nonce="{{ csp_nonce() }}">
window.addEventListener('load', function () {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) return;
    window.JuwriReorder.pickup(@json($store->slug ?? ''), window.__picnicPickup || function () { return false; }, {
        toast: window.mbToast || null,
    });
});
</script>
@endpush
