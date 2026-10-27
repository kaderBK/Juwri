{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.image-field name="image" :label="___('Image')" :src="$url" :hint="___('Allowed JPG, JPEG or PNG.')" />
  Picture upload with a live preview (assets/owner-ui/admin.js, [data-ui-preview]).
    size    preview size in px (default 64); round → circular preview
--}}
@props(['name', 'label', 'src' => null, 'hint' => null, 'accept' => 'image/png, image/jpeg', 'size' => 64, 'round' => false])
@php $__fid = 'ui-img-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name); @endphp
<div class="ui-field">
    <span class="ui-field__label">{{ $label }}</span>
    <div class="ui-imgfield">
        <img @class(['ui-imgfield__preview', 'is-round' => $round]) id="{{ $__fid }}-preview" src="{{ $src }}" alt="" width="{{ (int) $size }}" height="{{ (int) $size }}">
        <div>
            <label class="ui-btn ui-btn--secondary ui-btn--sm" for="{{ $__fid }}">
                <span class="ui-btn__icon"><x-ui.icon name="upload" size="16" /></span>{{ ___('Upload Image') }}
            </label>
            <input type="file" id="{{ $__fid }}" name="{{ $name }}" accept="{{ $accept }}" class="ui-visually-hidden" data-ui-preview="{{ $__fid }}-preview" {{ $attributes }}>
            @if ($hint)<span class="ui-field__help">{{ $hint }}</span>@endif
        </div>
    </div>
</div>
