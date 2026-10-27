{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Google posts'))
@section('page_title', ___('Google posts'))
@section('page_sub', ___('Publish updates to your Google Business Profile.'))

@section('page_actions')
    <x-ui.button :href="route('stores.gbp.reviews.index').'?store_id='.$rid" variant="secondary" size="sm" icon="star">{{ ___('Google reviews') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-gbp">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

@unless($connected)
    <div class="ui-card">
        <x-ui.empty icon="share" :title="___('Not connected yet')" :body="___('Connect your Google Business location to publish posts.')">
            <x-ui.button :href="route('stores.gbp.index').'?store_id='.$rid">{{ ___('Connect Google') }}</x-ui.button>
        </x-ui.empty>
    </div>
@else

<div class="ui-gbp__grid">
<x-ui.card :title="___('New post')">
    <form method="post" action="{{ route('stores.gbp.posts.store') }}?store_id={{ $rid }}" class="ui-gbp__form">
        @csrf
        <div>
            <label class="ui-label" for="gbp-post-summary">{{ ___('What\'s new?') }}</label>
            <textarea id="gbp-post-summary" name="summary" class="ui-input" rows="3" maxlength="1500" required
                placeholder="{{ ___('Share an update, offer, or event…') }}">{{ old('summary') }}</textarea>
            <small class="ui-gbp__hint">{{ ___('Up to 1500 characters.') }}</small>
        </div>
        <div class="ui-gbp__fields ui-gbp__fields--cta">
            <div>
                <label class="ui-label" for="gbp-post-cta">{{ ___('Button') }}</label>
                <select id="gbp-post-cta" name="cta_type" class="ui-input">
                    @foreach($ctaTypes as $t)
                        <option value="{{ $t }}" @selected(old('cta_type') === $t)>{{ $t === 'NONE' ? ___('No button') : $t }}</option>
                    @endforeach
                </select>
            </div>
            <div x-data="{ bioUrl: @js($bioUrl ?? null) }">
                <label class="ui-label" for="gbp-post-url">{{ ___('Button link') }}</label>
                <input id="gbp-post-url" type="url" name="cta_url" class="ui-input ui-ltr" value="{{ old('cta_url') }}" placeholder="https://…" x-ref="ctaUrl">
                <small class="ui-gbp__hint">{{ ___('Not needed for the Call button.') }}</small>
                {{-- biolink-tpl lane-d (T2-4a): prefill the CTA with the bio page link; shown only when the bio page is live --}}
                @if(!empty($bioUrl))
                    <button type="button" class="ui-btn ui-btn--link ui-btn--sm" @click="$refs.ctaUrl.value = bioUrl; $refs.ctaUrl.dispatchEvent(new Event('input'))">{{ ___('Use my bio page link') }}</button>
                @endif
            </div>
        </div>
        @if(!empty($photos))
            <div>
                <label class="ui-label" for="gbp-post-photo">{{ ___('Photo (optional)') }}</label>
                <select id="gbp-post-photo" name="photo" class="ui-input">
                    <option value="">{{ ___('No photo') }}</option>
                    @isset($photos['cover'])<option value="cover">{{ ___('Cover photo') }}</option>@endisset
                    @isset($photos['logo'])<option value="logo">{{ ___('Logo') }}</option>@endisset
                </select>
            </div>
        @endif
        <div><x-ui.button type="submit" icon="share" data-ui-busy>{{ ___('Publish to Google') }}</x-ui.button></div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Recent posts')" variant="flush">
    @if(empty($recent))
        <x-ui.empty icon="share" :title="___('No posts yet.')" />
    @else
        <ul class="ui-gbp__posts">
            @foreach($recent as $p)
                <li>
                    <div class="ui-gbp__post-top">
                        <span class="ui-gbp__post-text">{{ $p['summary'] ?? ___('(no text)') }}</span>
                        <x-ui.pill :tone="($p['state'] ?? '') === 'LIVE' ? 'success' : 'neutral'">{{ $p['state'] ?? '—' }}</x-ui.pill>
                    </div>
                    @if(!empty($p['callToAction']['actionType']) && ($p['callToAction']['actionType'] !== 'ACTION_TYPE_UNSPECIFIED'))
                        <small class="ui-gbp__hint">{{ ___('Button') }}: <span class="ui-ltr">{{ $p['callToAction']['actionType'] }}</span></small>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
</div>

@endunless

</div>
@endsection
