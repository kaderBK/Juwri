{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- juwri mail button: a table cell with a solid fill, so it still looks like a
     button in clients that ignore padding on links (Outlook desktop). --}}
@props(['href', 'align' => null])
@php
    $btnAccent = '#4F46E5';
    try {
        $hex = config('settings.theme_color');
        if (is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
            $btnAccent = $hex;
        }
    } catch (\Throwable $e) {
    }
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" @if ($align) align="{{ $align }}" @endif style="margin:20px 0;">
    <tr>
        <td style="border-radius:10px;background:{{ $btnAccent }};">
            <a href="{{ $href }}" target="_blank" rel="noopener" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;line-height:1.2;color:#FFFFFF;text-decoration:none;border-radius:10px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
