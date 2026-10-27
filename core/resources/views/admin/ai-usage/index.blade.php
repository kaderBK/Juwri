{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('AI Usage'))
@section('content')
<div class="ui-stack">
    {{-- Range filter --}}
    <form method="GET" class="ui-cluster">
        <select name="days" id="filter-days" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Range') }}">
            <option value="7" @selected($days === 7)>{{ ___('Last 7 days') }}</option>
            <option value="30" @selected($days === 30)>{{ ___('Last 30 days') }}</option>
            <option value="90" @selected($days === 90)>{{ ___('Last 90 days') }}</option>
        </select>
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Apply') }}</x-ui.button>
    </form>

    {{-- Stats --}}
    <section class="ui-kpis" aria-label="{{ ___('AI Usage') }}">
        <x-ui.stat :value="number_format($totalGenerations)" :label="___('Generations')" icon="sparkle" />
        <x-ui.stat :value="number_format($totalTokens)" :label="___('Total tokens')" icon="chart" />
        <x-ui.stat :value="$fallbackRate.'%'" :label="___('Fallback rate')" icon="refresh" tone="warning" />
        <x-ui.stat :value="number_format($distinctUsers)" :label="___('Distinct users')" icon="users" tone="success" />
    </section>

    <div class="ui-columns">
        {{-- By provider --}}
        <x-ui.card variant="flush" :title="___('By provider')">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Provider') }}</th>
                            <th>{{ ___('Generations') }}</th>
                            <th>{{ ___('Tokens') }}</th>
                            <th>{{ ___('Fallbacks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($byProvider as $row)
                        <tr>
                            <td data-primary data-label="{{ ___('Provider') }}">{{ $row->provider }}</td>
                            <td data-label="{{ ___('Generations') }}">{{ number_format($row->generations) }}</td>
                            <td data-label="{{ ___('Tokens') }}">{{ number_format($row->tokens) }}</td>
                            <td data-label="{{ ___('Fallbacks') }}">{{ number_format($row->fallbacks) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2">{{ ___('No AI generations in this range yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- By feature --}}
        <x-ui.card variant="flush" :title="___('By feature')">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Feature') }}</th>
                            <th>{{ ___('Generations') }}</th>
                            <th>{{ ___('Tokens') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($byFeature as $row)
                        <tr>
                            <td data-primary data-label="{{ ___('Feature') }}">{{ $row->feature }}</td>
                            <td data-label="{{ ___('Generations') }}">{{ number_format($row->generations) }}</td>
                            <td data-label="{{ ___('Tokens') }}">{{ number_format($row->tokens) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No AI generations in this range yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    {{-- Top users --}}
    <x-ui.card variant="flush" :title="___('Top users')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('User') }}</th>
                        <th>{{ ___('Generations') }}</th>
                        <th>{{ ___('Tokens') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($topUsers as $row)
                    <tr>
                        <td data-primary data-label="{{ ___('User') }}">
                            @php($u = $row->user_id ? $users->get($row->user_id) : null)
                            @if($u)
                                <a href="{{ route('admin.users.edit', $u->id) }}">{{ $u->name }}</a>
                                <small class="ui-cell-sub">{{ $u->email }}</small>
                            @elseif($row->user_id)
                                <span class="ui-text-2">#{{ $row->user_id }}</span>
                            @else
                                <span class="ui-text-2">{{ ___('System') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ ___('Generations') }}">{{ number_format($row->generations) }}</td>
                        <td data-label="{{ ___('Tokens') }}">{{ number_format($row->tokens) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No AI generations in this range yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- Recent generations --}}
    <x-ui.card variant="flush" :title="___('Recent generations')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Provider') }}</th>
                        <th>{{ ___('Model') }}</th>
                        <th>{{ ___('Feature') }}</th>
                        <th>{{ ___('Tokens') }}</th>
                        <th>{{ ___('Fallback') }}</th>
                        <th>{{ ___('User') }}</th>
                        <th>{{ ___('Time') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($recent as $row)
                    <tr>
                        <td data-primary data-label="{{ ___('Provider') }}">{{ $row->provider }}</td>
                        <td data-label="{{ ___('Model') }}"><span class="ui-small ui-mono">{{ $row->model ?? '—' }}</span></td>
                        <td data-label="{{ ___('Feature') }}">{{ $row->feature }}</td>
                        <td data-label="{{ ___('Tokens') }}">{{ number_format($row->prompt_tokens + $row->completion_tokens) }}</td>
                        <td data-label="{{ ___('Fallback') }}">
                            @if($row->fell_back)
                                <x-ui.tag tone="warning">{{ ___('Yes') }}</x-ui.tag>
                            @else
                                <span class="ui-text-2">&mdash;</span>
                            @endif
                        </td>
                        <td data-label="{{ ___('User') }}">
                            @php($u = $row->user_id ? $users->get($row->user_id) : null)
                            @if($u)
                                <a href="{{ route('admin.users.edit', $u->id) }}">{{ $u->name }}</a>
                            @elseif($row->user_id)
                                <span class="ui-text-2">#{{ $row->user_id }}</span>
                            @else
                                <span class="ui-text-2">{{ ___('System') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ ___('Time') }}">{{ $row->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ui-center ui-text-2">{{ ___('No AI generations in this range yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
