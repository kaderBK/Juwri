{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-13: the brand ring of the home header (monogram + logo), escaped — was a PHP string printed raw. --}}
<span class="brand-ring sm" data-initial="{{ $monogram }}">@if($hasLogo)<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-mono>@endif</span>
