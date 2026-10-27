{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 the kiosk ordering screen shared by the kit themes (landscape tablet first): categories rail, the order-type step, the grid catalogue
     in kiosk sizing, the order panel. $theme = the theme's views are tried first (its own catalogue / order-type section). Reads only $vm. --}}
@php
    $__kvm = $vm;
    $__kvm['theme']['settings']['layout.card'] = 'grid'; // kiosk: the grid card is forced (sections/kiosk.md)
    $__types = $vm['context']['kiosk']['order_types'] ?? [];
@endphp
<main class="c-main c-kiosk" id="main">
    <div class="c-kiosk__cats">@include('store_templates._kit.sections.category_nav')</div>
    <div class="c-kiosk__menu">
        @if (count($__types) > 1)
        <div class="c-kiosk__step"><h2 class="c-kiosk__ask">{{ $kit->s("Choose how you'd like to order") }}</h2>@include(\Juwri\Core\Support\ViewHelpers::kioskView($theme, 'order_type'))</div>
        @endif
        @include(\Juwri\Core\Support\ViewHelpers::kioskView($theme, 'catalog'), ['vm' => $__kvm])
    </div>
    @include('store_templates._kit.kiosk.panel')
    @include('store_templates._kit.kiosk.loyalty') {{-- item 26 --}}
</main>
<button class="c-kiosk__a11y" type="button" data-kiosk-a11y aria-pressed="false">{{ $kit->icon('person-standing') }}<span>{{ $kit->s('Accessibility') }}</span></button>
