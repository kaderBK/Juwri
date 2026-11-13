{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.freshly.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       freshly — calm mint service-booking storefront (laundry & dry
       cleaning). Cloned data-prep foundation from bookla/index.blade.php
       (the SERVICE family's own theme): $items from $total_menus, category
       roots via $rootOf, FulfillmentRegistry::modesFor(), the currency
       contract, and the SAME native booking endpoints (Lane D's
       LaundryBookingController / ServiceBookingService) — never the legacy
       restaurant.submitOrder target. Presentation is its own app-shell
       (home/service/basket/schedule/checkout/confirm/orders/offers/profile
       screens + bottom tab bar) instead of bookla's list+side-sheet UI, but
       every write still goes through the exact same real routes bookla
       uses, so a laundry store gets identical server-side behavior under a
       different skin.
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    // Same currency contract every other kept theme's client-computed totals
    // honour (price_symbol_format() server-side; CUR below client-side).
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // Storefront language switch: the store's enabled menu languages, normalised
    // to {code,name}. Rendered as a header control only when there are ≥2.
    $frsLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();

    // Real tax contract (Order::computeTax) — a client-side estimate of the same
    // store tax, never a fabricated fee. 0 for every store with no tax configured.
    $taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

    $formatDuration = \Juwri\Core\Support\ViewHelpers::formatDuration(...);

    $items = collect($total_menus)->values();

    $rootOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id] = $parentCat->id;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id] = $parentCat->id;
        }
    }
    $rootItemCount = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $rootItemCount[$root] = ($rootItemCount[$root] ?? 0) + 1;
    }
    $categories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = \Juwri\Core\Support\ViewHelpers::fulfillmentModes($fulfillment, $store->store_type ?? null);

    // $categoryItems: root category id => [item rows] — feeds both the
    // per-service quantity-builder screens below and each service card's
    // "from $X" price.
    $jsItems = [];
    $categoryItems = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $unit = in_array($m->pricing_unit ?? 'each', ['each', 'kg'], true) ? ($m->pricing_unit ?? 'each') : 'each';
        $turnaroundHours = isset($m->turnaround_hours) ? (int) $m->turnaround_hours : null;

        // Gallery: primary image first, then every extra gallery image, all
        // resolved to public URLs (deduped) — feeds the service-detail
        // thumbnail strip (rendered only when a category has ≥2 images).
        $primaryUrl = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        $gallery = [];
        if ($primaryUrl !== '') {
            $gallery[] = $primaryUrl;
        }
        foreach ((array) ($m->gallery_images ?? []) as $gimg) {
            // Skip GD placeholder art (vshowcase-item-*.png): it's a solid
            // brand-colored tile, and padding the gallery with it produces a
            // thumbnail strip that alternates real photo / teal block. A real
            // photo makes the strip; a placeholder never should.
            if ($gimg && $gimg !== 'default.png' && ! str_starts_with((string) $gimg, 'vshowcase-item-')) {
                $gurl = media_url('storage/menu/', $gimg, $store->id ?? null);
                if ($gurl !== '' && ! in_array($gurl, $gallery, true)) {
                    $gallery[] = $gurl;
                }
            }
        }

        $row = [
            'id' => $m->id,
            'cat' => $m->category_id,
            'root' => $root,
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            'img' => $primaryUrl,
            'gallery' => $gallery,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'unit' => $unit,
            'unitLabel' => $unit === 'kg' ? ___('per kg') : ___('per item'),
            'soldOut' => (bool) $m->is_sold_out,
            'turnaroundHours' => $turnaroundHours,
            'duration' => $formatDuration($turnaroundHours),
            'addRoute' => route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
        $jsItems[$m->id] = $row;
        $categoryItems[$root][] = $row;
    }

    // One "service card" per top-level category — the demo's fixed Wash &
    // Fold / Dry Cleaning / Ironing cards, standing in for this store's own
    // categories, priced "from" the cheapest item inside each.
    $jsServices = [];
    foreach ($categories as $cat) {
        $its = $categoryItems[$cat->id] ?? [];
        if (! count($its)) {
            continue;
        }
        $prices = array_column($its, 'price');
        $minPrice = min($prices);
        $cheapest = collect($its)->firstWhere('price', $minPrice);
        $jsServices[] = [
            'id' => $cat->id,
            'name' => $cat->name,
            'desc' => '', // menu_categories has no description column.
            'img' => $cat->image_url ?: ($its[0]['img'] ?? ''),
            'fromPrice' => $minPrice,
            'fromPriceFormatted' => price_symbol_format($minPrice),
            'unitLabel' => $cheapest['unitLabel'] ?? ___('per item'),
        ];
    }

    // rev/multi-vertical Lane V (integration fix 1) — the SAME native
    // booking endpoints bookla's own JS posts to (LaundryBookingController /
    // ServiceBookingService), never the legacy restaurant.submitOrder target.
    $bookRoute = route('laundry.book.place', ['slug' => $store->slug]);
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
    $slotRoute = route('store.scheduleSlots', $store->id);
    $trackRouteTemplate = route('store.orderTrack', 'XTRACKTOKENX');

    // A REAL "need help" card on the confirm screen instead of the demo's
    // fictitious named courier: no driver-assignment backend exists for a
    // laundry booking today, so Call/Chat point at the store's own real
    // phone / WhatsApp number (same helper crave's header uses) rather than
    // a fake person nobody could actually reach.
    $storePhone = $store->phone ?? null;
    $storeWhatsapp = function_exists('storefront_whatsapp_number') ? storefront_whatsapp_number($store) : null;
    $storePhoneHref = $storePhone ? preg_replace('/[^0-9+]/', '', (string) $storePhone) : null;
    $storeWaHref = $storeWhatsapp ? preg_replace('/[^0-9]/', '', (string) $storeWhatsapp) : null;


    // Every user-facing string below goes through ___() (freshly's new keys
    // are listed in store_templates/freshly/_i18n_keys.txt for the AR sweep).
    // The booking status vocabulary further down REUSES bookla/track.blade.
    // php's own exact strings on purpose — zero *new* lang debt for it.
    $T = [
        'bookAPickup' => ___('Book a pickup'),
        'home' => ___('Home'),
        'ordersTab' => $vocab['orders'] ?? ___('Bookings'),
        'offersTab' => ___('Offers'),
        'profileTab' => ___('Profile'),
        'bookTab' => $vocab['add_to_cart'] ?? ___('Book'),
        'hello' => ___('Hello'),
        'heroKicker' => ___('Doorstep service'),
        'heroBody' => ___('We collect, clean and deliver — right on schedule.'),
        'trustLine' => ___('Trusted doorstep pickup & delivery'),
        'activeBooking' => ___('Active booking'),
        'track' => ___('Track'),
        'ourServices' => ___('Our services'),
        'from' => ___('from'),
        'bookCta' => $vocab['add_to_cart'] ?? ___('Book'),
        'basket' => ___('Basket'),
        'subtotal' => ___('Subtotal'),
        'total' => ___('Total'),
        'schedulePickup' => ___('Schedule pickup'),
        'emptyBasketTitle' => ___('Your basket is empty'),
        'emptyBasketBody' => ___('Add a service to get started.'),
        'browseServices' => ___('Browse services'),
        'scheduleTitle' => ___('Schedule pickup'),
        'pickupDate' => ___('Pickup date'),
        'pickupTime' => ___('Pickup time'),
        'deliveryPref' => ___('Delivery preference'),
        'pickupAddress' => ___('Pickup address'),
        'addressPlaceholder' => ___('Street, building, floor / apartment'),
        'full' => ___('Full'),
        'continue' => ___('Continue'),
        'today' => ___('Today'),
        'tomorrow' => ___('Tomorrow'),
        'checkoutTitle' => ___('Order summary'),
        'servicesLabel' => $vocab['items'] ?? ___('Services'),
        'pickupLabel' => ___('Pickup'),
        'name' => ___('Name'),
        'phone' => ___('Phone'),
        'confirmBooking' => ___('Confirm booking'),
        'placing' => ___('Placing your booking…'),
        'bookingConfirmed' => ___('Booking confirmed'),
        'bookingThanks' => ___('Your pickup is scheduled. We’ll handle the rest.'),
        'viewFullTracking' => ___('View full tracking page'),
        'backHome' => ___('Back to home'),
        'myBookings' => ___('My bookings'),
        'noOrdersTitle' => ___('No bookings yet'),
        'noOrdersBody' => ___('Book your first pickup to see it here.'),
        'trackBooking' => ___('Track booking'),
        'offersTitle' => ___('Offers'),
        'offer1Title' => ___('Free pickup over :amount', ['amount' => price_symbol_format(30)]),
        'offer1Body' => ___('No pickup fee on baskets above that amount.'),
        'offer2Title' => ___('Same-day turnaround'),
        'offer2Body' => ___('Pick a service with a fast turnaround time for same-day care.'),
        'offer3Title' => ___('Doorstep, every time'),
        'offer3Body' => ___('We come to you for pickup and bring it back clean.'),
        'profileTitle' => ___('Profile'),
        'guest' => ___('Guest'),
        'signInRegister' => ___('Sign in / Register'),
        'darkMode' => ___('Dark mode'),
        'contactStore' => ___('Contact the store'),
        'call' => ___('Call'),
        'chat' => ___('Chat'),
        'needHelp' => ___('Need help with this booking?'),
        'addedToBasket' => ___('Added to basket'),
        'removed' => ___('Removed'),
        'selectTimeError' => ___('Please choose a pickup time.'),
        'nameRequired' => ___('Please enter your name.'),
        'addressRequired' => ___('Please enter a pickup address.'),
        'genericError' => ___('Something went wrong. Please try again.'),
        'kg' => ___('kg'),
        'back' => ___('Back'),
        'decrease' => ___('Decrease'),
        'increase' => ___('Increase'),
        'nothingYet' => ___('Nothing here yet.'),

        // Curated core features (loop/enrich): search, wishlist, reviews, loyalty.
        'searchPlaceholder' => ___('Search services'),
        'noResults' => ___('No results.'),
        'save' => ___('Save'),
        'unsave' => ___('Remove from saved'),
        'savedTitle' => ___('Saved'),
        'nothingSavedTitle' => ___('Nothing saved yet'),
        'nothingSavedBody' => ___('Tap the heart on a service to save it here.'),
        'reviewsLabel' => ___('Reviews'),
        'writeReview' => ___('Write a review'),
        'yourRating' => ___('Your rating'),
        'yourName' => ___('Your name'),
        'optional' => ___('Optional'),
        'yourReview' => ___('Your review'),
        'submitReview' => ___('Submit review'),
        'noReviewsYet' => ___('No reviews yet — be the first.'),
        'chooseRatingFirst' => ___('Choose a rating first.'),
        'reviewThanks' => ___('Thank you! Your review will appear once approved.'),
        'reviewFailed' => ___('Could not send your review. Please try again.'),
        'anonymous' => ___('Anonymous'),
        'verified' => ___('Verified'),
        'reviewsWord' => ___('reviews'),
        'reviewWord' => ___('review'),
        'rewardsTitle' => ___('Rewards'),
        'silver' => ___('Silver'),
        'gold' => ___('Gold'),
        'platinum' => ___('Platinum'),
        'points' => ___('points'),
        'applyRewardOff' => ___('Apply :amount off'),
        'signInToEarn' => ___('Sign in to earn & redeem points'),
        'story' => ___('Story'),
        'close' => ___('Close'),
        'previous' => ___('Previous'),
        'next' => ___('Next'),
    ];

    // Booking status vocabulary — REUSES bookla/track.blade.php's exact
    // ___() strings (RetailOrderBoardService's additive state machine: new →
    // packing → ready → shipped|collected → done), so this costs the AR
    // sweep zero new keys. 'ready' has two title/desc variants depending on
    // $order->type — both kept so the client can pick the right one once a
    // real order exists (index.blade.php never knows the type in advance).
    $statuses = [
        'unpaid' => ['title' => ___('Awaiting Payment'), 'desc' => ___('Please complete your online payment to submit this booking.'), 'badge' => ___('Awaiting Payment'), 'step' => -1],
        'new' => ['title' => ___('Booking received'), 'desc' => ___('Your booking has been received and is awaiting processing.'), 'badge' => ___('Received'), 'step' => 0],
        'pending' => ['title' => ___('Booking received'), 'desc' => ___('Your booking has been received and is awaiting processing.'), 'badge' => ___('Received'), 'step' => 0],
        'pending_approval' => ['title' => ___('Awaiting approval'), 'desc' => ___('Your booking has been received and is awaiting confirmation from the store.'), 'badge' => ___('Awaiting approval'), 'step' => 0],
        'packing' => ['title' => ___('Preparing your order'), 'desc' => ___('The store is working on your service.'), 'badge' => ___('Processing'), 'step' => 1],
        'ready_pickup' => ['title' => ___('Ready for pickup'), 'desc' => ___('Your order is ready for pickup.'), 'badge' => ___('Ready'), 'step' => 2],
        'ready_delivery' => ['title' => ___('Ready — awaiting delivery'), 'desc' => ___('Your order is ready and will be delivered shortly.'), 'badge' => ___('Ready'), 'step' => 2],
        'shipped' => ['title' => ___('Out for delivery'), 'desc' => ___('Your order has left the store and is on its way.'), 'badge' => ___('Out for delivery'), 'step' => 2],
        'collected' => ['title' => ___('Out for delivery'), 'desc' => ___('Your order has left the store and is on its way.'), 'badge' => ___('Out for delivery'), 'step' => 2],
        'done' => ['title' => ___('Completed'), 'desc' => ___('This booking is complete. Thank you!'), 'badge' => ___('Done'), 'step' => 3],
        'cancelled' => ['title' => ___('Booking Cancelled'), 'desc' => ___('This booking has been cancelled. Please contact the store for assistance.'), 'badge' => ___('Cancelled'), 'step' => -1],
    ];

    /* -----------------------------------------------------------------
       Curated core features (loop/enrich): stories, reviews, loyalty.
       All three reuse existing, already-seeded/live platform endpoints —
       no backend changes made here.
       ----------------------------------------------------------------- */
    $storiesUrl = url('r/'.$store->id.'/stories');
    $mbrBase = url('r/'.$store->id.'/item');
    $loyaltyBalanceUrl = url('restaurant/'.$store->id.'/loyalty/balance');
    $loy = store_options($store->id, 'loyalty_teaser');
    $loy = $loy ? (array) json_decode(json_encode($loy), true) : null;

    // Reviews are attached to the SERVICE (category) as a whole, not to each
    // individual bookable row inside it — but the review endpoint is keyed on
    // a real menu (item) id, so each service's thread is anchored to the SAME
    // representative item its "from $X" price already uses (the cheapest row
    // in that category).
    $serviceReviewItem = [];
    foreach ($categories as $cat) {
        $its = $categoryItems[$cat->id] ?? [];
        if (! count($its)) {
            continue;
        }
        $cheapest = collect($its)->firstWhere('price', min(array_column($its, 'price')));
        $serviceReviewItem[$cat->id] = $cheapest['id'];
    }

    // Keyed lookup of every service card's own data (id/name/img/price) —
    // reused client-side to render the "Saved" wishlist view.
    $jsServicesById = collect($jsServices)->keyBy('id')->all();

    // Crave-parity features (same backend data; no controller change).
    $frsIsOpen = store_open_now($store);
    $frsHoursToday = storefront_hours_today($store);
    $frsBranches = collect($branches ?? []);
    $frsShowBranches = $frsBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
    $frsActiveBranch = $activeBranch ?? null;
    $frsBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);
@endphp
@push('style')
<style nonce="{{ csp_nonce() }}">
:root{
    --frs-ground:#ECF6F4; --frs-card:#FFFFFF; --frs-card-2:#EFF7F5;
    --frs-ink:#122320; --frs-ink-2:#536965; --frs-ink-3:#8AA09B;
    --frs-hair:#E1EFEC; --frs-hair-2:#D2E6E1;
    --frs-accent:#12B3A6; --frs-accent-2:#0A736B; --frs-accent-bg:#D8F2EF;
    --frs-sun:#F6C445; --frs-sale:#B63F1A; --frs-offer-bg:#FDF1D9;
    --frs-radius:16px; --frs-radius-lg:24px;
    --frs-shadow-card:0 10px 30px -18px rgba(18,35,32,.28);
    --frs-font:"Saudi Riyal","Manrope",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    --frs-nav-h:70px;
}
@media (prefers-color-scheme:dark){
  :root:not([data-theme="light"]){
    --frs-ground:#0B1615; --frs-card:#122120; --frs-card-2:#1A2C2A;
    --frs-ink:#E9F5F3; --frs-ink-2:#9DB4AF; --frs-ink-3:#69807B;
    --frs-hair:#213230; --frs-hair-2:#2C3E3B;
    --frs-accent:#2AD0C2; --frs-accent-2:#1FA89C; --frs-accent-bg:#123C38;
    --frs-sale:#FF7F63; --frs-offer-bg:#332A16; --frs-open:#4cc98a; --frs-closed:#ff8da0;
    --frs-shadow-card:0 14px 32px -18px rgba(0,0,0,.55);
  }
}
:root[data-theme="dark"]{
  --frs-ground:#0B1615; --frs-card:#122120; --frs-card-2:#1A2C2A;
  --frs-ink:#E9F5F3; --frs-ink-2:#9DB4AF; --frs-ink-3:#69807B;
  --frs-hair:#213230; --frs-hair-2:#2C3E3B;
  --frs-accent:#2AD0C2; --frs-accent-2:#1FA89C; --frs-accent-bg:#123C38;
  --frs-sale:#FF7F63; --frs-offer-bg:#332A16; --frs-open:#4cc98a; --frs-closed:#ff8da0;
  --frs-shadow-card:0 14px 32px -18px rgba(0,0,0,.55);
}
.frs-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:50}
.frs-skip:focus{inset-inline-start:0}
*{box-sizing:border-box}
.frs-body{margin:0;background:var(--frs-ground);color:var(--frs-ink);font-family:var(--frs-font);-webkit-font-smoothing:antialiased}
.frs img{display:block;max-width:100%}
.frs button{font-family:inherit;cursor:pointer}
/* Global [hidden] reset. The shared mb-* sheet partials (auth, wallet) style
   .mba-scrim as a fixed, max-z-index, display:flex overlay and rely on the host
   theme's [hidden] rule to hide it; without this, the invisible (opacity:0) but
   pointer-events:auto scrim sits on top and swallows EVERY click on the page. */
[hidden]{display:none !important}
.frs :focus-visible{outline:2px solid var(--frs-accent);outline-offset:2px}
.frs{max-width:520px;margin:0 auto;min-height:100vh;position:relative;background:var(--frs-ground);overflow-x:clip}
.frs-toast{position:fixed;left:50%;bottom:calc(var(--frs-nav-h) + 20px);transform:translateX(-50%) translateY(20px);background:var(--frs-ink);color:var(--frs-ground);padding:12px 20px;border-radius:100px;font-size:13.5px;font-weight:600;opacity:0;transition:opacity .25s,transform .25s;z-index:200;white-space:nowrap;display:flex;align-items:center;gap:8px;pointer-events:none;max-width:88vw;overflow:hidden;text-overflow:ellipsis}
.frs-toast.is-show{opacity:1;transform:translateX(-50%) translateY(0)}

.frs-screen[hidden]{display:none}
.frs-screen{min-height:100vh;display:flex;flex-direction:column;position:relative}

.frs-hdr{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px 10px;position:sticky;top:0;z-index:20;background:var(--frs-ground)}
.frs-hdr-title{font-size:17px;font-weight:800;letter-spacing:-.01em}
.frs-hdr-spacer{width:42px;height:1px;flex:none}
.frs-hdr-tools{display:flex;align-items:center;gap:8px}
.frs-greet{display:flex;flex-direction:column}
.frs-greet-hello{font-weight:800;font-size:16px}
.frs-greet-brand{font-size:12.5px;color:var(--frs-ink-2);margin-top:1px}

.frs-iconbtn{position:relative;width:42px;height:42px;border-radius:50%;background:var(--frs-card);border:1px solid var(--frs-hair);display:flex;align-items:center;justify-content:center;color:var(--frs-ink);flex:none;transition:transform .15s,background .15s}
.frs-iconbtn:hover{background:var(--frs-card-2)}
.frs-iconbtn:active{transform:scale(.92)}
.frs-iconbtn svg{width:19px;height:19px}
.frs-langsel{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--frs-hair);border-radius:100px;padding:0 12px;height:42px;color:var(--frs-ink-2);background:var(--frs-card);flex:none;transition:background .15s}
.frs-langsel:hover{background:var(--frs-card-2)}
.frs-langsel svg{width:16px;height:16px}
.frs-langsel select{border:0;background:transparent;color:var(--frs-ink);font:inherit;font-size:.8rem;font-weight:700;cursor:pointer;padding-inline-end:2px}
.frs-langsel select:focus{outline:none}
.frs-badge{position:absolute;top:-4px;inset-inline-end:-4px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:var(--frs-sale);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
.frs-badge[hidden]{display:none}

.frs-scroll{flex:1;padding:0 16px 24px}
.frs-haspad{padding-bottom:calc(var(--frs-nav-h) + 20px)}

.frs-label{display:block;font-size:13px;font-weight:700;letter-spacing:.01em;margin:20px 0 10px;color:var(--frs-ink)}

/* crave-parity: status row + branch + banners */
.frs-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px}
.frs-openchip{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:var(--frs-open,#1b7a48);
    background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:100px;padding:6px 12px}
.frs-openchip i{width:7px;height:7px;border-radius:50%;background:var(--frs-open,#1b7a48);flex:none}
.frs-openchip.is-closed{color:var(--frs-closed,#b52a43)} .frs-openchip.is-closed i{background:var(--frs-closed,#b52a43)}
.frs-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
    border:1px solid var(--frs-hair);background:var(--frs-card);color:var(--frs-ink);cursor:pointer}
.frs-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--frs-accent-2);pointer-events:none}
.frs-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;border-inline-end:1.5px solid var(--frs-ink-3);border-bottom:1.5px solid var(--frs-ink-3);transform:translateY(-65%) rotate(45deg);pointer-events:none}
.frs-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;outline:none;height:100%;padding-inline-start:30px;padding-inline-end:24px;max-width:170px}
.frs .frs-branchsel select:focus-visible{outline:none}
.frs-branchsel:focus-within{outline:2px solid var(--frs-accent);outline-offset:2px}
.frs-banners{margin:0 0 16px}
.frs-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
.frs-banner-track::-webkit-scrollbar{height:0}
.frs-banner{flex:0 0 86%;scroll-snap-align:center;position:relative;min-height:104px;aspect-ratio:5/2;border-radius:var(--frs-radius-lg);overflow:hidden;display:flex;align-items:center;padding:18px 20px;text-decoration:none;background:linear-gradient(135deg,var(--frs-accent-2),var(--frs-accent));color:#fff}
.frs-banner:nth-child(even){background:var(--frs-ink)}
.frs-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.9}
.frs-banner-copy{position:relative;display:flex;flex-direction:column;gap:3px;z-index:1}
.frs-banner-kick{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.88}
.frs-banner-title{font-family:var(--frs-font);font-size:19px;font-weight:800;line-height:1.15}
.frs-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
.frs-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--frs-hair-2);transition:width .2s,background .2s}
.frs-banner-dots i.on{background:var(--frs-accent-2);width:16px;border-radius:100px}

