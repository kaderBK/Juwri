{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-23: read-only list of accepted legal document versions (who, which document and version, when, IP as stored). --}}
@extends('admin.layout')
@section('title', ___('Legal acceptances'))
@section('content')
<div class="ui-stack">
    <form method="GET" action="{{ route('admin.legal-acceptances.index') }}" class="ui-cluster" role="search">
        <input type="search" name="user" value="{{ $term }}" class="ui-input" maxlength="100"
               placeholder="{{ ___('Filter by user (name, email or ID)') }}" aria-label="{{ ___('Filter by user (name, email or ID)') }}">
        <x-ui.button type="submit" size="sm">{{ ___('Filter') }}</x-ui.button>
        @if ($term !== '')
            <a href="{{ route('admin.legal-acceptances.index') }}">{{ ___('Clear filter') }}</a>
        @endif
    </form>

    <x-ui.card :title="___('Legal acceptances')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('User') }}</th><th>{{ ___('Document') }}</th><th>{{ ___('Version') }}</th>
                    <th>{{ ___('Source') }}</th><th>{{ ___('Accepted at') }}</th><th>{{ ___('IP address') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($acceptances as $a)
                    @php($u = $users->get($a->user_id))
                    <tr>
                        <td data-primary data-label="{{ ___('User') }}">
                            <a href="{{ route('admin.legal-acceptances.index', ['user' => $a->user_id]) }}">{{ $u?->name ?: ('#'.$a->user_id) }}</a>
                            @if ($u?->email)<div class="ui-text-2 ui-ltr">{{ $u->email }}</div>@endif
                        </td>
                        <td data-label="{{ ___('Document') }}">{{ \Juwri\Core\Legal\LegalDocuments::label($a->document) }}</td>
                        <td data-label="{{ ___('Version') }}"><span class="ui-ltr">{{ $a->version }}</span></td>
                        <td data-label="{{ ___('Source') }}">{{ ['signup' => ___('At sign-up'), 'banner' => ___('Change notice'), 'checkout' => ___('At checkout')][$a->source] ?? $a->source }}</td>
                        <td data-label="{{ ___('Accepted at') }}"><span class="ui-ltr">{{ $a->accepted_at?->format('Y-m-d H:i') }}</span></td>
                        <td data-label="{{ ___('IP address') }}"><span class="ui-ltr">{{ $a->ip ?: '—' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('No records found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($acceptances->hasPages())<div class="ui-catalog__pager">{{ $acceptances->links() }}</div>@endif
    </x-ui.card>
</div>
@endsection
