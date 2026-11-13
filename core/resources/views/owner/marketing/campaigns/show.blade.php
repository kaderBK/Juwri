{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Campaign report'))
@section('page_title', ___('Campaign report'))
@section('page_sub', ___('Audience, delivery state, and per-recipient outcomes.'))

@section('page_actions')
    <x-ui.button :href="route('campaigns.index')" variant="ghost" icon="back">
        {{ ___('Back') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $total = $campaign->sent_count + $campaign->failed_count;
    $deliveryRate = $total > 0 ? round(($campaign->sent_count / $total) * 100, 1) : 0;
    $statusVariant = match ($campaign->status) {
        'sent'      => 'success',
        'failed'    => 'danger',
        'scheduled' => 'warning',
        default     => 'neutral',
    };
@endphp

<div class="ui-form-grid">
    <div><x-ui.stat :label="___('Audience')" :value="number_format($total)" /></div>
    <div><x-ui.stat :label="___('Sent')" :value="number_format($campaign->sent_count)" /></div>
    <div><x-ui.stat :label="___('Failed')" :value="number_format($campaign->failed_count)" /></div>
    <div><x-ui.stat :label="___('Delivery rate')" :value="$deliveryRate.'%'" /></div>
</div>

<div class="ui-card">
    <div class="ui-cluster ui-cluster--between ui-card__body">
        <h3 class="ui-section-title">{{ ___('Status') }}</h3>
        <x-ui.tag :tone="$statusVariant">{{ ___($campaign->status) }}</x-ui.tag>
    </div>
</div>

<x-ui.card :title="___('Details')">
    <dl class="ui-form-grid">
        <dt>{{ ___('Channel') }}</dt>
        <dd>{{ strtoupper($campaign->channel) }}</dd>

        @if ($campaign->channel === 'email')
            <dt>{{ ___('Subject') }}</dt>
            <dd>{{ $campaign->subject }}</dd>
        @endif

        <dt>{{ ___('Segment') }}</dt>
        <dd>{{ ___($campaign->segment) }}</dd>

        <dt>{{ ___('Scheduled at') }}</dt>
        <dd>{{ $campaign->scheduled_at ? $campaign->scheduled_at->format('Y-m-d H:i') : '—' }}</dd>

        <dt>{{ ___('Message') }}</dt>
        <dd><pre class="ui-bg-inset" style="white-space:pre-wrap;">{{ $campaign->body }}</pre></dd>
    </dl>
</x-ui.card>

<x-ui.card :title="___('Delivery log')">
    <x-slot:actions><small class="ui-text-2">{{ ___('Recipients') }}: {{ number_format($recipients->total()) }}</small></x-slot:actions>

    @if ($recipients->isEmpty())
        <p class="ui-text-2">{{ ___('Audience resolution is pending or no customers matched the segment.') }}</p>
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Recipient') }}</th>
                        <th>{{ ___('Contact') }}</th>
                        <th>{{ ___('Sent at') }}</th>
                        <th>{{ ___('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recipients as $recipient)
                        @php
                            $rVariant = match ($recipient->status) {
                                'sent'   => 'success',
                                'failed' => 'danger',
                                default  => 'warning',
                            };
                            $contact = $campaign->channel === 'email'
                                ? ($recipient->customer->email ?? '—')
                                : ($recipient->customer->phone ?? '—');
                        @endphp
                        <tr>
                            <td data-label="{{ ___('Recipient') }}">
                                <strong>{{ $recipient->customer->name ?? ___('Guest') }}</strong>
                                @if ($recipient->error_message)
                                    <em class="ui-block ui-text-danger ui-small">{{ $recipient->error_message }}</em>
                                @endif
                            </td>
                            <td data-label="{{ ___('Contact') }}">{{ $contact }}</td>
                            <td class="ui-muted" data-label="{{ ___('Sent at') }}">{{ $recipient->sent_at?->format('Y-m-d H:i:s') }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="$rVariant">{{ ___($recipient->status) }}</x-ui.tag></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($recipients->hasPages())
            <div>
                {{ $recipients->links() }}
            </div>
        @endif
    @endif
</x-ui.card>
@endsection
