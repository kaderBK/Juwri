{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Media Sources'))
@section('content')

    <form method="post" action="{{ route('admin.media-sources.update') }}">
        @csrf

        <x-ui.card :title="___('Stock photo platform keys')">
            <div class="ui-stack">
                <p class="ui-text-2">{{ ___('Platform-default keys let every store use the Stock Photos tab with zero setup. Owners can still bring their own keys. Leave blank to keep the existing key. Stored encrypted.') }}</p>
                <div class="ui-form-grid">
                    <div class="ui-stack" style="--ui-gap:var(--ui-space-2)">
                        <x-ui.field name="pexels_key" :label="___('Pexels API key')" autocomplete="off" :placeholder="___('Paste key…')" />
                        @if($pexelsActive)<span><x-ui.tag tone="success">{{ ___('Set') }}</x-ui.tag></span>@endif
                    </div>
                    <div class="ui-stack" style="--ui-gap:var(--ui-space-2)">
                        <x-ui.field name="unsplash_key" :label="___('Unsplash Access Key')" autocomplete="off" :placeholder="___('Paste key…')" />
                        @if($unsplashActive)<span><x-ui.tag tone="success">{{ ___('Set') }}</x-ui.tag></span>@endif
                    </div>
                </div>
                <x-ui.banner>
                    {{ ___('Google image search is intentionally BYOK-only (owners add their own key in store settings) because its results are arbitrary web images, not licensed stock.') }}
                </x-ui.banner>
            </div>
            <div class="ui-card__foot">
                <x-ui.button type="submit" icon="device-floppy" data-ui-busy>{{ ___('Save') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>

@endsection
