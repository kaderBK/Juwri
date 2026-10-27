{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* P4-16: the item map of the ids a crave page asked for (`?fragment=crave&ids=1,2,3`), the same data.php the page uses. JSON. */
    include resource_path('views/store_templates/crave/data.php');
@endphp
@json(['items' => (object) $jsItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
