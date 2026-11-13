{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('API request logs'))
@section('page_title', ___('API request logs'))
@section('page_sub', ___('The last 7 days of requests made with your API tokens.'))

@section('page_actions')
    <x-ui.button :href="route('api.index')" variant="ghost" icon="back">{{ ___('API & webhooks') }}</x-ui.button>
@endsection

@section('content')
<x-ui.card>
    <form method="get" action="{{ route('api.requestLogs') }}" class="ui-form-grid">
        <div>
            <label class="ui-label">{{ ___('Token') }}</label>
            <select name="token_id" class="ui-input">
                <option value="">{{ ___('All tokens') }}</option>
                @foreach($tokens as $t)
                    <option value="{{ $t->id }}" @selected((string) request('token_id') === (string) $t->id)>{{ $t->name ?: ('#'.$t->id) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label">{{ ___('Status') }}</label>
            <input type="number" name="status" class="ui-input" value="{{ request('status') }}" placeholder="200">
        </div>
        <div>
            <x-ui.button type="submit" variant="secondary" icon="filter">{{ ___('Filter') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card>
    @if($logs->isEmpty())
        <x-ui.empty :title="___('No API requests logged yet.')" />
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Time') }}</th><th>{{ ___('Method') }}</th><th>{{ ___('Path') }}</th><th>{{ ___('Status') }}</th><th class="ui-end">{{ ___('Duration') }}</th></tr></thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td class="ui-text-2" data-label="{{ ___('Time') }}">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td data-label="{{ ___('Method') }}"><code>{{ $log->method }}</code></td>
                            <td class="ui-truncate" style="max-width:340px;" data-label="{{ ___('Path') }}">{{ $log->path }}</td>
                            <td data-label="{{ ___('Status') }}">
                                @php($s = (int) $log->status)
                                <x-ui.tag :tone="$s >= 500 ? 'danger' : ($s >= 400 ? 'warning' : 'success')">{{ $log->status ?: '—' }}</x-ui.tag>
                            </td>
                            <td class="ui-end" data-label="{{ ___('Duration') }}">{{ $log->duration_ms !== null ? $log->duration_ms.' ms' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $logs->links() }}</div>
    @endif
</x-ui.card>
@endsection
