{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
/**
 * Onboarding checklist — setup progress card on the dashboard.
 * Steps computed from real data. Hidden once the user dismisses it.
 *
 * P6-297: every step that can prevent taking an order now keys on a real
 * business signal — a menu item that is BOTH active AND priced, opening hours
 * or 24/7 confirmed, a payment gateway enabled, the first test order placed
 * (or cancelled in test), a printer profile or kitchen station created — and
 * each step has a deep link to the screen that closes it. The previous weak
 * signals ("any menu row", "any StoreView page hit") could leave a freshly
 * seeded store ticking every box while it could not actually take an order.
 */
$user = request()->user();

$dismissed = \Juwri\Core\Settings\UserSettings::legacy((int) $user->id, 'onboarding_dismissed') === '1';
@endphp

@if($user && !$dismissed)
    @php
        $stores = $user->stores ?? collect();
        // P0-7: scope every step to the CONTEXT store (sidebar switcher), not just
        // the first store — so switching store switches checklist state.
        $firstRestaurant = (function_exists('dashboard_current_store') ? dashboard_current_store() : null) ?? $stores->first();
        $storeId = $firstRestaurant ? (int) $firstRestaurant->id : null;

        // P6-297: real-check predicates — each is a query that can ONLY return
        // true when the underlying capability is actually wired up. The previous
        // weak signals could pass on a freshly seeded, never-configured store.
        $menuActivePriced = $storeId !== null
            ? \Juwri\Modules\Menu\Models\Menu::where('store_id', $storeId)
                ->where('active', 1)
                ->where('price_minor', '>', 0)
                ->exists()
            : false;
        // P6-297: hours step ticks on EITHER an active branch's structured hours
        // OR a parsable timing string on the post (column) or in store_options
        // (the legacy settings store — onboarding writes there too). Reads the
        // column first (the storefront's source of truth), falls back to the
        // post_option key set by the legacy / onboarding flow.
        $hoursSet = false;
        if ($firstRestaurant) {
            $timingColumn = $firstRestaurant->timing ?? null;
            $timingOption = $storeId !== null
                ? (string) \App\Models\StoreOption::getStoreOption($storeId, 'timing')
                : '';
            $timing = trim(($timingColumn ?? '').' '.($timingOption ?? ''));
            $weekly = \App\Services\Directory\OpeningHoursService::weeklyModelForPost($firstRestaurant);
            // The weekly helper already parses `timing` from the column; if the
            // legacy StoreOption holds a "24/7"-shaped value the helper did not see,
            // recognise it here so the step closes.
            $timingSet = $timing !== '' && preg_match(
                '/\b24\s*\/?\s*7\b|24\s*-?\s*hours?/i',
                $timing
            );
            $hoursSet = $weekly !== null || $timingSet;
        }
        $paymentEnabled = $storeId !== null
            ? \Juwri\Modules\Payments\Services\StorePaymentGateways::enabledGateways($storeId) !== []
            : false;
        $qrVisited = $storeId !== null
            ? ((string) \App\Models\StoreOption::getStoreOption($storeId, 'qr_builder_visited') === '1')
            : false;
        $testOrderPlaced = $storeId !== null
            ? \App\Models\Order::where('store_id', $storeId)->exists()
            : false;
        $printerOrKds = $storeId !== null
            ? \Juwri\Modules\Pos\Models\PrinterProfile::where('store_id', $storeId)->exists()
              || \App\Models\KitchenStation::where('store_id', $storeId)->exists()
            : false;
        $previewLive = $storeId !== null
            ? \App\Models\StoreView::where('store_id', $storeId)->count() > 0
            : false;

        $steps = [
            ['title' => ___('Create your account'), 'desc' => ___('You are logged in and ready to go.'), 'icon' => 'check-circle-2', 'done' => true, 'link' => null, 'btn' => null],
            ['title' => ___('Add your first store'), 'desc' => ___('Set up your store profile with a name and description.'), 'icon' => 'store', 'done' => $stores->count() > 0, 'link' => Route::has('stores.create') ? route('stores.create') : null, 'btn' => ___('Add store')],
            ['title' => ___('Upload your logo'), 'desc' => ___('Add a logo so diners recognise your brand.'), 'icon' => 'palette', 'done' => $firstRestaurant && !in_array($firstRestaurant->main_image ?? '', ['', 'default.png', null], true), 'link' => $firstRestaurant && Route::has('stores.edit') ? route('stores.edit', $firstRestaurant->id) : (Route::has('stores.create') ? route('stores.create') : null), 'btn' => ___('Edit store')],

            // P6-297: ≥1 menu item that is BOTH active AND priced > 0 — a draft
            // seeded item, or a 0-price promo row, no longer ticks the box.
            ['title' => ___('Add your first menu item'), 'desc' => ___('Build your digital menu: categories, items, prices, photos.'), 'icon' => 'utensils-crossed', 'done' => $menuActivePriced, 'link' => $firstRestaurant && Route::has('stores.menu') ? route('stores.menu', $firstRestaurant->id) : null, 'btn' => ___('Build menu')],

            // P6-297: opening hours set or 24/7 confirmed — without hours, the
            // storefront "open" chip stays red and diners cannot place orders.
            ['title' => ___('Set opening hours'), 'desc' => ___('Tell diners when you accept orders, or confirm you are open 24/7.'), 'icon' => 'clock-3', 'done' => $hoursSet, 'link' => $firstRestaurant && Route::has('branches.index') ? route('branches.index', ['store_id' => $firstRestaurant->id]) : null, 'btn' => ___('Set hours')],

            // P6-297: at least one payment gateway enabled — without this, the
            // cart cannot be paid.
            ['title' => ___('Enable a payment method'), 'desc' => ___('Turn on at least one gateway so diners can pay.'), 'icon' => 'credit-card', 'done' => $paymentEnabled, 'link' => $firstRestaurant && Route::has('stores.edit') ? route('stores.edit', $firstRestaurant->id) : null, 'btn' => ___('Set up payments')],

            ['title' => ___('Generate your QR code'), 'desc' => ___('Get your unique QR code and place it on your tables or flyers.'), 'icon' => 'qr-code', 'done' => $qrVisited, 'link' => $firstRestaurant && Route::has('stores.qrDesigner') ? route('stores.qrDesigner', $firstRestaurant->id) : null, 'btn' => ___('QR builder')],

            // P6-297: any order row (placed OR cancelled-in-test) means the
            // owner has at least once pushed a cart through — a phantom menu
            // cannot satisfy this step.
            ['title' => ___('Place your first test order'), 'desc' => ___('Run a paid test through the cart, then cancel it if you like.'), 'icon' => 'shopping-bag', 'done' => $testOrderPlaced, 'link' => $firstRestaurant && Route::has('publicView') ? route('publicView', ['slug' => $firstRestaurant->slug]) : null, 'btn' => ___('Open storefront')],

            // P6-297: a printer profile or a kitchen station configured — the
            // kitchen will not see an order until one of these exists.
            ['title' => ___('Open a printer or KDS'), 'desc' => ___('Pair a printer or add a kitchen station so tickets can land somewhere.'), 'icon' => 'kitchen', 'done' => $printerOrKds, 'link' => Route::has('stores.printing.index') ? route('stores.printing.index') : (Route::has('kds.index') ? route('kds.index') : null), 'btn' => ___('Open printers')],

            // P6-47: ticks once a TOTP secret is confirmed or a passkey exists.
            ['title' => ___('Secure your account'), 'desc' => ___('Turn on two-factor sign-in or add a passkey.'), 'icon' => 'lock', 'done' => $user->hasSecondFactor(), 'link' => Route::has('2fa.show') ? route('2fa.show') : null, 'btn' => ___('Security settings')],

            ['title' => ___('Preview your live menu'), 'desc' => ___('See what your customers see. Share the link with your team.'), 'icon' => 'eye', 'done' => $previewLive, 'link' => $firstRestaurant && Route::has('publicView') ? route('publicView', ['slug' => $firstRestaurant->slug]) : null, 'btn' => ___('View menu')],
        ];

        $totalSteps  = count($steps);
        $doneCount   = collect($steps)->where('done', true)->count();
        $progressPct = $totalSteps ? round(($doneCount / $totalSteps) * 100) : 0;
        $allDone     = $doneCount === $totalSteps;
        $nextIndex   = $allDone ? -1 : \Juwri\Core\Support\ViewHelpers::firstUndone($steps);
    @endphp

    <section class="ui-card spotlight-checklist ui-onb" id="ob-checklist" data-user="{{ $user->id }}">
        <header class="ui-onb__head">
            <div class="spotlight-checklist__head">
                <h2 class="spotlight-checklist__title">{{ ___('Get started') }}</h2>
                <p class="spotlight-checklist__count">
                    <strong class="ui-ltr">{{ $doneCount }}/{{ $totalSteps }}</strong> {{ ___('steps completed') }}
                    @if($allDone) · <span class="ui-onb__done">{{ ___('You are all set!') }}</span> @endif
                </p>
            </div>
            @if(Route::has('onboarding.dismiss'))
                <button type="button" class="ui-icon-btn" data-ui-call="dashDismissChecklist" aria-label="{{ ___('Dismiss') }}" title="{{ ___('Dismiss') }}">
                    <x-ui.icon name="x" size="18" />
                </button>
            @endif
        </header>

        <span class="ui-ov-meter spotlight-checklist__meter" role="progressbar" aria-label="{{ ___('Get started') }}" aria-valuenow="{{ $progressPct }}" aria-valuemin="0" aria-valuemax="100"><span style="inline-size: {{ $progressPct }}%"></span></span>

        @unless($allDone)
            <ul class="spotlight-checklist__steps">
                @foreach($steps as $i => $step)
                    <li class="spotlight-checklist__step" @if($step['done']) data-done @endif @if($i === $nextIndex) data-next @endif>
                        <span class="spotlight-checklist__dot" aria-hidden="true">
                            @if($step['done'])<x-ui.icon name="check" size="16" />@endif
                        </span>
                        <div class="spotlight-checklist__body">
                            <div class="spotlight-checklist__step-title">
                                {{ $step['title'] }}
                                @if($i === $nextIndex)<x-ui.tag tone="accent">{{ ___('Next') }}</x-ui.tag>@endif
                            </div>
                            <div class="spotlight-checklist__desc">{{ $step['desc'] }}</div>
                        </div>
                        @if(!$step['done'] && $step['link'])
                            <a href="{{ $step['link'] }}" class="ui-btn ui-btn--sm spotlight-checklist__cta {{ $i === $nextIndex ? '' : 'ui-btn--secondary' }}">{{ $step['btn'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endunless
    </section>

    @if(Route::has('onboarding.dismiss'))
        @push('scripts_at_bottom')
        <script nonce="{{ csp_nonce() }}">
            function dashDismissChecklist() {
                var card = document.getElementById('ob-checklist');
                if (card) { card.style.transition = 'opacity .25s ease'; card.style.opacity = '0'; setTimeout(function () { card.remove(); }, 260); }
                var token = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
                fetch('{{ route('onboarding.dismiss') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).catch(function () {});
            }
        </script>
        @endpush
    @endif
@endif