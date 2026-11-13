{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Apps'))
@section('page_title', ___('Apps'))
@section('page_sub', ___('Third-party apps that connect to your account through scoped, revocable access.'))

{{-- Redesign (research-backed): installed apps are an INVENTORY → row list with
     icon, status, billing, a view/edit permission one-liner and inline icon
     actions (Shopify/Slack/Stripe management pattern); discovery is a separate
     card-grid section below a divider, never interleaved. Uninstall = simple
     dialog with consequence copy (no type-to-confirm). --}}
@section('content')
@php
    /* "Can view: X · Can edit: Y" summary — friendly resource names derived
       from scope keys; the full labeled list lives on the store detail page. */
    $scopeSummary = function ($app) {
        $view = []; $edit = []; $other = [];
        foreach (($app->scopes ?? []) as $s) {
            $res = ___(ucfirst(str_replace('_', ' ', explode(':', $s)[0])));
            if (str_ends_with($s, ':read')) { $view[] = $res; }
            elseif (str_ends_with($s, ':write')) { $edit[] = $res; }
            else { $other[] = $res; }
        }
        $parts = [];
        if ($view) { $parts[] = ___('Can view: :list', ['list' => implode(', ', array_unique($view))]); }
        if ($edit) { $parts[] = ___('Can edit: :list', ['list' => implode(', ', array_unique($edit))]); }
        if ($other) { $parts[] = implode(', ', array_unique($other)); }
        return $parts ? implode(' · ', $parts) : ___('No account access requested.');
    };

    /* Same data as bullet lines for the pre-install consent dialog. */
    $scopeLines = function ($app) {
        $view = []; $edit = []; $other = [];
        foreach (($app->scopes ?? []) as $s) {
            $res = ___(ucfirst(str_replace('_', ' ', explode(':', $s)[0])));
            if (str_ends_with($s, ':read')) { $view[] = $res; }
            elseif (str_ends_with($s, ':write')) { $edit[] = $res; }
            else { $other[] = $res; }
        }
        $lines = [];
        if ($view) { $lines[] = ___('Can view: :list', ['list' => implode(', ', array_unique($view))]); }
        if ($edit) { $lines[] = ___('Can edit: :list', ['list' => implode(', ', array_unique($edit))]); }
        if ($other) { $lines[] = implode(', ', array_unique($other)); }
        return $lines ?: [___('No account access requested.')];
    };

    $activeInstalls = $installations->filter(fn ($i) => in_array($i->status, ['active', 'suspended'], true));
    $installedApps = $apps->filter(fn ($a) => $activeInstalls->has($a->id));
    $discoverApps = $apps->reject(fn ($a) => $activeInstalls->has($a->id));
@endphp

<div class="ui-scope ui-apps" x-data="appsPage()">
    <x-ui.card class="ui-apps__intro">
        <div class="ui-apps__intro-text">
            <p class="ui-overline">{{ ___('Platform') }}</p>
            <h2 class="ui-apps__intro-title">{{ ___('Installed apps') }}</h2>
            <p class="ui-apps__intro-sub">{{ ___('Install to grant an app its listed permissions. Uninstall revokes its access immediately.') }}</p>
        </div>
        <x-ui.button :href="route('user.store.apps')" icon="store">{{ ___('Browse the App Store') }}</x-ui.button>
    </x-ui.card>

    {{-- ============================== Installed (inventory rows) ============ --}}
    @if($installedApps->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="sparkle" :title="___('Extend your store with apps')"
                        :body="___('You have not installed any apps yet. Apps add ordering, marketing, delivery and analytics superpowers — install one and it appears here with its access and billing.')">
                <x-ui.button :href="route('user.store.apps')">{{ ___('Explore the App Store') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <x-ui.card :title="___('Installed')" variant="flush">
            <ul class="ui-apps__list">
                @foreach($installedApps as $app)
                    @php
                        $inst = $activeInstalls->get($app->id);
                        $suspended = $inst->status === 'suspended';
                    @endphp
                    <li class="ui-apps__item">
                        <span class="ui-apps__icon">
                            @if($app->icon)<img src="{{ $app->icon->url() }}" alt="" width="46" height="46">
                            @else{{ mb_substr($app->name, 0, 1) }}@endif
                        </span>
                        <div class="ui-apps__body">
                            <div class="ui-apps__title-row">
                                <a class="ui-apps__name" href="{{ route('user.store.apps.show', $app->slug) }}">{{ $app->name }}</a>
                                @if($suspended)
                                    <x-ui.pill tone="warning">{{ ___('Needs attention') }}</x-ui.pill>
                                @else
                                    <x-ui.pill tone="success">{{ ___('Installed') }}</x-ui.pill>
                                @endif
                                <x-ui.tag>{{ $app->pricingSummary() }}</x-ui.tag>
                            </div>
                            @if($app->tagline)<p class="ui-apps__sub">{{ $app->tagline }}</p>@endif
                            <p class="ui-apps__sub">{{ $scopeSummary($app) }}</p>
                            @if($suspended)
                                <p class="ui-apps__sub ui-apps__sub--danger">{{ ___('Renewal payment failed — top up your wallet to reactivate.') }}</p>
                            @elseif($app->billing_type === 'recurring' && $inst->current_period_ends_at)
                                <p class="ui-apps__sub">{{ ___('Renews on :date', ['date' => $inst->current_period_ends_at->format('Y-m-d')]) }}</p>
                            @endif
                        </div>
                        <div class="ui-apps__actions">
                            @if($suspended && Route::has('wallet.topup'))
                                <x-ui.button :href="route('wallet.topup')" size="sm" icon="wallet">{{ ___('Top up wallet') }}</x-ui.button>
                            @elseif($app->embed_url)
                                <x-ui.button :href="route('apps.embed', $app->id)" size="sm" variant="secondary" icon="external">{{ ___('Open') }}</x-ui.button>
                            @endif
                            <a class="ui-icon-btn" href="{{ route('user.store.apps.show', $app->slug) }}" aria-label="{{ ___('View in App Store') }}" title="{{ ___('View in App Store') }}"><x-ui.icon name="store" /></a>
                            @if($app->support_url)<a class="ui-icon-btn" href="{{ $app->support_url }}" target="_blank" rel="noopener" aria-label="{{ ___('Get support') }}" title="{{ ___('Get support') }}"><x-ui.icon name="chat" /></a>@endif
                            @if($app->docs_url)<a class="ui-icon-btn" href="{{ $app->docs_url }}" target="_blank" rel="noopener" aria-label="{{ ___('Documentation') }}" title="{{ ___('Documentation') }}"><x-ui.icon name="book-open" /></a>@endif
                            <button type="button" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Uninstall') }}" title="{{ ___('Uninstall') }}" @click="askUninstall({{ $app->id }}, @js($app->name), @js($app->billing_type !== 'free'))"><x-ui.icon name="trash" /></button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- ============================== Discover (card grid) ================= --}}
    @if($discoverApps->isNotEmpty())
        <div class="ui-apps__section-head">
            <h2 class="ui-apps__section-title">{{ ___('Discover more apps') }}</h2>
            <a class="ui-apps__link" href="{{ route('user.store.apps') }}">{{ ___('See all in the App Store') }} <x-ui.icon name="chevron" size="16" flip /></a>
        </div>
        <div class="ui-apps__grid">
            @foreach($discoverApps as $app)
                <article class="ui-card ui-apps__card">
                    <div class="ui-apps__card-head">
                        <span class="ui-apps__icon">
                            @if($app->icon)<img src="{{ $app->icon->url() }}" alt="" width="46" height="46">
                            @else{{ mb_substr($app->name, 0, 1) }}@endif
                        </span>
                        <div class="ui-apps__body">
                            <h3 class="ui-apps__card-name">{{ $app->name }}</h3>
                            <p class="ui-apps__sub ui-apps__clamp">{{ $app->tagline }}</p>
                        </div>
                    </div>
                    <div class="ui-apps__meta">
                        <x-ui.tag>{{ $app->pricingSummary() }}</x-ui.tag>
                        @if((int) $app->rating_count > 0)
                            <span class="ui-apps__rating"><span class="ui-apps__star" aria-hidden="true">★</span> <span class="ui-ltr">{{ number_format($app->rating_avg, 1) }} ({{ $app->rating_count }})</span></span>
                        @endif
                    </div>
                    <div class="ui-apps__card-actions">
                        {{-- Plain <button> (not a Blade component): the @js directive is not
                             compiled inside component attributes, so the args stay literal. --}}
                        <button type="button" class="ui-btn ui-btn--sm" @click="askInstall({{ $app->id }}, @js($app->name), @js($scopeLines($app)), @js(! $app->isFreeBilling()), @js($app->pricingSummary()))">{{ ___('Install') }}</button>
                        <x-ui.button variant="ghost" size="sm" :href="route('user.store.apps.show', $app->slug)">{{ ___('Details') }}</x-ui.button>
                    </div>
                </article>
            @endforeach
        </div>
    @elseif($apps->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('No apps are available yet.')">
                <x-ui.button variant="secondary" :href="route('user.store.apps')">{{ ___('Browse the App Store') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @endif

    {{-- Install consent: show the scopes being granted BEFORE installing
         (research pattern — consent at install, Shopify/Stripe). --}}
    <x-ui.sheet id="apps-install" :title="___('Install app')" x-ref="inDialog" @keydown.escape.window="$refs.inDialog.open && $refs.inDialog.close()">
        <p class="ui-apps__sheet-lead"><strong x-text="pendingInstall.name"></strong> {{ ___('will be granted the following access:') }}</p>
        <ul class="ui-apps__perms">
            <template x-for="line in pendingInstall.perms" :key="line">
                <li><x-ui.icon name="check" size="18" /><span x-text="line"></span></li>
            </template>
        </ul>
        <p class="ui-apps__sub" x-show="pendingInstall.paid">{{ ___('Paid app — charges apply per its pricing once installed.') }} (<span x-text="pendingInstall.price"></span>)</p>
        <p class="ui-apps__sub ui-apps__sub--danger" x-show="installMsg" x-text="installMsg"></p>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="$refs.inDialog.close()">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button @click="confirmInstall()" x-bind:disabled="busy">
                <span x-show="!busy">{{ ___('Install app') }}</span><span x-show="busy">{{ ___('Installing…') }}</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>

    {{-- Uninstall confirm: simple dialog + consequence copy (no type-to-confirm). --}}
    <x-ui.sheet id="apps-uninstall" :title="___('Uninstall app')" x-ref="unDialog" @keydown.escape.window="$refs.unDialog.open && $refs.unDialog.close()">
        <p class="ui-apps__sheet-lead">{{ ___('Uninstall') }} <strong x-text="pending.name"></strong>?</p>
        <ul class="ui-apps__consequences">
            <li>{{ ___('Its access to your account is revoked immediately.') }}</li>
            <li x-show="pending.paid">{{ ___('Recurring billing stops — no further charges after the current period.') }}</li>
            <li>{{ ___('Reinstalling later may not restore the app\'s previous settings.') }}</li>
        </ul>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="$refs.unDialog.close()">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" @click="confirmUninstall()" x-bind:disabled="busy">{{ ___('Uninstall') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
function appsPage() {
    return {
        pending: { id: null, name: '', paid: false },
        pendingInstall: { id: null, name: '', perms: [], paid: false, price: '' },
        installMsg: '',
        busy: false,
        askInstall(id, name, perms, paid, price) {
            this.pendingInstall = { id, name, perms, paid, price };
            this.installMsg = '';
            this.$refs.inDialog.showModal();
        },
        async confirmInstall() {
            if (!this.pendingInstall.id || this.busy) return;
            this.busy = true;
            this.installMsg = '';
            try {
                const r = await fetch(`{{ url('apps') }}/${this.pendingInstall.id}/install`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', Accept: 'application/json' },
                });
                const d = await r.json();
                if (r.ok && d.ok) { location.reload(); return; }
                this.installMsg = d.message || '{{ ___('Could not install this app.') }}';
            } catch (e) {
                this.installMsg = '{{ ___('Something went wrong. Please try again.') }}';
            }
            this.busy = false;
        },
        askUninstall(id, name, paid) {
            this.pending = { id, name, paid };
            this.$refs.unDialog.showModal();
        },
        async confirmUninstall() {
            if (!this.pending.id || this.busy) return;
            this.busy = true;
            const r = await fetch(`{{ url('apps') }}/${this.pending.id}/uninstall`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', Accept: 'application/json' },
            });
            if ((await r.json()).ok) location.reload();
            this.busy = false;
            this.$refs.unDialog.close();
        },
    };
}
</script>
@endpush
@endsection
