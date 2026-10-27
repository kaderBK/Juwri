{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Orders').' - '.$store->title)
@section('page_title', ___('Orders'))
@section('page_sub', $store->title)

@section('page_actions')
    <div class="ui-orders-tools" x-data>
        <button type="button" class="ui-icon-btn" :class="$store.ordersPrefs?.sound ? 'ui-icon-btn--filled' : ''"
            @click="$store.ordersPrefs.toggleSound()" :aria-pressed="$store.ordersPrefs?.sound ? 'true' : 'false'"
            title="{{ ___('New order sound') }}" aria-label="{{ ___('New order sound') }}">
            <x-ui.icon name="bell" />
        </button>
        <button type="button" class="ui-icon-btn" :class="$store.ordersPrefs?.autoPrint ? 'ui-icon-btn--filled' : ''"
            @click="$store.ordersPrefs.togglePrint()" :aria-pressed="$store.ordersPrefs?.autoPrint ? 'true' : 'false'"
            title="{{ ___('Auto-print new orders') }}" aria-label="{{ ___('Auto-print new orders') }}">
            <x-ui.icon name="print" />
        </button>
        @if(Route::has('stores.ordersExportCsv'))
            {{-- D9: export current orders (honours ?q/?status/?from/?to filters) --}}
            <a class="ui-icon-btn" href="{{ route('stores.ordersExportCsv', array_merge([$store->id], request()->only('q','status','from','to'))) }}"
               data-busy-download data-busy-label="{{ ___('Preparing…') }}"
               title="{{ ___('Export CSV') }}" aria-label="{{ ___('Export CSV') }}">
                <x-ui.icon name="download" />
            </a>
        @endif
        {{-- Refund requests: shortcut + pending-count badge. Shows when there are
             pending requests or the refund filter is already active. --}}
        @if(($pendingRefundCount ?? 0) > 0 || ($activeRefundFilter ?? null))
            <a class="ui-icon-btn ui-orders-tools__refunds {{ ($activeRefundFilter ?? null) ? 'ui-icon-btn--filled' : '' }}"
               href="{{ route('stores.orders', ['store' => $store->id, 'refunds' => 'requested']) }}"
               title="{{ ___('Refund requests') }}" aria-label="{{ ___('Refund requests') }}">
                <x-ui.icon name="wallet" />
                <x-ui.count :value="$pendingRefundCount ?? 0" />
            </a>
        @endif
    </div>
@endsection

@section('content')
@php
    $orderCollection = collect($orders->items());
    $pendingCount = $orderCollection->where('status', 'pending')->count();
    $activeCount = $orderCollection->whereIn('status', ['pending', 'preparing', 'ready'])->count();
    $completedCount = $orderCollection->where('status', 'completed')->count();
    $revenueTotal = \Juwri\Core\Support\ViewHelpers::sumPrices($orderCollection);
    $currentStatus = (string) request('status', '');
    $statusFilters = [
        '' => ___('All'),
        'pending' => ___('Pending'),
        'preparing' => ___('Preparing'),
        'ready' => ___('Ready'),
        'completed' => ___('Completed'),
        'cancelled' => ___('Cancelled'),
    ];
    $keep = request()->only('q', 'from', 'to');
@endphp

<div class="ui-scope ui-orders"
    x-data="ordersPage({
        token: @js(csrf_token()),
        failedText: @js(___('Unable to update order.')),
        deleteConfirmText: @js(___('Delete this order? It disappears from the queue and reports.')),
        deleteLabel: @js(___('Delete')),
        statusUpdatedText: @js(___('Order status updated.')),
        completedText: @js(___('Order marked complete.')),
        deletedText: @js(___('Order deleted.')),
        restaurantName: @js($store->title),
        restaurantAddress: @js($store->address),
        thanksText: @js(___('Thank you for visiting.')),
        initialQuery: @js((string) request('q', '')),
        initialStatus: @js((string) request('status', '')),
        focusOrderId: @js($focusOrderId ?? null)
    })"
