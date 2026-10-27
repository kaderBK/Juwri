{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Sign-in / sign-up layout for section-based auth views (@extends … 'layouts.auth').
  WI-16b: the page frame is the owner-ui kit (<x-ui.auth>); this file only hands the
  page's `content` section to it. Brand (active_brand_reseller(), brand_title(),
  brand_favicon()) is resolved inside the component.
--}}
<x-ui.auth>
@yield('content')
</x-ui.auth>
