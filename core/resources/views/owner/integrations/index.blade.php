{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Integrations & API Keys'))
@section('page_title', ___('Integrations & API Keys'))
@section('page_sub', ___('Connect AI, messaging, and storage providers per store. Keys are stored encrypted and never shown back in full.'))

@section('content')
@php
    $getSecret = function ($feature, $provider, $key) use ($credentials) {
        $cred = $credentials->get($feature.'_'.$provider);
        if ($cred && isset($cred->secrets[$key])) {
            $val = $cred->secrets[$key];
            return strlen($val) > 4 ? '••••••••'.substr($val, -4) : '••••••••';
        }
        return '';
    };
    $rawSecret = function ($feature, $provider, $key) use ($credentials) {
        $cred = $credentials->get($feature.'_'.$provider);
        return $cred->secrets[$key] ?? '';
    };
    $isActive = function ($feature, $provider) use ($credentials) {
        $cred = $credentials->get($feature.'_'.$provider);
        return $cred ? (bool) $cred->active : false;
    };
    $selectedRestaurant = $stores->firstWhere('id', $selectedRestaurantId);
    $configuredCount = $credentials ? collect($credentials)->filter(fn ($c) => $c->active)->count() : 0;

    /* One definition per provider card; the markup below renders them all the
       same way. Field tuple: [secret key, label, input type, placeholder,
       masked (getSecret) or raw (rawSecret), width: full|half|third].
       Field names, provider/feature values, ids and data-test-conn hooks are
       exactly those of the hand-written cards this replaced. */
    $groups = [
        ___('AI providers') => [
            ['feature' => 'ai', 'provider' => 'openai', 'title' => 'OpenAI', 'icon' => 'sparkle', 'desc' => ___('GPT suite for menu copy, translations, and recommendations.'), 'test' => ___('Test connection'),
             'fields' => [['api_key', ___('API key'), 'password', 'sk-...', true, 'full']]],
            ['feature' => 'ai', 'provider' => 'gemini', 'title' => 'Google Gemini', 'icon' => 'sparkle', 'desc' => ___('Gemini for menu extraction (photos, handwriting) and PDF-styled design.'), 'test' => ___('Test connection'),
             'fields' => [['api_key', ___('API key'), 'password', 'AIza...', true, 'full']]],
            ['feature' => 'ai', 'provider' => 'anthropic', 'title' => 'Anthropic Claude', 'icon' => 'sparkle', 'desc' => ___('Default copilot for menu intelligence and customer assistance.'), 'test' => ___('Test connection'),
             'fields' => [['api_key', ___('API key'), 'password', 'sk-ant-...', true, 'full']]],
            // MiMo / Kimi / DeepSeek (OpenAI-compatible AI providers, #103)
            ['feature' => 'ai', 'provider' => 'mimo', 'title' => 'Xiaomi MiMo', 'icon' => 'sparkle', 'desc' => ___('Default AI provider for menu generation, translation, and copilot.'), 'test' => ___('Test connection'),
             'fields' => [['api_key', ___('API key'), 'password', '', true, 'half'], ['model', ___('Model (optional)'), 'text', '', false, 'half']]],
            ['feature' => 'ai', 'provider' => 'kimi', 'title' => 'Kimi (Moonshot)', 'icon' => 'sparkle', 'desc' => ___('Fallback AI provider — long context, vision.'), 'test' => ___('Test connection'),
             'fields' => [['api_key', ___('API key'), 'password', '', true, 'half'], ['model', ___('Model (optional)'), 'text', '', false, 'half']]],
            ['feature' => 'ai', 'provider' => 'deepseek', 'title' => 'DeepSeek', 'icon' => 'sparkle', 'desc' => ___('Alternate AI provider.'), 'test' => ___('Test connection'),
             'fields' => [['api_key', ___('API key'), 'password', '', true, 'half'], ['model', ___('Model (optional)'), 'text', '', false, 'half']]],
        ],
        ___('SMS') => [
            ['feature' => 'sms', 'provider' => 'twilio', 'title' => 'Twilio SMS', 'icon' => 'chat', 'desc' => ___('Order, reservation, and campaign SMS delivery.'), 'test' => ___('Test connection'),
             'fields' => [['sid', ___('Account SID'), 'text', 'AC...', true, 'third'], ['auth_token', ___('Auth token'), 'password', 'token', true, 'third'], ['from_number', ___('From number'), 'text', '+1 555 010 0000', false, 'third']]],
            ['feature' => 'sms', 'provider' => 'msegat', 'title' => 'Msegat SMS', 'icon' => 'chat', 'desc' => ___('Saudi SMS gateway (Arabic native). Register a CST/Jaber sender name first.'), 'test' => ___('Test connection'),
             'fields' => [['username', ___('Username'), 'text', 'msegat user', true, 'third'], ['api_key', ___('API key'), 'password', 'api key', true, 'third'], ['sender', ___('Sender name'), 'text', 'juwri', false, 'third']],
             'doc' => [route('integrations.docs', 'msegat-sender-registration'), ___('Sender-name registration guide (CST/Jaber)')]],
        ],
        // Accounting: QuickBooks / Xero (#98)
        ___('Accounting') => [
            ['feature' => 'accounting', 'provider' => 'quickbooks', 'title' => ___('Accounting (QuickBooks / Xero)'), 'icon' => 'wallet', 'desc' => ___('Export paid orders as invoices to your accounting provider.'), 'test' => null, 'enable' => ___('Enable'),
             'fields' => [['access_token', ___('Access token'), 'password', '', true, 'half'], ['base_url', ___('API base URL'), 'text', 'https://...', false, 'half']]],
        ],
        ___('Other') => [
            ['feature' => 'storage', 'provider' => 's3', 'title' => 'S3-compatible storage', 'icon' => 'upload', 'desc' => ___('Offload menu images and CSVs to S3, R2, Spaces, or any compatible bucket.'), 'test' => ___('Test configuration'),
             'fields' => [['key', ___('Access key'), 'text', 'AKIA...', true, 'half'], ['secret', ___('Secret key'), 'password', 'secret', true, 'half'],
                          ['region', ___('Region'), 'text', 'us-east-1', false, 'third'], ['bucket', ___('Bucket name'), 'text', 'my-bucket', false, 'third'], ['cdn_url', ___('CDN base URL'), 'text', 'https://cdn.example.com', false, 'third']]],
        ],
    ];
@endphp

<div class="ui-scope ui-integ" x-data="linenIntegrations()">
    <x-ui.card class="ui-integ__hero">
        <div class="ui-integ__hero-text">
            <p class="ui-overline">{{ ___('Integrations') }}</p>
            <h2 class="ui-integ__title">{{ ___('Bring your own keys') }}</h2>
            <p class="ui-integ__sub">{{ ___('Per-store credentials for OpenAI, Anthropic, Twilio, and S3-compatible storage. Tested in real time before save.') }}</p>
        </div>
        <div class="ui-integ__stats">
            <x-ui.stat :value="number_format($stores->count())" :label="___('Stores')" icon="store" />
            <x-ui.stat :value="number_format($configuredCount)" :label="___('Active credentials')" icon="check" tone="success" />
            <x-ui.stat :value="$selectedRestaurant ? $selectedRestaurant->title : ___('None')" :label="___('Selected')" icon="pin" class="ui-integ__stat-wide" />
        </div>
    </x-ui.card>

    {{-- features-4 A6: read-only AI engine transparency --}}
    @php
        $aiSourceLabels = [
            'byok'     => ___('Your key'),
            'reseller' => ___('Reseller key'),
            'platform' => ___('Platform key'),
        ];
    @endphp
    <x-ui.card>
        <div class="ui-integ__engine-head">
            <div>
                <p class="ui-overline">{{ ___('AI engine') }}</p>
                <h2 class="ui-integ__h">{{ ___('Which model runs, and whose key pays') }}</h2>
            </div>
            @if (!empty($aiLastUsed))
                <div class="ui-integ__last">
                    <span class="ui-integ__sub">{{ ___('Last generation used') }}</span>
                    <strong class="ui-ltr">{{ ucfirst($aiLastUsed->provider) }} · {{ $aiLastUsed->model ?: '—' }}</strong>
                    <x-ui.pill :tone="$aiLastUsed->fell_back ? 'warning' : 'success'">{{ $aiLastUsed->fell_back ? ___('Fell back') : ___('Primary') }}</x-ui.pill>
                </div>
            @endif
        </div>

        @if (empty($aiChain))
            <p class="ui-integ__sub">{{ ___('No AI provider is configured yet.') }}</p>
        @else
            <p class="ui-integ__sub">{{ ___('Providers are tried top to bottom. The first configured one handles each request.') }}</p>
            <ol class="ui-integ__chain">
                @foreach ($aiChain as $link)
                    <li>
                        <span class="ui-integ__chain-n ui-ltr">{{ $loop->iteration }}</span>
                        <span class="ui-integ__chain-body">
                            <strong>{{ ucfirst($link['provider']) }}</strong>
                            <span class="ui-integ__sub ui-ltr">{{ $link['model'] ?: '—' }}</span>
                        </span>
                        @if ($loop->first)
                            <x-ui.tag tone="accent">{{ ___('Primary') }}</x-ui.tag>
                        @endif
                        <x-ui.tag>{{ $aiSourceLabels[$link['key_source']] ?? $link['key_source'] }}</x-ui.tag>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>

    @if ($stores->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('No stores yet')" :body="___('Create a store first to manage its integration credentials.')">
                <x-ui.button :href="route('stores.create')" icon="plus">{{ ___('Create store') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <x-ui.card>
            <form method="get" action="{{ route('settings.integrations') }}" id="integrations-restaurant-form" class="ui-integ__store">
                <label class="ui-label" for="integration_restaurant_id">
                    <span class="ui-integ__label">{{ ___('Store') }}</span>
                    <small class="ui-integ__sub">{{ ___('Each store has its own credential set.') }}</small>
                </label>
                <select
                    name="store_id"
                    id="integration_restaurant_id"
                    class="ui-input"
                    data-ui-do="submit-target" data-ui-target="integrations-restaurant-form"
                >
                    @foreach ($stores as $res)
                        <option value="{{ $res->id }}" @selected($res->id == $selectedRestaurantId)>{{ $res->title }}</option>
                    @endforeach
                </select>
            </form>
        </x-ui.card>

        @if ($selectedRestaurantId)
            @foreach ($groups as $groupTitle => $providers)
                <section class="ui-integ__group">
                    <h2 class="ui-integ__group-title">{{ $groupTitle }}</h2>
                    <div class="ui-integ__grid">
                        @foreach ($providers as $p)
                            @php $on = $isActive($p['feature'], $p['provider']); @endphp
                            <details class="ui-card ui-card--flush ui-integ__provider">
                                <summary class="ui-integ__summary">
                                    <span class="ui-integ__icon"><x-ui.icon :name="$p['icon']" /></span>
                                    <span class="ui-integ__summary-text">
                                        <span class="ui-integ__name">{{ $p['title'] }}</span>
                                        <span class="ui-integ__sub">{{ $p['desc'] }}</span>
                                    </span>
                                    <x-ui.pill :tone="$on ? 'success' : 'neutral'">{{ $on ? ___('Active') : ___('Inactive') }}</x-ui.pill>
                                    <x-ui.icon name="chevron-down" size="18" class="ui-integ__caret" />
                                </summary>
                                <form method="post" action="{{ route('settings.integrations.update') }}" data-test-form class="ui-integ__form">
                                    @csrf
                                    <input type="hidden" name="store_id" value="{{ $selectedRestaurantId }}">
                                    <input type="hidden" name="feature" value="{{ $p['feature'] }}">
                                    <input type="hidden" name="provider" value="{{ $p['provider'] }}">

                                    <x-ui.switch name="active" :id="$p['provider'].'-active'" :checked="$on" :label="$p['enable'] ?? ___('Enable provider')" />

                                    <div class="ui-integ__fields">
                                        @foreach ($p['fields'] as [$key, $label, $type, $placeholder, $masked, $width])
                                            <div class="ui-integ__field" data-w="{{ $width }}">
                                                <label class="ui-label" for="{{ $p['provider'] }}-{{ $key }}">{{ $label }}</label>
                                                <input id="{{ $p['provider'] }}-{{ $key }}" type="{{ $type }}" name="secrets[{{ $key }}]" class="ui-input" @if($placeholder !== '') placeholder="{{ $placeholder }}" @endif value="{{ $masked ? $getSecret($p['feature'], $p['provider'], $key) : $rawSecret($p['feature'], $p['provider'], $key) }}" autocomplete="off">
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="ui-integ__buttons">
                                        <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                                        @if ($p['test'])
                                            <x-ui.button type="button" variant="secondary" icon="refresh" data-test-conn :data-feature="$p['feature']" :data-provider="$p['provider']">{{ $p['test'] }}</x-ui.button>
                                        @endif
                                    </div>
                                    @if (!empty($p['doc']))
                                        <p class="ui-integ__sub"><a href="{{ $p['doc'][0] }}" target="_blank" rel="noopener">{{ $p['doc'][1] }}</a></p>
                                    @endif
                                    @if ($p['test'])
                                        <p class="ui-integ__status" data-test-status hidden></p>
                                    @endif
                                </form>
                            </details>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif

        {{-- FEATURES-5 Track A — Customer Social Login (BYOK) --}}
        @if($selectedRestaurantId)
        @php
            $socialProviders = [
                'google'   => ['label' => 'Google',   'desc' => ___('Let diners sign in with their Google account.')],
                'facebook' => ['label' => 'Facebook', 'desc' => ___('Let diners sign in with their Facebook account.')],
                'apple'    => ['label' => 'Apple',    'desc' => ___('Sign in with Apple. Requires a .p8 key, team ID and key ID.')],
            ];
        @endphp
        <section class="ui-integ__group">
            <h2 class="ui-integ__group-title">{{ ___('Customer Social Login') }}</h2>
            <p class="ui-integ__sub">{{ ___('Add your own OAuth keys to show social sign-in buttons on your storefront. Leave blank to use the platform default (if enabled by the platform).') }}</p>
            <div class="ui-integ__grid">
                @foreach($socialProviders as $prov => $meta)
                    @php $on = $isActive('social', $prov); @endphp
                    <details class="ui-card ui-card--flush ui-integ__provider">
                        <summary class="ui-integ__summary">
                            <span class="ui-integ__icon"><x-ui.icon name="users" /></span>
                            <span class="ui-integ__summary-text">
                                <span class="ui-integ__name">{{ $meta['label'] }}</span>
                                <span class="ui-integ__sub">{{ $meta['desc'] }}</span>
                            </span>
                            <x-ui.pill :tone="$on ? 'success' : 'neutral'">{{ $on ? ___('Active') : ___('Inactive') }}</x-ui.pill>
                            <x-ui.icon name="chevron-down" size="18" class="ui-integ__caret" />
                        </summary>
                        <form method="post" action="{{ route('settings.integrations.update') }}" data-test-form class="ui-integ__form">
                            @csrf
                            <input type="hidden" name="store_id" value="{{ $selectedRestaurantId }}">
                            <input type="hidden" name="feature" value="social">
                            <input type="hidden" name="provider" value="{{ $prov }}">

                            <x-ui.switch name="active" :id="'social-'.$prov.'-active'" :checked="$on" :label="___('Show this button on my storefront')" />

                            <div class="ui-integ__field">
                                <label class="ui-label" for="social-{{ $prov }}-redirect">{{ ___('Redirect URI (copy into the provider console)') }}</label>
                                <div class="ui-integ__copy" data-copy-group>
                                    <input id="social-{{ $prov }}-redirect" type="text" class="ui-input ui-ltr" readonly value="{{ route('customer.social.callback', $prov) }}" data-copy-src>
                                    <button class="ui-btn ui-btn--secondary" type="button" data-copy-btn>{{ ___('Copy') }}</button>
                                </div>
                            </div>

                            @if($prov === 'apple')
                                <div class="ui-integ__fields">
                                    <div class="ui-integ__field" data-w="full">
                                        <label class="ui-label" for="apple-client_id">{{ ___('Services ID (client_id)') }}</label>
                                        <input id="apple-client_id" type="text" name="secrets[client_id]" class="ui-input" placeholder="com.example.service" value="{{ $rawSecret('social', 'apple', 'client_id') }}" autocomplete="off">
                                    </div>
                                    <div class="ui-integ__field" data-w="half">
                                        <label class="ui-label" for="apple-team_id">{{ ___('Team ID') }}</label>
                                        <input id="apple-team_id" type="text" name="secrets[team_id]" class="ui-input" placeholder="XXXXXXXXXX" value="{{ $rawSecret('social', 'apple', 'team_id') }}" autocomplete="off">
                                    </div>
                                    <div class="ui-integ__field" data-w="half">
                                        <label class="ui-label" for="apple-key_id">{{ ___('Key ID') }}</label>
                                        <input id="apple-key_id" type="text" name="secrets[key_id]" class="ui-input" placeholder="XXXXXXXXXX" value="{{ $rawSecret('social', 'apple', 'key_id') }}" autocomplete="off">
                                    </div>
                                    <div class="ui-integ__field" data-w="full">
                                        <label class="ui-label" for="apple-private_key">{{ ___('Private key (.p8 contents)') }}</label>
                                        <textarea id="apple-private_key" name="secrets[private_key]" class="ui-input" rows="3" placeholder="-----BEGIN PRIVATE KEY-----" autocomplete="off">{{ $getSecret('social', 'apple', 'private_key') }}</textarea>
                                    </div>
                                </div>
                            @else
                                <div class="ui-integ__fields">
                                    <div class="ui-integ__field" data-w="full">
                                        <label class="ui-label" for="{{ $prov }}-client_id">{{ ___('Client ID') }}</label>
                                        <input id="{{ $prov }}-client_id" type="text" name="secrets[client_id]" class="ui-input" value="{{ $rawSecret('social', $prov, 'client_id') }}" autocomplete="off">
                                    </div>
                                    <div class="ui-integ__field" data-w="full">
                                        <label class="ui-label" for="{{ $prov }}-client_secret">{{ ___('Client secret') }}</label>
                                        <input id="{{ $prov }}-client_secret" type="password" name="secrets[client_secret]" class="ui-input" value="{{ $getSecret('social', $prov, 'client_secret') }}" autocomplete="off">
                                    </div>
                                </div>
                            @endif

                            <div class="ui-integ__buttons">
                                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                                <x-ui.button type="button" variant="secondary" icon="refresh" data-test-conn data-feature="social" :data-provider="$prov">{{ ___('Test configuration') }}</x-ui.button>
                            </div>
                            <p class="ui-integ__status" data-test-status hidden></p>
                        </form>
                    </details>
                @endforeach
            </div>
        </section>
        @endif
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function linenIntegrations() {
        return {
            init() {
                const url = @json(route('settings.integrations.test'));
                const labelTest = @json(___('Test connection'));
                const labelTesting = @json(___('Testing…'));
                const labelNetwork = @json(___('Network error while testing connection.'));

                // FEATURES-5 A2 — copy redirect-URI buttons.
                document.querySelectorAll('[data-copy-btn]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const src = btn.closest('[data-copy-group]')?.querySelector('[data-copy-src]');
                        if (!src) return;
                        navigator.clipboard?.writeText(src.value);
                        const original = btn.textContent;
                        btn.textContent = @json(___('Copied'));
                        setTimeout(() => { btn.textContent = original; }, 1500);
                    });
                });

                document.querySelectorAll('[data-test-conn]').forEach((btn) => {
                    btn.addEventListener('click', async () => {
                        const form = btn.closest('form');
                        if (!form) return;
                        const status = form.querySelector('[data-test-status]');
                        const fd = new FormData(form);
                        const original = btn.innerHTML;

                        btn.disabled = true;
                        btn.innerHTML = labelTesting;
                        if (status) {
                            status.hidden = false;
                            status.dataset.state = 'pending';
                            status.textContent = labelTesting;
                        }

                        try {
                            const res = await fetch(url, {
                                method: 'POST',
                                body: fd,
                                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                                credentials: 'same-origin',
                            });
                            const data = await res.json();
                            if (status) {
                                status.dataset.state = data.success ? 'ok' : 'fail';
                                                                status.textContent = data.message || '';
                            }
                            if (window.Alpine && window.Alpine.store && Alpine.store('toast')) {
                                Alpine.store('toast').push({
                                    variant: data.success ? 'success' : 'danger',
                                    title: data.message || (data.success ? 'OK' : 'Error'),
                                });
                            }
                        } catch (e) {
                            if (status) {
                                status.dataset.state = 'fail';
                                                                status.textContent = labelNetwork;
                            }
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = original;
                        }
                    });
                });
            },
        };
    }
</script>
@endpush
@endsection
