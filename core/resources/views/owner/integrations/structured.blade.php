{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Analytics & tags'))
@section('page_title', ___('Analytics & tags'))
@section('page_sub', $store->title)

@section('page_actions')
    @if(Route::has('stores.edit'))
        <x-ui.button :href="route('stores.edit', $store->id)" variant="secondary" icon="back">
            {{ ___('Back to store') }}
        </x-ui.button>
    @endif
    @if(Route::has('publicView'))
        <x-ui.button :href="route('publicView', $store->slug)" variant="secondary" icon="external" target="_blank" rel="noopener">
            {{ ___('Public view') }}
        </x-ui.button>
    @endif
@endsection

@section('content')
@php
    // Prefer the just-submitted value on a validation bounce, else the saved one.
    $iv = fn (string $k, $d = '') => old($k, data_get($integration, $k, $d));
@endphp

<form method="post" action="{{ route('integrations.structured.save', $store->id) }}" class="ui-scope ui-stack structured-integrations-form">
    @csrf

    <section class="ui-card">
        <div class="ui-card__body ui-stack">
            <div>
                <p class="ui-overline">{{ ___('Analytics and tags') }}</p>
                <h2 class="ui-card__title">{{ ___('Tracking') }}</h2>
            </div>

            <div class="ui-form-grid">
                <div>
                    <x-ui.field
                        name="ga_id"
                        :label="___('Google Analytics / Tag ID')"
                        :value="$iv('ga_id')"
                        placeholder="G-XXXXXXX, AW-XXXXXXX or GTM-XXXXXX"
                        :help="___('Paste a Google Analytics 4 (G-), Google Ads (AW-) or Google Tag Manager (GTM-) ID. GTM- IDs now load the full Tag Manager container; G-/AW- load gtag.')" />
                </div>
                <div>
                    <x-ui.field
                        name="meta_pixel_id"
                        :label="___('Meta Pixel ID')"
                        :value="$iv('meta_pixel_id')"
                        placeholder="1234567890"
                        :help="___('Numeric Meta (Facebook) Pixel ID.')" />
                </div>
                <div>
                    <x-ui.field
                        name="font_family"
                        :label="___('Web font family')"
                        :value="$iv('font_family')"
                        placeholder="Plus Jakarta Sans"
                        :help="___('A Google Fonts family name (letters, digits, spaces).')" />
                </div>
            </div>
        </div>
    </section>

    <section class="ui-card">
        <div class="ui-card__body ui-stack">
            <div>
                <p class="ui-overline">{{ ___('Storefront') }}</p>
                <h2 class="ui-card__title">{{ ___('Map and structured data') }}</h2>
            </div>

            <div class="ui-form-grid">
                <div>
                    <x-ui.field
                        name="store_map_embed"
                        type="select"
                        :label="___('Show map on info pages')"
                        :value="(string) $iv('store_map_embed', '0')"
                        :options="['1' => ___('Yes'), '0' => ___('No')]"
                        :help="___('Embeds a Google map on your storefront info pages. Uses a branch location when set, otherwise your store address. Off by default.')" />
                </div>

                {{-- D5 handshake: Lane B ships a self-contained JSON-LD menu toggle
                     field and D references it here. @includeIf (not @include) so it
                     no-ops until B's partial merges at F0; F0 confirms it resolves.
                     F0 VERIFY: exact view name below must match B's published
                     partial (expected store_templates/_partials/jsonld-toggle-field
                     .blade.php reading $store). If B named it differently, update
                     this one line. --}}
                @includeIf('store_templates._partials.jsonld-toggle-field', ['store' => $store, 'postOptions' => $postOptions])
            </div>
        </div>
    </section>

    <div class="ui-savebar">
        @if(Route::has('stores.index'))
            <x-ui.button :href="route('stores.index')" variant="secondary" icon="back">
                {{ ___('Back') }}
            </x-ui.button>
        @endif
        <x-ui.button type="submit" icon="check" data-ui-busy>
            {{ ___('Save integrations') }}
        </x-ui.button>
    </div>
</form>
@endsection