.frs-herocard{position:relative;display:block;width:100%;text-align:start;border:0;padding:0;border-radius:var(--frs-radius-lg);overflow:hidden;min-height:168px;box-shadow:var(--frs-shadow-card);background:linear-gradient(135deg,var(--frs-accent-2),var(--frs-accent))}
.frs-scrim{position:absolute;inset:0;background:linear-gradient(115deg,rgba(6,16,15,.32) 40%,rgba(6,16,15,.05) 75%,transparent)}
.frs-hero-content{position:relative;z-index:1;padding:22px 20px;display:flex;flex-direction:column;gap:8px;color:#fff}
.frs-hero-k{font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--frs-sun)}
.frs-hero-h{font-family:var(--frs-font);font-weight:800;font-size:22px;line-height:1.15;max-width:80%;margin:0}
.frs-hero-p{color:rgba(255,255,255,.86);font-size:12.5px;max-width:78%;margin:0}
.frs-hero-go{margin-top:6px;align-self:flex-start;height:44px;padding:0 20px;border-radius:100px;background:#fff;color:#0A6F67;font-weight:700;font-size:13.5px;display:inline-flex;align-items:center;gap:8px}
.frs-hero-go svg{width:16px;height:16px}

.frs-trustline{display:flex;align-items:center;gap:7px;margin-top:14px;font-size:12.5px;color:var(--frs-ink-2);font-weight:600}
.frs-trustline svg{width:15px;height:15px;color:var(--frs-sun);flex:none}

.frs-statusstrip{display:flex;align-items:center;gap:12px;background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:13px 14px;margin-top:16px;box-shadow:var(--frs-shadow-card);width:100%;text-align:start}
.frs-statusstrip[hidden]{display:none}
.frs-sicon{width:42px;height:42px;border-radius:50%;background:var(--frs-accent-bg);color:var(--frs-accent-2);display:flex;align-items:center;justify-content:center;flex:none;position:relative}
.frs-sicon svg{width:20px;height:20px}
.frs-sicon::after{content:"";position:absolute;inset:-3px;border-radius:50%;border:2px solid var(--frs-accent);opacity:.5;animation:frsPulse2 1.8s infinite}
@keyframes frsPulse2{0%{transform:scale(.9);opacity:.6}70%{transform:scale(1.25);opacity:0}100%{opacity:0}}
.frs-stexts{flex:1;min-width:0;display:flex;flex-direction:column}
.frs-slabel{font-size:11.5px;color:var(--frs-ink-2);font-weight:600}
.frs-sval{font-weight:700;font-size:14.5px;margin-top:1px}
.frs-strackbtn{height:36px;padding:0 16px;border-radius:100px;background:var(--frs-ink);color:var(--frs-ground);font-weight:700;font-size:12.5px;flex:none;border:0;display:inline-flex;align-items:center}

.frs-svc-list{display:flex;flex-direction:column;gap:12px;margin-top:16px}
.frs-svc-card{display:flex;align-items:center;gap:13px;background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:12px;box-shadow:var(--frs-shadow-card);text-align:start;transition:transform .15s;text-decoration:none;color:inherit}
.frs-svc-card:active{transform:scale(.98)}
.frs-svc-thumb{width:66px;height:66px;border-radius:14px;overflow:hidden;flex:none;background:var(--frs-card-2);display:flex;align-items:center;justify-content:center;color:var(--frs-ink-3)}
.frs-svc-thumb img{width:100%;height:100%;object-fit:cover}
.frs-svc-body{flex:1;min-width:0;display:flex;flex-direction:column}
.frs-svc-name{font-weight:700;font-size:15px}
.frs-svc-desc{font-size:12.5px;color:var(--frs-ink-2);margin-top:2px}
.frs-svc-price{font-size:12.5px;color:var(--frs-accent-2);font-weight:700;margin-top:5px}
.frs-svc-action{width:40px;height:40px;border-radius:50%;background:var(--frs-accent);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}
.frs-svc-action svg{width:18px;height:18px}
.frs-svc-thumb-ph,.frs-detail-banner-ph,.frs-cthumb-ph{display:flex;align-items:center;justify-content:center;width:100%;height:100%}

.frs-detail-banner{position:relative;border-radius:var(--frs-radius-lg);overflow:hidden;aspect-ratio:16/10;margin-top:6px;background:var(--frs-card-2);display:flex;align-items:center;justify-content:center;color:var(--frs-ink-3)}
.frs-detail-banner img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.frs-detail-title{font-family:var(--frs-font);font-size:24px;font-weight:800;line-height:1.1;letter-spacing:-.01em;margin-top:18px}
.frs-detail-from{display:inline-flex;align-items:center;gap:6px;margin-top:12px;background:var(--frs-accent-bg);color:var(--frs-accent-2);font-weight:700;font-size:13px;padding:6px 14px;border-radius:100px}
.frs-thumbs{display:flex;gap:8px;margin-top:10px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:2px}
.frs-thumbs::-webkit-scrollbar{height:0}
.frs-thumb{flex:0 0 auto;width:62px;height:62px;border-radius:12px;overflow:hidden;border:2px solid transparent;padding:0;background:var(--frs-card-2);cursor:pointer}
.frs-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.frs-thumb[aria-current="true"]{border-color:var(--frs-accent)}

.frs-qtylist{margin-top:6px}
.frs-qtyrow{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--frs-hair)}
.frs-qtyrow:last-child{border-bottom:none}
.frs-qty-info{flex:1;min-width:0}
.frs-qty-name{font-weight:600;font-size:14.5px}
.frs-qty-price{font-size:12.5px;color:var(--frs-ink-2);margin-top:2px;font-variant-numeric:tabular-nums}
.frs-stepper{display:flex;align-items:center;gap:6px}
.frs-step{width:32px;height:32px;border-radius:10px;border:1px solid var(--frs-hair-2);display:flex;align-items:center;justify-content:center;font-size:17px;line-height:1;background:var(--frs-card);color:var(--frs-ink);transition:transform .12s,background .12s}
.frs-step:active{transform:scale(.9)}
.frs-step[disabled]{opacity:.35;pointer-events:none}
.frs-step-on{background:var(--frs-accent);border-color:var(--frs-accent);color:#fff}
.frs-qty{min-width:24px;text-align:center;font-weight:700;font-variant-numeric:tabular-nums}

.frs-stickybar{position:sticky;bottom:0;left:0;right:0;padding:14px 16px calc(14px + env(safe-area-inset-bottom));background:linear-gradient(to top,var(--frs-ground) 76%,transparent);display:flex;flex-direction:column;gap:10px;z-index:30;margin-top:auto}
.frs-subrow{display:flex;align-items:center;justify-content:space-between;font-size:13.5px;color:var(--frs-ink-2);padding:0 2px}
/* The book CTA carries flex:1 for the side-by-side actionbar row; inside the
   column stickybar that flex-basis:0 collapses its height to the text (~23px)
   and swallows its height:54px. Pin it so the primary CTA keeps full height. */
.frs-stickybar .frs-btn{flex:0 0 auto}
.frs-subrow b{color:var(--frs-ink);font-weight:700;font-variant-numeric:tabular-nums}
.frs-actionbar{position:sticky;bottom:0;left:0;right:0;padding:14px 16px calc(14px + env(safe-area-inset-bottom));background:linear-gradient(to top,var(--frs-ground) 72%,transparent);display:flex;gap:12px;z-index:30;margin-top:auto}

.frs-btn{height:54px;border-radius:100px;font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:9px;transition:transform .14s;border:0}
.frs-btn:active{transform:scale(.98)}
.frs-btn[disabled]{opacity:.45;pointer-events:none}
.frs-btn-dark{background:var(--frs-ink);color:var(--frs-ground);flex:1;text-decoration:none}
.frs-btn-accent{background:var(--frs-accent);color:#fff;flex:1}
.frs-btn-ghost{background:var(--frs-card);color:var(--frs-ink);border:1px solid var(--frs-hair-2);flex:1}
.frs-btn-block{width:100%}
.frs-btn svg{width:19px;height:19px}

.frs-msg{font-size:.86rem;padding:9px 12px;border-radius:10px;margin-bottom:12px;background:var(--frs-accent-bg);color:var(--frs-accent-2)}
.frs-msg-error{background:color-mix(in srgb, var(--frs-sale) 16%, transparent);color:var(--frs-sale)}

.frs-citem{background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:14px;margin-top:14px;box-shadow:var(--frs-shadow-card)}
.frs-citem-top{display:flex;gap:12px;align-items:flex-start}
.frs-cthumb{width:60px;height:60px;border-radius:12px;background:var(--frs-card-2);flex:none;overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--frs-ink-3)}
.frs-cthumb img{width:100%;height:100%;object-fit:cover}
.frs-cinfo{flex:1;min-width:0}
.frs-cname{font-weight:700;font-size:14.5px}
.frs-ccol{font-size:12.5px;color:var(--frs-ink-2);margin-top:1px}
.frs-cprice{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.frs-cfoot{display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--frs-hair);margin-top:12px;padding-top:12px;gap:10px}
.frs-order-actions{display:flex;gap:8px;margin-top:12px}
.frs-order-btn{flex:1;text-align:center;height:38px;line-height:38px;border-radius:100px;font-size:.82rem;font-weight:600;text-decoration:none;color:var(--frs-ink);background:var(--frs-card);border:1px solid var(--frs-hair);cursor:pointer;transition:background .15s}
.frs-order-btn:hover{background:var(--frs-card-2)}
.frs-order-btn--p{background:var(--frs-accent);color:#fff;border-color:var(--frs-accent)}
.frs-order-btn--p:hover{background:var(--frs-accent-2);border-color:var(--frs-accent-2)}
.frs-trash{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--frs-ink-2);border:0;background:none;flex:none}
.frs-trash svg{width:16px;height:16px}

.frs-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;opacity:0;pointer-events:none;border:0}
.frs-notif-dot{position:absolute;top:6px;inset-inline-end:6px;width:8px;height:8px;border-radius:50%;background:var(--frs-accent-2);border:2px solid var(--frs-card);box-sizing:content-box}
.frs-notif-dot[hidden]{display:none}
.mb-nb-card{--nb-surface:var(--frs-card);--nb-surface-2:var(--frs-card-2);--nb-ink:var(--frs-ink);--nb-muted:var(--frs-ink-3);--nb-line:var(--frs-hair);--nb-accent:var(--frs-accent-2);--nb-radius:16px}
.frs .mb-nb-panel{opacity:0}
.frs .mb-nb-panel.is-open{opacity:1}
.frs-couponbox{margin-top:8px}
.frs-couponrow{display:flex;gap:8px}
.frs-coupon-input{flex:1;height:48px;border-radius:12px;border:1px solid var(--frs-hair-2);background:var(--frs-card);padding:0 14px;font:inherit;font-size:14px;color:var(--frs-ink)}
.frs-coupon-apply{flex:none}
.frs-coupon-msg{font-size:12px;font-weight:700;margin:7px 0 0;color:#2f9e63}
.frs-coupon-msg.is-bad{color:#e2586f}
.frs-summary{background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:18px;margin-top:18px;box-shadow:var(--frs-shadow-card)}
.frs-summary h3{font-family:var(--frs-font);font-weight:800;font-size:18px;margin:0 0 12px}
.frs-srow{display:flex;justify-content:space-between;font-size:14px;color:var(--frs-ink-2);padding:6px 0;font-variant-numeric:tabular-nums}
.frs-srow-total{color:var(--frs-ink);font-weight:800;font-size:16px;border-top:1px solid var(--frs-hair);margin-top:8px;padding-top:12px}

.frs-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;padding:60px 24px;color:var(--frs-ink-2)}
.frs-empty[hidden]{display:none}
.frs-empty-ico{width:74px;height:74px;border-radius:50%;background:var(--frs-card);border:1px solid var(--frs-hair);display:flex;align-items:center;justify-content:center;color:var(--frs-ink-3)}
.frs-empty-ico svg{width:30px;height:30px}
.frs-empty h3{font-family:var(--frs-font);color:var(--frs-ink);font-weight:800;font-size:19px;margin:4px 0 0}

.frs-optcard{width:100%;background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:15px 16px;display:flex;align-items:center;gap:13px;margin-bottom:10px;transition:border-color .15s;text-align:start;color:inherit}
.frs-optcard[aria-pressed="true"]{border-color:var(--frs-accent)}
.frs-oico{width:40px;height:40px;border-radius:10px;background:var(--frs-card-2);display:flex;align-items:center;justify-content:center;flex:none;color:var(--frs-ink)}
.frs-oico svg{width:20px;height:20px}
.frs-obody{flex:1;min-width:0;display:flex;flex-direction:column}
.frs-ot{font-weight:700;font-size:14.5px}
.frs-os{font-size:12.5px;color:var(--frs-ink-2);margin-top:1px}
.frs-radio{width:22px;height:22px;border-radius:50%;border:2px solid var(--frs-hair-2);flex:none;position:relative}
.frs-optcard[aria-pressed="true"] .frs-radio{border-color:var(--frs-accent)}
.frs-optcard[aria-pressed="true"] .frs-radio::after{content:"";position:absolute;inset:4px;border-radius:50%;background:var(--frs-accent)}

.frs-input{width:100%;height:52px;border-radius:14px;border:1px solid var(--frs-hair-2);background:var(--frs-card);padding:0 16px;font-family:inherit;font-size:14.5px;color:var(--frs-ink)}
.frs-input::placeholder{color:var(--frs-ink-3)}
/* Delivery-address autocomplete (crave-style geocode suggest) */
.frs-addr{position:relative}
.frs-addr .frs-input{padding-inline-end:42px}
.frs-addr-loc{position:absolute;inset-inline-end:8px;top:9px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
    border:0;background:transparent;color:var(--frs-ink-2);cursor:pointer;border-radius:10px}
.frs-addr-loc:hover{background:var(--frs-card-2);color:var(--frs-accent-2)}
.frs-addr-loc.is-loading{opacity:.5;pointer-events:none}
.frs-addr-loc svg{width:19px;height:19px}
.frs-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 5px);z-index:40;background:var(--frs-card);
    border:1px solid var(--frs-hair);border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.14);overflow:hidden;max-height:248px;overflow-y:auto}
.frs-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:11px 14px;background:none;border:0;
    border-block-end:1px solid var(--frs-hair-2);color:var(--frs-ink);font:inherit;font-size:.84rem;cursor:pointer;line-height:1.4}
.frs-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
.frs-addr-suggest .mb-addr-opt:hover{background:var(--frs-card-2)}
.frs-field{margin-bottom:14px}
.frs-field label{display:block;font-size:12.5px;font-weight:700;color:var(--frs-ink-2);margin-bottom:6px}

.frs-datechips{display:flex;gap:9px;overflow-x:auto;padding:2px 16px 6px;margin:0 -16px}
.frs-datechips::-webkit-scrollbar{height:0}
/* Desktop mouse can't swipe a hidden rail — restore a slim bar on hover/fine so an
   overflowing date rail stays reachable; touch keeps the hidden-bar look. */
@media (hover:hover) and (pointer:fine){
    #frs .frs-datechips{scrollbar-width:thin;scrollbar-color:var(--frs-hair-2) transparent}
    #frs .frs-datechips::-webkit-scrollbar{display:block;height:8px}
    #frs .frs-datechips::-webkit-scrollbar-thumb{background:var(--frs-hair-2);border-radius:999px;border:2px solid transparent;background-clip:content-box}
    #frs .frs-datechips::-webkit-scrollbar-thumb:hover{background:var(--frs-accent)}
    #frs .frs-datechips::-webkit-scrollbar-track{background:transparent}
}
.frs-datechip{flex:none;width:56px;height:68px;border-radius:16px;background:var(--frs-card);border:1px solid var(--frs-hair);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;transition:transform .12s,background .12s;color:inherit}
.frs-datechip:active{transform:scale(.94)}
.frs-dd{font-size:11px;font-weight:700;color:var(--frs-ink-2);text-transform:uppercase}
.frs-dn{font-size:15px;font-weight:800}
.frs-datechip[aria-pressed="true"]{background:var(--frs-ink);border-color:var(--frs-ink)}
.frs-datechip[aria-pressed="true"] .frs-dd{color:var(--frs-ink-3)}
.frs-datechip[aria-pressed="true"] .frs-dn{color:var(--frs-ground)}

.frs-timegrid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:2px}
.frs-timeslot{height:48px;border-radius:12px;border:1px solid var(--frs-hair-2);background:var(--frs-card);color:inherit;font-weight:700;font-size:13.5px;display:flex;align-items:center;justify-content:center;transition:transform .12s,background .12s;position:relative}
.frs-timeslot:active{transform:scale(.95)}
.frs-timeslot[aria-pressed="true"]{background:var(--frs-accent);border-color:var(--frs-accent);color:#fff}
.frs-timeslot[disabled]{opacity:.4;pointer-events:none;text-decoration:line-through;background:var(--frs-card-2)}
.frs-full{position:absolute;bottom:-16px;left:0;right:0;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--frs-sale);letter-spacing:.04em}

.frs-couriercard{display:flex;align-items:center;gap:13px;background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:14px;margin-top:16px;box-shadow:var(--frs-shadow-card);width:100%}
.frs-couriercard[hidden]{display:none}
.frs-courier-ico{width:48px;height:48px;border-radius:50%;flex:none;background:var(--frs-accent-bg);color:var(--frs-accent-2);display:flex;align-items:center;justify-content:center}
.frs-courier-ico svg{width:20px;height:20px}
.frs-courier-info{flex:1;min-width:0;text-align:start}
.frs-courier-name{font-weight:700;font-size:14.5px}
.frs-courier-role{font-size:12px;color:var(--frs-ink-2);margin-top:1px}
.frs-courier-acts{display:flex;gap:8px}
.frs-courier-btn{width:40px;height:40px;border-radius:50%;background:var(--frs-accent-bg);color:var(--frs-accent-2);display:flex;align-items:center;justify-content:center;flex:none;text-decoration:none}
.frs-courier-btn svg{width:17px;height:17px}

.frs-confwrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:36px 24px 20px}
.frs-checkc{width:90px;height:90px}
.frs-checkc circle{fill:none;stroke:var(--frs-accent);stroke-width:4;stroke-dasharray:283;stroke-dashoffset:283;animation:frsDraw .6s ease forwards}
.frs-checkc path{fill:none;stroke:var(--frs-ink);stroke-width:5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:frsDraw .4s .5s ease forwards}
@keyframes frsDraw{to{stroke-dashoffset:0}}
.frs-confwrap h2{font-family:var(--frs-font);font-weight:800;font-size:24px;margin:16px 0 4px}
.frs-confwrap p{color:var(--frs-ink-2);font-size:14px;margin:0}
.frs-orderno{margin-top:8px;font-weight:700;letter-spacing:.02em;color:var(--frs-accent-2);font-variant-numeric:tabular-nums;word-break:break-all;font-size:13px}

