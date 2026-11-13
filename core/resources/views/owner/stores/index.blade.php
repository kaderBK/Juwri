{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('All Stores'))
@section('page_title', ___('Your stores'))
@section('page_sub', ___('Manage menus, QR codes, ordering, and public links.'))

@php
    $owner = dashboard_owner();
    $isStaffDashboard = is_staff_dashboard();
    $stores = dashboard_stores();
    if ($stores->isNotEmpty() && method_exists($stores, 'load')) {
        $stores->load(['views', 'menu_categories']);
    }

    // PERF: precompute each card's three stats for EVERY store in a handful of
    // grouped queries, instead of ~4 queries per card inside the @forelse below.
    // The old per-row `Order::…->with(items.*)->get()` hydrated every line item of
    // the day once PER store; here today's orders load ONCE and are grouped in
    // PHP, so revenue still sums through the live ->total accessor (math unchanged).
    $rIds = $stores->pluck('id')->all();
    $menuCounts = $rIds
        ? \Juwri\Modules\Menu\Models\Menu::whereIn('store_id', $rIds)
            ->selectRaw('store_id, COUNT(*) as c')->groupBy('store_id')
            ->pluck('c', 'store_id')
        : collect();
    $scanCounts = $rIds
        ? \App\Models\StoreView::whereIn('store_id', $rIds)->whereDate('date', today())
            ->selectRaw('store_id, COUNT(*) as c')->groupBy('store_id')
            ->pluck('c', 'store_id')
        : collect();
    $ordersByStore = $rIds
        ? \App\Models\Order::whereIn('store_id', $rIds)->whereDate('created_at', today())
            ->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])
            ->get()->groupBy('store_id')
        : collect();

    $restaurantLimit = $owner ? effective_limit('restaurant', $owner) : 0;
    $usedSlots = $stores->count();
    $hasLimit = !\App\Services\Entitlements::isUnlimitedSentinel($restaurantLimit);
    $reachedLimit = $hasLimit && $usedSlots >= $restaurantLimit;
    // Edition limits (StoreLimitGuard): with no plan limit, say what the edition allows instead of "Unlimited".
    $editionLimits = app(\Juwri\Core\Editions\Limits\StoreLimitGuard::class)->limits();
    $editionLimitText = match (true) {
        $editionLimits->storesPerType !== null => ___('One store of each store type, up to :limit branches each', ['limit' => $editionLimits->branchesPerStore ?? 3]),
        $editionLimits->branchesTotal !== null => ___('Up to :limit branches in total', ['limit' => $editionLimits->branchesTotal]),
        default => null,
    };
    $primaryRestaurant = $stores->first();
@endphp

@section('page_actions')
    @if($isStaffDashboard)
        @if(dashboard_allows('orders') && $primaryRestaurant && Route::has('stores.orders'))
            <x-ui.button :href="route('stores.orders', $primaryRestaurant->id)" icon="orders">{{ ___('Orders') }}</x-ui.button>
        @endif
    @elseif($reachedLimit && Route::has('subscription'))
        <x-ui.button :href="route('subscription')" icon="sparkle">{{ ___('Manage plan') }}</x-ui.button>
    @elseif(Route::has('stores.create'))
        {{-- Owner app S5: a native nav-bar "+" in the app (bridge--button). --}}
        <x-ui.button :href="route('stores.create')" icon="plus" data-controller="bridge--button" data-bridge-title="{{ ___('Add store') }}" data-bridge-ios-image="plus" data-bridge-android-image="add">{{ ___('New store') }}</x-ui.button>
    @endif
@endsection

@section('content')

{{-- WI-A2: the expansion nudge. Set when a create was refused for hitting the
     store ceiling — the moment the owner most wants a way past it. --}}
@if (session()->has('spotlight_limit'))
    @php $__lim = session('spotlight_limit'); @endphp
    <x-spotlight.limit :type="$__lim['type'] ?? 'restaurant'"
                       :used="$__lim['used'] ?? null"
                       :limit="$__lim['limit'] ?? null" />
@endif

