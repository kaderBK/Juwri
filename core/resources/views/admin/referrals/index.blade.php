{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Referral Conversions'))
@section('page_sub', ___('Approve or reject referral rewards'))
@section('content')
@php
    $statusLabels = ['pending' => ___('Pending'), 'approved' => ___('Approved'), 'paid' => ___('Paid'), 'rejected' => ___('Rejected')];
    $statusTones = ['pending' => 'warning', 'approved' => 'success', 'paid' => 'accent', 'rejected' => 'danger'];
@endphp
<div class="ui-stack">
    <form method="GET" class="ui-cluster">
        <select name="status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Status') }}" data-ui-autosubmit>
            <option value="">{{ ___('All statuses') }}</option>
            @foreach (['pending','approved','paid','rejected'] as $s)
                <option value="{{ $s }}" @selected(request('status') == $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
        <noscript><x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button></noscript>
    </form>

    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Referrer') }}</th>
                        <th>{{ ___('New User') }}</th>
                        <th>{{ ___('Reward') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Date') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($conversions as $conv)
                <tr>
                    <td data-primary data-label="{{ ___('Referrer') }}"><strong>{{ $conv->referral->user?->name ?? '—' }}</strong><small class="ui-cell-sub ui-mono">{{ $conv->referral->code }}</small></td>
                    <td data-label="{{ ___('New User') }}"><strong>{{ $conv->newUser?->name ?? '—' }}</strong><small class="ui-cell-sub">{{ $conv->newUser?->email }}</small></td>
                    <td data-label="{{ ___('Reward') }}"><span dir="ltr">${{ number_format($conv->reward_amount, 2) }}</span> <small class="ui-text-2">{{ $conv->reward_type }}</small></td>
                    {{-- Same labels as ReferralConversion::status_badge, drawn as a kit tag. --}}
                    <td data-label="{{ ___('Status') }}">
                        @if (isset($statusLabels[$conv->status]))
                            <x-ui.tag :tone="$statusTones[$conv->status]">{{ $statusLabels[$conv->status] }}</x-ui.tag>
                        @else
                            {{ $conv->status }}
                        @endif
                    </td>
                    <td data-label="{{ ___('Date') }}" class="ui-text-2">{{ $conv->created_at->format('Y-m-d') }}</td>
                    <td class="ui-end" data-label="">
                        @if ($conv->status === 'pending')
                            <div class="ui-cell-actions">
                                <form method="POST" action="{{ route('admin.referrals.approve', $conv) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="success" size="sm" icon="check">{{ ___('Approve') }}</x-ui.button>
                                </form>
                                <form method="POST" action="{{ route('admin.referrals.reject', $conv) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="danger" size="sm" icon="x">{{ ___('Reject') }}</x-ui.button>
                                </form>
                            </div>
                        @else
                            <span class="ui-text-2">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('No conversions yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($conversions->hasPages())
            <div class="ui-card__foot">{{ $conversions->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
