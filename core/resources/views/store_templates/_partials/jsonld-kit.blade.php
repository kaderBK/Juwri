{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}
{{-- P6-305: the kit layout's schema.org JSON-LD (product page / store head), kept out of the kit views, which hold no raw
     output or inline script (KitViewSourceTest). json_ld() escapes with JSON_HEX_TAG|AMP|APOS|QUOT. --}}
@if (! empty($jsonLd))<script type="application/ld+json" nonce="{{ csp_nonce() }}">{!! json_ld($jsonLd) !!}</script>@endif
