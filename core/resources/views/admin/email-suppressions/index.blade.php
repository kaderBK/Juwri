{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P6-249: addresses the mail provider reported as hard-bounced or as spam complaints. Bulk mail skips them. --}}
@extends('admin.layout')
@section('title', ___('Suppressed e-mails'))
@section('page_sub', ___('Campaigns and digests skip these addresses. Remove one only when the person asks to receive mail again.'))
@section('content')
<div class="ui-stack">
    <form method="GET" action="{{ route('admin.email-suppressions.index') }}" class="ui-cluster" role="search">
        <input type="search" name="q" value="{{ $term }}" class="ui-input" maxlength="100"
               placeholder="{{ ___('Search e-mail') }}" aria-label="{{ ___('Search e-mail') }}">
        <select name="reason" class="ui-input ui-input--auto" aria-label="{{ ___('Reason') }}">
            <option value="">{{ ___('All reasons') }}</option>
            <option value="bounce" @selected($reason === 'bounce')>{{ ___('E-mail bounced') }}</option>
            <option value="complaint" @selected($reason === 'complaint')>{{ ___('Marked as spam') }}</option>
        </select>
        <x-ui.button type="submit" size="sm">{{ ___('Filter') }}</x-ui.button>
    </form>

    <x-ui.card :title="___('Suppressed e-mails')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Email') }}</th><th>{{ ___('Reason') }}</th><th>{{ ___('Provider') }}</th>
                    <th>{{ ___('Events') }}</th><th>{{ ___('Last event') }}</th><th></th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td data-primary data-label="{{ ___('Email') }}"><span class="ui-ltr ui-break">{{ $row->email }}</span>
                            @if ($row->detail)<div class="ui-text-2 ui-break"><small>{{ $row->detail }}</small></div>@endif
                        </td>
                        <td data-label="{{ ___('Reason') }}"><x-ui.tag :tone="$row->reason === 'complaint' ? 'danger' : 'warning'">{{ $row->reason === 'complaint' ? ___('Marked as spam') : ___('E-mail bounced') }}</x-ui.tag></td>
                        <td data-label="{{ ___('Provider') }}">{{ $row->provider }}</td>
                        <td data-label="{{ ___('Events') }}">{{ $row->events }}</td>
                        <td data-label="{{ ___('Last event') }}"><span class="ui-ltr">{{ $row->last_event_at?->format('Y-m-d H:i') }}</span></td>
                        <td>
                            <form method="POST" action="{{ route('admin.email-suppressions.destroy', $row->id) }}">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Remove') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('No records found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="ui-card__foot">{{ $rows->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
