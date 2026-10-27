{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Custom domains'))
@section('page_title', ___('Custom domains'))
@section('page_sub', ___('Serve your menu on your own address, for example menu.yourbrand.com.'))

@section('content')
<div class="ui-scope ui-domains" x-data="domainsPage({ copiedText: @js(___('Copied')) })">

    @if($stores->count() > 1)
        <div class="ui-domains__store">
            <label class="ui-label" for="domains-store">{{ ___('Store') }}</label>
            <select id="domains-store" class="ui-input" data-ui-do="nav-value">
                <option value="">{{ ___('Select store…') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ route('stores.domains.index', $r->id) }}" @selected($store && $store->id == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(!$store)
        <div class="ui-card"><x-ui.empty :title="___('Pick a store to manage its custom domains.')" icon="store" /></div>
    @else
        @php
            $subdomain = $domains->firstWhere('type', 'subdomain');
            $domains = $domains->where('type', '!=', 'subdomain')->values();
            $subSuffix = '.'.\Juwri\Core\Http\Hosts\HostMap::storefrontHost();
        @endphp

        <x-ui.card :title="___('Platform subdomain')">
            @if($subdomain)
                <p class="ui-domains__hint">{{ ___('Your menu also answers on this address. It is live at once and needs no DNS setup.') }}</p>
                <div class="ui-domains__head">
                    <strong class="ui-domains__name ui-ltr">{{ $subdomain->host }}</strong>
                    @if($subdomain->is_primary)<x-ui.pill tone="accent">{{ ___('Main address') }}</x-ui.pill>@endif
                </div>
                <footer class="ui-domains__actions">
                    <form method="post" action="{{ route('stores.domains.destroy', [$store->id, $subdomain->id]) }}"
                          data-confirm="{{ ___('Release this subdomain? It will stop serving your menu immediately and anyone can claim the name.') }}" data-confirm-danger>
                        @csrf @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Release subdomain') }}</x-ui.button>
                    </form>
                    @unless($subdomain->is_primary)
                        <form method="post" action="{{ route('stores.domains.primary', [$store->id, $subdomain->id]) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Make main address') }}</x-ui.button>
                        </form>
                    @endunless
                </footer>
            @else
                <p class="ui-domains__hint">{{ ___('Claim a free name on our domain, for example yourbrand. One per store; some names are reserved.') }}</p>
                <form method="post" action="{{ route('stores.domains.subdomain', $store->id) }}" class="ui-domains__add">
                    @csrf
                    <div class="ui-domains__field">
                        <label class="ui-label" for="subdomain-input">{{ ___('Subdomain') }}</label>
                        <input id="subdomain-input" class="ui-input ui-ltr @error('subdomain') ui-input--error @enderror" name="subdomain" required maxlength="63"
                               value="{{ old('subdomain') }}" placeholder="yourbrand"
                               autocomplete="off" autocapitalize="off" spellcheck="false">
                        <div class="ui-field__help ui-ltr">{{ $subSuffix }}</div>
                    </div>
                    <x-ui.button type="submit" icon="plus">{{ ___('Claim subdomain') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>

        <x-ui.card :title="___('Add a domain')">
            <p class="ui-domains__hint">
                {{ ___('We recommend a subdomain such as menu.yourbrand.com — most DNS providers cannot add a CNAME on a bare domain.') }}
            </p>
            <form method="post" action="{{ route('stores.domains.store', $store->id) }}" class="ui-domains__add">
                @csrf
                <div class="ui-domains__field">
                    <label class="ui-label" for="domain-input">{{ ___('Domain') }}</label>
                    <input id="domain-input" class="ui-input ui-ltr @error('domain') ui-input--error @enderror" name="domain" required
                           value="{{ old('domain') }}" placeholder="menu.yourbrand.com"
                           autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="url">
                    @error('domain')<div class="ui-field__help ui-text-danger">{{ ___('Enter just the domain name, without https:// or a trailing slash.') }}</div>@enderror
                </div>
                <x-ui.button type="submit" icon="plus">{{ ___('Add domain') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Your domains')">
            <x-slot:actions><x-ui.tag>{{ $domains->count() }}</x-ui.tag></x-slot:actions>

            @if($domains->isEmpty())
                <x-ui.empty :title="___('No custom domains yet. Add one above.')" icon="external" />
            @else
                @php
                    $sslLabels = [
                        'pending' => ___('SSL pending'),
                        'active'  => ___('SSL active'),
                    ];
                @endphp
                <div class="ui-domains__list">
                    @foreach ($domains as $d)
                        <article class="ui-domains__item">
                            <header class="ui-domains__head">
                                <span class="ui-domains__icon" aria-hidden="true"><x-ui.icon name="external" size="18" /></span>
                                <strong class="ui-domains__name ui-ltr">{{ $d->domain }}</strong>
                                <span class="ui-domains__pills">
                                    @if($d->verified)
                                        <x-ui.pill tone="success">{{ ___('Verified') }}</x-ui.pill>
                                    @else
                                        <x-ui.pill tone="warning">{{ ___('Pending') }}</x-ui.pill>
                                    @endif
                                    <x-ui.pill :tone="$d->ssl_status === 'active' ? 'accent' : 'neutral'">{{ $sslLabels[$d->ssl_status] ?? $d->ssl_status }}</x-ui.pill>
                                    @if($d->is_primary && $d->verified)
                                        <x-ui.pill tone="accent">{{ ___('Main address') }}</x-ui.pill>
                                    @endif
                                </span>
                            </header>

                            @if($d->verified)
                                <x-ui.banner tone="success">
                                    {{ ___('Verified! Your menu will serve on this domain.') }}
                                    {{ ___('HTTPS is issued automatically — you do not need to buy a certificate.') }}
                                </x-ui.banner>
                            @else
                                <p class="ui-domains__hint">
                                    {{ ___('Add these records at your DNS provider, then click Verify. DNS changes usually take effect within a few minutes.') }}
                                </p>

                                <dl class="ui-domains__records">
                                    {{-- BL-036 R2: the TXT proof goes on the challenge name (it can sit next to the CNAME). --}}
                                    @foreach ([['CNAME', $target, $d->domain], ['TXT', $d->verification_token, \Juwri\Modules\Storefront\Services\DomainVerifier::CHALLENGE_LABEL.'.'.$d->domain]] as [$recordType, $recordValue, $recordHost])
                                        <div class="ui-domains__record">
                                            <dt><x-ui.tag>{{ $recordType }}</x-ui.tag></dt>
                                            <dd>
                                                <span class="ui-domains__kv"><span>{{ ___('Host / Name') }}</span><code class="ui-ltr">{{ $recordHost }}</code></span>
                                                <span class="ui-domains__kv"><span>{{ ___('Value / Points to') }}</span><code class="ui-ltr">{{ $recordValue }}</code></span>
                                            </dd>
                                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-domains__copy" @click="copy(@js($recordValue), $event)">
                                                {{ ___('Copy') }}
                                            </button>
                                        </div>
                                    @endforeach
                                </dl>

                                <p class="ui-domains__hint">
                                    {{ ___('Some providers want only the subdomain label in the Host field (for example "menu"). The CNAME is what routes visitors to your menu; the TXT record only proves ownership.') }}
                                </p>
                            @endif

                            <footer class="ui-domains__actions">
                                <form method="post" action="{{ route('stores.domains.destroy', [$store->id, $d->id]) }}"
                                      data-confirm="{{ ___('Remove this domain? It will stop serving your menu immediately.') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Remove') }}</x-ui.button>
                                </form>
                                {{-- P1-20: the other addresses of the store redirect (301) to the main one. --}}
                                @if($d->verified && ! $d->is_primary)
                                    <form method="post" action="{{ route('stores.domains.primary', [$store->id, $d->id]) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Make main address') }}</x-ui.button>
                                    </form>
                                @endif
                                @unless($d->verified)
                                    <form method="post" action="{{ route('stores.domains.verify', [$store->id, $d->id]) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" icon="check">{{ ___('Verify') }}</x-ui.button>
                                    </form>
                                @endunless
                            </footer>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card :title="___('How it works')" variant="inset">
            <ol class="ui-push__steps">
                <li>{{ ___('Add your domain above.') }}</li>
                <li>{{ ___('Create the TXT record shown next to it (it proves you own the domain) and the CNAME record that routes visitors to your menu, both at your DNS provider.') }}</li>
                <li>{{ ___('Click Verify. Once the record is found, your menu is live on that domain over HTTPS.') }}</li>
            </ol>
            <p class="ui-domains__hint">
                {{ ___('Your existing slug link and QR codes keep working — adding a domain does not break them.') }}
            </p>
        </x-ui.card>
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.domainsPage = function (config) {
        return {
            copy(value, e) {
                var done = function () {
                    if (window.dashToast) window.dashToast('success', config.copiedText);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(done).catch(function () {});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = value; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); done(); } catch (err) {}
                    document.body.removeChild(ta);
                }
            },
        };
    };
</script>
@endpush
@endsection
