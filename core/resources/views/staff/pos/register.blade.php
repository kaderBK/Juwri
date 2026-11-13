{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('POS') }} — {{ $store->title }}</title>
    {{-- P0-3: apply the saved theme BEFORE paint (no flash); light is the default.
         posToggleTheme() flips + persists and updates the topbar sun/moon icon. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var t = null;
            try { t = localStorage.getItem('pos-theme'); } catch (e) {}
            document.documentElement.dataset.posTheme = (t === 'dark') ? 'dark' : 'light';
        })();
        function posThemeIcon() {
            var dark = document.documentElement.dataset.posTheme === 'dark';
            document.querySelectorAll('.pos-theme-toggle').forEach(function (b) { b.textContent = dark ? '☀' : '☾'; });
        }
        function posToggleTheme() {
            var dark = document.documentElement.dataset.posTheme !== 'dark';
            document.documentElement.dataset.posTheme = dark ? 'dark' : 'light';
            try { localStorage.setItem('pos-theme', dark ? 'dark' : 'light'); } catch (e) {}
            posThemeIcon();
        }
        document.addEventListener('DOMContentLoaded', posThemeIcon);
    </script>
    {{-- Dynamic accent stays inline (carries the restaurant colour); the rest of
         the register styles live in the extracted, cache-busted stylesheet (P0-1).
         --pos-accent-ink is computed from the accent's WCAG luminance so text on an
         accent fill is always readable — never dark ink (#06231a) on a dark brand
         colour like pink #e6034b (which gets white ink instead). --}}
    @php
        $__accent = $store->color ?: '#10b981';
        $__hex = ltrim((string) $__accent, '#');
        if (strlen($__hex) === 3) { $__hex = $__hex[0].$__hex[0].$__hex[1].$__hex[1].$__hex[2].$__hex[2]; }
        $__ink = '#0B1220';
        if (strlen($__hex) === 6 && ctype_xdigit($__hex)) {
            $__lin = fn ($c) => ($c = $c / 255) <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
            $__L = 0.2126 * $__lin(hexdec(substr($__hex, 0, 2)))
                 + 0.7152 * $__lin(hexdec(substr($__hex, 2, 2)))
                 + 0.0722 * $__lin(hexdec(substr($__hex, 4, 2)));
            $__ink = $__L > 0.45 ? '#0B1220' : '#FFFFFF'; // dark ink on light accents, white on dark
        }
    @endphp
    <link rel="stylesheet" href="{{ asset('assets/pos/pos-register.css') }}?v={{ filemtime(public_path('assets/pos/pos-register.css')) }}">
    {{-- After the stylesheet so it wins over the light/dark token blocks (which also
         define --pos-accent-ink at equal specificity). --}}
    <style nonce="{{ csp_nonce() }}">:root, [data-pos-theme="light"], [data-pos-theme="dark"] { --pos-accent: {{ $__accent }}; --pos-accent-ink: {{ $__ink }}; }</style>
    {{-- Saudi Riyal (U+20C1): always-on @font-face (body font stack leads with
         'Saudi Riyal' in pos-register.css). unicode-range confines it to U+20C1. --}}
    @include('partials.riyal-font')
    @include('partials.arabic-font')
</head>
{{-- POS-V2 shell (P0-2): thin layout + data bootstrap; every surface is a partial
     under staff/pos/partials/ so parallel tracks edit their own files. --}}
<body x-data="posRegister()" x-init="boot()">
    @include('staff.pos.partials.rail')

    <div class="pos-main">
        @include('staff.pos.partials.topbar')

        <div class="pos-offline-banner" x-show="storageWarning" x-cloak role="status">{{ ___('This browser may delete offline sales if storage runs low. Offline, this till takes cash only: connect often, or use the juwri app.') }}</div>
        {{-- OP-9: one banner for every offline state (ready / no saved menu / time is up / syncing / needs a manager). --}}
        <div class="pos-offline-banner" :class="offlineBanner() ? 'is-' + offlineBanner().tone : ''" x-show="offlineBanner()" x-cloak role="status"
             x-text="offlineBanner() ? offlineBanner().text : ''"></div>

        <div class="pos">
            @include('staff.pos.partials.menu-grid')
            @include('staff.pos.partials.cart')
            {{-- Panels overlay the menu column (rail Tables/Orders + Select Table CTA) --}}
            @include('staff.pos.partials.tables')
            @include('staff.pos.partials.orders-hub')
            @if($isOwner || ($staff && $staff->hasPermission('pos_shift_close')))
                @include('staff.pos.partials.manage')
            @endif
        </div>
    </div>

    @include('staff.pos.partials.customer-panel')
    @include('staff.pos.partials.lock')
    @include('staff.pos.partials.settle')
    @include('staff.pos.partials.settle-existing')
    @include('staff.pos.partials.customize-sheet')
    @include('staff.pos.partials.combo-sheet')
    {{-- PARITY-RUN T4: merge confirmation + move-order-to-table sheets --}}
    @include('staff.pos.partials.merge')
    @include('staff.pos.partials.display-bridge')
    @include('staff.pos.partials.shortcuts')
    @include('staff.pos.partials.offline-receipt') {{-- OP-9: receipt printed from local data --}}

    {{-- Mobile: backdrop + floating cart button (CSS shows these only on phones) --}}
    <div class="pos-mbackdrop" x-show="mCart" x-cloak @click="mCart = false"></div>
    <button type="button" class="pos-cart-fab" @click="mCart = true" x-show="!mCart">
        <span class="fab-ico">@include('staff.pos.partials.icon', ['name' => 'cart', 'size' => 22])</span>
        <span class="fab-n" x-show="cart.length" x-text="cart.length"></span>
        <span class="fab-total" x-text="money(payable())"></span>
    </button>

    {{-- T1-6: toast (scanner/unknown-code + general notices) --}}
    <div class="pos-toast" :class="'is-' + (toast.type || 'info')" role="status" :aria-live="toast.type === 'error' ? 'assertive' : 'polite'" aria-atomic="true" x-show="toast.show" x-cloak x-text="toast.msg"></div>

    {{-- P0-1: Blade-derived values (routes, ids, flags, translated strings) are
         injected here; the extracted, cache-busted core script reads them from
         window.PosBoot. Keep this in sync when adding routes/strings to pos-core.js. --}}
    <script nonce="{{ csp_nonce() }}">
        window.PosBoot = {
            storeId: {{ $store->id }},
            currencyDecimals: {{ (int) \Juwri\Core\Money\Currencies::exponent((string) (store_options($store->id, 'currency_code') ?: config('settings.currency_code', 'USD'))) }}, {{-- the store currency's minor units (0 / 2 / 3) --}}
            lang: @json($currentLang ?? app()->getLocale()),
            branch: {{ $activeBranchId ?? ($branches->first()->id ?? 'null') }},
            liveBranchId: {{ \Juwri\Core\Broadcasting\LiveUpdates::branchScope() ?? 'null' }},
            isOwner: {{ $isOwner ? 'true' : 'false' }},
            staffId: {{ $staff ? (int) $staff->id : 'null' }}, {{-- OP-9: who is selling (staff id as in the offline snapshot); null for the owner --}}
            canRefund: {{ !empty($canRefund) ? 'true' : 'false' }}, {{-- STF-013: void/refund controls --}}
            canDrawer: {{ !empty($canDrawer) ? 'true' : 'false' }}, {{-- D2: drawer open/handoff --}}
            staffList: @json($posStaff ?? []), {{-- D2: handoff targets --}}
            autolockMinutes: {{ (int) ($autolockMinutes ?? 10) }},
            tax: @json($posTax ?? ['charge' => 0, 'type' => 'percentage', 'delivery' => 0]),
            notifSounds: @json(notification_sounds_config((int) $store->id, $activeBranchId ? (int) $activeBranchId : null)),
            loyalty: @json($posLoyalty ?? ['active' => false]),
            routes: {
                sw: '{{ route('pos.sw') }}',
                unlock: '{{ route('pos.unlock') }}',
                display: '{{ route('pos.display') }}',
                menu: '{{ route('pos.menu') }}',
                combos: '{{ route('pos.combos') }}', // T6: combo tiles (server-priced)
                couponCheck: '{{ route('pos.coupon.check') }}',
                customers: '{{ route('pos.customers') }}',
                customersStore: '{{ route('pos.customers.store') }}',
                tables: '{{ route('pos.tables') }}',
                held: '{{ route('pos.held') }}',
                ordersRecent: '{{ route('pos.orders.recent') }}',
                ordersIncoming: '{{ route('pos.orders.incoming') }}',
                ordersApprovals: '{{ route('pos.orders.approvals') }}', // PARITY-RUN T3
                ordersStore: '{{ route('pos.orders.store') }}',
                ordersBase: '{{ url('pos/orders') }}',
                shiftCurrent: '{{ route('pos.shift.current') }}',
                shiftOpen: '{{ route('pos.shift.open') }}',
                terminalsBase: '{{ url('pos/terminals') }}', {{-- D8: terminal registry --}}
                offlineDevices: '{{ route('pos.offline.devices.register') }}', {{-- offline till identity --}}
                offlineDevicesStatus: '{{ route('pos.offline.devices.status') }}',
                offlineDevicesWiped: '{{ route('pos.offline.devices.wiped') }}',
                offlineDevicesEscrow: '{{ route('pos.offline.devices.escrow') }}',
                offlineSnapshot: '{{ route('pos.offline.snapshot') }}', {{-- OP-9: catalogue snapshot --}}
                offlineReplay: '{{ route('pos.offline.replay') }}', {{-- OP-9: sales / tenders / voids recorded offline --}}
                shiftsBase: '{{ url('pos/shifts') }}',
                sales: '{{ route('pos.sales') }}',
                // T1: direct printing (config + job queue + per-profile actions)
                printConfig: '{{ route('pos.print.config') }}',
                printJobs: '{{ route('pos.print.jobs') }}',
                printAckBase: '{{ url('pos/print/jobs') }}',
                printTestBase: '{{ url('pos/print/test') }}',
                printOrderBase: '{{ url('pos/print/orders') }}',
                // PARITY-RUN T4: table merge (transfer reuses ordersBase)
                tablesMerges: '{{ route('pos.tables.merges') }}',
                tablesMerge: '{{ route('pos.tables.merge') }}',
                tablesUnmergeBase: '{{ url('pos/tables/merges') }}',
            },
            i18n: {
                orderHeld: @json(___('Order held.')),
                sentToKitchen: @json(___('Sent to kitchen.')),
                offlineQueued: @json(___('Offline — queued, will send when online.')),
                offlineBlocked: @json(___('Connect to continue: this till must reach the server before it can sell again.')),
                storageFull: @json(___('Storage is full: this sale was NOT saved. Free space on this device or connect, then try again.')),
                {{-- OP-9: the offline register --}}
                offlineReady: @json(___('Offline — sales are saved on this till and sent when you are back online.')),
                offlineNoMenu: @json(___('Offline — this till has no saved menu yet. Connect once to sell offline.')),
                offlineExpired: @json(___('Offline time is up. Connect to the internet to keep selling.')),
                offlineSyncing: @json(___('Reconnected — syncing')),
                offlineReview: @json(___(':count offline sales need a manager to review them (Offline exceptions).')),
                offlineOnly: @json(___(':action is not available offline. Connect to use it.')),
                offlineSaved: @json(___('Offline sale saved. It will be sent when you are back online.')),
                offlineCashShort: @json(___('The cash given is less than the total.')),
                offlineNoStaff: @json(___('Sign in with a staff PIN to sell offline.')),
                offlineNotAllowed: @json(___('You are not allowed to do this offline.')),
                offlineMenuChanged: @json(___('The saved menu does not have an item in this order. Connect to update it.')),
                offlineVoidReason: @json(___('Reason for voiding this sale')),
                offlineVoided: @json(___('Sale voided. The void will be sent when you are back online.')),
                offlineAlreadyVoided: @json(___('This sale is already voided.')),
                offlineSnapshotBad: @json(___('The menu download failed its check, so the saved menu was not changed.')),
                offlineCreditsDropped: @json(___('Coupon / store credit removed: they need a connection.')),
                offlineLabels: {
                    customers: @json(___('Add customer')), coupon: @json(___('Coupon')), wallet: @json(___('Wallet')), tables: @json(___('Tables')),
                    manage: @json(___('Manage')), delivery: @json(___('Delivery')), card: @json(___('Card')), other_payment: @json(___('Other modes')),
                    split: @json(___('Split')), hold: @json(___('Hold')), kitchen: @json(___('Send to kitchen')), settle_order: @json(___('Settle')),
                    combos: @json(___('Combos')), orders_online: @json(___('Orders')),
                },
                offlineQueuedSettle: @json(___('Offline — order queued (settle on the receipt when it syncs).')),
                paid: @json(___('Paid.')),
                change: @json(___('Change')),
                networkError: @json(___('Network error.')),
                tooManyAttempts: @json(___('Too many attempts. Wait a moment.')),
                wrongPin: @json(___('Wrong PIN.')),
                wrongPassword: @json(___('Incorrect password.')),
                couponInvalid: @json(___('Coupon code is invalid.')),
                unknownCode: @json(___('Unknown code:')),
                nameRequired: @json(___('Name is required.')),
                saveFailed: @json(___('Could not save.')),
                saved: @json(___('Saved.')),
                shiftOpened: @json(___('Shift opened.')),
                countedRequired: @json(___('Enter the counted cash.')),
                {{-- D2: drawer handoff --}}
                handoffPickStaff: @json(___('Choose who takes the drawer.')),
                handoffDone: @json(___('Drawer handed over.')),
                addingToOrder: @json(___('Adding products to the order.')),
                addedToOrder: @json(___('Added to the order.')),
                pick: @json(___('Pick')),
                atLeast: @json(___('Pick at least')),
                upTo: @json(___('Pick up to')),
                tableOpen: @json(___('This table has an open order.')),
                tableBlocked: @json(___('Table unavailable (needs cleaning).')),
                resumed: @json(___('Order resumed.')),
                {{-- STF-013: void / refund from the orders hub --}}
                voided: @json(___('Order voided.')),
                lineVoided: @json(___('Item voided.')),
                refunded: @json(___('Refund recorded.')),
                reasonRequired: @json(___('A reason is required.')),
                refundAmountInvalid: @json(___('Enter a valid refund amount.')),
                nothingLeftToRefund: @json(___('Nothing left to refund on this order.')),
                {{-- D1: item-level refund --}}
                refundByAmount: @json(___('By amount')),
                refundByItem: @json(___('By item')),
                refundPickItems: @json(___('Pick items to refund')),
                refundNoItems: @json(___('Select at least one item to refund.')),
                refundRemaining: @json(___('left')),
                {{-- D5: rush --}}
                rushOn: @json(___('Marked rush.')),
                rushOff: @json(___('Rush cleared.')),
                justNow: @json(___('now')),
                settleHeld: @json(___('Resume the order, then settle on the register.')),
                discardConfirm: @json(___('Discard this queued order?')),
                incomingOrder: @json(___('New online order!')),
                editingOrder: @json(___('Editing order')),
                orderUpdated: @json(___('Order updated.')),
                couponApplied: @json(___('Coupon applied.')),
                // PARITY-RUN T3 — staff approval queue
                rejectConfirm: @json(___('Reject this order?')),
                orderApproved: @json(___('Order approved.')),
                orderRejected: @json(___('Order rejected.')),
                // PARITY-RUN T1 — printing
                printersLabel: @json(___('Printers')),
                printFailed: @json(___('Print failed')),
                printerPaired: @json(___('Printer paired.')),
                printNoSerial: @json(___('This browser cannot pair USB printers. Use Chrome or Edge.')),
                printNoUsb: @json(___('This browser cannot pair USB printers. Use Chrome or Edge.')),
                // PARITY-RUN T4 — table merge / order transfer
                mergePickTwo: @json(___('Pick at least two tables to merge.')),
                mergeAlready: @json(___('This table is already part of a merged party.')),
                mergedBillOn: @json(___('This table is merged — the bill is on')),
                unmergeConfirm: @json(___('Release this merged party?')),
                tablesMerged: @json(___('Tables merged.')),
                tablesUnmerged: @json(___('Tables released.')),
                orderMoved: @json(___('Order moved.')),
            },
        };
    </script>
    {{-- Notification sounds: shared engine (also used by the dashboard). --}}
    <script src="{{ asset('assets/js/howler.min.js') }}?v={{ filemtime(public_path('assets/js/howler.min.js')) }}" defer></script>
    <script src="{{ asset('assets/js/notify-sounds.js') }}?v={{ filemtime(public_path('assets/js/notify-sounds.js')) }}" defer></script>
    {{-- T1-7: shortcuts module registers on window.PosModules before the core
         factory composes it (both deferred, in order). --}}
    <script src="{{ asset('assets/pos/pos-shortcuts.js') }}?v={{ filemtime(public_path('assets/pos/pos-shortcuts.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-customers.js') }}?v={{ filemtime(public_path('assets/pos/pos-customers.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-tables.js') }}?v={{ filemtime(public_path('assets/pos/pos-tables.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-hold.js') }}?v={{ filemtime(public_path('assets/pos/pos-hold.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-payments.js') }}?v={{ filemtime(public_path('assets/pos/pos-payments.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-manage.js') }}?v={{ filemtime(public_path('assets/pos/pos-manage.js')) }}" defer></script>
    {{-- PARITY-RUN T3: staff-approval queue module (own zone, no pos-core edits). --}}
    <script src="{{ asset('assets/pos/pos-approvals.js') }}?v={{ filemtime(public_path('assets/pos/pos-approvals.js')) }}" defer></script>
    {{-- PARITY-RUN T1: printing module (same contract — a PosModules entry). --}}
    <script src="{{ asset('assets/pos/pos-print.js') }}?v={{ filemtime(public_path('assets/pos/pos-print.js')) }}" defer></script>
    {{-- PARITY-RUN T4: table merge + order transfer (same contract). --}}
    <script src="{{ asset('assets/pos/pos-merge.js') }}?v={{ filemtime(public_path('assets/pos/pos-merge.js')) }}" defer></script>
    {{-- T6 follow-up: combo tile + configurator (server-priced; same module contract). --}}
    <script src="{{ asset('assets/pos/pos-combos.js') }}?v={{ filemtime(public_path('assets/pos/pos-combos.js')) }}" defer></script>
    {{-- D8: terminal registry (device heartbeat + printer override). Same module contract. --}}
    <script src="{{ asset('assets/pos/pos-terminal.js') }}?v={{ filemtime(public_path('assets/pos/pos-terminal.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-store.js') }}?v={{ filemtime(public_path('assets/pos/pos-store.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/till-calculator.js') }}?v={{ filemtime(public_path('assets/pos/till-calculator.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-offline.js') }}?v={{ filemtime(public_path('assets/pos/pos-offline.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-device.js') }}?v={{ filemtime(public_path('assets/pos/pos-device.js')) }}" defer></script>
    <script src="{{ asset('assets/pos/pos-core.js') }}?v={{ filemtime(public_path('assets/pos/pos-core.js')) }}" defer></script>
    @include('partials.live-updates') {{-- Reverb live updates: prints nothing while off --}}
    {{-- Alpine LAST: its CDN build calls start() as soon as it runs (deferred
         scripts execute when readyState is already "interactive"), so posRegister()
         + the PosModules must be defined first, or x-data init throws.
         Self-hosted (3.15.0) so the SW-precached shell boots fully offline —
         the jsdelivr CDN was the one asset the offline register couldn't load. --}}
    <script src="{{ asset('assets/pos/alpine.min.js') }}?v={{ filemtime(public_path('assets/pos/alpine.min.js')) }}" defer></script>
</body>
</html>
