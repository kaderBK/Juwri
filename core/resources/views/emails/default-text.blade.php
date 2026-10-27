{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}
{{-- Plain-text alternative of emails.default (P6-314: the HTML part now carries the full mail frame). --}}
@php
    $mergedBody = (string) $body;
    foreach (($short_codes ?? []) as $mergeTag => $mergeValue) {
        $mergedBody = str_replace((string) $mergeTag, (string) $mergeValue, $mergedBody);
    }
    $plain = trim(html_entity_decode(strip_tags(preg_replace('#</(p|div|h[1-6]|li|tr)>|<br\s*/?>#i', "\n", $mergedBody))));
@endphp
{!! $plain !== '' ? $plain : (string) config('settings.site_title') !!}
