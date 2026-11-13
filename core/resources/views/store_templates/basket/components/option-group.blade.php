{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: a choice group of the product sheet — the weight steps as four chips with their estimate for screen readers (a check
     mark, never colour alone), the store's out-of-stock choice as rows each with what the picker does (not priced, carried on the
     line as its substitution), other single choices as chips, multiple choices as checkbox rows. --}}
@php
    $__g = $group;
    $__kind = $__g['kind'] === 'checkbox' ? 'checkbox' : 'radio';
    $__weight = $__g['kind'] === 'weight';
    $__sub = ($__g['purpose'] ?? null) === 'substitution';
    $__chips = $__weight || ($__kind === 'radio' && ! $__sub && count($__g['options']) <= 6 && \Juwri\Core\Support\ViewHelpers::labelsFit($__g['options'], 12));
    $__max = $__g['max'] ?? 0;
    $__selected = \Juwri\Core\Support\ViewHelpers::selectedCount($__g['options']);
@endphp
<fieldset class="c-group{{ $__weight ? ' c-group--weight' : '' }}" data-kit-group="{{ $__g['id'] }}" data-min="{{ $__g['min'] ?? 0 }}" data-max="{{ $__max }}" @if (! empty($__g['required'])) data-required @endif>
    <legend class="c-group__legend"><span class="c-group__title">{{ $__g['label'] }}</span>@if (! empty($__g['rule_label']))<span class="c-group__rule">{{ $__g['rule_label'] }}</span>@endif</legend>
    <div class="{{ $__chips ? 'c-chip-opts' : 'c-group__opts' }}">
    @foreach ($__g['options'] as $__o)
        @php
            $__oid = $__g['id'].'-'.$__o['id'];
            $__delta = $__o['price_delta'] ?? null;
            $__off = (isset($__o['available']) && ! $__o['available']) || ($__kind === 'checkbox' && $__max && $__selected >= $__max && empty($__o['selected']));
        @endphp
        @if ($__chips)
        <label class="c-chip-opt" for="o-{{ $__oid }}"><input class="c-chip-opt__input u-vh" type="radio" id="o-{{ $__oid }}" name="g-{{ $__g['id'] }}" value="{{ $__o['id'] }}" @if ($__sub) data-kit-substitution @else data-group="{{ $__g['id'] }}" @endif @checked(! empty($__o['selected'])) @disabled($__off)><span class="c-chip-opt__face">{{ $kit->icon('check') }}<span>{{ $__o['label'] }}</span>@if ($__weight && $__delta)<span class="u-sr">, ~ {{ $kit->money($__delta) }}</span>@endif</span></label>
        @elseif ($__sub || $__kind === 'radio')
        <label class="c-choice c-choice--row" for="o-{{ $__oid }}"><input class="c-choice__input u-vh" type="radio" id="o-{{ $__oid }}" name="g-{{ $__g['id'] }}" value="{{ $__o['id'] }}" @if ($__sub) data-kit-substitution @else data-group="{{ $__g['id'] }}" @endif @checked(! empty($__o['selected'])) @disabled($__off)><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span><span class="c-choice__text"><span class="c-choice__label">{{ $__o['label'] }}</span>@if (! empty($__o['sub_label']))<span class="c-choice__sub">{{ $__o['sub_label'] }}</span>@endif</span>@if ($__delta && ($__delta['minor'] ?? 0) > 0)<span class="c-choice__price">+ {{ $kit->money($__delta) }}</span>@endif</label>
        @else
        <label class="c-choice c-choice--row" for="o-{{ $__oid }}"><input class="c-choice__input u-vh" type="checkbox" id="o-{{ $__oid }}" name="g-{{ $__g['id'] }}[]" value="{{ $__o['id'] }}" @if ($__sub) data-kit-substitution @else data-group="{{ $__g['id'] }}" @endif @checked(! empty($__o['selected'])) @disabled($__off)><span class="c-choice__mark c-choice__mark--check" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-choice__text"><span class="c-choice__label">{{ $__o['label'] }}</span></span>@if ($__delta && ($__delta['minor'] ?? 0) > 0)<span class="c-choice__price">+ {{ $kit->money($__delta) }}</span>@endif</label>
        @endif
    @endforeach
    </div>
    @if (! empty($__g['required']) && ! $__sub)<p class="c-field__help c-field__error" data-kit-group-error hidden role="alert">{{ $__g['rule_label'] ?? $kit->s('Choose one') }}</p>@endif
</fieldset>
