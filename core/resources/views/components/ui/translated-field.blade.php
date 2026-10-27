{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.translated-field> — one text field with a value per admin language.

    name          base field name; posts `name` (default language) and
                  `translations[<code>][name]` for every active admin language
    label         visible label
    value         stored default value
    translations  object {code: {name: value}} (model `translations` column)
    type          text (default) | textarea
  A language picker shows one language at a time (assets/owner-ui/admin.js,
  [data-ui-translate]); typing in the default language also fills `name`.

    <x-ui.translated-field name="title" :label="___('Title')" :value="$row->title" :translations="$row->translations" />
--}}
@props(['name', 'label', 'value' => null, 'translations' => null, 'type' => 'text', 'required' => true])
@php
    $__langs = collect($admin_languages ?? [])->unique('code')->values();
    $__default = (string) env('DEFAULT_LANGUAGE', config('app.locale'));
    $__fid = 'ui-tf-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name);
@endphp
<div class="ui-field ui-tfield" data-ui-translate>
    <div class="ui-tfield__head">
        <label class="ui-field__label" for="{{ $__fid }}-{{ $__default }}">{{ $label }}@if ($required) *@endif</label>
        @if ($__langs->count() > 1)
            <label class="ui-tfield__pick">
                <x-ui.icon name="language" size="16" />
                <span class="ui-visually-hidden">{{ ___('Language') }}</span>
                <select class="ui-input ui-input--sm ui-input--auto" data-ui-translate-pick>
                    @foreach ($__langs as $__l)
                        <option value="{{ $__l->code }}" @selected($__l->code === $__default)>{{ $__l->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-ui-translate-base>
    @foreach ($__langs as $__l)
        @php $__isDefault = $__l->code === $__default; @endphp
        <div class="ui-field__control" data-ui-translate-lang="{{ $__l->code }}" @unless ($__isDefault) hidden @endunless>
            @if ($type === 'textarea')
                <textarea id="{{ $__fid }}-{{ $__l->code }}" class="ui-input" rows="3" name="translations[{{ $__l->code }}][{{ $name }}]"
                          @if ($__isDefault) data-ui-translate-default @if ($required) required @endif @endif>{{ \Juwri\Core\Support\ViewHelpers::translatedValue($__l->code, $translations, $name, $value) }}</textarea>
            @else
                <input type="text" id="{{ $__fid }}-{{ $__l->code }}" class="ui-input" name="translations[{{ $__l->code }}][{{ $name }}]"
                       value="{{ \Juwri\Core\Support\ViewHelpers::translatedValue($__l->code, $translations, $name, $value) }}" @if ($__isDefault) data-ui-translate-default @if ($required) required @endif @endif>
            @endif
        </div>
    @endforeach
    @if ($__langs->isEmpty())
        <div class="ui-field__control">
            @if ($type === 'textarea')
                <textarea id="{{ $__fid }}-{{ $__default }}" class="ui-input" rows="3" data-ui-translate-default @if ($required) required @endif>{{ $value }}</textarea>
            @else
                <input type="text" id="{{ $__fid }}-{{ $__default }}" class="ui-input" value="{{ $value }}" data-ui-translate-default @if ($required) required @endif>
            @endif
        </div>
    @endif
</div>
