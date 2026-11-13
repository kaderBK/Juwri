{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Google Business'))
@section('page_sub', ___('Stores that have connected a Google Business Profile.').' ('.$total.')')
@section('content')
{{-- session('success') is shown as a toast by the admin layout. --}}
<div class="ui-stack">
    <x-ui.card variant="flush" :title="___('Google Business connections')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Store') }}</th>
                        <th>{{ ___('Location') }}</th>
                        <th>{{ ___('State') }}</th>
                        <th>{{ ___('Menu sync') }}</th>
                        <th>{{ ___('Last synced') }}</th>
                        <th>{{ ___('Sync error') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-end">{{ ___('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td data-primary data-label="{{ ___('Store') }}">{{ $row['store'] }}</td>
                            <td data-label="{{ ___('Location') }}"><code class="ui-mono ui-break">{{ $row['location_id'] ?: '—' }}</code></td>
                            <td data-label="{{ ___('State') }}">
                                @php($tone = ['linked' => 'success', 'connected-no-location' => 'warning', 'incomplete' => 'neutral'][$row['state']] ?? 'neutral')
                                <x-ui.tag :tone="$tone">{{ $row['state'] }}</x-ui.tag>
                            </td>
                            <td data-label="{{ ___('Menu sync') }}">{{ $row['menu_sync'] ? ___('on') : ___('off') }}</td>
                            <td data-label="{{ ___('Last synced') }}">{{ $row['last_synced_at'] ?: '—' }}</td>
                            <td data-label="{{ ___('Sync error') }}">@if($row['sync_error'])<span class="ui-text-danger ui-break">{{ $row['sync_error'] }}</span>@else — @endif</td>
                            <td data-label="{{ ___('Status') }}">
                                <x-ui.tag :tone="$row['active'] ? 'success' : 'danger'">{{ $row['active'] ? ___('active') : ___('disabled') }}</x-ui.tag>
                            </td>
                            <td class="ui-end" data-label="">
                                <form method="post" action="{{ route('admin.google-business.toggle', $row['id']) }}">
                                    @csrf
                                    <x-ui.button type="submit" :variant="$row['active'] ? 'danger' : 'success'" size="sm">
                                        {{ $row['active'] ? ___('Disable') : ___('Enable') }}
                                    </x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="ui-center ui-text-2">{{ ___('No stores have connected Google Business yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="ui-card__foot">
            <span class="ui-small ui-text-2">{{ ___('Reseller bulk-connect is not enabled — each store owner connects their own profile.') }}</span>
        </div>
    </x-ui.card>
</div>
@endsection
