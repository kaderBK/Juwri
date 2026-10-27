{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §3: category chips (phone); on desktop the header's menu and the filters sidebar replace the row. Each chip is a link to
     the server-filtered page (?category=), the current one is aria-current. --}}
@php
    $__cats = \Juwri\Core\Support\ViewHelpers::populatedCategories($vm['catalog']['categories'] ?? []);
    $__home = $vm['endpoints']['home'] ?? '/';
    $__active = $listing['category'] ?? null;
@endphp
@if (count($__cats) > 1)
<nav class="c-catnav" data-section="category_nav" aria-label="{{ $kit->s('Categories') }}">
    <ul class="c-catnav__list">
        <li><a class="c-chip c-catnav__link" href="{{ $__home }}" @if ($__active === null) aria-current="true" @endif>{{ $kit->s('All products') }}</a></li>
        @foreach ($__cats as $__c)<li><a class="c-chip c-catnav__link" href="{{ $__home }}?category={{ $__c['id'] }}" @if ($__active === $__c['id']) aria-current="true" @endif>{{ $__c['name'] }}</a></li>@endforeach
    </ul>
</nav>
@endif