>
    @if($activeRefundFilter ?? null)
        <x-ui.banner tone="warning" class="ui-orders-banner">
            <span class="ui-orders-banner__text">
                <strong>{{ ___('Refund requests') }}</strong>
                <span>{{ ___('Approve to refund to store credit, or decline.') }}</span>
            </span>
            <a class="ui-btn ui-btn--secondary ui-btn--sm" href="{{ route('stores.orders', $store->id) }}">{{ ___('Show all orders') }}</a>
        </x-ui.banner>
    @endif

    {{-- Live queue summary (values refreshed in place by ordersRefreshKpis) --}}
    <section class="ui-orders-kpis" aria-label="{{ ___('Kitchen queue') }}">
        <div class="ui-stat" data-tone="warning">
            <span class="ui-stat__icon"><x-ui.icon name="flame" /></span>
            <span class="ui-stat__value" data-kpi="active">{{ number_format($activeCount) }}</span>
            <span class="ui-stat__label">{{ ___('Active') }}</span>
        </div>
        <div class="ui-stat">
            <span class="ui-stat__icon"><x-ui.icon name="clock" /></span>
            <span class="ui-stat__value" data-kpi="pending">{{ number_format($pendingCount) }}</span>
            <span class="ui-stat__label">{{ ___('Pending') }}</span>
        </div>
        <div class="ui-stat" data-tone="success">
            <span class="ui-stat__icon"><x-ui.icon name="check" /></span>
            <span class="ui-stat__value" data-kpi="completed">{{ number_format($completedCount) }}</span>
            <span class="ui-stat__label">{{ ___('Completed') }}</span>
        </div>
        <div class="ui-stat">
            <span class="ui-stat__icon"><x-ui.icon name="trend-up" /></span>
            <span class="ui-stat__value" data-kpi="revenue">{{ price_symbol_format($revenueTotal) }}</span>
            <span class="ui-stat__label">{{ ___('Shown revenue') }}</span>
        </div>
    </section>

    {{-- Syria A4: wallet-transfer payment claims awaiting owner confirmation. A
         wallet_transfer order stays 'unpaid' (hidden from the queue below) until the
         owner verifies the customer's transaction-id against their wallet SMS and
         confirms it here. --}}
    @php
        $__wtClaims = $walletTransferClaims ?? collect();
    @endphp
    @if($__wtClaims->count())
        @php
            $__wtLabels = ['syriatel' => ___('Syriatel Cash'), 'mtn' => ___('MTN Cash'), 'shamcash' => ___('Sham Cash')];
        @endphp
        <section class="ui-card ui-orders-claims">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Wallet-transfer payments to confirm') }}</h2>
                <x-ui.count :value="$__wtClaims->count()" tone="warning" />
            </div>
            <x-ui.banner tone="warning">{{ ___('The transaction ID is a customer claim, not proof of payment. Verify it against your wallet SMS before confirming.') }}</x-ui.banner>
            <div class="ui-orders-claims__list">
                @foreach($__wtClaims as $__claim)
                    @php
                        $__wallet = optional($__claim->details)->wallet;
                        $__txn = optional($__claim->details)->txn_id;
                        $__claimedAt = optional($__claim->details)->claimed_at;
                    @endphp
                    <div class="ui-orders-claim">
                        <div class="ui-orders-claim__body">
                            <strong>
                                {{ ___('Order') }} <bdi>#{{ $__claim->claimOrder->id }}</bdi>
                                <span class="ui-orders-muted">&middot; {{ $__wtLabels[$__wallet] ?? ($__wallet ?: '—') }}</span>
                            </strong>
                            <span class="ui-orders-muted">
                                {{ ___('Transaction ID') }}: <bdi class="ui-orders-claim__txn">{{ $__txn ?: '—' }}</bdi>
                                &middot; {{ ___('Amount') }}: <bdi>{{ price_symbol_format($__claim->amount) }}</bdi>
                                @if($__claimedAt)
                                    &middot; {{ \Carbon\Carbon::parse($__claimedAt)->diffForHumans() }}
                                @endif
                            </span>
                        </div>
                        <div class="ui-orders-claim__actions">
                            <form method="POST" action="{{ route('stores.walletTransfer.confirm', $__claim->claimOrder->id) }}">
                                @csrf
                                <button type="submit" class="ui-btn ui-btn--success ui-btn--sm">{{ ___('Confirm payment') }}</button>
                            </form>
                            <form method="POST" action="{{ route('stores.walletTransfer.reject', $__claim->claimOrder->id) }}">
                                @csrf
                                <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Reject') }}</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- P1-23 part 6: gift cards, store-credit top-ups and deposits paid by wallet or bank transfer go to THIS store's
         wallet / bank account, so only the owner confirms them here (the platform never can). Confirm once the money
         is in the account; a wallet transaction ID is the customer's claim, not proof. --}}
    @php
        $__stTransfers = $storeTransfers ?? collect();
    @endphp
    @if($__stTransfers->count())
        @php
            $__stKinds = ['gift_card_purchase' => ___('Gift card'), 'cust_wallet_topup' => ___('Store credit top-up'),
                'reservation_deposit' => ___('Reservation deposit'), 'catering_deposit' => ___('Catering deposit')];
            $__stTenders = ['wallet_transfer' => ___('Wallet transfer'), 'wire_transfer' => ___('Bank transfer')];
            $__stWallets = ['syriatel' => ___('Syriatel Cash'), 'mtn' => ___('MTN Cash'), 'shamcash' => ___('Sham Cash')];
        @endphp
        <section class="ui-card ui-orders-claims" data-store-transfers>
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Transfers to confirm') }}</h2>
                <x-ui.count :value="$__stTransfers->count()" tone="warning" />
            </div>
            <x-ui.banner tone="warning">{{ ___('Confirm only once the money is in your own wallet or bank account. A transaction ID is the customer\'s claim, not proof of payment.') }}</x-ui.banner>
            <div class="ui-orders-claims__list">
                @foreach($__stTransfers as $__st)
                    @php
                        $__stWallet = optional($__st->details)->wallet;
                        $__stTxn = optional($__st->details)->txn_id;
                    @endphp
                    <div class="ui-orders-claim">
                        <div class="ui-orders-claim__body">
                            <strong>
                                {{ $__stKinds[$__st->transaction_method] ?? $__st->product_name }} <bdi>#{{ $__st->id }}</bdi>
                                <span class="ui-orders-muted">&middot; {{ $__stTenders[$__st->transaction_gatway] ?? $__st->transaction_gatway }}@if($__stWallet) ({{ $__stWallets[$__stWallet] ?? $__stWallet }})@endif</span>
                            </strong>
                            <span class="ui-orders-muted">
                                {{ ___('Amount') }}: <bdi>{{ price_symbol_format($__st->amount) }}</bdi>
                                @if($__stTxn)
                                    &middot; {{ ___('Transaction ID') }}: <bdi class="ui-orders-claim__txn">{{ $__stTxn }}</bdi>
                                @endif
                                &middot; {{ $__st->created_at?->diffForHumans() }}
                            </span>
                        </div>
                        <div class="ui-orders-claim__actions">
                            <form method="POST" action="{{ route('stores.storeTransfer.confirm', $__st->id) }}">
                                @csrf
                                <button type="submit" class="ui-btn ui-btn--success ui-btn--sm">{{ ___('Confirm payment') }}</button>
                            </form>
                            <form method="POST" action="{{ route('stores.storeTransfer.reject', $__st->id) }}">
                                @csrf
                                <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Reject') }}</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Toolbar: D2 server-side search/filter — a real DB query (finds historical
         orders off page 1), with client x-model kept as an instant overlay. --}}
    <section class="ui-orders-toolbar">
        <form method="GET" action="{{ route('stores.orders', $store->id) }}" class="ui-orders-search">
            @if($currentStatus !== '')<input type="hidden" name="status" value="{{ $currentStatus }}">@endif
            <label class="ui-orders-search__field">
                <x-ui.icon name="search" size="18" />
                <span class="ui-visually-hidden">{{ ___('Search orders') }}</span>
                <input type="search" name="q" value="{{ request('q') }}" x-model.debounce.200ms="query" placeholder="{{ ___('Search name, phone or #id...') }}">
            </label>
            <span class="ui-periodbar__dates ui-orders-search__dates" @if(request('from') || request('to')) data-active @endif>
                <input type="date" name="from" value="{{ request('from') }}" aria-label="{{ ___('From') }}">
                <span aria-hidden="true">–</span>
                <input type="date" name="to" value="{{ request('to') }}" aria-label="{{ ___('To') }}">
            </span>
            <button type="submit" class="ui-btn ui-btn--sm">{{ ___('Search') }}</button>
            @if(request()->hasAny(['q','status','from','to']))
                <a href="{{ route('stores.orders', $store->id) }}" class="ui-btn ui-btn--ghost ui-btn--sm">{{ ___('Clear') }}</a>
            @endif
        </form>

        <div class="ui-orders-toolbar__row">
            <nav class="ui-chips ui-orders-status" aria-label="{{ ___('Status') }}">
                @foreach($statusFilters as $key => $label)
                    <a class="ui-chip" data-status="{{ $key ?: 'all' }}"
                       href="{{ route('stores.orders', array_merge([$store->id], $keep, $key !== '' ? ['status' => $key] : [])) }}"
                       @if($currentStatus === $key) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            <div class="ui-orders-toolbar__end">
                {{-- List or live board (Pending / Preparing / Ready columns; swipe on phones). --}}
                <div class="ui-chips ui-periodbar__presets ui-orders-view" role="group" aria-label="{{ ___('View mode') }}">
                    <button type="button" class="ui-chip" :aria-current="view === 'list' ? 'true' : null" @click="setView('list')" aria-label="{{ ___('List view') }}" title="{{ ___('List view') }}"><x-ui.icon name="list" size="18" /></button>
                    <button type="button" class="ui-chip" :aria-current="view === 'board' ? 'true' : null" @click="setView('board')" aria-label="{{ ___('Board') }}" title="{{ ___('Board') }}"><x-ui.icon name="more" size="18" /></button>
                </div>
                @if(count(request()->user()->stores) > 1)
                    <label class="ui-order__status ui-orders-store">
                        <span class="ui-visually-hidden">{{ ___('Store') }}</span>
                        <select @change="window.location = $event.target.value">
                            @foreach(request()->user()->stores as $otherStore)
                                <option value="{{ route('stores.orders', $otherStore->id) }}" @selected($otherStore->id == $store->id)>
                                    {{ $otherStore->title }}
                                </option>
                            @endforeach
                        </select>
                        <x-ui.icon name="chevron-down" size="16" />
                    </label>
                @endif

                @if(!empty($menu_languages) && $default_menu_language)
                    <div class="ui-periodbar__presets ui-chips ui-orders-langs" role="group" aria-label="{{ ___('Language') }}">
                        @foreach($menu_languages as $lang)
                            <button type="button" class="ui-chip"
                                @if($default_menu_language->code === $lang->code) aria-current="true" @endif
                                @click="switchLanguage(@js($lang->code))">{{ strtoupper($lang->code) }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- Orders --}}
    <div id="order-rows" class="ui-order-list" :data-view="view">
        {{-- Board column heads (board view only; the cards place themselves by data-status). --}}
        <div class="ui-order-col" data-col="pending" aria-hidden="true">{{ ___('Pending') }}</div>
        <div class="ui-order-col" data-col="preparing" aria-hidden="true">{{ ___('Preparing') }}</div>
        <div class="ui-order-col" data-col="ready" aria-hidden="true">{{ ___('Ready') }}</div>
        @forelse($orders as $order)
            @include('owner.orders.order-row', ['order' => $order, 'postOptions' => $postOptions])
        @empty
            <div class="ui-card ui-order-empty" data-orders-empty>
                <x-ui.empty :title="___('No orders found')" :body="___('New paid store orders will appear here.')" icon="orders" />
            </div>
        @endforelse
    </div>

    {{ $orders->links() }}

    {{-- Receipt drawer --}}
    <div class="ui-drawer-backdrop" x-show="drawerOpen" x-transition.opacity @click="closeDrawer()" x-cloak></div>
    <aside class="ui-drawer ui-orders-drawer" :class="drawerOpen ? 'is-open' : ''" tabindex="-1" x-cloak>
        <div class="ui-drawer__head">
            <div>
                <p class="ui-overline">{{ ___('Order Receipt') }}</p>
                <h2 class="ui-orders-drawer__title ui-ltr" x-text="drawerTitle"></h2>
            </div>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="closeDrawer()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
        </div>
        <div class="ui-drawer__body">
            <div class="orders-print">
                <div class="ui-orders-drawer__brand">
                    <strong>{{ $store->title }}</strong>
                    @if($store->address)
                        <span>{{ $store->address }}</span>
                    @endif
                </div>
                <div x-html="drawerHtml"></div>
                <p class="ui-orders-drawer__thanks">{{ ___('Thank you for visiting.') }}</p>
            </div>
        </div>
        <div class="ui-orders-drawer__foot">
            <x-ui.button icon="print" block x-on:click="printDrawer()">{{ ___('Print Receipt') }}</x-ui.button>
        </div>
    </aside>
