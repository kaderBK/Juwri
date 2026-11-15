{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Merchant Campaign Report'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="arrow-left" :href="route('admin.merchants.campaign')">{{ ___('Back') }}</x-ui.button>
@endsection

@section('content')
    <div class="ui-stack">
        @if ($pending > 0)
            <x-ui.banner tone="warning" :title="___('This campaign still has emails waiting in the queue.')">
                <p>{{ $pending }}</p>
                <x-slot:actions>
                    <x-ui.button variant="danger" size="sm" :href="route('admin.merchants.campaign', ['campaign' => $campaign])">
                        {{ ___('Stop this campaign') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.banner>
        @endif

        @if ($scheduled)
            <x-ui.banner tone="info" :title="___('Scheduled campaigns')">
                <p>
                    @foreach ($scheduled as $one)
                        <strong>{{ $one['campaign'] }}</strong> ({{ date('Y-m-d H:i', $one['at']) }})@if (! $loop->last), @endif
                    @endforeach
                </p>
            </x-ui.banner>
        @endif

        <div class="ui-stats">
            <x-ui.stat :value="$totals['sent'] ?? 0" :label="___('Sent')" icon="check" tone="success" />
            <x-ui.stat :value="$totals['skipped_optout'] ?? 0" :label="___('Opted out')" icon="bell-off" />
            <x-ui.stat :value="$totals['skipped_duplicate'] ?? 0" :label="___('Already sent')" icon="copy" />
            <x-ui.stat :value="$totals['failed'] ?? 0" :label="___('Failed')" icon="alert" tone="danger" />
        </div>

        <x-ui.card :title="___('Merchant Campaign Report')">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th>{{ ___('Merchant') }}</th>
                        <th>{{ ___('Email') }}</th>
                        <th>{{ ___('Campaign') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('When') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->user?->name ?: $row->user?->username }}</td>
                            <td>{{ $row->user?->email }}</td>
                            <td>{{ $row->campaign }}</td>
                            <td>
                                @if ($row->status === 'sent')
                                    <x-ui.pill tone="success">{{ ___('Sent') }}</x-ui.pill>
                                @elseif ($row->status === 'skipped_optout')
                                    <x-ui.pill tone="neutral">{{ ___('Opted out') }}</x-ui.pill>
                                @elseif ($row->status === 'failed')
                                    <x-ui.pill tone="danger">{{ ___('Failed') }}</x-ui.pill>
                                    @if ($row->error)
                                        <small class="ui-muted" style="display: block;">{{ $row->error }}</small>
                                    @endif
                                @else
                                    <x-ui.pill tone="warning">{{ $row->status }}</x-ui.pill>
                                @endif
                            </td>
                            <td>{{ $row->sent_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="ui-muted" style="text-align: center; padding: 24px;">
                                {{ ___('Nothing has been sent yet.') }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
