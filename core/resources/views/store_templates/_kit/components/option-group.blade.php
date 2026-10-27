{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__g = $group;
    $__kind = $__g['kind'] === 'radio' ? 'radio' : 'checkbox';
    $__min = $__g['min'] ?? 0;
    $__max = $__g['max'] ?? 0;
    $__selected = \Juwri\Core\Support\ViewHelpers::selectedCount($__g['options']);
@endphp
<fieldset class="c-group" data-kit-group="{{ $__g['id'] }}" data-min="{{ $__min }}" data-max="{{ $__max }}" @if (! empty($__g['required'])) data-required @endif>
    <legend class="c-group__legend">@if (! empty($__g['required']))<span class="c-badge c-badge--solid c-group__badge">{{ $kit->s('Required') }}</span>@else<span class="c-badge c-badge--muted c-group__badge">{{ $kit->s('Optional') }}</span>@endif<span class="c-group__title">{{ $__g['label'] }}</span><span class="c-group__rule">{{ $__g['rule_label'] ?? '' }}</span></legend>
    <div class="c-group__opts">
        @foreach ($__g['options'] as $__o)
        @php
            $__oid = $__g['id'].'-'.$__o['id'];
            $__disabled = $__kind === 'checkbox' && $__max && $__selected >= $__max && empty($__o['selected']);
            $__delta = $__o['price_delta'] ?? null;
        @endphp
        <label class="c-choice" for="o-{{ $__oid }}"><input class="c-choice__input u-vh" type="{{ $__kind }}" id="o-{{ $__oid }}" name="g-{{ $__g['id'] }}{{ $__kind === 'checkbox' ? '[]' : '' }}" value="{{ $__o['id'] }}" data-group="{{ $__g['id'] }}" @checked(! empty($__o['selected'])) @disabled($__disabled || (isset($__o['available']) && ! $__o['available']))>@if ($__kind === 'radio')<span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span>@else<span class="c-choice__mark c-choice__mark--check" aria-hidden="true">{{ $kit->icon('check') }}</span>@endif<span class="c-choice__label">{{ $__o['label'] }}</span>@if ($__delta && ($__delta['minor'] ?? 0) > 0)<span class="c-choice__price">+ {{ $kit->money($__delta) }}</span>@endif</label>
        @endforeach
    </div>
    @if (! empty($__g['required']))<p class="c-field__help c-field__error" data-kit-group-error hidden role="alert">{{ $__g['rule_label'] ?? '' }}</p>@endif
</fieldset>
