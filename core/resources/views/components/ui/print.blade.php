{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Kit print page: a standalone document for anything the owner or staff print
    (receipts, kitchen tickets, tent cards, QR sheets, folios, payslips).

    No shell, no app scripts. On screen the sheet sits on the kit background with
    a small toolbar; under @media print only the sheet is printed.

    Props:
      title      document title
      paper      a4 | a6 | receipt  (receipt = 72 mm thermal roll, monospace)
      autoprint  open the print dialog once the page (and its images) load
      dir        ltr|rtl (defaults to the current locale)
    Slots: default = the sheet, head = extra <style nonce> for the page.
--}}
@props([
    'title' => '',
    'paper' => 'a4',
    'autoprint' => false,
    'dir' => null,
])
@php
    if ($dir === null) {
        try {
            $dir = is_rtl(app()->getLocale()) ? 'rtl' : 'ltr';
        } catch (\Throwable $e) {
            $dir = 'ltr';
        }
    }
    $printPaper = in_array($paper, ['a4', 'a6', 'receipt'], true) ? $paper : 'a4';
    $printCss = 'assets/owner-ui/print.css';
    $printHref = asset($printCss).'?v='.(is_file(public_path($printCss)) ? filemtime(public_path($printCss)) : config('appinfo.version', '1'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ $printHref }}">
    @if ($printPaper === 'receipt')
        <style nonce="{{ csp_nonce() }}">@page { size: 80mm auto; margin: 0; }</style>
    @elseif ($printPaper === 'a6')
        <style nonce="{{ csp_nonce() }}">@page { size: A4; margin: 8mm; }</style>
    @else
        <style nonce="{{ csp_nonce() }}">@page { size: A4; margin: 12mm; }</style>
    @endif
    {{ $head ?? '' }}
</head>
<body class="ui-print ui-print--{{ $printPaper }}">
    <div class="ui-print__bar">
        <button type="button" class="ui-print__btn" data-ui-print>{{ ___('Print') }}</button>
    </div>
    <main class="ui-print__sheet">
        {{ $slot }}
    </main>
    <script nonce="{{ csp_nonce() }}">
        (function () {
            document.querySelectorAll('[data-ui-print]').forEach(function (b) {
                b.addEventListener('click', function () { window.print(); });
            });
            @if ($autoprint)
            window.addEventListener('load', function () { window.print(); });
            @endif
        })();
    </script>
</body>
</html>
