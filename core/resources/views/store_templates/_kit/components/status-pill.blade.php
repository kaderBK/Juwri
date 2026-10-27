{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__st = $vm['store']['status'];
    $__label = ($__st['paused_reason'] ?? null) ?: ($__st['label'] ?? null) ?: ($__st['open'] ? $kit->s('Open Now') : $kit->s('Closed'));
@endphp
<p class="c-status c-status--{{ $__st['open'] ? 'open' : 'closed' }}"><span class="c-status__dot" aria-hidden="true"></span><span class="c-status__label">{{ $kit->flow($__label) }}</span></p>
