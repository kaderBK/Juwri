{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-2: server-side in-page tab bar for a feature cluster. Links (not hidden
     panes) — the pages stay separate, chromed as tabs. Pass tabs via
     juwri_feature_tabs('<cluster>'). --}}
@php $__ftabs = $tabs ?? []; @endphp
@if(count($__ftabs) > 1)
    <nav class="ui-ftabs" aria-label="{{ $ariaLabel ?? ___('Views') }}">
        @foreach($__ftabs as $__ft)
            <a class="ui-chip ui-ftabs__tab" href="{{ $__ft['url'] }}"
               @if($__ft['active'] ?? false) aria-current="page" @endif>
                {{ $__ft['label'] }}
            </a>
        @endforeach
    </nav>
@endif
