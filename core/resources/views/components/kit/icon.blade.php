{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-05: a kit icon by its kit name (tools/themes/kit/icons-map.json) through <x-ui.icon>. Directional icons get
     `c-icon--dir`, which the kit CSS mirrors in RTL (contract section 6); the rest never flip. --}}
@props(['name', 'class' => ''])
@php($spec = \Juwri\Modules\Storefront\Services\Theme\KitIcons::resolve((string) $name))
@if ($spec)<x-ui.icon :name="$spec[0]" :size="24" class="c-icon{{ $spec[1] ? ' c-icon--dir' : '' }}{{ $class !== '' ? ' '.$class : '' }}" />@endif
