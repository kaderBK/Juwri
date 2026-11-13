{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared "More" menu for the goods / services catalog headers (owner UI kit).
     $extra: list of [url, kit icon, label]; $export: CSV export URL or null. --}}
<div class="ui-pop-host ui-menu-more" data-ui-pop>
    <button type="button" class="ui-btn ui-btn--secondary" data-ui-pop-toggle aria-haspopup="true" aria-expanded="false">
        <span class="ui-btn__icon"><x-ui.icon name="dots" /></span>{{ ___('More') }}
    </button>
    <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
        @foreach($extra ?? [] as [$url, $icon, $label])
            <a class="ui-pop__item" href="{{ $url }}"><x-ui.icon :name="$icon" size="18" /> {{ $label }}</a>
        @endforeach
        @if(Route::has('stores.edit'))
            <a class="ui-pop__item" href="{{ route('stores.edit', $store->id) }}"><x-ui.icon name="settings" size="18" /> {{ ___('Settings') }}</a>
        @endif
        @if(!empty($export))
            <a class="ui-pop__item" href="{{ $export }}" data-busy-download data-busy-label="{{ ___('Preparing…') }}"><x-ui.icon name="download" size="18" /> {{ ___('Export CSV') }}</a>
        @endif
        {{-- Vertical AI: same scan/paste/describe generator the food menu editor
             uses (restaurants.menu.aiGenerate — not vertical-gated), with goods- or
             services-flavoured wording from the shared tools partial. --}}
        @if(Route::has('stores.menu.aiGenerate'))
            <button type="button" class="ui-pop__item" x-data @click="$dispatch('menu-ai')"><x-ui.icon name="sparkle" size="18" /> {{ ___('AI generate') }}</button>
        @endif
    </div>
</div>