{{-- Clear confirmation after a successful create: which store, and what to do next. --}}
@if (session()->has('store_created'))
    @php
        $__created = session('store_created');
        // Ownership check: a session id for someone else's store renders nothing.
        $__createdStore = \Juwri\Core\Models\Store::where('user_id', auth()->id())->find((int) ($__created['id'] ?? 0));
    @endphp
    @if ($__createdStore)
        <x-ui.banner tone="success" class="ui-stores__created">
            <strong>{{ ___(':name is ready.', ['name' => $__createdStore->title]) }}</strong>
            <span>
                @if (($__created['items'] ?? 0) > 0)
                    {{ ___(':count menu items were imported from AI Studio. Review them, then share your store.', ['count' => $__created['items']]) }}
                @else
                    {{ ___('Next: add your menu items, then share your store link or QR code with customers.') }}
                @endif
            </span>
            <span class="ui-stores__created-actions">
                <x-ui.button :href="route('stores.menu', $__createdStore->id)" icon="list">{{ ___('Add menu items') }}</x-ui.button>
                @if (Route::has('publicView'))
                    <x-ui.button :href="route('publicView', $__createdStore->slug)" variant="secondary" target="_blank" rel="noopener">{{ ___('View store') }}</x-ui.button>
                @endif
                @if (Route::has('stores.qrDesigner'))
                    <x-ui.button :href="route('stores.qrDesigner', $__createdStore->id)" variant="secondary" icon="qr">{{ ___('Get QR code') }}</x-ui.button>
                @endif
            </span>
        </x-ui.banner>
    @endif
@endif

@php
    $registry = app(\App\Services\Verticals\VerticalRegistry::class);
    $vocab = app(\App\Services\Verticals\VerticalVocab::class);
    $host = config('app.url') ? parse_url(config('app.url'), PHP_URL_HOST) : 'juwri.com';
    $canEdit = !$isStaffDashboard || dashboard_allows('menu_edit');
@endphp

<div class="ui-scope ui-stores"
    x-data="storesPage({
        total: {{ $usedSlots }},
        token: @js(csrf_token()),
        {{-- Route templates keep any locale prefix (url('...') would drop it) --}}
        cloneUrlTemplate: @js(route('stores.clone', ['store' => '__ID__'])),
        menuUrlTemplate: @js(route('stores.menu', ['store' => '__ID__'])),
        emptyText: @js(___('No stores match your filters.')),
        showingText: @js(___('Showing :shown of :total stores'))
    })"
    x-init="refresh()"
