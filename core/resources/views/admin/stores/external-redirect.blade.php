{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('External redirect'))
@section('page_sub'){{ $store->title }} · {{ $store->slug }}@endsection
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.stores.index')">{{ ___('Back') }}</x-ui.button>
@endsection
@section('content')
    @php
        $erEnabled = old('external_redirect_enabled', store_options($store->id, 'external_redirect_enabled')) == 1;
        $erInterstitial = old('external_redirect_interstitial', store_options($store->id, 'external_redirect_interstitial')) == 1;
        $bioErEnabled = old('bio_external_redirect_enabled', store_options($store->id, 'bio_external_redirect_enabled')) == 1;
        $erUrl = old('external_redirect_url', store_options($store->id, 'external_redirect_url') ?: '');
        $bioErUrl = old('bio_external_redirect_url', store_options($store->id, 'bio_external_redirect_url') ?: '');
        $erInterstitialLabel = ___('Show a "menu has moved" page for 2 seconds instead of redirecting instantly');
    @endphp

    <div class="ui-columns ui-columns--wide-first">
        <form action="{{ route('admin.stores.externalRedirect.update', $store->id) }}" method="POST" class="ui-stack">
            @csrf
            <x-ui.card :title="___('Public menu')">
                <div class="ui-stack">
                    <p class="ui-small ui-text-2">{{ ___('Redirect visitors of this store\'s public menu to another website. Printed QR codes keep working.') }}</p>
                    <x-ui.switch id="er_enabled" name="external_redirect_enabled" off="0" :checked="$erEnabled"
                                 :label="___('Redirect the public menu to another URL')" />
                    <x-ui.field type="url" name="external_redirect_url" id="er_url" :label="___('Menu redirect URL')" :value="$erUrl"
                                placeholder="https://legacy.example.com/{slug}{query}"
                                :help="___('Use {slug} and {query} to forward the store name and table/QR parameters. Must start with https:// and point to a different site.')" />
                    <x-ui.switch id="er_interstitial" name="external_redirect_interstitial" off="0" :checked="$erInterstitial"
                                 :label="$erInterstitialLabel" />
                </div>
            </x-ui.card>

            <x-ui.card :title="___('Bio page')">
                <div class="ui-stack">
                    <p class="ui-small ui-text-2">{{ ___('An independent redirect for the bio / link page (always instant).') }}</p>
                    <x-ui.switch id="bio_er_enabled" name="bio_external_redirect_enabled" off="0" :checked="$bioErEnabled"
                                 :label="___('Redirect the bio page to another URL')" />
                    <x-ui.field type="url" name="bio_external_redirect_url" id="bio_er_url" :label="___('Bio redirect URL')" :value="$bioErUrl"
                                placeholder="https://legacy.example.com/{slug}/bio" />
                </div>
            </x-ui.card>

            <div><x-ui.button type="submit" data-ui-busy>{{ ___('Save Changes') }}</x-ui.button></div>
        </form>
    </div>
@endsection