.frs-track{width:100%;margin-top:22px;background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:18px;text-align:start;box-shadow:var(--frs-shadow-card)}
.frs-track-status{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:999px;font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:12px;background:var(--frs-accent-bg);color:var(--frs-accent-2)}
.frs-track-title{font-weight:800;font-size:1rem;margin:0 0 4px}
.frs-track-desc{color:var(--frs-ink-2);font-size:.84rem;line-height:1.5;margin:0 0 18px}
.frs-tstep{display:flex;gap:14px;position:relative;padding-bottom:22px}
.frs-tstep:last-child{padding-bottom:0}
.frs-dot{width:26px;height:26px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;border:2px solid var(--frs-hair-2);background:var(--frs-card);color:var(--frs-ink-3);z-index:2}
.frs-dot svg{width:13px;height:13px}
.frs-tstep::before{content:"";position:absolute;inset-inline-start:12px;top:26px;bottom:0;width:2px;background:var(--frs-hair-2)}
.frs-tstep:last-child::before{display:none}
.frs-tstep.is-done .frs-dot{background:var(--frs-accent);border-color:var(--frs-accent);color:#fff}
.frs-tstep.is-done::before{background:var(--frs-accent)}
.frs-tstep.is-active .frs-dot{border-color:var(--frs-accent);color:var(--frs-accent-2);animation:frsPulse 1.6s infinite}
@keyframes frsPulse{0%{box-shadow:0 0 0 0 rgba(18,179,166,.4)}70%{box-shadow:0 0 0 8px rgba(18,179,166,0)}100%{box-shadow:0 0 0 0 rgba(18,179,166,0)}}
.frs-tt{font-weight:700;font-size:14.5px}

.frs-profile{display:flex;align-items:center;gap:14px;background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);padding:16px;margin-top:8px;box-shadow:var(--frs-shadow-card)}
.frs-avatar{width:54px;height:54px;border-radius:50%;object-fit:cover;background:var(--frs-card-2);border:1px solid var(--frs-hair);flex:none}
.frs-avatar-ph{width:54px;height:54px;border-radius:50%;background:var(--frs-card-2);border:1px solid var(--frs-hair);flex:none;display:flex;align-items:center;justify-content:center;color:var(--frs-ink-3)}
.frs-pn{font-family:var(--frs-font);font-weight:800;font-size:17px}
.frs-pe{font-size:12.5px;color:var(--frs-ink-2);margin-top:2px;direction:ltr;text-align:start}
.frs-psub{font-size:12.5px;color:var(--frs-ink-2);margin-top:2px}
.frs-acclist{margin-top:16px}
.frs-accrow{display:flex;align-items:center;gap:14px;padding:15px 4px;border-bottom:1px solid var(--frs-hair);width:100%;text-align:start;color:inherit;text-decoration:none;background:none;border-inline:0;border-top:0}
.frs-accrow:last-child{border-bottom:0}
.frs-aico{width:38px;height:38px;border-radius:10px;background:var(--frs-card-2);display:flex;align-items:center;justify-content:center;color:var(--frs-ink);flex:none}
.frs-aico svg{width:18px;height:18px}
.frs-at{flex:1;font-weight:600;font-size:14.5px}
.frs-chev{color:var(--frs-ink-3);display:flex;flex:none}
.frs-chev svg{width:18px;height:18px}
.frs-switch{width:44px;height:26px;border-radius:100px;background:var(--frs-hair-2);position:relative;flex:none;transition:background .15s}
.frs-switch-knob{position:absolute;top:3px;inset-inline-start:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:inset-inline-start .15s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
#frsDarkToggle[aria-checked="true"] .frs-switch{background:var(--frs-accent)}
#frsDarkToggle[aria-checked="true"] .frs-switch-knob{inset-inline-start:21px}

.frs-promoban{position:relative;background:var(--frs-offer-bg);border-radius:var(--frs-radius-lg);padding:20px;margin-top:16px;overflow:hidden;display:flex;align-items:center;gap:14px;width:100%;text-align:start;border:0;color:inherit}
/* Desktop-only storefront footer — finishes sparse inner screens so short
   content on a tall canvas ends in a footer, not a raw whitespace void. It sits
   after .frs-scroll (which is flex:1) in the flex-column screen, so it pins to
   the viewport bottom. Full-bleed, centered content (no margin-auto collapse). */
.frs-footer{display:none}
@media (min-width:1024px){
    .frs-footer{display:block;width:100%;text-align:center;border-block-start:1px solid var(--frs-hair);padding:34px 20px;margin-block-start:28px;color:var(--frs-ink-2)}
    .frs-footer-brand{font-family:var(--frs-font);font-weight:800;font-size:16px;color:var(--frs-ink)}
    .frs-footer-tag{font-size:12.5px;margin-block-start:6px}
    .frs-footer-meta{font-size:12px;margin-block-start:6px;opacity:.82}
}
.frs-promoban-k{display:block;font-size:11.5px;font-weight:700;color:var(--frs-sale);letter-spacing:.04em;text-transform:uppercase}
.frs-promoban h3{font-family:var(--frs-font);font-weight:800;font-size:19px;margin:6px 0 2px;color:var(--frs-ink)}
.frs-promoban p{margin:0;font-size:12.5px;color:var(--frs-ink-2)}
.frs-promoban-ico{width:56px;height:56px;border-radius:16px;flex:none;background:var(--frs-card);display:flex;align-items:center;justify-content:center;color:var(--frs-accent-2)}

.frs-listrow{display:flex;align-items:flex-start;justify-content:space-between;width:100%;padding:16px 4px;border-bottom:1px solid var(--frs-hair);font-size:14.5px;font-weight:700;text-align:start;gap:10px}
.frs-listrow:last-child{border-bottom:0}
.frs-listrow-sub{display:block;font-size:12.5px;color:var(--frs-ink-2);font-weight:500;margin-top:4px}

.frs-tabbar{position:sticky;bottom:0;left:0;right:0;height:var(--frs-nav-h);background:var(--frs-card);border-top:1px solid var(--frs-hair);display:flex;align-items:center;justify-content:space-around;padding:0 10px 8px;z-index:30;margin-top:auto}
.frs-tab{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;color:var(--frs-ink-2);font-weight:600;flex:1;background:none;border:0}
.frs-tab svg{width:22px;height:22px}
.frs-tab[aria-current="true"]{color:var(--frs-accent-2)}
.frs-fab{width:56px;height:56px;border-radius:50%;background:var(--frs-accent);color:#fff;display:flex;align-items:center;justify-content:center;margin-top:-26px;box-shadow:0 12px 24px -8px rgba(18,179,166,.55);transition:transform .15s;flex:none;position:relative;border:0}
.frs-fab:active{transform:scale(.9)}
.frs-fab svg{width:24px;height:24px}

@media (prefers-reduced-motion:reduce){.frs *{animation-duration:.001s!important;transition-duration:.001s!important}}

/* =======================================================================
   Curated core features (loop/enrich): stories, reviews, loyalty, search +
   wishlist. Every endpoint reused here is an existing, already-seeded
   platform route — no backend changes made here. Every color below reuses
   an existing --frs-* token, so dark mode already covers every new surface;
   every offset uses logical (inset-inline/-block) properties for RTL.
   ======================================================================= */

/* ---- stories rail (home) + full-screen viewer ---- */
.frs-stories{display:flex;gap:14px;overflow-x:auto;overflow-y:clip;padding:2px 2px 2px;margin-top:16px;scrollbar-width:thin}
.frs-stories[hidden]{display:none}
.frs-story{flex:none;display:flex;flex-direction:column;align-items:center;gap:6px;width:68px;background:none;border:0;padding:0;color:inherit;text-align:center;font:inherit}
.frs-story-ring{width:60px;height:60px;border-radius:50%;padding:2.5px;flex:none;background:linear-gradient(135deg,var(--frs-accent),var(--frs-accent-2));display:flex;align-items:center;justify-content:center}
.frs-story.is-viewed .frs-story-ring{background:var(--frs-hair-2)}
.frs-story-thumb{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;border:2px solid var(--frs-ground);background:var(--frs-card-2)}
.frs-story-label{font-size:10.5px;font-weight:600;max-width:100%;overflow:clip;text-overflow:ellipsis;white-space:nowrap}

.frs-story-viewer{position:fixed;inset:0;z-index:80;background:#000;display:none;flex-direction:column}
.frs-story-viewer.is-open{display:flex}
.frs-sv-progress{display:flex;gap:4px;padding:12px 12px 0;flex:none}
.frs-sv-bar{flex:1;height:3px;border-radius:999px;background:rgba(255,255,255,.3);overflow:clip}
.frs-sv-bar-fill{display:block;height:100%;background:#fff;inline-size:0}
.frs-sv-bar.is-done .frs-sv-bar-fill{inline-size:100%}
.frs-sv-bar.is-active .frs-sv-bar-fill{transition:inline-size linear}
.frs-sv-head{display:flex;align-items:center;justify-content:flex-end;padding:10px 10px 0;flex:none}
.frs-sv-close{border:0;background:rgba(255,255,255,.18);color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:none}
.frs-sv-close svg{width:16px;height:16px}
.frs-sv-stage{position:relative;flex:1;display:flex;align-items:center;justify-content:center;overflow:clip;min-height:0}
.frs-sv-media{max-width:100%;max-height:100%;object-fit:contain}
.frs-sv-zone{position:absolute;inset-block:0;width:50%;background:none;border:0;padding:0}
.frs-sv-zone--prev{inset-inline-start:0}
.frs-sv-zone--next{inset-inline-end:0}
.frs-sv-foot{position:absolute;inset-inline:0;inset-block-end:0;padding:18px 18px calc(18px + env(safe-area-inset-bottom,0px));background:linear-gradient(to top,rgba(0,0,0,.75),transparent);color:#fff;display:flex;flex-direction:column;gap:12px;pointer-events:none}
.frs-sv-caption{font-size:13.5px;line-height:1.5;margin:0;text-shadow:0 1px 3px rgba(0,0,0,.5)}
.frs-sv-cta{align-self:flex-start;border:0;border-radius:100px;padding:11px 20px;font-weight:700;font-size:13px;background:#fff;color:var(--frs-accent-2);text-decoration:none;display:inline-block;pointer-events:auto}
.frs-sv-cta[hidden]{display:none}

/* ---- search (Services screen) ---- */
.frs-searchbar{position:relative;display:flex;align-items:center;margin:4px 0 4px}
.frs-searchbar svg{position:absolute;inset-inline-start:16px;width:17px;height:17px;color:var(--frs-ink-3);pointer-events:none}
.frs-searchbar input{padding-inline-start:44px}

/* ---- wishlist heart (service cards) + "Saved" view ---- */
.frs-svc-cardwrap{position:relative}
.frs-fav-btn{position:absolute;top:8px;inset-inline-start:8px;width:28px;height:28px;border-radius:50%;background:var(--frs-card);border:1px solid var(--frs-hair);color:var(--frs-ink-3);display:flex;align-items:center;justify-content:center;z-index:2;box-shadow:0 4px 10px -4px rgba(18,35,32,.35)}
.frs-fav-btn svg{width:14px;height:14px}
.frs-fav-btn.is-fav{color:var(--frs-sale)}
.frs-fav-btn.is-fav svg{fill:currentColor}

/* ---- reviews (service detail) ---- */
.frs-detail-reviews{margin-top:26px;padding-top:20px;border-top:1px solid var(--frs-hair)}
.frs-detail-reviews>.frs-label{margin-top:0}
.frs-rv-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.frs-rv-head[hidden]{display:none}
.frs-rv-avg{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.frs-rv-avg b{font-size:22px;font-weight:800}
.frs-rv-stars{color:var(--frs-sun);letter-spacing:1px;font-size:13.5px;white-space:nowrap}
.frs-rv-stars .is-off{color:var(--frs-hair-2)}
.frs-rv-count{font-size:12.5px;color:var(--frs-ink-2)}
.frs-rv-write-btn{border:1px solid var(--frs-hair-2);background:var(--frs-card);color:var(--frs-accent-2);border-radius:100px;padding:8px 14px;font-size:12.5px;font-weight:700;flex:none}
.frs-rv-dist{display:grid;gap:5px;margin-bottom:16px}
.frs-rv-dist[hidden]{display:none}
.frs-rv-dist-row{display:grid;grid-template-columns:1.2em 1fr 1.6em;align-items:center;gap:8px;font-size:11.5px;color:var(--frs-ink-2)}
.frs-rv-dist-row i{display:block;height:6px;border-radius:999px;background:var(--frs-hair);overflow:clip;font-style:normal}
.frs-rv-dist-row i s{display:block;height:100%;background:var(--frs-sun);text-decoration:none}
.frs-rv-list{display:flex;flex-direction:column;gap:14px;margin-bottom:6px}
.frs-rv-item{padding-bottom:14px;border-bottom:1px solid var(--frs-hair)}
.frs-rv-item:last-child{border-bottom:0;padding-bottom:0}
.frs-rv-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;flex-wrap:wrap}
.frs-rv-item-name{font-weight:700;font-size:13.5px}
.frs-rv-item-date{font-size:11.5px;color:var(--frs-ink-2)}
.frs-rv-item-body{font-size:13.5px;line-height:1.5;color:var(--frs-ink);margin:4px 0 0;overflow-wrap:anywhere}
.frs-rv-verified{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:var(--frs-accent-2);margin-inline-start:6px}
.frs-rv-empty{padding:14px 0 18px;text-align:center;color:var(--frs-ink-2);font-size:13.5px}
.frs-rv-empty[hidden]{display:none}
.frs-rv-form{display:none;gap:10px;margin-top:8px;padding-top:14px;border-top:1px solid var(--frs-hair)}
.frs-rv-form.is-open{display:grid}
.frs-rv-starpicker{display:flex;gap:6px}
.frs-rv-starpicker button{border:0;background:none;font-size:1.6rem;line-height:1;padding:0;color:var(--frs-hair-2)}
.frs-rv-starpicker button.is-on{color:var(--frs-sun)}
.frs-field--tight{margin-bottom:10px}

/* ---- loyalty: Rewards card (profile) + checkout redeem row ---- */
.frs-rewards-card{background:linear-gradient(135deg,var(--frs-accent-bg),var(--frs-card));border:1px solid var(--frs-hair-2);border-radius:var(--frs-radius-lg);padding:20px;margin-top:16px;box-shadow:var(--frs-shadow-card)}
.frs-rewards-top{display:flex;align-items:center;gap:12px}
.frs-rewards-ico{width:44px;height:44px;border-radius:50%;background:var(--frs-card);color:var(--frs-accent-2);display:flex;align-items:center;justify-content:center;flex:none}
.frs-rewards-ico svg{width:21px;height:21px}
.frs-rewards-program{font-weight:800;font-size:16px}
.frs-rewards-line{font-size:12.5px;color:var(--frs-ink-2);margin-top:2px}
.frs-rewards-balance{font-size:26px;font-weight:800;margin:14px 0 0;color:var(--frs-accent-2);font-variant-numeric:tabular-nums}
.frs-rewards-balance small{font-size:11.5px;font-weight:700;color:var(--frs-ink-2)}
.frs-tierchips{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.frs-tierchip{flex:1 1 0;min-width:76px;text-align:center;padding:8px 6px;border-radius:11px;font-size:11px;font-weight:700;background:var(--frs-card);border:1px solid var(--frs-hair-2);color:var(--frs-ink-2)}
.frs-tierchip.is-current{background:var(--frs-accent);border-color:var(--frs-accent);color:#fff}
.frs-rewards-signin{font-size:12.5px;color:var(--frs-accent-2);margin-top:12px;display:flex;align-items:center;gap:6px}
.frs-rewards-signin[hidden]{display:none}
.frs-rewards-signin svg{width:16px;height:16px;flex:none}
.frs-rewards-progress{height:6px;border-radius:999px;background:var(--frs-hair);margin-top:14px;overflow:clip}
.frs-rewards-progress s{display:block;height:100%;background:var(--frs-accent);text-decoration:none}

.frs-loyalty-row{border:1px dashed var(--frs-hair-2);border-radius:12px;padding:12px 14px;margin:6px 0 14px;font-size:13px}
.frs-loyalty-teaser{color:var(--frs-ink-2);line-height:1.5}
.frs-loyalty-signin{color:var(--frs-accent-2);font-weight:600;margin-top:4px}
.frs-loyalty-toggle{display:flex;align-items:center;gap:12px;width:100%;padding:0;background:none;border:0;color:inherit;font:inherit;text-align:start}
.frs-loyalty-toggle[hidden]{display:none}
.frs-loyalty-toggle .frs-at{flex:1}
.frs-loyalty-toggle[aria-checked="true"] .frs-switch{background:var(--frs-accent)}
.frs-loyalty-toggle[aria-checked="true"] .frs-switch-knob{inset-inline-start:21px}

/* =======================================================================
   DESKTOP LAYER — additive only. Nothing below this line changes anything
   under 768px; the phone design stays byte-identical. Every new rule lives
   inside a min-width media query, reuses the existing --frs-* tokens (so
   dark mode + RTL keep working with zero extra overrides), and only adds
   new classes/elements — no existing class/id/data-* is renamed or removed.
   ======================================================================= */
.frs-topnav{display:none}
.frs-hero-visual{display:none}

/* Lift the 520px phone-column cap progressively through the tablet band so the
   content is not stranded in a tiny centred column with huge grey gutters from
   ~560px up to the ≥1024 desktop shell (which sets max-width:none + its own
   inner cap). Starts at 92vw≈the old 520 near 560px, grows to 900px. */
@media (min-width:560px){
    .frs{max-width:min(900px,92vw)}
}

@media (min-width:768px){
    /* Services grid: 2-up card grid, cards flip from a horizontal row to a
       vertical card (image on top) — pure CSS reflow of the same markup. */
    .frs-svc-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .frs-svc-card{flex-direction:column;align-items:stretch;position:relative;padding-bottom:44px}
    .frs-svc-thumb{width:auto;height:168px;margin:-12px -12px 0;border-radius:var(--frs-radius) var(--frs-radius) 0 0}
    .frs-svc-body{padding-top:14px}
    .frs-svc-action{position:absolute;inset-inline-end:14px;bottom:14px}

    /* Schedule: a comfortable centered column, more slots per row instead
       of a stretched phone list. */
    [data-screen="schedule"] .frs-scroll{max-width:640px;margin-inline:auto}
    .frs-timegrid{grid-template-columns:repeat(4,minmax(0,1fr))}
}

@media (min-width:1024px){
    /* ---- Shell: lift the ~520px phone-column cap (per the contract, the
       base rule itself is never edited — only overridden here) and give
       every screen an inner content wrapper instead of a lone card in a
       sea of grey. ---- */
    .frs{max-width:none}
    .frs-hdr,.frs-scroll{
        /* width:100% is load-bearing: these are flex items in a flex column, and
           margin-inline:auto on a flex item collapses it to its content width
           (~657px) instead of filling the 1200px cap — which starved the whole
           desktop layout (2-col hero, 3-col services) down to a phone column. */
        width:100%;
        max-width:min(1200px,94vw);
        margin-inline:auto;
        padding-inline:clamp(16px,3vw,44px);
    }
    /* Sticky bottom bars stay comfortable/centered, not stretched edge to
       edge of a 1200px column. */
    /* width:100% so these flex-column items fill their 640px cap instead of
       collapsing to the button's text width (a tiny book bar) — same flex-item
       + margin-inline:auto trap as .frs-scroll above. */
    .frs-stickybar,.frs-actionbar{width:100%;max-width:640px;margin-inline:auto}

    /* ---- Top navigation: sticky top bar replaces the fixed bottom tabbar.
       Mobile/tablet keep the tabbar untouched. ---- */
    .frs-tabbar{display:none}
    .frs-haspad{padding-bottom:32px}
    .frs-toast{bottom:28px}
    .frs-hdr{top:64px}
    /* desktop top-nav already carries saved/basket → drop the duplicate header tools */
    .frs-hdr-tools{display:none}
    .frs-topnav{
        display:block;position:sticky;top:0;z-index:40;
        background:var(--frs-ground);border-block-end:1px solid var(--frs-hair);
    }
    .frs-topnav-inner{
        max-width:min(1200px,94vw);margin-inline:auto;padding-inline:clamp(16px,3vw,44px);
        height:64px;display:flex;align-items:center;gap:clamp(12px,2vw,28px);
    }
    /* Brand may shrink + ellipsize so a long store name never shoves the
       actions cluster off the start edge in the 1024–1200 band. */
    .frs-topnav-brand{
        font-family:var(--frs-font);font-weight:800;font-size:17px;color:var(--frs-ink);
        text-decoration:none;white-space:nowrap;background:none;border:0;padding:0;
        flex:0 0 auto;max-width:42%;overflow:hidden;text-overflow:ellipsis;
    }
    .frs-topnav-links{display:flex;align-items:center;gap:4px;flex:1 1 0;min-width:0;justify-content:center}
    .frs-topnav-actions{flex:0 0 auto}
    .frs-topnav-link{
        background:none;border:0;padding:9px 12px;border-radius:100px;font-weight:700;
        font-size:13.5px;color:var(--frs-ink-2);transition:background .15s,color .15s;white-space:nowrap;
    }
    .frs-topnav-link:hover{background:var(--frs-card);color:var(--frs-ink)}
    .frs-topnav-link[aria-current="true"]{background:var(--frs-accent-bg);color:var(--frs-accent-2)}
    .frs-topnav-actions{display:flex;align-items:center;gap:10px}

    /* ---- Home hero: 2-column (copy/CTA left, decorative visual right). --- */
    .frs-herocard{display:flex;align-items:center;min-height:300px}
    .frs-hero-content{max-width:54%;gap:14px}
    .frs-hero-h{font-size:30px;max-width:100%}
    .frs-hero-p{font-size:15px;max-width:100%}
    .frs-hero-visual{
        display:flex;align-items:center;justify-content:center;
        position:absolute;inset-inline-end:6%;top:50%;transform:translateY(-50%);
        width:190px;height:190px;z-index:1;
    }
    .frs-hero-visual svg{width:100%;height:100%}

    /* Services grid + 2-col service detail intentionally DEFERRED to ≥1200px
       (see that block). In the 1024–1199 band the tablet layout is roomier: a
       2-up services grid gives larger cards, and a single-column detail fills
       the viewport vertically instead of a cramped 2-col with a tall dead gap
       above the booking bar. */

    /* ---- Desktop legibility: the mobile type scale reads tiny on a wide
       viewport. Bump the small service-detail text (from-badge, item name,
       item price/duration, section labels) on desktop only. ---- */
    .frs-detail-from{font-size:15px;padding:7px 16px}
    .frs-detail-title{font-size:28px}
    .frs-label{font-size:15px}
    .frs-qty-name{font-size:16px}
    .frs-qty-price{font-size:14px}

    /* ---- Schedule: wider centered column, more slots per row. ---- */
    [data-screen="schedule"] .frs-scroll{max-width:720px}
    .frs-timegrid{grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
    .frs-timeslot{height:52px}
    .frs-datechip{width:64px;height:76px}

    /* ---- Cart / checkout: 2-column with a sticky summary card right.
       .frs-checkout-main is a new wrapper around the existing checkout
       fields; #frsBasketSummary / .frs-summary need no wrapper since the
       basket screen already has exactly the right sibling shape. ---- */
    [data-screen="checkout"] .frs-scroll,
    [data-screen="basket"] .frs-scroll{
        display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:32px;align-items:start;
    }
    [data-screen="checkout"] .frs-summary,
    [data-screen="basket"] #frsBasketSummary{position:sticky;top:128px;margin-top:0}
    [data-screen="basket"] .frs-empty{grid-column:1/-1}

    /* ---- Simple list-style screens: a comfortable centered reading column
       instead of full-bleed stretched rows. ---- */
    [data-screen="orders"] .frs-scroll,
    [data-screen="offers"] .frs-scroll,
    [data-screen="profile"] .frs-scroll{max-width:720px}
}

/* ---- Wide desktop only (≥1200): the densest layouts. Below this the
   1024–1199 band keeps the roomier tablet grid (2-up cards, single-column
   detail) so nothing is cramped and the detail screen has no dead gap. ---- */
@media (min-width:1200px){
    /* Services grid: 3-up. */
    .frs-svc-list{grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}

    /* Service detail: 2-column (media left, options + booking right). */
    [data-screen^="service:"] .frs-scroll{
        display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:40px;align-items:start;
    }
    .frs-detail-media{position:sticky;top:128px}
}

@media (min-width:1280px){
    .frs-hdr,.frs-scroll{max-width:1280px}
    .frs-topnav-inner{max-width:1280px}
    .frs-herocard{min-height:340px}
    .frs-hero-h{font-size:34px}
    .frs-hero-visual{width:220px;height:220px}
    [data-screen="schedule"] .frs-scroll{max-width:760px}
    .frs-timegrid{grid-template-columns:repeat(6,minmax(0,1fr))}
}
</style>
@endpush

@section('content')
<div class="frs" id="frs" data-store-id="{{ $store->id }}">
    <div class="frs-toast" id="frsToast" role="status" aria-live="polite"></div>

    {{-- DESKTOP-ONLY (≥1024) top nav — replaces the fixed bottom tabbar on
         wide screens. Hidden below 1024 via CSS; reuses the SAME data-nav
         values + .frs-cart-badge class the mobile UI already binds to /
         updates, so no new JS is needed at all. --}}
    <nav class="frs-topnav" aria-label="{{ $store->title }}">
        <div class="frs-topnav-inner">
            <button type="button" class="frs-topnav-brand" data-nav="home">{{ $store->title }}</button>
            <div class="frs-topnav-links">
                <button type="button" class="frs-topnav-link" data-nav="home">{{ $T['home'] }}</button>
                <button type="button" class="frs-topnav-link" data-nav="services">{{ $T['bookTab'] }}</button>
                <button type="button" class="frs-topnav-link" data-nav="orders">{{ $T['ordersTab'] }}</button>
                <button type="button" class="frs-topnav-link" data-nav="offers">{{ $T['offersTab'] }}</button>
                <button type="button" class="frs-topnav-link" data-nav="profile">{{ $T['profileTab'] }}</button>
            </div>
            <div class="frs-topnav-actions">
                @if($frsLanguages->count() > 1)
                    <label class="frs-langsel" aria-label="{{ ___('Language') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                        <select class="frs-langsel-select" aria-label="{{ ___('Language') }}">
                            @foreach($frsLanguages as $lang)
                                <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <button type="button" class="frs-iconbtn frs-notifbell" aria-label="{{ ___('Notifications') }}" style="position:relative">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                    <i class="frs-notif-dot" hidden aria-hidden="true"></i>
                </button>
                <button type="button" class="frs-iconbtn" data-nav="saved" aria-label="{{ $T['savedTitle'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                    <span class="frs-badge frs-saved-badge" hidden>0</span>
                </button>
                <button type="button" class="frs-iconbtn" data-nav="basket" aria-label="{{ $T['basket'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                    <span class="frs-badge frs-cart-badge" hidden>0</span>
                </button>
            </div>
        </div>
    </nav>

    {{-- HOME --}}
    <section class="frs-screen" id="frs-home" data-screen="home">
        <header class="frs-hdr">
            <div class="frs-greet">
                <span class="frs-greet-hello">{{ $T['hello'] }}</span>
                <span class="frs-greet-brand">{{ $store->title }}</span>
            </div>
            <div class="frs-hdr-tools">
                @if($frsLanguages->count() > 1)
                    <label class="frs-langsel" aria-label="{{ ___('Language') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                        <select class="frs-langsel-select" aria-label="{{ ___('Language') }}">
                            @foreach($frsLanguages as $lang)
                                <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <button type="button" class="frs-iconbtn frs-notifbell" aria-label="{{ ___('Notifications') }}" style="position:relative">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                    <i class="frs-notif-dot" hidden aria-hidden="true"></i>
                </button>
                <button type="button" class="frs-iconbtn" data-nav="saved" aria-label="{{ $T['savedTitle'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                    <span class="frs-badge frs-saved-badge" hidden>0</span>
                </button>
                <button type="button" class="frs-iconbtn" data-nav="basket" aria-label="{{ $T['basket'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                    <span class="frs-badge frs-cart-badge" hidden>0</span>
                </button>
            </div>
        </header>
        @if($frsLanguages->count() > 1)
            <script nonce="{{ csp_nonce() }}">
                (function(){var sels=document.querySelectorAll('.frs-langsel-select');if(!sels.length)return;sels.forEach(function(s){s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});});})();
            </script>
        @endif
        <div class="frs-scroll frs-haspad">
            {{-- Crave-parity status row: Open-Now chip + (multi-store) branch switch. --}}
            <div class="frs-statusrow">
                <span class="frs-openchip @if(! $frsIsOpen) is-closed @endif" data-mb-openchip data-mb-closed-class="is-closed">
                    <i aria-hidden="true"></i><span data-mb-open-text>{{ $frsIsOpen ? ___('Open now') : ___('Closed') }}@if($frsHoursToday) · {{ $frsHoursToday }}@endif</span>
                </span>
                @if($frsShowBranches)
                    <label class="frs-branchsel" aria-label="{{ ___('Branch') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <select id="frsBranchSel">
                            @foreach($frsBranches as $branch)
                                <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($frsActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>

            @if($frsBanners->count())
                <div class="frs-banners" id="frsBanners">
                    <div class="frs-banner-track" id="frsBannerTrack">
                        @foreach($frsBanners as $b)
                            @php
                                $frsBannerLink = trim((string) ($b->link_url ?? ''));
                                // A real http(s) link opens externally; anything else
                                // (empty, or a "#screen" placeholder) deep-links to the
                                // services screen via the SPA nav so a banner is never
                                // a dead tap.
                                $frsBannerIsExternal = (bool) preg_match('#^https?://#i', $frsBannerLink);
                            @endphp
                            <a class="frs-banner"
                               @if($frsBannerIsExternal) href="{{ storefront_localized_link($frsBannerLink) }}" target="_blank" rel="noopener"
                               @else href="#" data-banner-screen="services" @endif>
                                @if($b->image)<img class="frs-banner-img" src="{{ $b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
                                <span class="frs-banner-copy">
                                    @if($b->subtitle)<span class="frs-banner-kick">{{ $b->subtitle }}</span>@endif
                                    <span class="frs-banner-title">{{ $b->title }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                    @if($frsBanners->count() > 1)<div class="frs-banner-dots" id="frsBannerDots" aria-hidden="true"></div>@endif
                </div>
            @endif

            <button type="button" class="frs-herocard" data-nav="services">
                <span class="frs-scrim"></span>
                <span class="frs-hero-content">
                    <span class="frs-hero-k">{{ $T['heroKicker'] }}</span>
                    <span class="frs-hero-h">{{ $T['bookAPickup'] }}</span>
                    <span class="frs-hero-p">{{ $T['heroBody'] }}</span>
                    <span class="frs-hero-go">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        {{ $T['bookAPickup'] }}
                    </span>
                </span>
                {{-- Desktop-only decorative visual (hidden below 1024) — the
                     hero has no store photo field to render here, so this is
                     a purely decorative inline graphic, not user content. --}}
                <span class="frs-hero-visual" aria-hidden="true">
                    <svg viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="100" cy="100" r="88" stroke="rgba(255,255,255,.3)" stroke-width="10"/>
                        <circle cx="100" cy="100" r="60" stroke="rgba(255,255,255,.5)" stroke-width="10"/>
                        <circle cx="100" cy="100" r="32" fill="rgba(255,255,255,.16)"/>
                        <path d="M58 96c12 20 28 28 42 28s30-8 42-28" stroke="#fff" stroke-width="7" stroke-linecap="round" fill="none" opacity=".65"/>
                        <circle cx="100" cy="42" r="6" fill="#fff" opacity=".55"/>
                    </svg>
                </span>
            </button>
            <div class="frs-trustline">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                {{ $T['trustLine'] }}
            </div>

            <button type="button" class="frs-statusstrip" id="frsStatusStrip" data-nav="confirm" hidden></button>

            {{-- Curated core feature: stories rail. Fetched client-side from the
                 public, ungated stories feed and hidden entirely when empty. --}}
            <div class="frs-stories" id="frsStories" role="list" aria-label="{{ ___('Stories') }}" hidden></div>

            <div class="frs-label">{{ $T['ourServices'] }}</div>
            @if(count($jsServices))
                <div class="frs-svc-list">
                    @foreach($jsServices as $svc)
                        @include('store_templates.freshly.partials.svc-card', ['svc' => $svc, 'T' => $T])
                    @endforeach
                </div>
            @else
                <p style="color:var(--frs-ink-2);font-size:13px">{{ $T['nothingYet'] }}</p>
            @endif

            <button type="button" class="frs-promoban" data-nav="offers">
                <span class="frs-promoban-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/><path d="M17 7L7 17"/></svg>
                </span>
                <span>
                    <span class="frs-promoban-k">{{ $T['offersTitle'] }}</span>
                    <h3>{{ $T['offer1Title'] }}</h3>
                    <p>{{ $T['offer1Body'] }}</p>
                </span>
            </button>
        </div>
        @include('store_templates.freshly.partials.footer')
        @include('store_templates.freshly.partials.tabbar', ['active' => 'home', 'T' => $T])
    </section>

    {{-- SERVICES (full browsable list, reached via the bottom "Book" FAB) --}}
    <section class="frs-screen" id="frs-services" data-screen="services" hidden>
        <header class="frs-hdr">
            <button type="button" class="frs-iconbtn" data-back aria-label="{{ $T['back'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="frs-hdr-title">{{ $T['ourServices'] }}</span>
            <div class="frs-hdr-tools">
                <button type="button" class="frs-iconbtn frs-notifbell" aria-label="{{ ___('Notifications') }}" style="position:relative">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                    <i class="frs-notif-dot" hidden aria-hidden="true"></i>
                </button>
                <button type="button" class="frs-iconbtn" data-nav="saved" aria-label="{{ $T['savedTitle'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                    <span class="frs-badge frs-saved-badge" hidden>0</span>
                </button>
                <button type="button" class="frs-iconbtn" data-nav="basket" aria-label="{{ $T['basket'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                    <span class="frs-badge frs-cart-badge" hidden>0</span>
                </button>
            </div>
        </header>
        <div class="frs-scroll frs-haspad">
            @if(count($jsServices))
                {{-- Curated core feature: client-side search (no single-store
                     backend search exists — filters the already-loaded cards). --}}
                <div class="frs-searchbar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="search" id="frsSearch" class="frs-input" autocomplete="off" placeholder="{{ $T['searchPlaceholder'] }}" aria-label="{{ $T['searchPlaceholder'] }}">
                </div>
                <div class="frs-svc-list" id="frsServicesList" style="margin-top:4px">
                    @foreach($jsServices as $svc)
                        @include('store_templates.freshly.partials.svc-card', ['svc' => $svc, 'T' => $T])
                    @endforeach
                </div>
                <p class="frs-empty" id="frsSearchNoResults" hidden>{{ $T['noResults'] }}</p>
            @else
                <p style="color:var(--frs-ink-2);font-size:13px">{{ $T['nothingYet'] }}</p>
            @endif
        </div>
    </section>

    {{-- One quantity-builder screen per top-level category — server-rendered
         up front (categories are few for a service store), so every
         bookable item is a real DOM node, not client-templated HTML. --}}
    @foreach($categories as $cat)
        @php
            $its = $categoryItems[$cat->id] ?? [];
        @endphp
        @continue(! count($its))
        @php
            $catPrices = array_column($its, 'price');
            $catMinPrice = min($catPrices);
            $catCheapest = collect($its)->firstWhere('price', $catMinPrice);
            $catImg = $cat->image_url ?: ($its[0]['img'] ?? '');
            $catFromFormatted = price_symbol_format($catMinPrice);
            $catUnitLabel = $catCheapest['unitLabel'] ?? ___('per item');

            // Service gallery: the category image first, then every image of
            // every bookable row inside it (deduped). Rendered as a swipeable
            // thumbnail strip below the banner only when there are ≥2 images.
            // Dedup by URL first (cheap), then by FILE CONTENT: the seeder copies
            // one source photo to a per-item md5 filename, so several items in a
            // category can carry byte-identical images under different names — a
            // URL check alone leaves a strip of 3-5 visually identical thumbs.
            $catGallery = [];
            $seenHashes = [];
            $addGalleryImg = function ($url) use (&$catGallery, &$seenHashes) {
                if (! is_string($url) || $url === '' || in_array($url, $catGallery, true)) {
                    return;
                }
                $base = basename((string) parse_url($url, PHP_URL_PATH) ?: $url);
                // Skip GD placeholder art (a solid brand-colored tile) even when it
                // arrives as an item's PRIMARY image — the earlier filter only
                // covered gallery_images, so a placeholder primary still leaked in.
                if (str_starts_with($base, 'vshowcase-item-')) {
                    return;
                }
                $path = public_path('storage/menu/' . $base);
                $hash = is_file($path) ? md5_file($path) : $url;
                if (in_array($hash, $seenHashes, true)) {
                    return;
                }
                $seenHashes[] = $hash;
                $catGallery[] = $url;
            };
            $addGalleryImg($catImg);
            foreach ($its as $it) {
                foreach (($it['gallery'] ?? []) as $gurl) {
                    $addGalleryImg($gurl);
                }
            }
        @endphp
        @include('store_templates.freshly.partials.service-detail', [
            'cat' => $cat, 'its' => $its, 'T' => $T,
            'catImg' => $catImg, 'catFromFormatted' => $catFromFormatted, 'catUnitLabel' => $catUnitLabel,
            'catGallery' => $catGallery,
        ])
    @endforeach

    {{-- BASKET --}}
    <section class="frs-screen" id="frs-basket" data-screen="basket" hidden>
        <header class="frs-hdr">
            <button type="button" class="frs-iconbtn" data-back aria-label="{{ $T['back'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="frs-hdr-title">{{ $T['basket'] }}</span>
            <span class="frs-hdr-spacer" aria-hidden="true"></span>
        </header>
        <div class="frs-scroll" style="padding-bottom:24px">
            <div class="frs-empty" id="frsBasketEmpty">
                <div class="frs-empty-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                </div>
                <h3>{{ $T['emptyBasketTitle'] }}</h3>
                <p>{{ $T['emptyBasketBody'] }}</p>
                <button type="button" class="frs-btn frs-btn-accent" data-nav="services" style="padding:0 26px">{{ $T['browseServices'] }}</button>
            </div>
            <div id="frsBasketLines" hidden></div>
            <div class="frs-summary" id="frsBasketSummary" hidden>
                <h3>{{ $T['checkoutTitle'] }}</h3>
                <div id="frsBasketRows">
                <div class="frs-srow"><span>{{ $T['subtotal'] }}</span><span id="frsBasketSubtotal"></span></div>
                <div class="frs-srow" id="frsBasketTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="frsBasketTax"></span></div>
                <div class="frs-srow frs-srow-total"><span>{{ $T['total'] }}</span><span id="frsBasketTotal"></span></div>
                </div>
                <button type="button" class="frs-btn frs-btn-dark frs-btn-block" style="margin-top:16px" data-nav="schedule">{{ $T['schedulePickup'] }}</button>
            </div>
        </div>
    </section>

    {{-- SCHEDULE: real day/time slots from SchedulingService via
         restaurant.scheduleSlots, real fulfillment modes, a real address
         field (always collected, per spec). --}}
    <section class="frs-screen" id="frs-schedule" data-screen="schedule" hidden>
        <header class="frs-hdr">
            <button type="button" class="frs-iconbtn" data-back aria-label="{{ $T['back'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="frs-hdr-title">{{ $T['scheduleTitle'] }}</span>
            <span class="frs-hdr-spacer" aria-hidden="true"></span>
        </header>
        <div class="frs-scroll" style="padding-bottom:120px">
            <div class="frs-label" id="frsPickupDateLabel" style="margin-top:4px">{{ $T['pickupDate'] }}</div>
            <div class="frs-datechips" id="frsDays" role="group" aria-labelledby="frsPickupDateLabel"></div>

            <div class="frs-label" id="frsPickupTimeLabel">{{ $T['pickupTime'] }}</div>
            <div class="frs-timegrid" id="frsSlots" role="group" aria-labelledby="frsPickupTimeLabel"></div>

            @if($modes->count() > 1)
                <div class="frs-label" id="frsDeliveryPrefLabel">{{ $T['deliveryPref'] }}</div>
                <div id="frsModes" role="group" aria-labelledby="frsDeliveryPrefLabel">
                    @foreach($modes as $i => $mode)
                        <button type="button" class="frs-optcard" data-mode="{{ $mode['value'] }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}">
                            <span class="frs-oico">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">@if(in_array($mode['value'], ['delivery', 'ship'], true))<path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>@elseif(in_array($mode['value'], ['pickup_slot', 'appointment'], true))<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>@elseif($mode['value'] === 'in_store')<path d="M12 21s7-6 7-11a7 7 0 00-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>@else<circle cx="12" cy="12" r="9"/>@endif</svg>
                            </span>
                            <span class="frs-obody"><span class="frs-ot">{{ $mode['label'] }}</span></span>
                            <span class="frs-radio"></span>
                        </button>
                    @endforeach
                </div>
            @endif

            <label class="frs-label" for="frsAddress">{{ $T['pickupAddress'] }}</label>
            <div class="frs-addr">
                <input type="text" id="frsAddress" class="frs-input" placeholder="{{ ___('Start typing your address…') }}" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false">
                <button type="button" class="frs-addr-loc" id="frsAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                </button>
                <div class="frs-addr-suggest" id="frsAddrSuggest" hidden></div>
            </div>
            {{-- Drop-a-pin picker (shared window.mbMapPicker engine, freshly-skinned
                 .frs-mapsheet bottom sheet). Fills the SAME #frsAddress the
                 schedule/checkout submits. --}}
            <button type="button" class="frs-maplink" id="frsMapBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>
                {{ ___('Choose on map') }}
            </button>
        </div>
        <div class="frs-actionbar">
            <button type="button" class="frs-btn frs-btn-dark frs-btn-block" id="frsScheduleContinue">{{ $T['continue'] }}</button>
        </div>
    </section>

    {{-- CHECKOUT --}}
    <section class="frs-screen" id="frs-checkout" data-screen="checkout" hidden>
        <header class="frs-hdr">
            <button type="button" class="frs-iconbtn" data-back aria-label="{{ $T['back'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="frs-hdr-title">{{ $T['checkoutTitle'] }}</span>
            <span class="frs-hdr-spacer" aria-hidden="true"></span>
        </header>
        <div class="frs-scroll" style="padding-bottom:160px">
            {{-- .frs-checkout-main is a new wrapper (desktop 2-col layout
                 only — see the ≥1024 media query) around the SAME existing
                 fields below; nothing inside it is renamed or removed. --}}
            <div class="frs-checkout-main">
                <div class="frs-label" style="margin-top:4px">{{ $T['servicesLabel'] }}</div>
                <div id="frsCheckoutLines"></div>

                <div class="frs-label">{{ $T['pickupLabel'] }}</div>
                <div class="frs-optcard" id="frsCheckoutPickup" aria-pressed="true"></div>

                <div class="frs-field">
                    <label for="frsName">{{ $T['name'] }}</label>
                    <input type="text" id="frsName" class="frs-input" maxlength="255" autocomplete="name">
                </div>
                <div class="frs-field">
                    <label for="frsPhone">{{ $T['phone'] }}</label>
                    <input type="tel" id="frsPhone" class="frs-input" maxlength="25" autocomplete="tel">
                </div>

                {{-- Curated core feature: loyalty redeem row. Balance is fetched
                     (auth:customer) when checkout opens; guests / stores without
                     an active program fall back to the public teaser + a
                     sign-in hint, never a fabricated balance. --}}
                <div class="frs-loyalty-row" id="frsLoyaltyRow" hidden>
                    <div class="frs-loyalty-teaser" id="frsLoyaltyTeaser" hidden></div>
                    <div class="frs-loyalty-signin" id="frsLoyaltySignin" hidden>{{ $T['signInToEarn'] }}</div>
                    <button type="button" class="frs-loyalty-toggle" id="frsLoyaltyToggle" hidden role="switch" aria-checked="false">
                        <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-3.5a2.5 2.5 0 10-2.5 2.5H20zM4 7h3.5A2.5 2.5 0 106 4.5 2.5 2.5 0 004 7zm0 0v4h16V7M4 11v9a1 1 0 001 1h5v-10M20 11v9a1 1 0 01-1 1h-5v-10"/></svg></span>
                        <span class="frs-at" id="frsLoyaltyToggleLabel"></span>
                        <span class="frs-switch" aria-hidden="true"><span class="frs-switch-knob"></span></span>
                    </button>
                    <input type="hidden" id="frsLoyaltyRedeemInput" name="loyalty_redeem" value="0">
                </div>

                {{-- Promo code + gift card — applied server-side via OrderPricing. --}}
                <div class="frs-couponbox">
                    <div class="frs-couponrow">
                        <input type="text" class="frs-coupon-input" id="frsCouponInput" autocomplete="off" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                        <button type="button" class="frs-btn frs-btn-ghost frs-coupon-apply" id="frsCouponApply">{{ ___('Apply') }}</button>
                    </div>
                    <p class="frs-coupon-msg" id="frsCouponMsg" hidden></p>
                    <input type="hidden" name="coupon_code" id="frsCouponField" value="">
                    <input type="text" class="frs-coupon-input" id="frsGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}" style="margin-top:8px">
                </div>

                <div id="frsCheckoutMsg"></div>
            </div>

            <div class="frs-summary" id="frsCheckoutRows">
                <div class="frs-srow"><span>{{ $T['subtotal'] }}</span><span id="frsCheckoutSubtotal"></span></div>
                <div class="frs-srow" id="frsCheckoutTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="frsCheckoutTax"></span></div>
                <div class="frs-srow" id="frsCheckoutDiscountRow" hidden><span>{{ ___('Discount') }}</span><span id="frsCheckoutDiscount"></span></div>
                <div class="frs-srow frs-srow-total"><span>{{ $T['total'] }}</span><span id="frsCheckoutTotal"></span></div>
            </div>
        </div>
        <div class="frs-actionbar">
            <button type="button" class="frs-btn frs-btn-dark frs-btn-block" id="frsConfirmBtn">{{ $T['confirmBooking'] }}</button>
        </div>
    </section>

    {{-- CONFIRM: real booking reference + a live status timeline driven by
         the actual order (polls the same JSON contract bookla/track.blade.
         php's own poller uses), plus a real "contact the store" card. --}}
    <section class="frs-screen" id="frs-confirm" data-screen="confirm" hidden>
        <div class="frs-scroll" style="padding-bottom:24px">
            <div class="frs-confwrap">
                <svg class="frs-checkc" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="45"/><path d="M30 52l14 14 26-30"/></svg>
                <h2>{{ $T['bookingConfirmed'] }}</h2>
                <p>{{ $T['bookingThanks'] }}</p>
                <div class="frs-orderno" id="frsConfirmRef"></div>

                <div class="frs-track" id="frsConfirmTrack">
                    <div class="frs-track-status" id="frsTrkBadge"></div>
                    <div class="frs-track-title" id="frsTrkTitle">&nbsp;</div>
                    <p class="frs-track-desc" id="frsTrkDesc"></p>

                    <div class="frs-tstep" id="frsStep0">
                        <span class="frs-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
                        <div><div class="frs-tt">{{ ___('Received') }}</div></div>
                    </div>
                    <div class="frs-tstep" id="frsStep1">
                        <span class="frs-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/></svg></span>
                        <div><div class="frs-tt">{{ ___('Processing') }}</div></div>
                    </div>
                    <div class="frs-tstep" id="frsStep2">
                        <span class="frs-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg></span>
                        <div><div class="frs-tt" id="frsStep2Label">{{ ___('Ready') }}</div></div>
                    </div>
                    <div class="frs-tstep" id="frsStep3">
                        <span class="frs-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 001 1h10a1 1 0 001-1v-9"/></svg></span>
                        <div><div class="frs-tt">{{ ___('Done') }}</div></div>
                    </div>
                </div>

                @if($storePhone || $storeWhatsapp)
                    <div class="frs-couriercard" id="frsContactCard" hidden>
                        <span class="frs-courier-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 013.5-.5c1.5 1 .5 2.5-1 3.5v1M12 17h.01"/></svg>
                        </span>
                        <div class="frs-courier-info">
                            <div class="frs-courier-name">{{ $T['contactStore'] }}</div>
                            <div class="frs-courier-role">{{ $T['needHelp'] }}</div>
                        </div>
                        <div class="frs-courier-acts">
                            @if($storePhone)
                                <a class="frs-courier-btn" href="tel:{{ $storePhoneHref }}" aria-label="{{ $T['call'] }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h3l2 5-2 1a12 12 0 006 6l1-2 5 2v3a2 2 0 01-2 2A16 16 0 014 5a2 2 0 012-2z"/></svg>
                                </a>
                            @endif
                            @if($storeWhatsapp)
                                <a class="frs-courier-btn" href="https://wa.me/{{ $storeWaHref }}" target="_blank" rel="noopener" aria-label="{{ $T['chat'] }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v10H8l-4 4z"/></svg>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <div class="frs-actionbar">
            <button type="button" class="frs-btn frs-btn-ghost" data-nav="home">{{ $T['backHome'] }}</button>
            <a class="frs-btn frs-btn-dark" id="frsConfirmTrackLink" href="#" target="_blank" rel="noopener">{{ $T['viewFullTracking'] }}</a>
        </div>
    </section>

    {{-- ORDERS: real bookings only — each row is a store-side order reached
         through its own track_token; the list itself is remembered on this
         device (localStorage), the same convention the basket already uses,
         since this self-contained booking flow has no customer login. --}}
    <section class="frs-screen" id="frs-orders" data-screen="orders" hidden>
        <header class="frs-hdr"><span class="frs-hdr-title">{{ $T['ordersTab'] }}</span></header>
        <div class="frs-scroll frs-haspad">
            <div class="frs-empty" id="frsOrdersEmpty">
                <div class="frs-empty-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg>
                </div>
                <h3>{{ $T['noOrdersTitle'] }}</h3>
                <p>{{ $T['noOrdersBody'] }}</p>
                <button type="button" class="frs-btn frs-btn-accent" data-nav="services" style="padding:0 26px">{{ $T['browseServices'] }}</button>
            </div>
            <div id="frsOrdersList" hidden></div>
        </div>
        @include('store_templates.freshly.partials.tabbar', ['active' => 'orders', 'T' => $T])
    </section>

    {{-- OFFERS --}}
    <section class="frs-screen" id="frs-offers" data-screen="offers" hidden>
        <header class="frs-hdr"><span class="frs-hdr-title">{{ $T['offersTitle'] }}</span></header>
        <div class="frs-scroll frs-haspad">
            <div class="frs-promoban" style="margin-top:4px">
                <span class="frs-promoban-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/><path d="M17 7L7 17"/></svg>
                </span>
                <span>
                    <h3 style="margin-top:0">{{ $T['offer1Title'] }}</h3>
                    <p>{{ $T['offer1Body'] }}</p>
                </span>
            </div>
            <div class="frs-promoban" style="margin-top:14px">
                <span class="frs-promoban-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
                <span>
                    <h3 style="margin-top:0">{{ $T['offer2Title'] }}</h3>
                    <p>{{ $T['offer2Body'] }}</p>
                </span>
            </div>
            <div class="frs-promoban" style="margin-top:14px">
                <span class="frs-promoban-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7"/><path d="M6 10v9h12v-9"/><path d="M10 19v-5h4v5"/></svg>
                </span>
                <span>
                    <h3 style="margin-top:0">{{ $T['offer3Title'] }}</h3>
                    <p>{{ $T['offer3Body'] }}</p>
                </span>
            </div>
        </div>
        @include('store_templates.freshly.partials.footer')
        @include('store_templates.freshly.partials.tabbar', ['active' => 'offers', 'T' => $T])
    </section>

    {{-- SAVED — client-side wishlist (localStorage), curated core feature.
         Lists services favorited via the heart on any service card. --}}
    <section class="frs-screen" id="frs-saved" data-screen="saved" hidden>
        <header class="frs-hdr">
            <button type="button" class="frs-iconbtn" data-back aria-label="{{ $T['back'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="frs-hdr-title">{{ $T['savedTitle'] }}</span>
            <span class="frs-hdr-spacer" aria-hidden="true"></span>
        </header>
        <div class="frs-scroll frs-haspad">
            <div class="frs-empty" id="frsSavedEmpty">
                <div class="frs-empty-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                </div>
                <h3>{{ $T['nothingSavedTitle'] }}</h3>
                <p>{{ $T['nothingSavedBody'] }}</p>
                <button type="button" class="frs-btn frs-btn-accent" data-nav="services" style="padding:0 26px">{{ $T['browseServices'] }}</button>
            </div>
            <div class="frs-svc-list" id="frsSavedList" hidden></div>
        </div>
    </section>

    {{-- PROFILE --}}
    <section class="frs-screen" id="frs-profile" data-screen="profile" hidden>
        <header class="frs-hdr"><span class="frs-hdr-title">{{ $T['profileTitle'] }}</span></header>
        <div class="frs-scroll frs-haspad">
            {{-- Identity card = the VIEWER, never the store (the store name/phone
                 already lead the top brand bar). Logged-in customer shows their
                 own name + phone; a guest sees a neutral Guest state with a
                 sign-in prompt — never the store presented as the account. --}}
            @php $frsCust = auth('customer')->user(); @endphp
            <div class="frs-profile">
                <span class="frs-avatar-ph" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                </span>
                <div>
                    <div class="frs-pn">{{ $frsCust->name ?? $T['guest'] }}</div>
                    @if($frsCust && $frsCust->phone)
                        <div class="frs-pe">{{ $frsCust->phone }}</div>
                    @else
                        <div class="frs-psub">{{ $T['signInRegister'] }}</div>
                    @endif
                </div>
            </div>

            {{-- Curated core feature: Rewards card. Teaser is public; the balance
                 area is filled in (auth:customer) when the Profile screen opens —
                 a guest or a store without an active program sees the teaser +
                 a sign-in hint instead, never a fabricated balance. --}}
            @if($loy)
                <div class="frs-rewards-card">
                    <div class="frs-rewards-top">
                        <span class="frs-rewards-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-3.5a2.5 2.5 0 10-2.5 2.5H20zM4 7h3.5A2.5 2.5 0 106 4.5 2.5 2.5 0 004 7zm0 0v4h16V7M4 11v9a1 1 0 001 1h5v-10M20 11v9a1 1 0 01-1 1h-5v-10"/></svg></span>
                        <div>
                            <div class="frs-rewards-program">{{ $loy['program'] ?? $T['rewardsTitle'] }}</div>
                            @if(!empty($loy['earn']))
                                {{-- Seeded teaser text is stored in English; localise it
                                     through ___() (a custom owner value with no key just
                                     renders as-is). --}}
                                <div class="frs-rewards-line">{{ ___($loy['earn']) }}</div>
                            @endif
                        </div>
                    </div>
                    @if(!empty($loy['reward']))
                        <div class="frs-rewards-line">{{ ___($loy['reward']) }}</div>
                    @endif
                    <div id="frsRewardsBalanceArea"></div>
                    <div class="frs-tierchips" id="frsTierChips">
                        <span class="frs-tierchip" data-tier="silver">{{ $T['silver'] }}</span>
                        <span class="frs-tierchip" data-tier="gold">{{ $T['gold'] }}</span>
                        <span class="frs-tierchip" data-tier="platinum">{{ $T['platinum'] }}</span>
                    </div>
                    <div class="frs-rewards-signin" id="frsRewardsSignin" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                        <span>{{ $T['signInToEarn'] }}</span>
                    </div>
                </div>
            @endif

            <div class="frs-acclist">
                @if(($postOptions->customer_auth_mode ?? '') !== 'demo')
                    @if(auth('customer')->check())
                        @if(\App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
                            <button type="button" class="frs-accrow" data-mb-wallet-open>
                                <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M16 14h2"/></svg></span>
                                <span class="frs-at">{{ ___('Wallet — add funds') }}</span>
                                <span class="frs-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                            </button>
                        @endif
                        <button type="button" class="frs-accrow" data-mb-logout data-logout-url="{{ route('customer.logout', $store->id) }}">
                            <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 12h9M16 8l3 4-3 4"/></svg></span>
                            <span class="frs-at">{{ ___('Log out') }}</span>
                            <span class="frs-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                        </button>
                    @else
                        <button type="button" class="frs-accrow" data-mb-auth-open>
                            <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 00-2 2v12a2 2 0 002 2h3M14 12H4M11 8l-3 4 3 4"/></svg></span>
                            <span class="frs-at">{{ ___('Sign in / Register') }}</span>
                            <span class="frs-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                        </button>
                    @endif
                @endif
                <button type="button" class="frs-accrow" id="frsDarkToggle" role="switch" aria-checked="false">
                    <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg></span>
                    <span class="frs-at">{{ $T['darkMode'] }}</span>
                    <span class="frs-switch" aria-hidden="true"><span class="frs-switch-knob"></span></span>
                </button>
                <button type="button" class="frs-accrow" data-nav="orders">
                    <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></span>
                    <span class="frs-at">{{ $T['myBookings'] }}</span>
                    <span class="frs-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="frs-accrow" data-nav="saved">
                    <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg></span>
                    <span class="frs-at">{{ $T['savedTitle'] }}</span>
                    <span class="frs-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="frs-accrow" id="frsAccShare" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
                    <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg></span>
                    <span class="frs-at">{{ ___('Share this store') }}</span>
                    <span class="frs-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                @if($storePhone)
                    <a class="frs-accrow" href="tel:{{ $storePhoneHref }}">
                        <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h3l2 5-2 1a12 12 0 006 6l1-2 5 2v3a2 2 0 01-2 2A16 16 0 014 5a2 2 0 012-2z"/></svg></span>
                        <span class="frs-at">{{ $T['call'] }}</span>
                    </a>
                @endif
                @if($storeWhatsapp)
                    <a class="frs-accrow" href="https://wa.me/{{ $storeWaHref }}" target="_blank" rel="noopener">
                        <span class="frs-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v10H8l-4 4z"/></svg></span>
                        <span class="frs-at">{{ $T['chat'] }}</span>
                    </a>
                @endif
                {{-- Privacy: wipe the cart / favourites / saved-address data this
                     browser keeps (shared window.mbClearDeviceData engine). The
                     Profile screen is guest-reachable (no login gate), so the
                     control lives here rather than in the checkout footer. --}}
                <button type="button" class="frs-accrow frs-cleardata" id="frsClearData">
                    <span class="frs-aico frs-cleardata-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg></span>
                    <span class="frs-at">{{ ___('Clear saved data on this device') }}</span>
                </button>
            </div>
            <p class="frs-cleardata-hint" id="frsClearDataHint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
        </div>
        @include('store_templates.freshly.partials.footer')
        @include('store_templates.freshly.partials.tabbar', ['active' => 'profile', 'T' => $T])
    </section>

    {{-- Curated core feature: full-screen story viewer (image, caption, timed
         progress bars, optional CTA, tap-zone/prev-next, close). Visibility is
         driven by the .is-open class (not [hidden]) so it can layer above
         every screen regardless of which one is currently active. --}}
    <div class="frs-story-viewer" id="frsStoryViewer" role="dialog" aria-modal="true" aria-label="{{ ___('Stories') }}" aria-hidden="true" inert>
        <div class="frs-sv-progress" id="frsStoryProgress"></div>
        <div class="frs-sv-head">
            <button type="button" class="frs-sv-close" id="frsStoryClose" aria-label="{{ $T['close'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="frs-sv-stage">
            <button type="button" class="frs-sv-zone frs-sv-zone--prev" id="frsStoryPrev" aria-label="{{ $T['previous'] }}"></button>
            <img class="frs-sv-media" id="frsStoryMedia" alt="" data-csp-error="hide">
            <button type="button" class="frs-sv-zone frs-sv-zone--next" id="frsStoryNext" aria-label="{{ $T['next'] }}"></button>
        </div>
        <div class="frs-sv-foot">
            <p class="frs-sv-caption" id="frsStoryCaption" hidden></p>
            <a class="frs-sv-cta" id="frsStoryCta" href="#" hidden rel="noopener"></a>
        </div>
    </div>

    {{-- Address map picker — freshly-native .frs-mapsheet bottom sheet driven by the
         shared window.mbMapPicker engine (freshly has no drawer of its own, so this
         mirrors the theme's own full-screen story-viewer open pattern: .is-open +
         inert + aria-hidden, with its own scrim). Opened from the schedule screen's
         address field; the confirm fills #frsAddress. --}}
    <div class="frs-mapsheet-scrim" id="frsMapScrim" hidden></div>
    <div class="frs-mapsheet" id="frsMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" aria-hidden="true" inert>
        <div class="frs-mapsheet-head">
            <strong class="frs-mapsheet-title" id="frsMapTitle" tabindex="-1">{{ ___('Choose on map') }}</strong>
            <button type="button" class="frs-iconbtn" id="frsMapClose" aria-label="{{ ___('Close') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="frs-map-canvas" id="frsMap"></div>
        <p class="frs-map-preview" id="frsMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <div class="frs-mapsheet-foot">
            <button type="button" class="frs-btn frs-btn-accent frs-btn-block" id="frsMapConfirm">{{ ___('Use this location') }}</button>
        </div>
    </div>

    {{-- Real notification centre — the header bells forward to a hidden proxy. --}}
    <button type="button" id="mbNotifBell" class="frs-nb-proxy" aria-hidden="true" tabindex="-1"></button>
    <i id="mbNotifDot" hidden></i>
    @include('store_templates._partials.notif-bell')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var proxy = document.getElementById('mbNotifBell');
            var srcDot = document.getElementById('mbNotifDot');
            var bells = document.querySelectorAll('.frs-notifbell');
            bells.forEach(function (bell) { bell.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (proxy && window.mbNotify) { proxy.click(); } }); });
            if (srcDot) {
                var mirror = function () { bells.forEach(function (b) { var d = b.querySelector('.frs-notif-dot'); if (d) { d.hidden = srcDot.hidden; } }); };
                new MutationObserver(mirror).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
                mirror();
            }
            var shareBtn = document.getElementById('frsAccShare');
            if (shareBtn) { shareBtn.addEventListener('click', function () { var url = shareBtn.getAttribute('data-share-url') || location.href; var title = shareBtn.getAttribute('data-share-title') || document.title; if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); } else if (navigator.clipboard) { navigator.clipboard.writeText(url).catch(function () {}); } }); }
        })();
    </script>

@if(($postOptions->customer_auth_mode ?? '') !== 'demo')
    @include('store_templates._partials.mb-auth-sheet', ['store' => $store])
    @if(auth('customer')->check() && \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
        @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @endif
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--frs-card);--mba-ink:var(--frs-ink);--mba-muted:var(--frs-ink-2,var(--frs-ink));
            --mba-border:var(--frs-hair);--mba-hair:var(--frs-hair);--mba-in-bg:var(--frs-card);
            --mba-accent:var(--frs-accent);--mba-on-accent:#fff;--mba-accent-ring:var(--frs-hair);--mba-radius:16px}
    </style>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        ['frsRewardsSignin', 'frsLoyaltySignin'].forEach(function (id) {
            var e = document.getElementById(id);
            if (e) { e.setAttribute('data-mb-auth-open', ''); e.style.cursor = 'pointer'; }
        });
        var CSRF = @json(csrf_token());
        document.querySelectorAll('[data-mb-logout]').forEach(function (b) {
            b.addEventListener('click', function () {
                fetch(b.getAttribute('data-logout-url'), { method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function () { location.reload(); }).catch(function () { location.reload(); });
            });
        });
    })();
    </script>
@endif
</div>
@endsection

@push('style')
<style nonce="{{ csp_nonce() }}">
    /* Address map picker + clear-saved-data (shared engines, freshly-skinned). */
    /* "Choose on map" — accent text link under the delivery-address input. */
    .frs-maplink{display:inline-flex;align-items:center;gap:7px;margin-top:10px;padding:0;border:0;background:none;
        font:inherit;font-size:13px;font-weight:700;letter-spacing:.01em;color:var(--frs-accent-2);cursor:pointer}
    .frs-maplink svg{width:16px;height:16px}
    .frs-maplink:hover{text-decoration:underline;text-underline-offset:3px}
    /* Bottom-sheet map picker — mirrors the theme's story-viewer overlay pattern.
       Sits above the story viewer (z 80) and tab bar; below the shared auth sheet. */
    .frs-mapsheet-scrim{position:fixed;inset:0;z-index:88;background:rgba(6,16,15,.5);
        opacity:0;transition:opacity .25s ease}
    .frs-mapsheet-scrim.is-open{opacity:1}
    .frs-mapsheet-scrim[hidden]{display:none}
    .frs-mapsheet{position:fixed;inset-inline:0;bottom:0;z-index:89;display:flex;flex-direction:column;
        max-width:640px;margin-inline:auto;max-height:88vh;background:var(--frs-card);
        border-radius:var(--frs-radius-lg) var(--frs-radius-lg) 0 0;
        box-shadow:0 -14px 40px -20px rgba(6,16,15,.55);
        transform:translateY(100%);transition:transform .28s cubic-bezier(.22,1,.36,1);
        padding-bottom:env(safe-area-inset-bottom,0px)}
    .frs-mapsheet.is-open{transform:translateY(0)}
    .frs-mapsheet-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 16px 12px;flex:none}
    .frs-mapsheet-title{font-size:17px;font-weight:800;letter-spacing:-.01em;color:var(--frs-ink)}
    .frs-mapsheet-title:focus{outline:none}
    .frs-map-canvas{position:relative;height:min(52vh,360px);background:var(--frs-card-2)}
    .frs-map-canvas .leaflet-container{width:100%;height:100%;font:inherit;background:var(--frs-card-2)}
    .frs-map-preview{margin:0;padding:13px 16px;font-size:13px;line-height:1.45;color:var(--frs-ink-2);
        text-align:center;border-top:1px solid var(--frs-hair);min-height:20px}
    .frs-mapsheet-foot{padding:14px 16px calc(14px + env(safe-area-inset-bottom,0px));flex:none}
    /* Teardrop pin dropped into the map centre (engine injects .mb-map-pin; theme skins it). */
    .mb-map-pin{position:absolute;left:50%;top:50%;width:28px;height:36px;transform:translate(-50%,-100%);
        z-index:500;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(6,16,15,.4))}
    .mb-map-pin::before{content:"";position:absolute;left:50%;top:0;transform:translateX(-50%) rotate(45deg);
        width:24px;height:24px;border-radius:50% 50% 50% 0;background:var(--frs-accent);border:2px solid var(--frs-card)}
    .mb-map-pin::after{content:"";position:absolute;left:50%;top:7px;transform:translateX(-50%);width:8px;height:8px;border-radius:50%;background:var(--frs-card)}
    /* Clear-saved-data — account row tinted with the theme's danger token + a muted hint. */
    .frs-cleardata .frs-at{color:var(--frs-sale);font-weight:700}
    .frs-cleardata-ico{color:var(--frs-sale)}
    .frs-cleardata-hint{margin:9px 4px 0;font-size:12px;line-height:1.45;color:var(--frs-ink-2)}
</style>
@endpush

@push('script')
{{-- Device-local GUEST order history (window.mbGuestOrders) — shared across
     every storefront theme; lets a guest (no account) still see the order they
     just placed on the Orders screen. Must load before the main script. --}}
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
{{-- Shared map-picker + clear-device-data engines. freshly is a self-contained
     layout (does NOT extend store_templates/layout), so it @includes these itself
     — they are NOT auto-wired for this theme; the freshly-skinned UI + wiring is
     below in the main script. --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var root = document.getElementById('frs');
    if (!root) { return; }
    var STORE_ID = root.getAttribute('data-store-id') || '';

    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var SLOT_ROUTE = @json($slotRoute);
    var BOOK_ROUTE = @json($bookRoute);
    var CART_QUOTE_ROUTE = @json(route('store.cartQuote', $store->slug));
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    var TRACK_ROUTE_TEMPLATE = @json($trackRouteTemplate);
    var MY_ORDERS_ROUTE = @json(\Illuminate\Support\Facades\Route::has('publicView.myOrders.index') ? route('publicView.myOrders.index', $store->id) : null);
    var IS_CUSTOMER = @json(auth('customer')->check());
    var CSRF = @json(csrf_token());
    var T = @json($T);
    var STATUSES = @json($statuses);
    var CUR = { sign: @json($curSign), gap: @json($curGap), pos: @json($curPos), decimals: @json($curDecimals) };
    var TAX = { charge: @json($taxCharge), type: @json($taxType) };
    var TERMINAL = { done: 1, cancelled: 1 };

    // Mirrors Order::computeTax() exactly: 0 when no tax configured or empty cart.
    function computeTax(subtotal) {
        if (TAX.charge <= 0 || subtotal <= 0) { return 0; }
        var raw = TAX.type === 'fixed' ? TAX.charge : subtotal * (TAX.charge / 100);
        var f = Math.pow(10, CUR.decimals);
        return Math.round(raw * f) / f;
    }

    // Curated core features (loop/enrich) — endpoint bases + seeded data.
    var STORIES_URL = @json($storiesUrl);
    var MBR_BASE = @json($mbrBase);
    var LOYALTY_BALANCE_URL = @json($loyaltyBalanceUrl);
    var LOY_TEASER = @json($loy);
    var SERVICE_REVIEW_ITEM = @json($serviceReviewItem);
    var SERVICES = @json($jsServicesById);

    function cartLineRoute(lineId) { return CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(lineId)); }
    function trackRoute(token) { return TRACK_ROUTE_TEMPLATE.replace('XTRACKTOKENX', encodeURIComponent(token)); }
    function statusCfg(status, isDelivery) {
        if (status === 'ready') { return STATUSES[isDelivery ? 'ready_delivery' : 'ready_pickup']; }
        return STATUSES[status] || STATUSES.new;
    }
    function setText(id, txt) { var n = document.getElementById(id); if (n) { n.textContent = txt; } }
    function formatMoney(n) {
        var amount = Number(n) || 0;
        var str;
        try {
            str = amount.toLocaleString(document.documentElement.lang || undefined, {
                minimumFractionDigits: CUR.decimals, maximumFractionDigits: CUR.decimals,
            });
        } catch (e) { str = amount.toFixed(CUR.decimals); }
        var money = CUR.pos === '1' ? (CUR.sign + CUR.gap + str) : (str + CUR.gap + CUR.sign);
        // Wrap the whole price in an LTR isolate (U+2066…U+2069) so the number +
        // sign render as one left-to-right unit. Without it, a price-only element
        // (e.g. the subtotal) bidi-flips the sign to the wrong side on an RTL page.
        return '⁦' + money + '⁩';
    }

    /* ---------------- dark mode — same localStorage convention as bookla,
       surfaced as a switch row on the Profile screen instead of a header
       icon (this theme has many screens; a settings-style toggle belongs on
       Profile, not repeated on every header). ---------------- */
    var darkKey = 'freshly-theme-' + STORE_ID;
    try {
        var savedTheme = window.localStorage.getItem(darkKey);
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    function currentIsDark() {
        var attr = document.documentElement.getAttribute('data-theme');
        if (attr === 'dark') { return true; }
        if (attr === 'light') { return false; }
        try { return window.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { return false; }
    }
    function syncDarkSwitch() {
        var btn = document.getElementById('frsDarkToggle');
        if (!btn) { return; }
        btn.setAttribute('aria-checked', currentIsDark() ? 'true' : 'false');
    }
    var darkBtn = document.getElementById('frsDarkToggle');
    if (darkBtn) {
        darkBtn.addEventListener('click', function () {
            var next = currentIsDark() ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(darkKey, next); } catch (e) { /* best-effort */ }
            syncDarkSwitch();
        });
        syncDarkSwitch();
    }

    /* ---------------- screen navigation — every screen is a real,
       server-rendered <section data-screen="KEY">; JS only toggles [hidden]
       and updates the few genuinely dynamic regions (basket/schedule/
       checkout/confirm/orders), no client-side HTML templating for
       structure. Per-category screens use the key "service:<id>". -------- */
    var screens = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-screen]'), function (el) {
        screens[el.getAttribute('data-screen')] = el;
    });
    var state = { screen: 'home', stack: ['home'], activeOrder: null };

    function activate(key) {
        Object.keys(screens).forEach(function (k) { screens[k].hidden = (k !== key); });
        state.screen = key;
        // Remember the active screen for THIS tab so a same-tab reload (a
        // language switch is a full navigation) lands back here, not on home.
        try { window.sessionStorage.setItem('mb-screen-' + STORE_ID, key); } catch (e) {}
        window.scrollTo(0, 0);
        var section = screens[key];
        var focusTarget = section && section.querySelector('.frs-hdr-title, .frs-greet-hello');
        if (focusTarget) {
            focusTarget.setAttribute('tabindex', '-1');
            try { focusTarget.focus({ preventScroll: true }); } catch (e) { focusTarget.focus(); }
        }
        onEnterScreen(key);
    }
    function go(key) {
        if (!key || !screens[key]) { return; }
        if (key !== state.screen) { state.stack.push(key); }
        activate(key);
    }
    function back() {
        state.stack.pop();
        activate(state.stack[state.stack.length - 1] || 'home');
    }
    function onEnterScreen(key) {
        if (key === 'basket') { renderBasket(); }
        else if (key === 'schedule') { loadSlotsForDay(null); }
        else if (key === 'checkout') { renderCheckout(); loadLoyaltyRow(); }
        else if (key === 'confirm') { if (state.activeOrder) { applyActiveOrderToConfirmUI(); pollActiveOrder(true); } }
        else if (key === 'orders') { renderOrdersList(); }
        else if (key === 'saved') { renderSaved(); }
        else if (key === 'profile') { loadRewardsBalance(); }
        else if (key.indexOf('service:') === 0) { loadServiceReviews(key.slice(8)); }
    }

    /* ---------------- toast ---------------- */
    var toastTimer;
    function toast(msg) {
        var el = document.getElementById('frsToast');
        if (!el) { return; }
        el.textContent = msg;
        el.classList.add('is-show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('is-show'); }, 1800);
    }
    function showMsg(container, text, isError) {
        if (!container) { return; }
        container.innerHTML = '';
        var div = document.createElement('div');
        div.className = 'frs-msg' + (isError ? ' frs-msg-error' : '');
        div.textContent = text;
        container.appendChild(div);
    }

    /* ---------------- cart (client mirror of the real store-side booking
       cart, same convention bookla's own JS cart uses) ---------------- */
    var CART_KEY = 'freshly-cart-' + STORE_ID;
    var cart = [];
    try {
        var storedCart = window.localStorage.getItem(CART_KEY);
        if (storedCart) { cart = JSON.parse(storedCart) || []; }
    } catch (e) { cart = []; }
    // Drop stored lines whose menu item no longer exists (removed/unavailable
    // since added) — else cartCount() counts a line renderCart() skips, so the
    // cart badge shows a number over an empty cart. Prune once on load.
    try { var _cbefore = cart.length; cart = cart.filter(function (l) { return l && ITEMS[l.id]; }); if (cart.length !== _cbefore) { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } } catch (e) { /* best-effort */ }
    function saveCart() { try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) { /* best-effort */ } }
    function cartCount() { return cart.reduce(function (n, l) { return n + (Number(l.qty) || 0); }, 0); }
    function refreshCartBadges() {
        var n = Math.round(cartCount());
        Array.prototype.forEach.call(root.querySelectorAll('.frs-cart-badge'), function (b) {
            b.textContent = String(n);
            b.hidden = n === 0;
        });
    }

    function postAddLine(addRoute, qty) {
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('quantity', qty);
        return fetch(addRoute, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || !data.success) { throw new Error((data && data.message) || 'add-line failed'); }
                return data;
            });
    }

    /* ---------------- service detail: qty steppers + running subtotal ---- */
    function stepItem(itemId, dir) {
        var row = root.querySelector('.frs-qtyrow[data-item="' + itemId + '"]');
        if (!row) { return; }
        var unit = row.getAttribute('data-unit');
        var step = unit === 'kg' ? 0.1 : 1;
        var span = row.querySelector('.frs-qty');
        var cur = parseFloat(span.getAttribute('data-value') || '0') || 0;
        var next = Math.max(0, +(cur + dir * step).toFixed(2));
        span.setAttribute('data-value', String(next));
        span.textContent = unit === 'kg' ? next.toFixed(1) : String(Math.round(next));
        var decBtn = row.querySelector('[data-item-dec]');
        if (decBtn) { decBtn.disabled = next <= 0; }
        updateServiceSubtotal(row.getAttribute('data-service'));
    }
    function updateServiceSubtotal(catId) {
        var rows = root.querySelectorAll('.frs-qtyrow[data-service="' + catId + '"]');
        var sum = 0, any = false;
        Array.prototype.forEach.call(rows, function (row) {
            var price = parseFloat(row.getAttribute('data-price')) || 0;
            var span = row.querySelector('.frs-qty');
            var qty = parseFloat(span.getAttribute('data-value') || '0') || 0;
            if (qty > 0) { any = true; }
            sum += price * qty;
        });
        var out = root.querySelector('.frs-svc-subtotal[data-svc-subtotal="' + catId + '"]');
        if (out) { out.textContent = formatMoney(sum); }
        var addBtn = root.querySelector('[data-add-to-basket="' + catId + '"]');
        if (addBtn) { addBtn.disabled = !any; }
    }
    function addServiceToBasket(catId, btn) {
        var rows = root.querySelectorAll('.frs-qtyrow[data-service="' + catId + '"]');
        var toAdd = [];
        Array.prototype.forEach.call(rows, function (row) {
            var span = row.querySelector('.frs-qty');
            var qty = parseFloat(span.getAttribute('data-value') || '0') || 0;
            if (qty > 0) {
                toAdd.push({ id: row.getAttribute('data-item'), qty: qty, addRoute: row.getAttribute('data-add-route'), row: row, span: span });
            }
        });
        if (!toAdd.length) { return; }
        if (btn) { btn.disabled = true; }
        Promise.all(toAdd.map(function (line) {
            return postAddLine(line.addRoute, line.qty).then(function (data) {
                var existing = cart.filter(function (l) { return String(l.id) === String(line.id); })[0];
                if (existing) { existing.qty += line.qty; existing.lineId = data.line_id; }
                else { cart.push({ id: line.id, qty: line.qty, lineId: data.line_id }); }
            });
        })).then(function () {
            saveCart();
            refreshCartBadges();
            toAdd.forEach(function (line) {
                line.span.setAttribute('data-value', '0');
                line.span.textContent = '0';
                var dec = line.row.querySelector('[data-item-dec]');
                if (dec) { dec.disabled = true; }
            });
            updateServiceSubtotal(catId);
            toast(T.addedToBasket);
        }).catch(function () {
            window.alert(T.genericError);
        }).then(function () {
            if (btn) { btn.disabled = false; }
        });
    }

    /* ---------------- basket screen ---------------- */
    function removeCartLine(line) {
        cart = cart.filter(function (l) { return l !== line; });
        saveCart();
        refreshCartBadges();
        renderBasket();
        if (line.lineId) {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('quantity', '0');
            fetch(cartLineRoute(line.lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).catch(function () { /* best-effort */ });
        }
        toast(T.removed);
    }
    function adjustEachLine(line, dir) {
        var next = line.qty + dir;
        if (next <= 0) { removeCartLine(line); return; }
        line.qty = next;
        saveCart();
        if (line.lineId) {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('quantity', String(next));
            fetch(cartLineRoute(line.lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).catch(function () { /* best-effort */ });
        }
        refreshCartBadges();
        renderBasket();
    }
    // A 'kg' line's weight lives in a separate column GoodsCartService's own
    // quantity-set endpoint never touches (ServiceBookingService::
    // upsertWeightLine adds to it instead) — so a weighed line can only grow
    // here (via the same addRoute every "+" already uses) or be removed
    // outright; there is no real endpoint to shave weight off in place.
    function addWeightToLine(line) {
        var item = ITEMS[line.id];
        if (!item) { return; }
        postAddLine(item.addRoute, 0.1).then(function (data) {
            line.qty = +(line.qty + 0.1).toFixed(2);
            line.lineId = data.line_id;
            saveCart();
            refreshCartBadges();
            renderBasket();
        }).catch(function () { window.alert(T.genericError); });
    }
    function renderBasket() {
        var linesWrap = document.getElementById('frsBasketLines');
        var emptyWrap = document.getElementById('frsBasketEmpty');
        var summary = document.getElementById('frsBasketSummary');
        if (!linesWrap) { return; }
        linesWrap.innerHTML = '';
        if (!cart.length) {
            emptyWrap.hidden = false;
            linesWrap.hidden = true;
            summary.hidden = true;
            return;
        }
        emptyWrap.hidden = true;
        linesWrap.hidden = false;
        summary.hidden = false;

        var sum = 0;
        cart.forEach(function (line) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var lt = item.price * line.qty;
            sum += lt;

            var card = document.createElement('div');
            card.className = 'frs-citem';
            var top = document.createElement('div');
            top.className = 'frs-citem-top';
            var thumb = document.createElement('div');
            thumb.className = 'frs-cthumb';
            if (item.img) {
                var img = document.createElement('img');
                img.src = item.img; img.alt = ''; img.loading = 'lazy';
                img.setAttribute('data-csp-error', 'hide');
                thumb.appendChild(img);
            } else {
                thumb.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/></svg>';
            }
            var info = document.createElement('div');
            info.className = 'frs-cinfo';
            var nameEl = document.createElement('div'); nameEl.className = 'frs-cname'; nameEl.textContent = item.name;
            var colEl = document.createElement('div'); colEl.className = 'frs-ccol'; colEl.textContent = item.priceFormatted + ' / ' + item.unitLabel;
            info.appendChild(nameEl); info.appendChild(colEl);
            var priceEl = document.createElement('div'); priceEl.className = 'frs-cprice'; priceEl.textContent = formatMoney(lt);
            top.appendChild(thumb); top.appendChild(info); top.appendChild(priceEl);

            var foot = document.createElement('div');
            foot.className = 'frs-cfoot';
            var trash = document.createElement('button');
            trash.type = 'button'; trash.className = 'frs-trash'; trash.setAttribute('aria-label', T.removed);
            trash.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13"/></svg>';
            trash.addEventListener('click', function () { removeCartLine(line); });
            foot.appendChild(trash);

            if (item.unit === 'each') {
                var stepper = document.createElement('div');
                stepper.className = 'frs-stepper';
                var minus = document.createElement('button'); minus.type = 'button'; minus.className = 'frs-step'; minus.textContent = '−';
                minus.setAttribute('aria-label', T.decrease);
                minus.addEventListener('click', function () { adjustEachLine(line, -1); });
                var qtySpan = document.createElement('span'); qtySpan.className = 'frs-qty'; qtySpan.textContent = String(line.qty);
                var plus = document.createElement('button'); plus.type = 'button'; plus.className = 'frs-step frs-step-on'; plus.textContent = '+';
                plus.setAttribute('aria-label', T.increase);
                plus.addEventListener('click', function () { adjustEachLine(line, 1); });
                stepper.appendChild(minus); stepper.appendChild(qtySpan); stepper.appendChild(plus);
                foot.appendChild(stepper);
            } else {
                var qtyText = document.createElement('span');
                qtyText.className = 'frs-ccol'; qtyText.style.fontWeight = '700';
                qtyText.textContent = line.qty.toFixed(1) + ' ' + T.kg;
                var plusKg = document.createElement('button');
                plusKg.type = 'button'; plusKg.className = 'frs-step frs-step-on';
                plusKg.style.width = 'auto'; plusKg.style.padding = '0 10px'; plusKg.style.fontSize = '12px';
                plusKg.textContent = '+0.1 ' + T.kg;
                plusKg.addEventListener('click', function () { addWeightToLine(line); });
                foot.appendChild(qtyText); foot.appendChild(plusKg);
            }
            card.appendChild(top); card.appendChild(foot);
            linesWrap.appendChild(card);
        });

        var tax = computeTax(sum);
        var taxRow = document.getElementById('frsBasketTaxRow');
        if (taxRow) { taxRow.hidden = tax <= 0; }
        setText('frsBasketTax', formatMoney(tax));
        setText('frsBasketSubtotal', formatMoney(sum));
        setText('frsBasketTotal', formatMoney(sum + tax));
        serverQuote('basket', 'frsBasket', false);
    }

    /* P3-13 (THEME-CONTRACT §5.2): the summaries are the server quote of the booking the checkout will place (per-kg
       lines priced by weight, the chosen mode and — at checkout — the coupon); the sums are only the estimate shown
       until it answers. `prefix` names the summary's elements (frsBasket…, frsCheckout…). */
    function serverQuote(key, prefix, withCoupon) {
        var box = document.getElementById(prefix + 'Rows');
        if (!window.mbQuote || !box || !cart.length) { return; }
        var couponEl = document.getElementById('frsCouponField');
        window.mbQuote.update(key, CART_QUOTE_ROUTE, {
            _token: CSRF,
            type: scheduleState.mode,
            coupon_code: withCoupon && couponEl ? couponEl.value : '',
            flow: 'booking'
        }, function (q) {
            var e = window.mbQuote.esc;
            var seen = {};
            var html = window.mbQuote.rows(q).map(function (r) {
                var id = { subtotal: 'Subtotal', tax: 'Tax', discount: 'Discount' }[r.key];
                if (!id) { return '<div class="frs-srow"><span>' + e(r.label) + '</span><span>' + e(r.formatted) + '</span></div>'; }
                seen[id] = true;
                var rowId = id === 'Subtotal' ? '' : ' id="' + prefix + id + 'Row"';
                return '<div class="frs-srow"' + rowId + '><span>' + e(r.label) + '</span><span id="' + prefix + id + '">' + e(r.formatted) + '</span></div>';
            }).join('');
            ['Tax', 'Discount'].forEach(function (id) {
                if (!seen[id]) { html += '<div class="frs-srow" id="' + prefix + id + 'Row" hidden><span></span><span id="' + prefix + id + '"></span></div>'; }
            });
            box.innerHTML = html + '<div class="frs-srow frs-srow-total"><span>' + e(q.total_label) + '</span><span id="' + prefix + 'Total">' + e(q.total.formatted) + '</span></div>';
        }, [box]);
    }

    /* ---------------- schedule screen ---------------- */
    var scheduleState = { day: null, slot: null, mode: (MODES[0] || {}).value || 'pickup_slot', address: '' };
    function dayLabel(d) {
        try {
            return new Date(d + 'T00:00:00').toLocaleDateString(document.documentElement.lang || undefined, { weekday: 'short', day: 'numeric', month: 'short' });
        } catch (e) { return d; }
    }
    function renderSlotsFor(slots) {
        var wrap = document.getElementById('frsSlots');
        if (!wrap) { return; }
        wrap.innerHTML = '';
        scheduleState.slot = null;
        (slots || []).forEach(function (slot) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'frs-timeslot'; b.textContent = slot.label;
            b.setAttribute('aria-pressed', 'false');
            if (slot.full) {
                b.disabled = true;
                var full = document.createElement('span'); full.className = 'frs-full'; full.textContent = T.full;
                b.appendChild(full);
            }
            b.addEventListener('click', function () {
                scheduleState.slot = slot.value;
                Array.prototype.forEach.call(wrap.querySelectorAll('.frs-timeslot'), function (x) { x.setAttribute('aria-pressed', 'false'); });
                b.setAttribute('aria-pressed', 'true');
            });
            wrap.appendChild(b);
        });
    }
    function loadSlotsForDay(date) {
        var daysWrap = document.getElementById('frsDays');
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(scheduleState.mode) + (date ? '&date=' + encodeURIComponent(date) : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled) {
                    if (daysWrap) { daysWrap.innerHTML = ''; }
                    renderSlotsFor([]);
                    return;
                }
                scheduleState.day = data.date || date || null;
                if (daysWrap) {
                    daysWrap.innerHTML = '';
                    (data.dates || []).forEach(function (d, idx) {
                        var btn = document.createElement('button');
                        btn.type = 'button'; btn.className = 'frs-datechip';
                        btn.setAttribute('aria-pressed', d === scheduleState.day ? 'true' : 'false');
                        var dd = document.createElement('span'); dd.className = 'frs-dd';
                        dd.textContent = idx === 0 ? T.today : (idx === 1 ? T.tomorrow : dayLabel(d).split(',')[0].split(' ')[0]);
                        var dn = document.createElement('span'); dn.className = 'frs-dn';
                        var parsedDay = new Date(d + 'T00:00:00');
                        dn.textContent = isNaN(parsedDay.getTime()) ? '' : String(parsedDay.getDate());
                        btn.appendChild(dd); btn.appendChild(dn);
                        btn.addEventListener('click', function () { loadSlotsForDay(d); });
                        daysWrap.appendChild(btn);
                    });
                }
                renderSlotsFor(data.slots);
            })
            .catch(function () { /* best-effort — booking still works without a slot */ });
    }
    function chooseMode(value, el) {
        scheduleState.mode = value;
        Array.prototype.forEach.call(root.querySelectorAll('#frsModes [data-mode]'), function (b) {
            b.setAttribute('aria-pressed', b === el ? 'true' : 'false');
        });
        loadSlotsForDay(null);
    }
    // Wire the delivery-address autocomplete once (crave-style geocode suggest
    // + use-my-location). Safe to call up-front — the input lives in the DOM
    // the whole time and only fills the visible address field, never sends
    // coordinates to the server.
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('frsAddress'), {
            suggest: document.getElementById('frsAddrSuggest'),
            locBtn: document.getElementById('frsAddrLoc')
        });
    }

    /* Address map picker — freshly-native .frs-mapsheet bottom sheet. freshly has
       no drawer of its own, so its open/close mirrors the theme's story-viewer
       overlay (.is-open + inert + aria-hidden, own scrim). The shared
       window.mbMapPicker engine owns the Leaflet map + reverse-geocode; the
       confirm fills the SAME #frsAddress the schedule/checkout submits. */
    (function () {
        var mapBtn = document.getElementById('frsMapBtn');
        var sheet = document.getElementById('frsMapSheet');
        var scrim = document.getElementById('frsMapScrim');
        if (!mapBtn || !sheet || !scrim || !window.mbMapPicker) { if (mapBtn) { mapBtn.style.display = 'none'; } return; }
        function openMap() {
            scrim.hidden = false;
            requestAnimationFrame(function () { scrim.classList.add('is-open'); sheet.classList.add('is-open'); });
            sheet.removeAttribute('inert');
            sheet.setAttribute('aria-hidden', 'false');
            var title = document.getElementById('frsMapTitle');
            if (title) { title.focus(); }
        }
        function closeMap() {
            scrim.classList.remove('is-open');
            sheet.classList.remove('is-open');
            sheet.setAttribute('inert', '');
            sheet.setAttribute('aria-hidden', 'true');
            setTimeout(function () { scrim.hidden = true; }, 260);
            mapBtn.focus();
        }
        window.mbMapPicker({
            trigger: mapBtn,
            modal:   sheet,
            map:     document.getElementById('frsMap'),
            input:   document.getElementById('frsAddress'),
            preview: document.getElementById('frsMapPreview'),
            confirm: document.getElementById('frsMapConfirm'),
            close:   document.getElementById('frsMapClose'),
            toggle:  function (open) { if (open) { openMap(); } else { closeMap(); } },
            i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
        });
        scrim.addEventListener('click', closeMap);
        sheet.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMap(); } });
    })();

    /* Clear saved data on this device — Profile-screen row (Profile is
       guest-reachable). Wipes freshly's OWN cart/favourites/stories-viewed keys +
       any store-scoped shared keys; the shared engine keeps the light/dark choice
       and leaves guest booking history (mb-guest-orders-*) untouched. */
    (function () {
        var cd = document.getElementById('frsClearData');
        if (!cd) { return; }
        if (!window.mbClearDeviceData) { cd.style.display = 'none'; var h = document.getElementById('frsClearDataHint'); if (h) { h.style.display = 'none'; } return; }
        cd.addEventListener('click', function () {
            window.mbClearDeviceData({
                keys: ['freshly-cart-' + STORE_ID, 'freshly-favs-' + STORE_ID, 'freshly-stories-viewed-' + STORE_ID],
                storeId: STORE_ID,
                slug: @json($store->slug ?? ''),
                confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
            });
        });
    })();

    var scheduleContinueBtn = document.getElementById('frsScheduleContinue');
    if (scheduleContinueBtn) {
        scheduleContinueBtn.addEventListener('click', function () {
            var addressInput = document.getElementById('frsAddress');
            scheduleState.address = addressInput ? addressInput.value.trim() : '';
            if (!scheduleState.address) { toast(T.addressRequired); if (addressInput) { addressInput.focus(); } return; }
            var modeInfo = MODES.filter(function (m) { return m.value === scheduleState.mode; })[0] || {};
            if (modeInfo.needsSlot && !scheduleState.slot) { toast(T.selectTimeError); return; }
            go('checkout');
        });
    }

    /* ---------------- checkout screen ---------------- */
    var frsCouponDiscount = 0, frsCheckoutSum = 0, frsCouponSig = '';
    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is — and the quote is refreshed when the basket changes. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    function frsCouponSigFor(sum) { return JSON.stringify([sum, couponLines(), scheduleState.mode || '']); }
    function frsRequoteCoupon(sum) {
        var field = document.getElementById('frsCouponField');
        if (!field || !field.value || !COUPON_ROUTE) { return; }
        var sig = frsCouponSigFor(sum);
        if (sig === frsCouponSig) { return; }
        frsCouponSig = sig;
        var code = field.value, fd = new FormData();
        fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', sum);
        fd.append('items', JSON.stringify(couponLines())); fd.append('ordering-type', scheduleState.mode || '');
        fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (field.value !== code || frsCouponSig !== sig) { return; }
                frsCouponDiscount = d && d.success ? (parseFloat(d.discount) || 0) : 0;
                renderCheckout();
            })
            .catch(function () {});
    }
    (function () {
        var applyBtn = document.getElementById('frsCouponApply');
        if (!applyBtn || !COUPON_ROUTE) { return; }
        applyBtn.addEventListener('click', function () {
            var code = (document.getElementById('frsCouponInput').value || '').trim();
            var msgEl = document.getElementById('frsCouponMsg');
            var field = document.getElementById('frsCouponField');
            if (!code) { return; }
            var fd = new FormData();
            fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', frsCheckoutSum);
            fd.append('items', JSON.stringify(couponLines())); fd.append('ordering-type', scheduleState.mode || '');
            var sentSig = frsCouponSigFor(frsCheckoutSum);
            applyBtn.disabled = true;
            fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    applyBtn.disabled = false; msgEl.hidden = false;
                    if (d && d.success) { field.value = code; frsCouponDiscount = parseFloat(d.discount) || 0; frsCouponSig = sentSig; msgEl.textContent = d.message || @json(___('Coupon applied')); msgEl.classList.remove('is-bad'); }
                    else { field.value = ''; frsCouponDiscount = 0; msgEl.textContent = (d && d.message) || @json(___('Coupon code is invalid.')); msgEl.classList.add('is-bad'); }
                    renderCheckout();
                })
                .catch(function () { applyBtn.disabled = false; });
        });
    })();
    function renderCheckout() {
        var linesWrap = document.getElementById('frsCheckoutLines');
        linesWrap.innerHTML = '';
        var sum = 0;
        cart.forEach(function (line) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var lt = item.price * line.qty;
            sum += lt;
            var row = document.createElement('div');
            row.className = 'frs-listrow';
            var left = document.createElement('span');
            left.textContent = item.name + ' × ' + (item.unit === 'kg' ? line.qty.toFixed(1) + ' ' + T.kg : line.qty);
            var right = document.createElement('span');
            right.textContent = formatMoney(lt);
            row.appendChild(left); row.appendChild(right);
            linesWrap.appendChild(row);
        });

        var pickupWrap = document.getElementById('frsCheckoutPickup');
        pickupWrap.innerHTML = '';
        var oico = document.createElement('span'); oico.className = 'frs-oico';
        oico.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>';
        var obody = document.createElement('span'); obody.className = 'frs-obody';
        var ot = document.createElement('span'); ot.className = 'frs-ot';
        var dayText = scheduleState.day ? dayLabel(scheduleState.day) : '';
        var timeText = scheduleState.slot ? (scheduleState.slot.split(' ')[1] || scheduleState.slot) : '—';
        ot.textContent = (dayText ? dayText + ', ' : '') + timeText;
        var os = document.createElement('span'); os.className = 'frs-os'; os.textContent = scheduleState.address;
        obody.appendChild(ot); obody.appendChild(os);
        pickupWrap.appendChild(oico); pickupWrap.appendChild(obody);

        frsRequoteCoupon(sum); // bug batch 7 · 4: the basket changed since the quote → re-quote
        var disc = Math.min(frsCouponDiscount, sum);
        var dRow = document.getElementById('frsCheckoutDiscountRow');
        var dEl = document.getElementById('frsCheckoutDiscount');
        if (dRow && dEl) { if (disc > 0) { dRow.hidden = false; dEl.textContent = '-' + formatMoney(disc); } else { dRow.hidden = true; } }
        var tax = computeTax(sum);
        var taxRow = document.getElementById('frsCheckoutTaxRow');
        if (taxRow) { taxRow.hidden = tax <= 0; }
        setText('frsCheckoutTax', formatMoney(tax));
        setText('frsCheckoutSubtotal', formatMoney(sum));
        setText('frsCheckoutTotal', formatMoney(Math.max(0, sum + tax - disc)));
        frsCheckoutSum = sum;
        serverQuote('checkout', 'frsCheckout', true);
    }

    var confirmBtn = document.getElementById('frsConfirmBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var msg = document.getElementById('frsCheckoutMsg');
            msg.innerHTML = '';
            if (!cart.length) { showMsg(msg, T.genericError, true); return; }
            var name = document.getElementById('frsName').value.trim();
            var phone = document.getElementById('frsPhone').value.trim();
            if (!name) { showMsg(msg, T.nameRequired, true); return; }
            if (!scheduleState.address) { showMsg(msg, T.addressRequired, true); return; }

            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('customer_name', name);
            fd.append('phone_number', phone);
            fd.append('type', scheduleState.mode);
            fd.append('address', scheduleState.address);
            if (scheduleState.slot) { fd.append('scheduled_at', scheduleState.slot); }
            var loyaltyRedeemInput = document.getElementById('frsLoyaltyRedeemInput');
            fd.append('loyalty_redeem', loyaltyRedeemInput ? loyaltyRedeemInput.value : '0');
            var frsCouponField = document.getElementById('frsCouponField');
            if (frsCouponField && frsCouponField.value) { fd.append('coupon_code', frsCouponField.value); }
            var frsGiftField = document.getElementById('frsGiftCardInput');
            if (frsGiftField && frsGiftField.value.trim()) { fd.append('gift_card_code', frsGiftField.value.trim()); }

            confirmBtn.disabled = true;
            showMsg(msg, T.placing, false);

            fetch(BOOK_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) { onBookingPlaced(data); }
                    else { showMsg(msg, (data && data.message) || T.genericError, true); }
                })
                .catch(function () { showMsg(msg, T.genericError, true); })
                .then(function () { confirmBtn.disabled = false; });
        });
    }

    /* ---------------- orders (a real order per row, list remembered on
       this device — see the comment on the ORDERS screen markup) -------- */
    var ORDERS_KEY = 'freshly-orders-' + STORE_ID;
    function readOrders() {
        try {
            var raw = window.localStorage.getItem(ORDERS_KEY);
            return raw ? (JSON.parse(raw) || []) : [];
        } catch (e) { return []; }
    }
    function writeOrders(list) {
        try { window.localStorage.setItem(ORDERS_KEY, JSON.stringify(list.slice(0, 20))); } catch (e) { /* best-effort */ }
    }
    function persistOrder(order) {
        var list = readOrders();
        list.unshift(order);
        writeOrders(list);
    }
    function updateStoredOrder(token, patch) {
        var list = readOrders();
        var changed = false;
        list = list.map(function (o) {
            if (o.trackToken === token) { changed = true; return Object.assign({}, o, patch); }
            return o;
        });
        if (changed) { writeOrders(list); }
    }
    // Signed-in customers see their real server order history (with refund
    // controls); guests keep the session-local list (guests cannot refund).
    function renderServerOrders(emptyEl, listEl) {
        var lblMap = { unpaid: '{{ ___('Awaiting payment') }}', pending: '{{ ___('Received') }}', new: '{{ ___('Received') }}', preparing: '{{ ___('Preparing') }}', ready: '{{ ___('Ready') }}', out_for_delivery: '{{ ___('On the way') }}', delivered: '{{ ___('Delivered') }}', completed: '{{ ___('Completed') }}', cancelled: '{{ ___('Cancelled') }}' };
        function lbl(s) { return lblMap[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                var orders = (res && res.orders) || [];
                if (!orders.length) { emptyEl.hidden = false; listEl.hidden = true; return; }
                emptyEl.hidden = true; listEl.hidden = false;
                listEl.innerHTML = orders.map(function (o) {
                    var d = o.at ? new Date(o.at) : null;
                    var when = d ? d.toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                    return '<div class="frs-citem"><div class="frs-citem-top"><div class="frs-cinfo"><div class="frs-cname">' + esc(o.number) + '</div><div class="frs-ccol">' + esc(when) + ' · ' + esc(o.total) + '</div></div><span class="frs-ccol">' + esc(lbl(o.status)) + '</span></div>'
                        + '<div class="frs-cfoot" style="gap:10px;flex-wrap:wrap">'
                        + (o.trackUrl ? '<a class="frs-ccol" style="color:var(--frs-accent-2);font-weight:700;text-decoration:none" href="' + esc(o.trackUrl) + '" target="_blank" rel="noopener">{{ ___('Track') }}</a>' : '')
                        + (o.canRequestRefund ? '<button type="button" class="frs-ccol" style="color:var(--frs-accent);font-weight:700;background:none;border:0;cursor:pointer;padding:0" data-refund-request="' + esc(o.id) + '">{{ ___('Request refund') }}</button>' : '')
                        + (o.refundStatus ? '<span class="mb-refund mb-refund--' + esc(o.refundStatus) + '">' + mbRefundLabel(o.refundStatus, o.refundMethod) + '</span>' : '')
                        + '</div></div>';
                }).join('');
            })
            .catch(function (e) { if (e === 'guest') { renderLocalOrders(emptyEl, listEl); } });
    }

    function renderOrdersList() {
        var emptyEl = document.getElementById('frsOrdersEmpty');
        var listEl = document.getElementById('frsOrdersList');
        if (IS_CUSTOMER && MY_ORDERS_ROUTE) { renderServerOrders(emptyEl, listEl); return; }
        renderLocalOrders(emptyEl, listEl);
    }

    // Guest order history — device-local, via the shared mbGuestOrders store
    // (same convention every theme uses). A guest has no account, so this
    // per-device list is the only history they get: each row carries a Track
    // link and a one-tap Reorder (when its items still exist), followed by a
    // note + button to sign in and sync across devices. Falls back to the
    // browse-services empty state when this device has nothing stored.
    function frsEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function renderLocalOrders(emptyEl, listEl) {
        var local = (window.mbGuestOrders ? window.mbGuestOrders.list(STORE_ID) : []);
        if (!local.length) { emptyEl.hidden = false; listEl.hidden = true; return; }
        emptyEl.hidden = true;
        listEl.hidden = false;
        var html = local.map(function (o) {
            var when = '';
            try { when = o.at ? new Date(o.at).toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : ''; } catch (e) { when = ''; }
            var meta = [when, (o.itemCount ? o.itemCount + '×' : ''), (o.total || '')].filter(Boolean).join(' · ');
            var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
            return '<div class="frs-citem"><div class="frs-citem-top"><div class="frs-cinfo">'
                + '<div class="frs-cname">' + frsEsc('{{ ___('Recent order') }}') + '</div>'
                + '<div class="frs-ccol">' + frsEsc(meta) + '</div></div></div>'
                + '<div class="frs-order-actions">'
                + (o.trackUrl ? '<a class="frs-order-btn" href="' + frsEsc(o.trackUrl) + '" target="_blank" rel="noopener">' + frsEsc(T.track) + '</a>' : '')
                + (canReorder ? '<button type="button" class="frs-order-btn frs-order-btn--p" data-reorder="' + frsEsc((o.itemIds || []).join(',')) + '">' + frsEsc('{{ ___('Reorder') }}') + '</button>' : '')
                + '</div></div>';
        }).join('');
@if(($postOptions->customer_auth_mode ?? '') !== 'demo')
        html += '<div class="frs-citem" style="text-align:center">'
            + '<div class="frs-ccol" style="margin-bottom:10px">' + frsEsc('{{ ___('Sign in to sync your orders across devices.') }}') + '</div>'
            + '<button type="button" class="frs-order-btn frs-order-btn--p" id="frsOrdersSignin" data-mb-auth-open style="display:block;width:100%">' + frsEsc('{{ ___('Sign in') }}') + '</button></div>';
@endif
        listEl.innerHTML = html;
    }

    // Reorder a device-local guest order: re-add each of its still-available
    // items to the cart through the same add-line endpoint the "+" steppers
    // use, then jump to the basket. The auth-open button is wired by the
    // shared mb-auth-sheet partial (delegated on [data-mb-auth-open]).
    function reorderIds(ids) {
        var toAdd = (ids || []).filter(function (id) { return ITEMS[String(id)]; });
        if (!toAdd.length) { return; }
        Promise.all(toAdd.map(function (id) {
            var item = ITEMS[String(id)];
            return postAddLine(item.addRoute, 1).then(function (data) {
                var existing = cart.filter(function (l) { return String(l.id) === String(id); })[0];
                if (existing) { existing.qty += 1; existing.lineId = data.line_id; }
                else { cart.push({ id: String(id), qty: 1, lineId: data.line_id }); }
            });
        })).then(function () {
            saveCart();
            refreshCartBadges();
            renderBasket();
            go('basket');
        }).catch(function () { window.alert(T.genericError); });
    }
    (function () {
        var listEl = document.getElementById('frsOrdersList');
        if (!listEl) { return; }
        listEl.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-reorder]');
            if (!b) { return; }
            e.preventDefault();
            reorderIds((b.getAttribute('data-reorder') || '').split(',').filter(Boolean));
        });
    })();

    /* ---------------- confirm screen + live status timeline (real order
       state, polled via the SAME JSON contract bookla/track.blade.php's own
       poller uses) ---------------- */
    function updateTimelineUI(status, isDelivery) {
        var cfg = statusCfg(status, isDelivery);
        var badgeEl = document.getElementById('frsTrkBadge');
        if (badgeEl) { badgeEl.textContent = cfg.badge; }
        setText('frsTrkTitle', cfg.title);
        setText('frsTrkDesc', cfg.desc);
        if (status === 'ready' || status === 'shipped' || status === 'collected') { setText('frsStep2Label', cfg.badge); }

        ['frsStep0', 'frsStep1', 'frsStep2', 'frsStep3'].forEach(function (id, idx) {
            var n = document.getElementById(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });

        var contactCard = document.getElementById('frsContactCard');
        if (contactCard) { contactCard.hidden = !(cfg.step === 1 || cfg.step === 2); }
    }
    function applyActiveOrderToConfirmUI() {
        var order = state.activeOrder;
        if (!order) { return; }
        setText('frsConfirmRef', order.trackToken);
        var linkEl = document.getElementById('frsConfirmTrackLink');
        if (linkEl) { linkEl.href = trackRoute(order.trackToken); }
        updateTimelineUI(order.status || 'new', order.type === 'delivery');
    }
    function onBookingPlaced(data) {
        // Device-local guest history (shared mbGuestOrders convention across
        // themes): remember this order on THIS device so a guest with no
        // account still sees it on the Orders screen, with a Track link and a
        // one-tap reorder. Snapshot BEFORE the cart is cleared; the total is
        // freshly's own money contract (subtotal + computeTax(subtotal)).
        try {
            if (data && data.track_token && window.mbGuestOrders) {
                var _sub = cart.reduce(function (n, l) { var it = ITEMS[l.id]; return n + (it ? it.price * l.qty : 0); }, 0);
                window.mbGuestOrders.save(STORE_ID, {
                    token: data.track_token,
                    trackUrl: data.redirect || trackRoute(data.track_token),
                    total: formatMoney(_sub + computeTax(_sub)),
                    itemCount: cart.reduce(function (n, l) { return n + (Number(l.qty) || 0); }, 0),
                    itemIds: cart.map(function (l) { return l.id; })
                });
            }
        } catch (e) { /* best-effort — device cache only */ }
        cart = [];
        saveCart();
        refreshCartBadges();

        var order = { trackToken: data.track_token, type: scheduleState.mode, createdAt: Date.now(), status: 'new' };
        persistOrder(order);
        state.activeOrder = order;
        applyActiveOrderToConfirmUI();
        renderStatusStrip();
        go('confirm');
        pollActiveOrder(true);
    }

    var pollTimer = null;
    function stopPolling() { clearInterval(pollTimer); pollTimer = null; }
    function fetchStatusOnce() {
        if (!state.activeOrder) { stopPolling(); return; }
        fetch(trackRoute(state.activeOrder.trackToken), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.status || !state.activeOrder) { return; }
                state.activeOrder.status = res.status;
                updateStoredOrder(state.activeOrder.trackToken, { status: res.status });
                if (state.screen === 'confirm') { updateTimelineUI(res.status, state.activeOrder.type === 'delivery'); }
                renderStatusStrip();
                if (TERMINAL[res.status]) { stopPolling(); }
            })
            .catch(function () { /* best-effort — the last known status stands */ });
    }
    function pollActiveOrder(immediate) {
        if (!state.activeOrder || TERMINAL[state.activeOrder.status]) { return; }
        if (immediate) { fetchStatusOnce(); }
        stopPolling();
        pollTimer = setInterval(fetchStatusOnce, 5000);
    }
    document.addEventListener('visibilitychange', function () {
        if (!state.activeOrder || TERMINAL[state.activeOrder.status]) { return; }
        if (document.hidden) { stopPolling(); } else { pollActiveOrder(true); }
    });

    /* ---------------- home: active-booking status strip ---------------- */
    function renderStatusStrip() {
        var strip = document.getElementById('frsStatusStrip');
        if (!strip) { return; }
        var order = state.activeOrder;
        if (!order || TERMINAL[order.status]) { strip.hidden = true; strip.innerHTML = ''; return; }
        var cfg = statusCfg(order.status, order.type === 'delivery');
        strip.hidden = false;
        strip.innerHTML = '';
        var icon = document.createElement('span'); icon.className = 'frs-sicon';
        icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/></svg>';
        var texts = document.createElement('span'); texts.className = 'frs-stexts';
        var label = document.createElement('span'); label.className = 'frs-slabel'; label.textContent = T.activeBooking;
        var val = document.createElement('span'); val.className = 'frs-sval'; val.textContent = cfg.badge;
        texts.appendChild(label); texts.appendChild(val);
        var btn = document.createElement('span'); btn.className = 'frs-strackbtn'; btn.textContent = T.track;
        strip.appendChild(icon); strip.appendChild(texts); strip.appendChild(btn);
    }

    /* =====================================================================
       Curated core features (loop/enrich) — stories, reviews, loyalty,
       search + wishlist. Every endpoint here is an existing, already-seeded
       platform route; nothing below adds or changes backend behaviour.
       ===================================================================== */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---------------- F1: stories rail + full-screen viewer ---------------- */
    var storiesRail = document.getElementById('frsStories');
    var storyViewer = document.getElementById('frsStoryViewer');
    var storyProgress = document.getElementById('frsStoryProgress');
    var storyMedia = document.getElementById('frsStoryMedia');
    var storyCaption = document.getElementById('frsStoryCaption');
    var storyCta = document.getElementById('frsStoryCta');
    var stories = [];
    var storyIndex = 0;
    var storyTimeout = null;
    var STORY_DURATION = 5000;
    var STORY_VIEWED_KEY = 'freshly-stories-viewed-' + STORE_ID;
    var storyViewed = {};
    try {
        (JSON.parse(window.localStorage.getItem(STORY_VIEWED_KEY) || '[]') || []).forEach(function (id) { storyViewed[id] = true; });
    } catch (e) { storyViewed = {}; }

    function markStoryViewed(id) {
        if (storyViewed[id]) { return; }
        storyViewed[id] = true;
        try { window.localStorage.setItem(STORY_VIEWED_KEY, JSON.stringify(Object.keys(storyViewed))); } catch (e) { /* best-effort */ }
        var bubble = storiesRail.children[stories.map(function (s) { return s.id; }).indexOf(id)];
        if (bubble) { bubble.classList.add('is-viewed'); }
    }

    function renderStoryBubbles() {
        if (!storiesRail) { return; }
        storiesRail.innerHTML = '';
        stories.forEach(function (s, idx) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'frs-story' + (storyViewed[s.id] ? ' is-viewed' : '');
            btn.setAttribute('role', 'listitem');
            btn.setAttribute('aria-label', s.caption || (T.story + ' ' + (idx + 1)));
            var ring = document.createElement('span'); ring.className = 'frs-story-ring';
            var img = document.createElement('img'); img.className = 'frs-story-thumb'; img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (s.media_url) { img.src = s.media_url; }
            ring.appendChild(img);
            btn.appendChild(ring);
            if (s.caption) {
                var label = document.createElement('span'); label.className = 'frs-story-label'; label.textContent = s.caption;
                btn.appendChild(label);
            }
            btn.addEventListener('click', function () { openStoryViewer(idx); });
            storiesRail.appendChild(btn);
        });
        storiesRail.hidden = stories.length === 0;
    }

    function buildStoryProgressBars() {
        if (!storyProgress) { return; }
        storyProgress.innerHTML = '';
        stories.forEach(function () {
            var bar = document.createElement('span'); bar.className = 'frs-sv-bar';
            var fill = document.createElement('span'); fill.className = 'frs-sv-bar-fill';
            bar.appendChild(fill);
            storyProgress.appendChild(bar);
        });
    }

    function showStory(idx) {
        clearTimeout(storyTimeout);
        if (idx < 0) { idx = 0; }
        if (idx >= stories.length) { closeStoryViewer(); return; }
        storyIndex = idx;
        var s = stories[idx];
        markStoryViewed(s.id);

        var bars = storyProgress.children;
        for (var i = 0; i < bars.length; i++) {
            var fill = bars[i].querySelector('.frs-sv-bar-fill');
            bars[i].classList.remove('is-active');
            bars[i].classList.toggle('is-done', i < idx);
            fill.style.transition = 'none';
            fill.style.inlineSize = i < idx ? '100%' : '0%';
        }

        storyMedia.src = s.media_url || '';
        storyMedia.alt = s.caption || '';
        if (s.caption) { storyCaption.textContent = s.caption; storyCaption.hidden = false; }
        else { storyCaption.hidden = true; }
        if (s.cta_url) {
            storyCta.textContent = s.cta_label || '';
            storyCta.href = s.cta_url;
            var external = /^[a-z][a-z0-9+.-]*:\/\//i.test(s.cta_url) && s.cta_url.indexOf(window.location.origin) !== 0;
            if (external) { storyCta.target = '_blank'; storyCta.setAttribute('rel', 'noopener'); }
            else { storyCta.removeAttribute('target'); storyCta.removeAttribute('rel'); }
            storyCta.hidden = false;
        } else {
            storyCta.hidden = true;
        }

        var curBar = bars[idx];
        if (curBar) {
            var curFill = curBar.querySelector('.frs-sv-bar-fill');
            curBar.classList.add('is-active');
            // Force reflow so the transition from 0 -> 100 actually animates.
            void curFill.offsetWidth;
            curFill.style.transition = 'inline-size ' + STORY_DURATION + 'ms linear';
            curFill.style.inlineSize = '100%';
        }
        storyTimeout = setTimeout(function () {
            if (storyViewer.classList.contains('is-open')) { showStory(idx + 1); }
        }, STORY_DURATION);
    }

    function openStoryViewer(startIndex) {
        if (!stories.length || !storyViewer) { return; }
        buildStoryProgressBars();
        storyViewer.classList.add('is-open');
        storyViewer.removeAttribute('inert');
        storyViewer.setAttribute('aria-hidden', 'false');
        showStory(startIndex || 0);
    }
    function closeStoryViewer() {
        clearTimeout(storyTimeout);
        if (!storyViewer) { return; }
        storyViewer.classList.remove('is-open');
        storyViewer.setAttribute('inert', '');
        storyViewer.setAttribute('aria-hidden', 'true');
        storyMedia.src = '';
    }

    var storyCloseBtn = document.getElementById('frsStoryClose');
    var storyPrevBtn = document.getElementById('frsStoryPrev');
    var storyNextBtn = document.getElementById('frsStoryNext');
    if (storyCloseBtn) { storyCloseBtn.addEventListener('click', closeStoryViewer); }
    if (storyPrevBtn) { storyPrevBtn.addEventListener('click', function () { showStory(storyIndex - 1); }); }
    if (storyNextBtn) { storyNextBtn.addEventListener('click', function () { showStory(storyIndex + 1); }); }

    if (storiesRail) {
        fetch(STORIES_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                var list = payload && (Array.isArray(payload) ? payload : payload.data);
                stories = Array.isArray(list) ? list.slice().sort(function (a, b) { return (a.sort || 0) - (b.sort || 0); }) : [];
                if (stories.length) { renderStoryBubbles(); }
            })
            .catch(function () { /* leave the rail hidden — never an empty shell */ });
    }

    /* ---------------- F2: reviews (service detail screens) ---------------- */
    function starGlyphs(n) {
        n = Math.round(Number(n) || 0);
        var out = '';
        for (var i = 1; i <= 5; i++) { out += '<span' + (i > n ? ' class="is-off"' : '') + '>★</span>'; }
        return out;
    }
    function relativeTime(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return ''; }
        var diffSec = Math.round((d.getTime() - Date.now()) / 1000);
        var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        try {
            var rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });
            for (var i = 0; i < units.length; i++) {
                if (Math.abs(diffSec) >= units[i][1]) { return rtf.format(Math.round(diffSec / units[i][1]), units[i][0]); }
            }
            return rtf.format(diffSec, 'second');
        } catch (e) {
            try { return d.toLocaleDateString(); } catch (e2) { return ''; }
        }
    }

    function loadServiceReviews(catId) {
        var section = document.getElementById('frs-service-' + catId);
        if (!section) { return; }
        var wrap = section.querySelector('.frs-detail-reviews');
        var menuId = SERVICE_REVIEW_ITEM[catId];
        if (!wrap || !menuId || !MBR_BASE) { return; }
        var head = wrap.querySelector('.frs-rv-head');
        var dist = wrap.querySelector('.frs-rv-dist');
        var list = wrap.querySelector('.frs-rv-list');
        var empty = wrap.querySelector('.frs-rv-empty');
        var form = wrap.querySelector('.frs-rv-form');
        form.classList.remove('is-open');
        head.hidden = true; dist.hidden = true; list.innerHTML = ''; empty.hidden = true;

        fetch(MBR_BASE + '/' + menuId + '/reviews?filter=latest', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) { if (payload) { renderServiceReviews(wrap, payload); } })
            .catch(function () { /* best-effort — the reviews block just stays quiet */ });
    }
    function renderServiceReviews(wrap, payload) {
        var head = wrap.querySelector('.frs-rv-head');
        var dist = wrap.querySelector('.frs-rv-dist');
        var list = wrap.querySelector('.frs-rv-list');
        var empty = wrap.querySelector('.frs-rv-empty');
        var form = wrap.querySelector('.frs-rv-form');
        var writeBtn = wrap.querySelector('.frs-rv-write-btn');

        var items = (payload && payload.data) || [];
        var count = Number((payload && payload.count) || 0);
        var avg = Number((payload && payload.average) || 0);
        var distribution = (payload && payload.distribution) || {};

        head.hidden = count === 0;
        if (count > 0) {
            wrap.querySelector('.frs-rv-avg-num').textContent = avg.toFixed(1);
            wrap.querySelector('.frs-rv-stars').innerHTML = starGlyphs(avg);
            wrap.querySelector('.frs-rv-count').textContent = count + ' ' + (count === 1 ? T.reviewWord : T.reviewsWord);
        }

        dist.hidden = count === 0;
        dist.innerHTML = '';
        if (count > 0) {
            [5, 4, 3, 2, 1].forEach(function (star) {
                var c = Number(distribution[star] || 0);
                var pct = count ? Math.round((c / count) * 100) : 0;
                var row = document.createElement('div');
                row.className = 'frs-rv-dist-row';
                row.innerHTML = '<span>' + star + '★</span><i><s style="width:' + pct + '%"></s></i><span>' + c + '</span>';
                dist.appendChild(row);
            });
        }

        list.innerHTML = '';
        items.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'frs-rv-item';
            var name = r.name || T.anonymous;
            row.innerHTML = '<div class="frs-rv-item-top">'
                + '<span class="frs-rv-stars">' + starGlyphs(r.stars) + '</span>'
                + '<span class="frs-rv-item-date">' + esc(relativeTime(r.created_at)) + '</span>'
                + '</div>'
                + '<div class="frs-rv-item-name">' + esc(name) + (r.is_verified ? '<span class="frs-rv-verified">' + esc(T.verified) + '</span>' : '') + '</div>'
                + (r.body ? '<p class="frs-rv-item-body">' + esc(r.body) + '</p>' : '');
            list.appendChild(row);
        });

        empty.hidden = count !== 0;
        if (count === 0) { form.classList.add('is-open'); if (writeBtn) { writeBtn.hidden = true; } }
        else if (writeBtn) { writeBtn.hidden = false; }
    }

    root.addEventListener('click', function (e) {
        var writeBtn = e.target.closest('.frs-rv-write-btn');
        if (writeBtn) {
            var form = writeBtn.closest('.frs-detail-reviews').querySelector('.frs-rv-form');
            form.classList.toggle('is-open');
            if (form.classList.contains('is-open')) {
                var firstStar = form.querySelector('[data-rv-star="1"]');
                if (firstStar) { firstStar.focus(); }
            }
            return;
        }
        var starBtn = e.target.closest('[data-rv-star]');
        if (starBtn) {
            var picker = starBtn.closest('.frs-rv-starpicker');
            var n = Number(starBtn.getAttribute('data-rv-star'));
            picker.setAttribute('data-chosen', String(n));
            Array.prototype.forEach.call(picker.querySelectorAll('[data-rv-star]'), function (b) {
                b.classList.toggle('is-on', Number(b.getAttribute('data-rv-star')) <= n);
            });
            return;
        }
    });

    root.addEventListener('submit', function (e) {
        var form = e.target.closest('.frs-rv-form');
        if (!form) { return; }
        e.preventDefault();
        var wrap = form.closest('.frs-detail-reviews');
        var catId = wrap.getAttribute('data-review-service');
        var menuId = SERVICE_REVIEW_ITEM[catId];
        var picker = form.querySelector('.frs-rv-starpicker');
        var rating = Number(picker.getAttribute('data-chosen') || '0');
        var msgEl = form.querySelector('.frs-rv-msg');
        msgEl.innerHTML = '';
        if (!rating) { showMsg(msgEl, T.chooseRatingFirst, true); return; }
        if (!menuId || !MBR_BASE) { return; }
        var submitBtn = form.querySelector('.frs-rv-submit');
        submitBtn.disabled = true;
        fetch(MBR_BASE + '/' + menuId + '/reviews', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({
                rating: rating,
                text: form.querySelector('.frs-rv-body').value.trim() || null,
                name: form.querySelector('.frs-rv-name').value.trim() || null,
            }),
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error('review-store-failed'); }
                showMsg(msgEl, T.reviewThanks, false);
                picker.removeAttribute('data-chosen');
                Array.prototype.forEach.call(picker.querySelectorAll('[data-rv-star]'), function (b) { b.classList.remove('is-on'); });
                form.querySelector('.frs-rv-name').value = '';
                form.querySelector('.frs-rv-body').value = '';
            })
            .catch(function () { showMsg(msgEl, T.reviewFailed, true); })
            .then(function () { submitBtn.disabled = false; });
    });

    /* ---------------- F3: loyalty (Rewards card + checkout redeem row) ---- */
    function loyaltyTeaserLine() {
        if (!LOY_TEASER) { return ''; }
        return [LOY_TEASER.program, LOY_TEASER.earn, LOY_TEASER.reward].filter(Boolean).join(' · ');
    }
    function renderRewardsBalance(data) {
        var area = document.getElementById('frsRewardsBalanceArea');
        var signin = document.getElementById('frsRewardsSignin');
        if (!area) { return; }
        if (data && data.active) {
            if (signin) { signin.hidden = true; }
            var pct = Math.max(0, Math.min(100, Number(data.progress_pct) || 0));
            area.innerHTML = '<div class="frs-rewards-balance">' + esc(String(Number(data.balance) || 0)) + ' <small>' + esc(T.points) + '</small></div>'
                + '<div class="frs-rewards-progress"><s style="width:' + pct + '%"></s></div>';
            Array.prototype.forEach.call(document.querySelectorAll('#frsTierChips .frs-tierchip'), function (chip) {
                chip.classList.toggle('is-current', String(data.tier || '').toLowerCase() === chip.getAttribute('data-tier'));
            });
        } else {
            area.innerHTML = '';
            if (signin) { signin.hidden = false; }
        }
    }
    function loadRewardsBalance() {
        if (!document.getElementById('frsRewardsBalanceArea')) { return; }
        fetch(LOYALTY_BALANCE_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : { active: false }; })
            .then(renderRewardsBalance)
            .catch(function () { renderRewardsBalance({ active: false }); });
    }
    function renderLoyaltyRow(data) {
        var row = document.getElementById('frsLoyaltyRow');
        if (!row) { return; }
        var teaserEl = document.getElementById('frsLoyaltyTeaser');
        var signinEl = document.getElementById('frsLoyaltySignin');
        var toggle = document.getElementById('frsLoyaltyToggle');
        var input = document.getElementById('frsLoyaltyRedeemInput');

        var active = !!(data && data.active);
        var readyToRedeem = active && !!data.ready_to_redeem;
        row.hidden = !LOY_TEASER && !active;
        if (row.hidden) { return; }

        toggle.hidden = !readyToRedeem;
        if (!readyToRedeem) {
            input.value = '0';
            toggle.setAttribute('aria-checked', 'false');
        } else {
            document.getElementById('frsLoyaltyToggleLabel').textContent = T.applyRewardOff.replace(':amount', data.reward_discount_display || '');
        }

        var teaserText = loyaltyTeaserLine();
        teaserEl.hidden = readyToRedeem || !teaserText;
        if (!teaserEl.hidden) { teaserEl.textContent = teaserText; }
        signinEl.hidden = active || readyToRedeem;
    }
    function loadLoyaltyRow() {
        fetch(LOYALTY_BALANCE_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : { active: false }; })
            .then(renderLoyaltyRow)
            .catch(function () { renderLoyaltyRow({ active: false }); });
    }
    var loyaltyToggleBtn = document.getElementById('frsLoyaltyToggle');
    if (loyaltyToggleBtn) {
        loyaltyToggleBtn.addEventListener('click', function () {
            var on = this.getAttribute('aria-checked') !== 'true';
            this.setAttribute('aria-checked', on ? 'true' : 'false');
            document.getElementById('frsLoyaltyRedeemInput').value = on ? '1' : '0';
        });
    }

    /* ---------------- F4a: wishlist ("Saved") — client-side, localStorage - */
    var FAV_KEY = 'freshly-favs-' + STORE_ID;
    var favIds = {};
    try {
        (JSON.parse(window.localStorage.getItem(FAV_KEY) || '[]') || []).forEach(function (id) { favIds[id] = true; });
    } catch (e) { favIds = {}; }
    function saveFavs() { try { window.localStorage.setItem(FAV_KEY, JSON.stringify(Object.keys(favIds))); } catch (e) { /* best-effort */ } }
    function paintFavButton(btn, isFav) {
        btn.classList.toggle('is-fav', isFav);
        btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
        btn.setAttribute('aria-label', isFav ? T.unsave : T.save);
    }
    function syncFavButtons() {
        Array.prototype.forEach.call(root.querySelectorAll('[data-fav-toggle]'), function (btn) {
            paintFavButton(btn, !!favIds[btn.getAttribute('data-fav-toggle')]);
        });
    }
    function refreshSavedBadges() {
        var n = Object.keys(favIds).length;
        Array.prototype.forEach.call(root.querySelectorAll('.frs-saved-badge'), function (b) {
            b.textContent = String(n);
            b.hidden = n === 0;
        });
    }
    function toggleFav(id, btn) {
        var isFav = !favIds[id];
        if (isFav) { favIds[id] = true; } else { delete favIds[id]; }
        saveFavs();
        Array.prototype.forEach.call(root.querySelectorAll('[data-fav-toggle="' + id + '"]'), function (b) { paintFavButton(b, isFav); });
        refreshSavedBadges();
        if (state.screen === 'saved') { renderSaved(); }
    }
    function buildSavedCard(svc) {
        var wrap = document.createElement('div');
        wrap.className = 'frs-svc-cardwrap';
        var a = document.createElement('a');
        a.href = '?service=' + svc.id;
        a.className = 'frs-svc-card';
        a.setAttribute('data-open-service', svc.id);
        var thumb = document.createElement('span'); thumb.className = 'frs-svc-thumb';
        if (svc.img) {
            var img = document.createElement('img'); img.src = svc.img; img.alt = ''; img.loading = 'lazy';
            img.setAttribute('data-csp-error', 'hide');
            thumb.appendChild(img);
        } else {
            thumb.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/></svg>';
        }
        var body = document.createElement('span'); body.className = 'frs-svc-body';
        var name = document.createElement('span'); name.className = 'frs-svc-name'; name.textContent = svc.name;
        var price = document.createElement('span'); price.className = 'frs-svc-price'; price.textContent = T.from + ' ' + svc.fromPriceFormatted + '/' + svc.unitLabel;
        body.appendChild(name); body.appendChild(price);
        var action = document.createElement('span'); action.className = 'frs-svc-action'; action.setAttribute('aria-hidden', 'true');
        action.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
        a.appendChild(thumb); a.appendChild(body); a.appendChild(action);
        var fav = document.createElement('button');
        fav.type = 'button'; fav.className = 'frs-fav-btn is-fav'; fav.setAttribute('data-fav-toggle', svc.id);
        fav.setAttribute('aria-pressed', 'true'); fav.setAttribute('aria-label', T.unsave);
        fav.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>';
        wrap.appendChild(a); wrap.appendChild(fav);
        return wrap;
    }
    function renderSaved() {
        var emptyEl = document.getElementById('frsSavedEmpty');
        var listEl = document.getElementById('frsSavedList');
        if (!listEl) { return; }
        var ids = Object.keys(favIds);
        listEl.innerHTML = '';
        if (!ids.length) { emptyEl.hidden = false; listEl.hidden = true; return; }
        emptyEl.hidden = true; listEl.hidden = false;
        ids.forEach(function (id) {
            var svc = SERVICES[id];
            if (!svc) { return; }
            listEl.appendChild(buildSavedCard(svc));
        });
    }

    /* ---------------- F4b: search (Services screen) — client-side only ---- */
    var servicesSearchInput = document.getElementById('frsSearch');
    if (servicesSearchInput) {
        servicesSearchInput.addEventListener('input', function () {
            var q = servicesSearchInput.value.trim().toLowerCase();
            var listEl = document.getElementById('frsServicesList');
            var noResults = document.getElementById('frsSearchNoResults');
            if (!listEl) { return; }
            var any = false;
            Array.prototype.forEach.call(listEl.querySelectorAll('.frs-svc-cardwrap'), function (card) {
                var match = !q || (card.getAttribute('data-name') || '').indexOf(q) !== -1;
                card.hidden = !match;
                if (match) { any = true; }
            });
            if (noResults) { noResults.hidden = !q || any; }
        });
    }

    /* ---------------- delegated click handling ---------------- */
    root.addEventListener('click', function (e) {
        var el = e.target.closest('[data-nav],[data-back],[data-open-service],[data-item-inc],[data-item-dec],[data-add-to-basket],[data-mode],[data-fav-toggle],[data-detail-thumb]');
        if (!el) { return; }
        var d = el.dataset;
        if (d.detailThumb !== undefined) {
            e.preventDefault();
            var media = el.closest('.frs-detail-media');
            var main = media && media.querySelector('[data-detail-main]');
            if (main) { main.src = d.detailThumb; }
            if (media) {
                Array.prototype.forEach.call(media.querySelectorAll('[data-detail-thumb]'), function (t) {
                    t.setAttribute('aria-current', t === el ? 'true' : 'false');
                });
            }
            return;
        }
        if (d.favToggle !== undefined) { e.preventDefault(); e.stopPropagation(); toggleFav(d.favToggle, el); return; }
        if (d.openService !== undefined) { e.preventDefault(); go('service:' + d.openService); return; }
        if (d.nav !== undefined) { e.preventDefault(); go(d.nav); return; }
        if ('back' in d) { back(); return; }
        if (d.itemInc !== undefined) { stepItem(d.itemInc, 1); return; }
        if (d.itemDec !== undefined) { stepItem(d.itemDec, -1); return; }
        if (d.addToBasket !== undefined) { addServiceToBasket(d.addToBasket, el); return; }
        if (d.mode !== undefined) { chooseMode(d.mode, el); return; }
    });

    /* ---------------- boot ---------------- */
    (function boot() {
        var stored = readOrders();
        var active = stored.filter(function (o) { return !TERMINAL[o.status]; })[0] || null;
        state.activeOrder = active;
        refreshCartBadges();
        renderStatusStrip();
        if (active) { applyActiveOrderToConfirmUI(); pollActiveOrder(true); }
        syncFavButtons();
        refreshSavedBadges();

        try {
            var params = new URLSearchParams(window.location.search);
            var svc = params.get('service');
            if (svc && screens['service:' + svc]) { go('service:' + svc); }
        } catch (e) { /* no-op on an unsupported/old browser */ }
    })();

    /* Restore the screen across a same-tab reload (a language switch is a full
       navigation, which otherwise dumps the shopper back on home and makes their
       order history look "gone"). sessionStorage is per-tab, so a fresh visit
       still opens on home. Skipped when a deep-link (?item / ?service) drives
       the initial screen, so it never fights that. Runs once. */
    (function restoreScreen() {
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('item') || params.get('service')) { return; }
            var saved = window.sessionStorage.getItem('mb-screen-' + STORE_ID);
            if (saved && saved !== 'home' && screens[saved]) { go(saved); }
        } catch (e) { /* best-effort */ }
    })();

    /* crave-parity: branch switch */
    (function () {
        var bs = document.getElementById('frsBranchSel');
        if (bs) { bs.addEventListener('change', function (e) { if (e.target.value) { window.location.assign(e.target.value); } }); }
    })();

    /* Promo-banner CTA: an in-app banner (empty/"#screen" link_url) deep-links
       to its SPA screen instead of being a dead href="#". Closes any open
       story viewer first so the target screen isn't hidden behind it. */
    Array.prototype.forEach.call(root.querySelectorAll('.frs-banner[data-banner-screen]'), function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof closeStoryViewer === 'function') { closeStoryViewer(); }
            go(el.getAttribute('data-banner-screen'));
        });
    });

    /* crave-parity: promo banner slider */
    (function () {
        var track = document.getElementById('frsBannerTrack');
        var dotsWrap = document.getElementById('frsBannerDots');
        if (!track) { return; }
        var slides = track.children;
        if (slides.length < 2) { return; }
        function scrollToSlide(i) {
            var s = slides[i]; if (!s) { return; }
            // Scroll the track horizontally only. scrollIntoView() scrolls the whole
            // PAGE up to the banner whenever it is out of view, so the 5s auto-advance
            // yanks the shopper back to the top. A relative scrollBy delta is RTL-safe.
            var sr = s.getBoundingClientRect(), tr = track.getBoundingClientRect();
            track.scrollBy({ left: (sr.left + sr.width / 2) - (tr.left + tr.width / 2), behavior: 'smooth' });
        }
        var dots = [];
        if (dotsWrap) {
            for (var i = 0; i < slides.length; i++) {
                var d = document.createElement('i');
                if (i === 0) { d.className = 'on'; }
                (function (idx) { d.addEventListener('click', function () { scrollToSlide(idx); }); })(i);
                dotsWrap.appendChild(d); dots.push(d);
            }
        }
        var cur = 0, raf = null;
        function paint(i) { cur = i; dots.forEach(function (d, j) { d.className = j === i ? 'on' : ''; }); }
        track.addEventListener('scroll', function () {
            if (raf) { return; }
            raf = requestAnimationFrame(function () { raf = null; var tc = track.getBoundingClientRect().left + track.clientWidth / 2, bi = 0, bd = Infinity; for (var k = 0; k < slides.length; k++) { var r = slides[k].getBoundingClientRect(), d = Math.abs(r.left + r.width / 2 - tc); if (d < bd) { bd = d; bi = k; } } if (bi !== cur && dots[bi]) { paint(bi); } });
        }, { passive: true });
        var timer = null, touched = false;
        function next() { if (touched || document.hidden) { return; } scrollToSlide((cur + 1) % slides.length); }
        ['touchstart', 'pointerdown', 'wheel'].forEach(function (ev) { track.addEventListener(ev, function () { touched = true; if (timer) { clearInterval(timer); timer = null; } }, { passive: true, once: true }); });
        timer = setInterval(next, 5000);
    })();
})();
</script>
@endpush

