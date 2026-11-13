{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Account security'))
@section('content')
{{-- A1: privileged accounts, their two-factor state and recent sign-ins. --}}
<div class="ui-stack">
    <x-ui.banner :tone="$force2fa ? 'success' : 'warning'">
        @if ($force2fa)
            {{ ___('Two-factor authentication is required for superadmins and reseller owners.') }}
        @else
            {{ ___('Two-factor authentication is optional for superadmins and reseller owners. The owner can require it by setting FORCE_2FA_ADMIN=true in the server .env file.') }}
        @endif
    </x-ui.banner>

    {{-- P1-20 (J28): origin separation health (single-host installs get this warning). --}}
    @foreach ($hostWarnings ?? [] as $__hostWarning)
        <x-ui.banner tone="warning">{{ $__hostWarning }}</x-ui.banner>
    @endforeach

    @foreach ([[___('Superadmins'), $admins], [___('Reseller owners'), $resellerOwners]] as [$__title, $__users])
        <x-ui.card variant="flush" :title="$__title.' ('.$__users->count().')'">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ ___('Name') }}</th>
                            <th>{{ ___('Email') }}</th>
                            <th>{{ ___('Two-factor') }}</th>
                            <th>{{ ___('Last sign-in') }}</th>
                            <th>{{ ___('New devices') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($__users as $__u)
                            @php $__ll = $lastLogins[$__u->id] ?? null; @endphp
                            <tr>
                                <td data-label="#" class="ui-text-2">{{ $__u->id }}</td>
                                <td data-primary data-label="{{ ___('Name') }}">{{ $__u->name }}</td>
                                <td data-label="{{ ___('Email') }}"><span class="ui-break">{{ $__u->email }}</span></td>
                                <td data-label="{{ ___('Two-factor') }}">
                                    <x-ui.tag :tone="$__u->hasTwoFactorEnabled() ? 'success' : 'danger'">{{ $__u->hasTwoFactorEnabled() ? ___('On') : ___('Off') }}</x-ui.tag>
                                </td>
                                <td data-label="{{ ___('Last sign-in') }}"><span class="ui-small">{{ $__ll?->last_at ? \Illuminate\Support\Carbon::parse($__ll->last_at)->diffForHumans() : '—' }}</span></td>
                                <td data-label="{{ ___('New devices') }}">{{ (int) ($__ll->new_devices ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('None') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endforeach

    <x-ui.card variant="flush" :title="___('Recent privileged sign-ins')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('When') }}</th>
                        <th>{{ ___('Account') }}</th>
                        <th>{{ ___('IP address') }}</th>
                        <th>{{ ___('Browser') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $__row)
                        <tr>
                            <td data-primary data-label="{{ ___('When') }}"><span class="ui-small">{{ optional($__row->created_at)->format('Y-m-d H:i') }}</span></td>
                            <td data-label="{{ ___('Account') }}">#{{ $__row->user_id }}</td>
                            <td data-label="{{ ___('IP address') }}" dir="ltr">{{ $__row->ip }}</td>
                            <td data-label="{{ ___('Browser') }}"><span class="ui-small ui-break">{{ $__row->user_agent }}</span></td>
                            <td data-label="">@if ($__row->new_device)<x-ui.tag tone="warning">{{ ___('New device') }}</x-ui.tag>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No sign-ins recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
