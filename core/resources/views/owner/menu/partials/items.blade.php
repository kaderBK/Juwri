{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@if($menus->count())
    <div class="menu-items ui-menu-items">
        @foreach($menus as $menu)
            @include('owner.menu.item', ['menu' => $menu, 'store' => $store])
        @endforeach
    </div>
@else
    <div class="menu-items-empty ui-menu-items-empty">
        {{ ___('No menu items in this category yet.') }}
    </div>
@endif