@push('script')
{{-- Desktop top-nav active-link sync — purely additive: watches the SAME
     [hidden] toggling the existing app-shell script above already performs
     on .frs-screen sections and mirrors it onto the new .frs-topnav-link
     buttons, without touching that script at all. No-op below 1024 (the
     top nav is display:none there via CSS). --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var root = document.getElementById('frs');
    if (!root) { return; }
    var links = root.querySelectorAll('.frs-topnav-link[data-nav]');
    if (!links.length) { return; }
    var TOP_LEVEL = { home: 1, services: 1, orders: 1, offers: 1, profile: 1 };
    function sync() {
        var visible = root.querySelector('.frs-screen:not([hidden])');
        var key = visible ? visible.getAttribute('data-screen') : null;
        if (!TOP_LEVEL[key]) { key = null; }
        Array.prototype.forEach.call(links, function (a) {
            a.setAttribute('aria-current', a.getAttribute('data-nav') === key ? 'true' : 'false');
        });
    }
    var screens = root.querySelectorAll('.frs-screen');
    if (window.MutationObserver) {
        var mo = new MutationObserver(sync);
        Array.prototype.forEach.call(screens, function (s) {
            mo.observe(s, { attributes: true, attributeFilter: ['hidden'] });
        });
    }
    sync();
})();
</script>
@endpush
