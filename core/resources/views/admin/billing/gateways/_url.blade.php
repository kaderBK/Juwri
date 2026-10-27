{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Read-only URL the provider must call (webhook / callback), with a copy button.
  $label, $url; optional $help (text) or $helpHtml (trusted markup, e.g. <code> event names).
--}}
@php $__uid = 'gw-url-'.substr(md5($url.$label), 0, 8); @endphp
<div class="ui-field">
    <label class="ui-field__label" for="{{ $__uid }}">{{ $label }}</label>
    <div class="ui-input-group">
        <input id="{{ $__uid }}" type="text" class="ui-input ui-mono" value="{{ $url }}" readonly @if (! empty($help) || ! empty($helpHtml)) aria-describedby="{{ $__uid }}-help" @endif>
        <button type="button" class="ui-btn ui-btn--secondary" data-ui-copy-text="{{ $url }}">
            <span class="ui-btn__icon"><x-ui.icon name="copy" size="16" /></span>{{ ___('Copy') }}
        </button>
    </div>
    @if (! empty($helpHtml))
        <span class="ui-field__help" id="{{ $__uid }}-help">{!! $helpHtml !!}</span>
    @elseif (! empty($help))
        <span class="ui-field__help" id="{{ $__uid }}-help">{{ $help }}</span>
    @endif
</div>
