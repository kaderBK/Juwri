{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-23 (AUP 3.1(b)): review pharmacy licence uploads before the store is published. --}}
@extends('admin.layout')
@section('title', ___('Pharmacy licences'))
@section('content')
<div class="ui-stack">
    <x-ui.banner :tone="$enabledCountries === [] ? 'warning' : 'info'">
        @if ($enabledCountries === [])
            {{ ___('Pharmacy selling is not enabled in any country (PHARMACY_ENABLED_COUNTRIES). Approved stores stay unpublished until their country is enabled.') }}
        @else
            {{ ___('Pharmacy selling is enabled in: :list', ['list' => implode(', ', $enabledCountries)]) }}
        @endif
    </x-ui.banner>

    <nav class="ui-segmented" aria-label="{{ ___('Status') }}">
        @foreach (['pending' => ___('Waiting for review'), 'approved' => ___('Approved'), 'rejected' => ___('Not accepted'), 'all' => ___('All')] as $key => $label)
            <a href="{{ route('admin.pharmacy-licences.index', ['status' => $key]) }}" @if ($status === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.card :title="___('Pharmacy licences')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Store') }}</th><th>{{ ___('Country') }}</th><th>{{ ___('Licence number') }}</th>
                    <th>{{ ___('Issuing authority') }}</th><th>{{ ___('Licence expiry date') }}</th>
                    <th>{{ ___('Responsible pharmacist') }}</th><th>{{ ___('Online-sales permit') }}</th>
                    <th>{{ ___('Status') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                @forelse ($licences as $l)
                    <tr>
                        <td data-primary data-label="{{ ___('Store') }}">{{ $l->store?->title ?? '#'.$l->store_id }}</td>
                        <td data-label="{{ ___('Country') }}"><span class="ui-ltr">{{ $l->country }}</span></td>
                        <td data-label="{{ ___('Licence number') }}"><span class="ui-ltr">{{ $l->licence_number }}</span></td>
                        <td data-label="{{ ___('Issuing authority') }}">{{ $l->issuing_authority }}</td>
                        <td data-label="{{ ___('Licence expiry date') }}"><span class="ui-ltr">{{ $l->expires_on->toDateString() }}</span></td>
                        <td data-label="{{ ___('Responsible pharmacist') }}">{{ trim(($l->responsible_person_name ?? '').' '.($l->responsible_person_registration ?? '')) ?: '—' }}</td>
                        <td data-label="{{ ___('Online-sales permit') }}">{{ $l->online_sales_permit ?: '—' }}</td>
                        <td data-label="{{ ___('Status') }}">{{ $l->status }}@if ($l->review_note)<div class="ui-text-2">{{ $l->review_note }}</div>@endif</td>
                        <td>
                            <a href="{{ route('admin.pharmacy-licences.document', $l->id) }}">{{ ___('Document') }}</a>
                            @if ($l->status !== 'approved')
                                <form method="POST" action="{{ route('admin.pharmacy-licences.approve', $l->id) }}">@csrf
                                    <x-ui.button type="submit" size="sm">{{ ___('Approve') }}</x-ui.button>
                                </form>
                            @endif
                            @if ($l->status !== 'rejected')
                                <form method="POST" action="{{ route('admin.pharmacy-licences.reject', $l->id) }}">@csrf
                                    <input type="text" name="review_note" class="ui-input" required maxlength="500" placeholder="{{ ___('Reason shown to the owner') }}" aria-label="{{ ___('Reason shown to the owner') }}">
                                    <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Reject') }}</x-ui.button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="ui-center ui-text-2">{{ ___('No records found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($licences->hasPages())<div class="ui-catalog__pager">{{ $licences->links() }}</div>@endif
    </x-ui.card>
</div>
@endsection
