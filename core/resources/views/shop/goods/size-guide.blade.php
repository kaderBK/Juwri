{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $guide['title'] }} — {{ $store->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem;">
<h1>{{ $guide['title'] }}</h1>
<p>{{ $guide['unit'] === 'in' ? ___('Measurements in inches') : ___('Measurements in centimetres') }}</p>
<div style="overflow-x: auto;">
<table style="border-collapse: collapse; width: 100%;">
    <thead>
    <tr>
        @foreach($guide['columns'] as $column)
            <th scope="col" style="border-bottom: 1px solid #999; padding: .4rem; text-align: start;">{{ $column }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach($guide['rows'] as $row)
        <tr>
            @foreach((array) $row as $cell)
                <td style="border-bottom: 1px solid #ddd; padding: .4rem;">{{ $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@if(! empty($guide['note']))
    <p>{{ $guide['note'] }}</p>
@endif
</body>
</html>
