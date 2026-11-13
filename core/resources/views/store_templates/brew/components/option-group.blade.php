{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: a choice group in the cafe pattern — segmented control for 2-4 short, equal choices (Size, Sweetness), chips that
     wrap for longer radio lists (Milk; the chosen chip carries a check, never colour alone), rows for extras (checkboxes). --}}
@php
    $__g = $group;
    $__opts = $__g['options'];
    $__kind = $__g['kind'] === 'checkbox' ? 'checkbox' : 'radio';
    $__min = $__g['min'] ?? 0;
    $__max = $__g['max'] ?? 0;
    // the rule line already says Required / Optional in the view model; the badge carries that, so the line keeps only the rule
    $__rule = (string) preg_replace('/^(?:'.preg_quote($kit->s('Required'), '/').'|'.preg_quote($kit->s('Optional'), '/').')\s*[·:]\s*/ui', '', (string) ($__g['rule_label'] ?? ''));
    $__selected = \Juwri\Core\Support\ViewHelpers::selectedCount($__opts);
    $__fits = count($__opts) >= 2 && count($__opts) <= 4 && \Juwri\Core\Support\ViewHelpers::labelsFit($__opts, 10);
    $__style = match (true) {
        $__kind === 'checkbox' => 'rows',
        $__g['kind'] === 'chips' => 'chips',
        $__g['kind'] === 'segmented' => count($__opts) <= 4 ? 'seg' : 'chips',
        default => $__fits ? 'seg' : 'chips',
    };
@endphp
<fieldset class="c-group c-group--{{ $__style }}" data-kit-group="{{ $__g['id'] }}" data-min="{{ $__min }}" data-max="{{ $__max }}" @if (! empty($__g['required'])) data-required @endif>
    <legend class="c-group__legend"><span class="c-group__title">{{ $__g['label'] }}</span><span class="c-group__rule">{{ $__rule }}</span>@if (! empty($__g['required']))<span class="c-badge c-badge--solid c-group__badge">{{ $kit->s('Required') }}</span>@else<span class="c-badge c-badge--muted c-group__badge">{{ $kit->s('Optional') }}</span>@endif</legend>
    <div class="c-group__opts{{ $__style === 'seg' ? ' c-group__opts--seg' : ($__style === 'chips' ? ' c-group__opts--chips' : '') }}">
        @foreach ($__opts as $__o)
        @php
            $__oid = $__g['id'].'-'.$__o['id'];
            $__disabled = $__kind === 'checkbox' && $__max && $__selected >= $__max && empty($__o['selected']);
            $__delta = $__o['price_delta'] ?? null;
            $__price = $__delta && ($__delta['minor'] ?? 0) > 0 ? $kit->money($__delta) : null;
            $__input = '';
        @endphp
        @if ($__style === 'rows')
        <label class="c-choice" for="o-{{ $__oid }}"><input class="c-choice__input u-vh" type="checkbox" id="o-{{ $__oid }}" name="g-{{ $__g['id'] }}[]" value="{{ $__o['id'] }}" data-group="{{ $__g['id'] }}" @checked(! empty($__o['selected'])) @disabled($__disabled || (isset($__o['available']) && ! $__o['available']))><span class="c-choice__mark c-choice__mark--check" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-choice__label">{{ $__o['label'] }}</span>@if ($__price)<span class="c-choice__price">+ {{ $__price }}</span>@endif</label>
        @else
        <label class="c-opt c-opt--{{ $__style === 'seg' ? 'seg' : 'chip' }}" for="o-{{ $__oid }}"><input class="c-opt__input u-vh" type="radio" id="o-{{ $__oid }}" name="g-{{ $__g['id'] }}" value="{{ $__o['id'] }}" data-group="{{ $__g['id'] }}" @checked(! empty($__o['selected'])) @disabled(isset($__o['available']) && ! $__o['available'])><span class="c-opt__face">@if ($__style === 'chips'){{ $kit->icon('check') }}@endif<span class="c-opt__label">{{ $__o['label'] }}</span>@if ($__price)<span class="c-opt__price">+ {{ $__price }}</span>@endif</span></label>
        @endif
        @endforeach
    </div>
    @if (! empty($__g['required']))<p class="c-field__help c-field__error" data-kit-group-error hidden role="alert">{{ $__g['rule_label'] ?? '' }}</p>@endif
</fieldset>
