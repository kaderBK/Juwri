{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-05: the core's end-of-body parts for a kit theme: the storefront JS (cart, sheets, offline queue) and the cookie consent banner the
     footer's "Cookie settings" button re-opens. Core partials; nothing renders without a store (the conformance fixtures). --}}
@isset($store)
@if (empty($bookingMode) && empty($laundryMode))
@include('store_templates._partials.storefront-core')
@endif
@include('partials.cookie-consent')
@endisset
