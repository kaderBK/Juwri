{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Admin-edited email templates: the stored HTML body with its {SHORT_CODE}
     merge tags filled in (each tag replaced in turn, as before). P6-314: wrapped in the shared mail frame (dark palette, color-scheme, dir from the
     recipient locale) so every template mail renders the same in Outlook.com dark mode and RTL. --}}
@php
    $mergedBody = (string) $body;
    foreach (($short_codes ?? []) as $mergeTag => $mergeValue) {
        $mergedBody = str_replace((string) $mergeTag, (string) $mergeValue, $mergedBody);
    }
@endphp
<x-mail.frame :title="(string) config('settings.site_title')">
@if (trim(strip_tags($mergedBody)) === '' && ! str_contains($mergedBody, '<img'))
    {{-- An empty template body would make the CSS inliner throw; send the site title instead. --}}
    <p>{{ config('settings.site_title') }}</p>
@else
    {!! $mergedBody !!}
@endif
</x-mail.frame>
