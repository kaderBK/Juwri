{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Reserve with Google'))
@section('content')
<div class="ui-stack">

@unless($platform['enabled'])
    <x-ui.banner tone="warning">{{ ___('Reserve with Google is DARK — the platform integration is off (RWG_ENABLED). Booking server returns 503 and no feeds are uploaded.') }}</x-ui.banner>
@endunless

<section class="ui-kpis" aria-label="{{ ___('Reserve with Google') }}">
    <x-ui.stat :value="$platform['enabled'] ? ___('On') : ___('Off')" :label="___('Platform integration')" icon="calendar" :tone="$platform['enabled'] ? 'success' : 'danger'" />
    <x-ui.stat :value="$platform['driver'] === 'real' ? ___('Production') : ___('Sandbox')" :label="___('Driver')" icon="calendar" />
    <x-ui.stat :value="$platform['creds'] ? ___('Ready') : ___('Missing')" :label="___('Booking-server auth')" icon="calendar" :tone="$platform['creds'] ? 'success' : 'danger'" />
    <x-ui.stat :value="$platform['sftp'] ? ___('Configured') : ___('None')" :label="___('Feed SFTP')" icon="calendar" />
</section>

<x-ui.card variant="flush" :title="___('Feed artifacts')">
    <div class="ui-table-wrap">
    <table class="ui-table">
        <thead><tr><th>{{ ___('Feed') }}</th><th>{{ ___('Last generated') }}</th><th>{{ ___('Size') }}</th></tr></thead>
        <tbody>
        @foreach ($feeds as $name => $info)
            <tr>
                <td data-primary data-label="{{ ___('Feed') }}"><code class="ui-mono">{{ $name }}</code></td>
                <td data-label="{{ ___('Last generated') }}">@if($info){{ $info['at']->diffForHumans() }}@else<span class="ui-text-2">{{ ___('never') }}</span>@endif</td>
                <td data-label="{{ ___('Size') }}">@if($info){{ number_format($info['bytes']) }} B@else—@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</x-ui.card>

<x-ui.card variant="flush" :title="___('Last feed drop')" data-rwg-last-drop>
    @if ($lastDrop)
        <p>
            <x-ui.tag :tone="in_array($lastDrop['status'], ['ok'], true) ? 'success' : ($lastDrop['status'] === 'written' ? '' : 'danger')" data-rwg-drop-status="{{ $lastDrop['status'] }}">
                {{ match ($lastDrop['status']) { 'ok' => ___('Uploaded'), 'partial' => ___('Partly uploaded'), 'failed' => ___('Upload failed'), default => ___('Written, not uploaded') } }}
            </x-ui.tag>
            <span class="ui-text-2">{{ \Illuminate\Support\Carbon::parse($lastDrop['at'])->diffForHumans() }}</span>
            @if (! empty($lastDrop['error']))<br><span class="ui-text-2">{{ $lastDrop['error'] }}</span>@endif
        </p>
        <div class="ui-table-wrap">
        <table class="ui-table">
            <thead><tr><th>{{ ___('Feed') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Attempts') }}</th><th>{{ ___('Details') }}</th></tr></thead>
            <tbody>
            @foreach ($lastDrop['files'] as $f)
                <tr>
                    <td data-primary data-label="{{ ___('Feed') }}"><code class="ui-mono">{{ $f['name'] }}</code></td>
                    <td data-label="{{ ___('Status') }}">{{ $f['status'] }}</td>
                    <td data-label="{{ ___('Attempts') }}">{{ $f['attempts'] }}</td>
                    <td data-label="{{ ___('Details') }}" class="ui-text-2">{{ $f['error'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @if (($lastDrop['problem_count'] ?? 0) > 0)
            <p data-rwg-problems>{{ ___('Entries left out because they broke a Google field rule:') }} {{ $lastDrop['problem_count'] }}</p>
            <ul class="ui-text-2">@foreach ($lastDrop['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul>
        @endif
    @else
        <p class="ui-text-2">{{ ___('No feed drop has run yet.') }}</p>
    @endif
</x-ui.card>

<x-ui.card variant="flush" :title="___('Enabled stores')">
    <div class="ui-table-wrap">
    <table class="ui-table">
        <thead><tr>
            <th>{{ ___('Store') }}</th><th>{{ ___('Reservations') }}</th><th>{{ ___('Waitlist') }}</th>
            <th>{{ ___('Covers/slot') }}</th><th>{{ ___('Interval') }}</th><th class="ui-end">{{ ___('Action') }}</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $r)
            <tr>
                <td data-primary data-label="{{ ___('Store') }}">{{ $r['title'] }} <span class="ui-text-2">#{{ $r['id'] }}</span></td>
                <td data-label="{{ ___('Reservations') }}">@if($r['reservations'])<x-ui.tag tone="success">{{ ___('On') }}</x-ui.tag>@else<x-ui.tag>{{ ___('Off') }}</x-ui.tag>@endif</td>
                <td data-label="{{ ___('Waitlist') }}">@if($r['waitlist'])<x-ui.tag tone="success">{{ ___('On') }}</x-ui.tag>@else<x-ui.tag>{{ ___('Off') }}</x-ui.tag>@endif</td>
                <td data-label="{{ ___('Covers/slot') }}">{{ $r['covers'] === 0 ? ___('unlimited') : $r['covers'] }}</td>
                <td data-label="{{ ___('Interval') }}">{{ $r['interval'] }} min</td>
                <td class="ui-end" data-label="">
                    @if($r['reservations'])
                    <form action="{{ route('admin.reserve-google.kill', $r['id']) }}" method="post"
                          data-ui-confirm="{{ ___('Disable Reserve with Google reservations for this store?') }}" data-ui-danger>
                        @csrf
                        <x-ui.button type="submit" variant="danger" size="sm">{{ ___('Disable') }}</x-ui.button>
                    </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('No stores have enabled Reserve with Google yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>

</div>
@endsection
