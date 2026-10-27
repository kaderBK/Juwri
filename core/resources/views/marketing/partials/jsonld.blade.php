{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Inline JSON-LD block. Pass $ld (array). Always hex-escapes < > & ' " so
     text from the database can never close the script element (same flags as
     the json_ld() helper). --}}
@if (! empty($ld))
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) !!}</script>
@endif
