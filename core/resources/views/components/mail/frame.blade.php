{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    juwri mail frame. One table-based shell for every HTML email the app sends.

    Mail clients drop <link> tags, most ignore <style>, and many rewrite colours
    in dark mode, so every colour the message needs is inline; the <style> block
    only adds a dark palette (prefers-color-scheme / Outlook.com's data-ogsc) and
    a narrow-screen padding tweak for clients that honour it.

    Props:
      title      <title> and screen-reader name of the message
      heading    optional big line at the top of the card
      eyebrow    optional small caps line above the heading
      brand      name in the header bar (defaults to the site title)
      preheader  optional inbox preview text (hidden in the body)
      dir        ltr|rtl (defaults to the current locale)
    Slots: default = body, footer = small print under the card body.
--}}
@props([
    'title' => '',
    'heading' => null,
    'eyebrow' => null,
    'brand' => null,
    'preheader' => null,
    'dir' => null,
])
@php
    $mailLocale = str_replace('_', '-', app()->getLocale());
    if ($dir === null) {
        try {
            $dir = function_exists('is_rtl') && is_rtl(app()->getLocale()) ? 'rtl' : 'ltr';
        } catch (\Throwable $e) {
            $dir = 'ltr';
        }
    }
    $mailStart = $dir === 'rtl' ? 'right' : 'left';
    $mailBrand = $brand;
    if ($mailBrand === null || $mailBrand === '') {
        $mailBrand = (string) (config('settings.site_title') ?: config('app.name'));
    }
    $mailAccent = '#4F46E5';
    try {
        $hex = config('settings.theme_color');
        if (is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
            $mailAccent = $hex;
        }
    } catch (\Throwable $e) {
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $mailLocale }}" dir="{{ $dir }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title }}</title>
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; }
    a { color: {{ $mailAccent }}; }
    @media (max-width: 620px) {
        .jm-pad { padding-left: 20px !important; padding-right: 20px !important; }
        .jm-cell { display: block !important; width: 100% !important; }
    }
    @media (prefers-color-scheme: dark) {
        .jm-page { background: #121215 !important; }
        .jm-card { background: #1B1B20 !important; border-color: #2A2A31 !important; }
        .jm-soft { background: #232329 !important; border-color: #2A2A31 !important; }
        .jm-ink { color: #F4F2ED !important; }
        .jm-muted { color: #A9A9B6 !important; }
        .jm-line { border-color: #2A2A31 !important; }
    }
    [data-ogsc] .jm-page { background: #121215 !important; }
    [data-ogsc] .jm-card { background: #1B1B20 !important; }
    [data-ogsc] .jm-soft { background: #232329 !important; }
    [data-ogsc] .jm-ink { color: #F4F2ED !important; }
    [data-ogsc] .jm-muted { color: #A9A9B6 !important; }
</style>
</head>
<body class="jm-page" style="margin:0;padding:0;background:#F4F2ED;">
@if ($preheader)
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">{{ $preheader }}</div>
@endif
<table role="presentation" class="jm-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F2ED;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" class="jm-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;background:#FFFFFF;border:1px solid #ECE9E2;border-radius:14px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Tahoma,Arial,sans-serif;">
                <tr>
                    <td class="jm-pad jm-line" style="padding:20px 32px;border-bottom:1px solid #ECE9E2;text-align:{{ $mailStart }};">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:{{ $mailAccent }};vertical-align:middle;"></span>
                        <span class="jm-ink" style="font-size:15px;font-weight:700;color:#17171C;vertical-align:middle;">&nbsp;{{ $mailBrand }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="jm-pad jm-ink" style="padding:28px 32px 8px;font-size:15px;line-height:1.6;color:#17171C;text-align:{{ $mailStart }};">
                        @if ($eyebrow)
                            <p class="jm-muted" style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#76767F;">{{ $eyebrow }}</p>
                        @endif
                        @if ($heading)
                            <h1 class="jm-ink" style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:700;color:#17171C;">{{ $heading }}</h1>
                        @endif
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td class="jm-pad jm-muted jm-line" style="padding:16px 32px 24px;border-top:1px solid #ECE9E2;font-size:12px;line-height:1.6;color:#76767F;text-align:{{ $mailStart }};">
                        @isset($footer)
                            {{ $footer }}
                        @else
                            {{ $mailBrand }}
                        @endisset
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
