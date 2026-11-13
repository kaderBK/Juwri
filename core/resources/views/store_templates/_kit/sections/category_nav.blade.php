{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__cats = \Juwri\Core\Support\ViewHelpers::populatedCategories($vm['catalog']['categories']);
    $__variant = $vm['theme']['settings']['layout.category_nav'] ?? 'chips';
@endphp
<nav class="c-catnav" data-section="category_nav" data-variant="{{ $__variant }}" aria-label="{{ $kit->s('Menu categories') }}">
    <p class="c-catnav__label" aria-hidden="true">{{ $kit->s('Menu') }}</p>
    <ul class="c-catnav__list">
    @foreach ($__cats as $__i => $__cat)
        <li><a class="c-chip c-catnav__link" href="#cat-{{ $__cat['id'] }}" @if ($__i === 0) aria-current="true" @endif><span class="c-catnav__name">{{ $__cat['name'] }}</span><span class="c-catnav__count">{{ $__cat['count'] }}</span></a></li>
    @endforeach
    </ul>
</nav>
