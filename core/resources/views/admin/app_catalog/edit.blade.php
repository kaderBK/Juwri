{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', $app->name)
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.app-catalog.index')">{{ ___('Back to catalog') }}</x-ui.button>
    @if ($app->status === 'approved')
        <x-ui.button variant="secondary" size="sm" icon="eye" :href="route('apps.store.show', $app->slug)" target="_blank" rel="noopener">{{ ___('View listing') }}</x-ui.button>
    @endif
@endsection

@section('content')
@php
    $__currency = $settings->currency_sign ?? '$';
@endphp
<div class="ui-columns ui-columns--wide-first">
    <form method="post" action="{{ route('admin.app-catalog.update', $app->id) }}" class="ui-stack">@csrf

        {{-- Storefront copy --}}
        <x-ui.card :title="___('Listing')">
            <div class="ui-form-grid">
                <x-ui.field name="name" :label="___('Name')" :value="$app->name" required maxlength="120" />
                <x-ui.field name="slug_display" id="app-slug" :label="___('Slug')" :value="$app->slug" disabled
                            :help="___('Permanent — it is the public store URL.')" />
                <div class="ui-form-grid__full">
                    <x-ui.field name="tagline" :label="___('Tagline')" :value="$app->tagline" maxlength="255"
                                :help="___('One line, shown on the store card under the name.')" />
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.field type="textarea" name="description" :label="___('Description')" :value="$app->description" rows="6" maxlength="5000" />
                </div>
                <x-ui.field type="select" name="category" :label="___('Category')" :value="$app->category"
                            :options="['' => ___('Uncategorised')] + array_combine(array_values((array) $categories), array_values((array) $categories))" />
                <x-ui.field type="url" name="support_url" :label="___('Support URL')" :value="$app->support_url" maxlength="255" />
                <x-ui.field type="url" name="docs_url" :label="___('Docs URL')" :value="$app->docs_url" maxlength="255" />
            </div>
        </x-ui.card>

        {{-- Commercials --}}
        <x-ui.card :title="___('Pricing')" id="pricing-card">
            <div class="ui-stack">
                @if ($activeInstalls > 0)
                    <x-ui.banner tone="warning">{{ ___('This app has :count active installs. Apps charge the listed price live — a price change applies to them at their next renewal.', ['count' => $activeInstalls]) }}</x-ui.banner>
                @endif

                <div class="ui-form-grid">
                    <div class="ui-field">
                        <label class="ui-field__label" for="billing_type">{{ ___('Billing model') }}</label>
                        <div class="ui-field__control">
                            <select name="billing_type" id="billing_type">
                                @foreach ($billingTypes as $type)
                                    <option value="{{ $type }}" @selected(old('billing_type', $app->billing_type) === $type)>
                                        @switch($type)
                                            @case('free'){{ ___('Free') }}@break
                                            @case('recurring'){{ ___('Subscription') }}@break
                                            @case('one_time'){{ ___('One-time purchase') }}@break
                                            @default{{ ___('Usage-based') }}
                                        @endswitch
                                    </option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                        </div>
                    </div>

                    <div class="ui-field" data-billing="recurring one_time usage">
                        <label class="ui-field__label" for="app-price">{{ ___('Price') }}</label>
                        <div class="ui-input-group">
                            <span class="ui-input-group__text">{{ $__currency }}</span>
                            <input type="number" id="app-price" name="price" value="{{ old('price', $app->price) }}" step="0.01" min="0" class="ui-input">
                        </div>
                    </div>

                    <div class="ui-field" data-billing="recurring usage">
                        <label class="ui-field__label" for="app-period">{{ ___('Billing period') }}</label>
                        <div class="ui-field__control">
                            <select name="billing_period_days" id="app-period">
                                @foreach ($periodPresets as $days => $label)
                                    <option value="{{ $days }}" @selected((int) old('billing_period_days', $app->billing_period_days ?: 30) === (int) $days)>
                                        {{ ___($label) }} ({{ $days }} {{ ___('days') }})
                                    </option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                        </div>
                    </div>

                    <div class="ui-field" data-billing="recurring">
                        <label class="ui-field__label" for="app-trial">{{ ___('Trial period') }}</label>
                        <div class="ui-input-group">
                            <input type="number" id="app-trial" name="trial_days" value="{{ old('trial_days', $app->trial_days) }}" min="0" max="365" class="ui-input" aria-describedby="app-trial-help">
                            <span class="ui-input-group__text">{{ ___('days') }}</span>
                        </div>
                        <span class="ui-field__help" id="app-trial-help">{{ ___('0 disables the trial. Only the first-ever install of an app gets one.') }}</span>
                    </div>

                    <div data-billing="usage">
                        <x-ui.field name="usage_unit" :label="___('Usage unit')" :value="$app->usage_unit" maxlength="40" :placeholder="___('e.g. order, message')" />
                    </div>
                    <div class="ui-field" data-billing="usage">
                        <label class="ui-field__label" for="app-usage-price">{{ ___('Price per unit') }}</label>
                        <div class="ui-input-group">
                            <span class="ui-input-group__text">{{ $__currency }}</span>
                            <input type="number" id="app-usage-price" name="usage_price" value="{{ old('usage_price', $app->usage_price) }}" step="0.0001" min="0" class="ui-input">
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- Feature grants + status --}}
        <x-ui.card :title="___('Feature grants')">
            <div class="ui-stack">
                <p class="ui-text-2 ui-small">
                    {{ ___('Installing this app unlocks the ticked feature keys for the merchant; uninstalling removes them. Changes re-sync live installs immediately.') }}
                </p>
                @php $granted = (array) old('grants_features', $app->grants_features ?? []); @endphp
                <fieldset class="ui-fieldset ui-scroll" style="max-block-size:280px">
                    <legend class="ui-visually-hidden">{{ ___('Feature grants') }}</legend>
                    <div class="ui-grid" style="--ui-grid-min:240px;--ui-gap:var(--ui-space-2)">
                        @foreach ($grantableFeatures as $feature)
                            <label class="ui-check" for="grant-{{ $feature->key }}">
                                <input type="checkbox" name="grants_features[]" value="{{ $feature->key }}" id="grant-{{ $feature->key }}"
                                       @checked(in_array($feature->key, $granted, true))>
                                <span>
                                    {{ $feature->label }}
                                    <code class="ui-cell-sub ui-mono">{{ $feature->key }}</code>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <div class="ui-card__foot ui-cluster ui-cluster--between ui-cluster--bottom">
                <div>
                    <label class="ui-label ui-label--sm" for="app-status">{{ ___('Status') }}</label>
                    <select name="status" id="app-status" class="ui-input ui-input--sm ui-input--auto">
                        @foreach (['draft', 'review', 'approved', 'rejected', 'delisted'] as $s)
                            <option value="{{ $s }}" @selected(old('status', $app->status) === $s)>{{ ___(ucfirst($s)) }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" data-ui-busy>{{ ___('Save listing') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>

    {{-- Icon + facts --}}
    <div class="ui-stack">
        <x-ui.card :title="___('Listing icon')">
            <div class="ui-stack ui-center">
                <div class="ui-tpl-thumb" style="aspect-ratio:auto;min-block-size:140px">
                    @if ($app->icon)
                        <img src="{{ $app->icon->url() }}" alt="" style="max-inline-size:120px;max-block-size:120px;inline-size:auto;block-size:auto">
                    @else
                        <x-ui.icon name="layout" size="32" />
                    @endif
                </div>
                <form method="post" action="{{ route('admin.app-catalog.icon', $app->id) }}" enctype="multipart/form-data">@csrf
                    <label for="app_icon" class="ui-btn ui-btn--secondary ui-btn--block">
                        <span class="ui-btn__icon"><x-ui.icon name="upload" /></span>{{ ___('Upload icon') }}
                    </label>
                    <input type="file" name="icon" id="app_icon" class="ui-visually-hidden" accept=".png,.webp,.jpg,.jpeg" data-ui-autosubmit>
                </form>
                <small class="ui-text-2 ui-small">
                    {{ ___('Square image, PNG / WEBP / JPG.') }} {{ ___('Max') }} {{ (int) (config('marketplace.media.max_kb') / 1024) }}MB.
                </small>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('At a glance')">
            <dl class="ui-dl">
                <div><dt>{{ ___('Active installs') }}</dt><dd>{{ $activeInstalls }}</dd></div>
                <div><dt>{{ ___('Rating') }}</dt><dd>{{ $app->rating_count ? number_format((float) $app->rating_avg, 1).' ('.$app->rating_count.')' : '—' }}</dd></div>
                <div><dt>{{ ___('Current price') }}</dt><dd>{{ $app->pricingSummary() }}</dd></div>
                <div><dt>{{ ___('Screenshots') }}</dt><dd>{{ $app->screenshots->count() }}</dd></div>
                <div><dt>{{ ___('Developer') }}</dt><dd>{{ $app->developer->username ?? $app->developer->name ?? ___('First-party') }}</dd></div>
            </dl>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts_at_bottom')
{{-- Admin has no Alpine — plain JS shows only the fields the chosen billing model uses. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var select = document.getElementById('billing_type');
    if (!select) return;
    var fields = document.querySelectorAll('#pricing-card [data-billing]');
    function sync() {
        var value = select.value;
        fields.forEach(function (el) {
            el.classList.toggle('ui-hidden', el.dataset.billing.split(' ').indexOf(value) === -1);
        });
    }
    select.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
