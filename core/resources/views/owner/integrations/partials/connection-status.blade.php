{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared connection status chip + test-connection button + last-synced line.
  Used by the Foodics and Marn POS pages (owner UI kit markup).
    $connected   (bool)
    $lastSynced  (string|null)  human date
    $testAction  (string|null)  POST route for "Test connection" (optional)
    $rid         (int)
    $label       (string|null)  provider label
--}}
<div class="integration-connection-status ui-connstat">
    <div class="ui-connstat__text">
        <span class="ui-connstat__line">
            @if($connected)
                <x-ui.pill tone="success">{{ ___('Connected') }}</x-ui.pill>
            @else
                <x-ui.pill>{{ ___('Not connected yet') }}</x-ui.pill>
            @endif
            @if(!empty($label))
                <span class="ui-connstat__label">{{ $label }}</span>
            @endif
        </span>
        @if(!empty($lastSynced))
            <small class="ui-connstat__sub">{{ ___('Last synced') }}: {{ $lastSynced }}</small>
        @endif
    </div>
    @if(!empty($testAction))
        <form method="post" action="{{ $testAction }}?store_id={{ $rid }}">
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm" :disabled="! $connected">{{ ___('Test connection') }}</x-ui.button>
        </form>
    @endif
</div>
