{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php($__a = $vm['features']['announcement'] ?? null)
@if ($__a)
<div class="c-announce" role="region" aria-label="{{ $kit->s('Offers') }}">{{ $kit->icon($__a['icon'] ?? 'info') }}<p class="c-announce__text">{{ $kit->flow($__a['text']) }}</p><button class="c-iconbtn c-iconbtn--sm" type="button" data-kit-dismiss aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
@endif
