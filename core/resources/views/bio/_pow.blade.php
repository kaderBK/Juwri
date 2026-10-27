{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Powered-by footer. $poweredBy is a reseller/white-label-aware string from Lane A's controller. --}}
@php $pb = trim((string) ($poweredBy ?? '')); @endphp
@if ($pb !== '')<p class="bio-pow">{{ $pb }}</p>@endif