</div>
@endsection

@push('scripts_at_bottom')
@include('partials.live-updates')
<script nonce="{{ csp_nonce() }}">
    // Per-browser order-screen preferences. Keys were "lamar_orders_*" before WI-16c:
    // read the old key once so a till keeps its sound / auto-print choice.
    window.ordersPref = window.ordersPref || {
        get: function (key) {
            try {
                var value = localStorage.getItem('orders_' + key);
                if (value === null) {
                    value = localStorage.getItem('lamar_orders_' + key);
                    if (value !== null) { localStorage.setItem('orders_' + key, value); }
                }
                return value;
            } catch (e) { return null; }
        },
        set: function (key, value) {
            try { localStorage.setItem('orders_' + key, value); } catch (e) {}
        },
    };

    window.ordersPage = function (config) {
        return {
            query: config.initialQuery || '',
            // List or board view, remembered per browser (storage may be unavailable).
            view: window.ordersPref.get('view') === 'board' ? 'board' : 'list',
            setView: function (v) {
                this.view = v;
                window.ordersPref.set('view', v);
            },
            status: config.initialStatus || '',
            drawerOpen: false,
            drawerHtml: '',
            drawerTitle: '',
            init: function () {
                // D2/D8: scroll to and highlight the ?order= / palette-linked row.
                if (!config.focusOrderId) { return; }
                var self = this;
                this.$nextTick(function () {
                    var row = document.querySelector('#order-rows [data-order-id="' + config.focusOrderId + '"]');
                    if (!row) { return; }
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    row.classList.add('is-focus');
                    setTimeout(function () { row.classList.remove('is-focus'); }, 3000);
                });
            },
            matchesOrder: function (row) {
                var rowStatus = row.dataset.status || '';
                var text = row.dataset.search || row.textContent || '';
                var query = this.query.trim().toLowerCase();

                if (this.status && rowStatus !== this.status) {
                    return false;
                }

                return !query || text.toLowerCase().indexOf(query) !== -1;
            },
            submitJson: function (url, payload) {
                return fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.token },
                    body: payload || new FormData()
                }).then(function (response) {
                    return response.json();
                });
            },
            // D1: helpers — resolve the row from an event/element, toast instead
            // of alert, update the row in place instead of a full-page reload.
            _rowFrom: function (elOrEvent) {
                var t = (elOrEvent && elOrEvent.target) ? elOrEvent.target : elOrEvent;
                return t && t.closest ? t.closest('#order-rows [data-order-id]') : null;
            },
            _toast: function (message, type) {
                if (window.dashToast) { window.dashToast(message, { type: type || 'success' }); }
                else if (type === 'error') { /* no-op */ }
            },
            // After an in-place mutation, pull the server-computed KPI strip + rows once
            // the write has committed (small delay), instead of waiting for the 25s poll.
            _refreshKpis: function () {
                if (window.ordersRefreshKpis) { window.ordersRefreshKpis(); }
            },
            updateStatus: function (select, url) {
                var self = this;
                var row = select.closest('#order-rows [data-order-id]');
                var newStatus = select.value;
                var payload = new FormData();
                payload.append('status', newStatus);

                this.submitJson(url, payload).then(function (data) {
                    if (data.success) {
                        if (row) { row.dataset.status = newStatus; }
                        self._toast(config.statusUpdatedText || 'Order updated', 'success');
                        self._refreshKpis();
                        return;
                    }
                    self._toast(data.message || config.failedText, 'error');
                }).catch(function () {
                    self._toast(config.failedText, 'error');
                });
            },
            completeOrder: function (ev, url) {
                var self = this;
                var row = this._rowFrom(ev);
                this.submitJson(url).then(function (data) {
                    if (data.success) {
                        if (row) {
                            row.dataset.status = 'completed';
                            var sel = row.querySelector('select');
                            if (sel) { sel.value = 'completed'; }
                        }
                        self._toast(config.completedText || 'Order completed', 'success');
                        self._refreshKpis();
                        return;
                    }
                    self._toast(data.message || config.failedText, 'error');
                }).catch(function () {
                    self._toast(config.failedText, 'error');
                });
            },
            refundRequestAction: function (ev, url, confirmText) {
                // Approve (-> store credit / original card) or reject a customer
                // refund request. Reload on success so the row re-renders with the
                // resolved badge. `confirmText` gates the money-moving actions.
                var self = this;
                var btn = ev && ev.currentTarget;
                var run = function () {
                    if (btn) { btn.disabled = true; }
                    self.submitJson(url).then(function (data) {
                        if (data.success) {
                            // A manual (non-automatic) gateway refund returns a note
                            // telling the owner to finish it in the provider — show it
                            // before the reload so it is not missed.
                            if (data.automatic === false && data.message) {
                                self._toast(data.message, 'info');
                                setTimeout(function () { window.location.reload(); }, 2200);
                                return;
                            }
                            window.location.reload();
                            return;
                        }
                        if (btn) { btn.disabled = false; }
                        self._toast(data.message || config.failedText, 'error');
                    }).catch(function () {
                        if (btn) { btn.disabled = false; }
                        self._toast(config.failedText, 'error');
                    });
                };
                if (confirmText) {
                    window.dashConfirm({ body: confirmText, confirmLabel: '{{ ___('Refund') }}' }).then(function (ok) { if (ok) { run(); } });
                } else {
                    run();
                }
            },
            deleteOrder: function (ev, url) {
                var self = this;
                var row = this._rowFrom(ev);
                window.dashConfirm({
                    body: config.deleteConfirmText,
                    confirmLabel: config.deleteLabel,
                    danger: true
                }).then(function (ok) {
                    if (!ok) { return; }
                    self.submitJson(url).then(function (data) {
                        if (data.success) {
                            if (row) {
                                row.style.transition = 'opacity .2s ease';
                                row.style.opacity = '0';
                                setTimeout(function () { if (row.parentNode) row.parentNode.removeChild(row); }, 200);
                            }
                            self._toast(config.deletedText || 'Order deleted', 'success');
                            self._refreshKpis();
                            return;
                        }
                        self._toast(data.message || config.failedText, 'error');
                    }).catch(function () {
                        self._toast(config.failedText, 'error');
                    });
                });
            },
            openOrder: function (id) {
                var template = document.getElementById('order-detail-' + id);
                if (!template) {
                    return;
                }

                this.drawerTitle = '#' + id;
                this.drawerHtml = template.innerHTML;
                this.drawerOpen = true;
            },
            closeDrawer: function () {
                this.drawerOpen = false;
            },
            printDrawer: function () {
                var win = window.open('', '_blank', 'width=360,height=720');
                if (!win) {
                    return;
                }

                // The closing head/body/html tags below are escaped on purpose: a literal one inside this inline script makes tools that
                // inject before the LAST closing head tag of a page (dev browser-log scripts) break the markup and print this code.
                win.document.write('<html><head><title>' + this.drawerTitle + '</title><style nonce="{{ csp_nonce() }}">body{font-family:monospace;font-size:12px;width:280px;margin:0;padding:12px;color:CanvasText}h3,p{text-align:center;margin:4px 0}table{width:100%;border-collapse:collapse}td,th{padding:4px;text-align:start;border-bottom:1px dashed currentColor}</style><\/head><body><h3>' + config.restaurantName + '</h3><p>' + (config.restaurantAddress || '') + '</p>' + this.drawerHtml + '<p>' + config.thanksText + '</p><\/body><\/html>');
                win.document.close();
                win.focus();
                setTimeout(function () {
                    win.print();
                    win.close();
                }, 300);
            },
            switchLanguage: function (code) {
                document.cookie = 'Quick_user_lang_code=' + encodeURIComponent(code) + '; path=/';
                window.location.reload();
            }
        };
    };

    document.addEventListener('alpine:init', function () {
        Alpine.store('ordersPrefs', {
            sound: window.ordersPref.get('sound') === '1',
            autoPrint: window.ordersPref.get('autoprint') === '1',
            toggleSound() { this.sound = !this.sound; window.ordersPref.set('sound', this.sound ? '1' : '0'); },
            togglePrint() { this.autoPrint = !this.autoPrint; window.ordersPref.set('autoprint', this.autoPrint ? '1' : '0'); },
        });
    });

    (function () {
        var pollUrl = window.location.href;
        // The poll merges rows in place — new orders are prepended (with chime +
        // toast + optional auto-print), already-present rows are swapped if their
        // status changed — so it NEVER reloads the page (a reload used to eat
        // whatever the owner was typing in search/status controls).
        //
        // The endpoint answers this X-Requested-With request with JSON
        // { success, orders: [ "<article data-order-id…>…</article>", … ] } — the unseen
        // orders only, each a rendered order-row partial (it then marks them seen).
        // (An earlier rewrite parsed the response as a full HTML document and read
        // #order-rows from it — that element only exists on the NON-ajax page, so
        // the poll silently no-op'd. Consume the JSON row fragments instead.)
        async function poll() {
            try {
                var res = await fetch(pollUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                // Session expired → stop polling + reload to login (see LivePoll in the kit shell scripts).
                if (window.LivePoll && !LivePoll.guard(res)) { return; }
                if (!res.ok) { return; }
                var data = await res.json();
                if (!data || !data.success || !Array.isArray(data.orders) || !data.orders.length) { return; }
                var live = document.getElementById('order-rows');
                if (!live) { return; }
                // Each entry is one rendered order-row partial (an <article> card).
                var tplParse = document.createElement('template');
                tplParse.innerHTML = data.orders.join('');
                var fetchedRows = Array.prototype.slice.call(tplParse.content.querySelectorAll('[data-order-id]'));
                if (!fetchedRows.length) { return; }
                var liveIds = {};
                Array.prototype.forEach.call(live.querySelectorAll('[data-order-id]'), function (r) {
                    liveIds[r.getAttribute('data-order-id')] = r;
                });
                // First orders ever: drop the empty-state row before inserting.
                if (!Object.keys(liveIds).length) {
                    var emptyCard = live.querySelector('[data-orders-empty]');
                    if (emptyCard) { emptyCard.remove(); }
                }
                var newIds = [];
                var anchor = live.querySelector('[data-order-id]');
                fetchedRows.forEach(function (row) {
                    var id = row.getAttribute('data-order-id');
                    var current = liveIds[id];
                    if (current) {
                        // Swap only when the status changed elsewhere (POS/KDS) and
                        // the owner isn't mid-interaction inside that row.
                        if (current.getAttribute('data-status') !== row.getAttribute('data-status')
                            && !current.contains(document.activeElement)
                            && !current.querySelector('[open], .show, .is-open, [x-data][aria-expanded="true"]')) {
                            current.replaceWith(document.importNode(row, true));
                        }
                        return;
                    }
                    var node = document.importNode(row, true);
                    live.insertBefore(node, anchor);
                    // The row carries x-show/@click bindings; nudge Alpine to init the
                    // freshly-inserted subtree (its MutationObserver usually catches
                    // this, but initTree is idempotent and makes it deterministic).
                    if (window.Alpine && Alpine.initTree) { try { Alpine.initTree(node); } catch (e) {} }
                    newIds.push(id);
                });
                if (newIds.length) {
                    // A new order changes the live-orders KPI strip — refresh it.
                    if (window.ordersRefreshKpis) { window.ordersRefreshKpis(); }
                    var prefs = window.Alpine && Alpine.store ? Alpine.store('ordersPrefs') : null;
                    // N3: route through the shared NotifySounds engine instead of a raw
                    // `new Audio` — this order chime now honours the owner's per-device
                    // mute, volume and quiet-hours like every other dashboard sound. The
                    // page-local toggle stays as an explicit opt-in gate on top of that.
                    if (prefs && prefs.sound && window.NotifySounds) {
                        try { NotifySounds.play('order_new'); } catch (e) {}
                    }
                    // D1: a new order raises a toast instead of a silent state.
                    if (window.dashToast) { window.dashToast(@json(___('New order received')), { type: 'info' }); }
                    // P1-5: auto-print straight off the merged row's receipt template —
                    // no reload needed.
                    if (prefs && prefs.autoPrint && window.Alpine) {
                        var root = document.querySelector('[x-data^="ordersPage"]');
                        var comp = root ? Alpine.$data(root) : null;
                        if (comp) {
                            newIds.forEach(function (id) {
                                var tpl = document.getElementById('order-detail-' + id);
                                if (!tpl) { return; }
                                comp.drawerTitle = '#' + id;
                                comp.drawerHtml = tpl.innerHTML;
                                comp.printDrawer();
                            });
                        }
                    }
                }
            } catch (e) {}
        }
        setInterval(poll, 25000);
        // Live updates (Reverb, only when switched on): an order placed / changed in this store runs the same poll
        // right away. The 25s poll above stays as the fallback if the socket is off or drops.
        if (window.JuwriLiveWhenReady) { JuwriLiveWhenReady(function () { JuwriLive.listen({{ (int) $store->id }}, 'orders', 'order.changed', poll, {{ \Juwri\Core\Broadcasting\LiveUpdates::branchScope() ?? 'null' }}); }); }

        // Follow-up: refresh the live-orders KPI strip (Active / Pending / Completed /
        // Shown revenue) after an in-place mutation. The 25s poll hits request()->ajax()
        // (JSON row fragments, no strip), so this does its own PLAIN fetch — no
        // X-Requested-With — to get the full page whose strip is server-computed over
        // the entire filtered window (pagination + the canonical revenue total), then
        // copies the four values verbatim. Never reloads; leaves the search box alone.
        var kpiTimer = null;
        window.ordersRefreshKpis = function () {
            clearTimeout(kpiTimer);
            kpiTimer = setTimeout(function () {
                fetch(window.location.href, { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        Array.prototype.forEach.call(doc.querySelectorAll('[data-kpi]'), function (src) {
                            var key = src.getAttribute('data-kpi');
                            var dst = document.querySelector('[data-kpi="' + key + '"]');
                            if (dst && dst.textContent !== src.textContent) { dst.textContent = src.textContent; }
                        });
                    })
                    .catch(function () {});
            }, 350);
        };
    })();
</script>
@endpush