>
    {{-- Toolbar: search, status, view — with the plan's store slots at the end. --}}
    <section class="ui-stores-bar" aria-label="{{ ___('Store filters') }}">
        <label class="ui-orders-search__field ui-stores-bar__search">
            <x-ui.icon name="search" size="18" />
            <span class="ui-visually-hidden">{{ ___('Search stores') }}</span>
            <input type="search" x-model.debounce.200ms="query" @input="refresh()" placeholder="{{ ___('Search by name or slug') }}">
        </label>

        <div class="ui-chips ui-periodbar__presets" role="group" aria-label="{{ ___('Filter by status') }}">
            <button type="button" class="ui-chip" :aria-current="status === 'all' ? 'true' : null" @click="status = 'all'; refresh()">{{ ___('All') }}</button>
            <button type="button" class="ui-chip" :aria-current="status === 'active' ? 'true' : null" @click="status = 'active'; refresh()">{{ ___('Active') }}</button>
            <button type="button" class="ui-chip" :aria-current="status === 'draft' ? 'true' : null" @click="status = 'draft'; refresh()">{{ ___('Draft') }}</button>
        </div>

        <div class="ui-chips ui-periodbar__presets ui-stores-bar__view" role="group" aria-label="{{ ___('View mode') }}">
            <button type="button" class="ui-chip" :aria-current="view === 'grid' ? 'true' : null" @click="view = 'grid'" aria-label="{{ ___('Grid view') }}"><x-ui.icon name="more" size="18" /></button>
            <button type="button" class="ui-chip" :aria-current="view === 'list' ? 'true' : null" @click="view = 'list'" aria-label="{{ ___('List view') }}"><x-ui.icon name="list" size="18" /></button>
        </div>

        <div class="ui-stores-bar__slots">
            <span class="ui-overline">{{ $isStaffDashboard ? ___('Assigned stores') : ___('Store slots') }}</span>
            @if($isStaffDashboard)
                <span class="ui-orders-muted">{{ ___('You can access the stores assigned to your staff account.') }}</span>
            @else
                <span class="ui-stores-bar__slots-row">
                    <strong class="ui-ltr">{{ number_format($usedSlots) }}@if($hasLimit || $editionLimitText === null) / {{ $hasLimit ? number_format($restaurantLimit) : ___('Unlimited') }}@endif</strong>
                    @if(! $hasLimit && $editionLimitText !== null)<span class="ui-orders-muted" data-edition-limits>{{ $editionLimitText }}</span>@endif
                    <span @class(['ui-ov-meter', 'is-unlimited' => !$hasLimit, 'is-exhausted' => $reachedLimit]) aria-hidden="true"><span style="inline-size: {{ $hasLimit ? min(100, round(($usedSlots / max(1, $restaurantLimit)) * 100)) : 100 }}%"></span></span>
                </span>
            @endif
        </div>
    </section>

    <p class="ui-orders-muted ui-stores-count" aria-live="polite" x-text="announcement"></p>

    <div class="ui-stores-grid" :data-view="view" data-view="grid" aria-label="{{ ___('Store cards') }}">
        @forelse($stores as $index => $store)
            @php
                $templateName = store_options($store->id, 'store_template', 'aurora');
                $menuCount = (int) ($menuCounts->get($store->id) ?? 0);
                $todayScans = (int) ($scanCounts->get($store->id) ?? 0);
                $todayOrders = $ordersByStore->get($store->id) ?? collect();
                $todayRevenue = $todayOrders->sum(fn ($order) => $order->total);
                $statusKey = $menuCount > 0 ? 'active' : 'draft';
                $statusLabel = $statusKey === 'active' ? ___('Active') : ___('Draft');
                $coverUrl = !empty($store->cover_image) && $store->cover_image !== 'default.png'
                    ? asset('storage/store/cover/'.$store->cover_image)
                    : null;
                $logoUrl = !empty($store->main_image) && $store->main_image !== 'default.png'
                    ? asset('storage/store/logo/'.$store->main_image)
                    : null;
                $searchText = mb_strtolower($store->title.' '.$store->slug);
                $cardId = 'restaurant-card-'.$store->id;
                $itemForm = $registry->itemForm($store->store_type ?? null);
                $itemsLabel = $registry->isFood($store->store_type ?? null) ? ___('Menu items') : $vocab->forStore($store, 'items');
            @endphp

            <div class="ui-stores-grid__cell"
                x-show="matches($el)"
                x-transition.opacity.duration.200ms
                data-restaurant-card
                data-status="{{ $statusKey }}"
                data-search="{{ $searchText }}"
            >
                <article id="{{ $cardId }}" class="ui-store" aria-labelledby="{{ $cardId }}-title">
                    <a class="ui-store__link" href="{{ Route::has('stores.edit') ? route('stores.edit', $store->id) : '#' }}" aria-label="{{ ___('Open store settings') }}"></a>

                    <div class="ui-store__cover" role="presentation">
                        @if($coverUrl)
                            <img src="{{ $coverUrl }}" alt="" loading="lazy">
                        @endif
                        <x-ui.pill :tone="$statusKey === 'active' ? 'success' : 'warning'" class="ui-store__status">{{ $statusLabel }}</x-ui.pill>
                        <div class="ui-store__quick">
                            @if(Route::has('stores.qrDesigner') && $canEdit)
                                <a class="ui-icon-btn ui-store__quick-btn" href="{{ route('stores.qrDesigner', $store->id) }}" aria-label="{{ ___('Open QR builder') }}" title="{{ ___('Open QR builder') }}"><x-ui.icon name="qr" size="18" /></a>
                            @endif
                            @if(Route::has('publicView'))
                                <a class="ui-icon-btn ui-store__quick-btn" href="{{ route('publicView', $store->slug) }}" target="_blank" rel="noopener" aria-label="{{ ___('View public menu') }}" title="{{ ___('View public menu') }}"><x-ui.icon name="external" size="18" /></a>
                            @endif
                            @unless($isStaffDashboard)
                                <button type="button" class="ui-icon-btn ui-store__quick-btn" aria-label="{{ ___('Clone store') }}" title="{{ ___('Clone store — menu, settings & tables') }}" @click.stop="cloneAsk({{ $store->id }}, @js($store->title))"><x-ui.icon name="share" size="18" /></button>
                            @endunless
                        </div>
                    </div>

                    <div class="ui-store__body">
                        <div class="ui-store__id">
                            <span class="ui-store__logo">
                                @if($logoUrl)
                                    <img src="{{ $logoUrl }}" alt="{{ $store->title }}" loading="lazy">
                                @else
                                    {{ \Illuminate\Support\Str::of($store->title)->substr(0, 1)->upper() }}
                                @endif
                            </span>
                            <div class="ui-store__names">
                                <h2 class="ui-store__title" id="{{ $cardId }}-title">{{ $store->title }}</h2>
                                <span class="ui-store__url ui-ltr">{{ $host }}/{{ $store->slug }}</span>
                            </div>
                        </div>

                        <dl class="ui-store__stats">
                            <div><dt>{{ ___('Scans') }}</dt><dd>{{ number_format($todayScans) }}</dd></div>
                            <div><dt>{{ ___('Orders') }}</dt><dd>{{ number_format($todayOrders->count()) }}</dd></div>
                            <div><dt>{{ ___('Revenue') }}</dt><dd>{{ price_symbol_format($todayRevenue) }}</dd></div>
                        </dl>

                        <p class="ui-store__meta">
                            <span>{{ ___('Template') }}: {{ ___(ucfirst((string) $templateName)) }}</span>
                            {{-- rev/multi-vertical: food keeps its exact legacy label
                                 (DECISIONS D4 — relabel, never rename); a non-food store
                                 gets its own vocab word (Products/Services/…). --}}
                            <span>{{ $itemsLabel }}: {{ number_format($menuCount) }}</span>
                        </p>

                        <div class="ui-store__actions">
                            @if($itemForm === 'retail' && Route::has('retail.products.index') && $canEdit)
                                {{-- rev/multi-vertical Lane A4: goods stores get the retail catalog
                                     dashboard instead of the food menu editor (itemForm()==='retail',
                                     not !isFood — laundry 'service' stores 404 on the retail form). --}}
                                <x-ui.button :href="route('retail.products.index', $store->id)" size="sm" icon="pencil">{{ $vocab->forStore($store, 'items') }}</x-ui.button>
                            @elseif($itemForm === 'service' && Route::has('laundry.services.index') && $canEdit)
                                {{-- rev/multi-vertical Lane D1: service stores (laundry) get
                                     their own service catalog dashboard. --}}
                                <x-ui.button :href="route('laundry.services.index', $store->id)" size="sm" icon="pencil">{{ $vocab->forStore($store, 'items') }}</x-ui.button>
                            @elseif(Route::has('stores.menu') && $canEdit)
                                <x-ui.button :href="route('stores.menu', $store->id)" size="sm" icon="pencil">{{ ___('Edit menu') }}</x-ui.button>
                            @elseif(Route::has('stores.orders') && dashboard_allows('orders'))
                                <x-ui.button :href="route('stores.orders', $store->id)" size="sm" icon="orders">{{ ___('Orders') }}</x-ui.button>
                            @endif

                            @if(Route::has('stores.edit') && $canEdit)
                                <x-ui.button :href="route('stores.edit', $store->id)" variant="secondary" size="sm" icon="settings">{{ ___('Settings') }}</x-ui.button>
                            @endif
                        </div>

                        <div class="ui-store__more">
                            @if(Route::has('stores.whatsappSettings') && $canEdit)
                                <a class="ui-chip" href="{{ route('stores.whatsappSettings', $store->id) }}"><x-ui.icon name="chat" size="16" />{{ ___('WhatsApp') }}</a>
                            @endif
                            @if(Route::has('stores.marketplaceSettings') && !$isStaffDashboard)
                                <a class="ui-chip" href="{{ route('stores.marketplaceSettings', $store->id) }}"><x-ui.icon name="store" size="16" />{{ ___('Marketplace') }}</a>
                            @endif
                            @if($registry->has($store->store_type, 'fitment') && Route::has('retail.fitments.index') && $canEdit)
                                {{-- rev/multi-vertical Lane E3: car-parts stores get a "Fitments"
                                     entry point into the vehicle-compatibility manager. --}}
                                <a class="ui-chip" href="{{ route('retail.fitments.index', $store->id) }}"><x-ui.icon name="delivery" size="16" />{{ ___('Fitments') }}</a>
                            @endif
                            @if(Route::has('pharmacy.compliance.index') && ($registry->has($store->store_type, 'age_gate') || $registry->has($store->store_type, 'expiry')) && $canEdit)
                                {{-- rev/multi-vertical Lane F1/F2: OTC-only compliance
                                     (age-gate + batch/expiry) for age_gate/expiry-capable
                                     verticals (pharmacy today). --}}
                                <a class="ui-chip" href="{{ route('pharmacy.compliance.index', $store->id) }}"><x-ui.icon name="check" size="16" />{{ ___('Compliance') }}</a>
                            @endif
                        </div>
                    </div>
                </article>
            </div>
        @empty
            <div class="ui-card ui-stores-grid__cell ui-stores-grid__cell--wide">
                <x-ui.empty :title="___('Create your first store')" :body="___('It takes 60 seconds. Pick a name, upload a logo, and you are live.')" icon="store">
                    @if(!$isStaffDashboard && Route::has('stores.create'))
                        <x-ui.button :href="route('stores.create')" icon="plus">{{ ___('Create store') }}</x-ui.button>
                    @endif
                </x-ui.empty>
            </div>
        @endforelse

        @if(!$isStaffDashboard && $stores->isNotEmpty())
            <div class="ui-stores-grid__cell">
                @if($reachedLimit && Route::has('subscription'))
                    <a href="{{ route('subscription') }}" class="ui-store-add" data-full>
                        <span class="ui-store-add__icon"><x-ui.icon name="sparkle" size="24" /></span>
                        <strong>{{ ___('Upgrade plan') }}</strong>
                        <span>{{ ___(':used of :limit slots used', ['used' => number_format($usedSlots), 'limit' => number_format($restaurantLimit)]) }}</span>
                    </a>
                @elseif(Route::has('stores.create'))
                    <a href="{{ route('stores.create') }}" class="ui-store-add">
                        <span class="ui-store-add__icon"><x-ui.icon name="plus" size="24" /></span>
                        <strong>{{ ___('Add store') }}</strong>
                        <span>
                            @if($hasLimit)
                                {{ ___(':used of :limit slots used', ['used' => number_format($usedSlots), 'limit' => number_format($restaurantLimit)]) }}
                            @elseif($editionLimitText !== null)
                                {{ $editionLimitText }}
                            @else
                                {{ ___('Unlimited store slots') }}
                            @endif
                        </span>
                    </a>
                @endif
            </div>
        @endif
    </div>

    <div class="ui-card" x-show="visible === 0 && total > 0" x-cloak>
        <x-ui.empty :title="___('No stores found')" :body="___('Try another search term or switch back to all statuses.')" icon="search" />
    </div>

    {{-- Clone store dialog: confirm → progress → result --}}
    <dialog class="ui-sheet" x-ref="cloneDialog" aria-labelledby="clone-dialog-title" data-ui-persistent @cancel.prevent="cloneClose()">
        <div class="ui-sheet__handle" aria-hidden="true"></div>
        <h2 class="ui-sheet__title" id="clone-dialog-title">{{ ___('Clone store') }}</h2>
        <div class="ui-sheet__body">
            <template x-if="cloneState === 'confirm'">
                <p class="ui-sheet__desc">
                    {{-- rev/multi-vertical: the clone dialog is one shared markup block
                         reused for every store card regardless of vertical (cloneTarget is
                         set client-side on click), so this copy stays vertical-neutral
                         rather than naming food-only nouns (menu/tables). --}}
                    {{ ___('This will create a full copy of') }} <strong x-text="cloneTarget.title"></strong> —
                    {{ ___('its catalog, settings and everything configured within it (categories, items, options and extras).') }}
                </p>
            </template>
            <template x-if="cloneState === 'busy'">
                <p class="ui-sheet__desc ui-stores-clone__busy" aria-live="polite">
                    <span class="ui-menu-progress__dot" aria-hidden="true"></span>
                    {{ ___('Cloning your store… this can take a few seconds for large menus.') }}
                </p>
            </template>
            <template x-if="cloneState === 'done'">
                <div>
                    <x-ui.banner tone="success">{{ ___('Store cloned successfully.') }}</x-ui.banner>
                    <p class="ui-sheet__desc" x-text="cloneSummary()"></p>
                </div>
            </template>
            <template x-if="cloneState === 'error'">
                <x-ui.banner tone="danger"><span x-text="cloneError" aria-live="polite"></span></x-ui.banner>
            </template>
        </div>
        <div class="ui-sheet__foot">
            <template x-if="cloneState === 'confirm'">
                <div class="ui-stores-clone__buttons">
                    <button type="button" class="ui-btn ui-btn--secondary" @click="cloneClose()">{{ ___('Cancel') }}</button>
                    <button type="button" class="ui-btn" @click="cloneRun()"><span class="ui-btn__icon"><x-ui.icon name="share" /></span>{{ ___('Clone store') }}</button>
                </div>
            </template>
            <template x-if="cloneState === 'done'">
                <div class="ui-stores-clone__buttons">
                    <button type="button" class="ui-btn ui-btn--secondary" @click="cloneClose()">{{ ___('Close') }}</button>
                    <button type="button" class="ui-btn" @click="cloneOpenNew()">{{ ___('Open the copy') }}</button>
                </div>
            </template>
            <template x-if="cloneState === 'error'">
                <button type="button" class="ui-btn ui-btn--secondary" @click="cloneClose()">{{ ___('Close') }}</button>
            </template>
        </div>
    </dialog>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.storesPage = function (config) {
        return {
            query: '',
            status: 'all',
            view: 'grid',
            visible: config.total || 0,
            total: config.total || 0,
            announcement: '',
            matches: function (card) {
                var text = card.dataset.search || '';
                var state = card.dataset.status || 'draft';
                var query = this.query.trim().toLowerCase();
                var passesQuery = !query || text.indexOf(query) !== -1;
                var passesStatus = this.status === 'all' || state === this.status;

                return passesQuery && passesStatus;
            },
            refresh: function () {
                var cards = Array.prototype.slice.call(document.querySelectorAll('[data-restaurant-card]'));
                var count = 0;

                cards.forEach(function (card) {
                    if (this.matches(card)) {
                        count += 1;
                    }
                }, this);

                this.visible = count;
                this.announcement = (config.showingText || '').replace(':shown', count).replace(':total', this.total);
            },
            cloneTarget: { id: null, title: '' },
            cloneState: 'confirm', // confirm | busy | done | error
            cloneResult: null,
            cloneError: '',
            cloneAsk: function (id, title) {
                this.cloneTarget = { id: id, title: title };
                this.cloneState = 'confirm';
                this.cloneResult = null;
                this.cloneError = '';
                this.$refs.cloneDialog.showModal();
            },
            cloneClose: function () {
                if (this.cloneState === 'busy') return; // don't abandon mid-clone
                this.$refs.cloneDialog.close();
                if (this.cloneState === 'done') window.location.reload();
            },
            cloneSummary: function () {
                var c = (this.cloneResult && this.cloneResult.counts) || {};
                return @js(___('Copied :categories categories, :items items, :modifiers modifier groups, :extras extras, :tables tables.'))
                    .replace(':categories', c.categories || 0)
                    .replace(':items', c.items || 0)
                    .replace(':modifiers', c.modifiers || 0)
                    .replace(':extras', c.extras || 0)
                    .replace(':tables', c.tables || 0);
            },
            cloneOpenNew: function () {
                if (this.cloneResult && this.cloneResult.id) {
                    window.location.href = config.menuUrlTemplate.replace('__ID__', this.cloneResult.id);
                }
            },
            cloneRun: function () {
                if (!this.cloneTarget.id || this.cloneState === 'busy') return;
                var self = this;
                this.cloneState = 'busy';

                fetch(config.cloneUrlTemplate.replace('__ID__', this.cloneTarget.id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': config.token
                    },
                    body: JSON.stringify({})
                }).then(function (response) {
                    return response.json().then(function (data) { return { ok: response.ok, data: data }; });
                }).then(function (r) {
                    if (r.ok && r.data.success) {
                        self.cloneResult = r.data;
                        self.cloneState = 'done';
                    } else {
                        self.cloneError = r.data.error || r.data.message || @js(___('Clone failed'));
                        self.cloneState = 'error';
                    }
                }).catch(function () {
                    self.cloneError = @js(___('Clone failed'));
                    self.cloneState = 'error';
                });
            }
        };
    };
</script>
@endpush
