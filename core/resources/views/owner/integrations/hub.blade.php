{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Integrations'))
@section('page_title', ___('Integrations'))
@section('page_sub', ___('Connect your :noun to the tools you already use.', ['noun' => store_noun(null, 'poss')]))

@section('content')
@php
    /* D1: status chip → bootstrap variant + label. Kept in the view (presentation
       only); the controller decides the status from real data. */
    $chip = [
        'connected'     => ['success',   ___('Connected')],
        'attention'     => ['warning',   ___('Needs attention')],
        'not_connected' => ['secondary', ___('Not connected yet')],
    ];
    $cardMeta = [
        'deliverect' => [
            'name' => ___('Deliverect'),
            'desc' => ___('Talabat, Jahez, HungerStation, Careem and more land in your normal order queue.'),
            'action' => route('integrations.deliverect.index'),
            'cta' => ___('Manage'),
        ],
        'zapier' => [
            'name' => ___('Zapier'),
            'desc' => ___('Automate 6,000+ apps — send orders, customers and menu changes anywhere.'),
            'action' => route('api.index'),
            'cta' => ___('Connect'),
        ],
        'make' => [
            'name' => ___('Make'),
            'desc' => ___('Visual scenarios that react to your orders and reservations in real time.'),
            'action' => route('api.index'),
            'cta' => ___('Connect'),
        ],
        'n8n' => [
            'name' => ___('n8n'),
            'desc' => ___('Self-hosted, fair-code automation with our verified community node.'),
            'action' => route('api.index'),
            'cta' => ___('Connect'),
        ],
        'webhooks' => [
            'name' => ___('Webhooks & API'),
            'desc' => ___('Scoped API tokens and signed webhook events for your own integrations.'),
            'action' => route('api.index'),
            'cta' => ___('Open'),
        ],
    ];
@endphp

<div class="ui-stack">
    <div class="ui-card ui-card--inset">
        <div class="ui-card__body">
            <p class="ui-text-accent ui-small ui-caps">{{ ___('Automation') }}</p>
            <h3 class="ui-section-title">{{ ___('Connect your other tools') }}</h3>
            <p class="ui-text-2">{{ ___('Every order, customer and menu change can flow into the apps you already run.') }}</p>
        </div>
    </div>

    <div class="ui-form-grid" data-testid="integration-cards">
        @foreach($cardMeta as $key => $meta)
            @php
                $status = $cards[$key]['status'] ?? 'not_connected';
                [$variant, $label] = $chip[$status] ?? $chip['not_connected'];
            @endphp
            <div class="ui-form-grid__full">
                <div class="ui-fill ui-card" data-integration="{{ $key }}">
                    <div class="ui-stack ui-card__body">
                        <div class="ui-cluster ui-cluster--between ui-cluster--top">
                            <h3 class="ui-section-title">{{ $meta['name'] }}</h3>
                            <span class="ui-tag" data-tone="{{ (['success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'info' => 'accent', 'primary' => 'accent'][$variant] ?? 'neutral') }}" data-status="{{ $status }}">{{ $label }}</span>
                        </div>
                        <p class="ui-muted ui-grow">{{ $meta['desc'] }}</p>
                        @if($key === 'deliverect' && ($cards['deliverect']['locations'] ?? 0) > 0)
                            <p class="ui-muted">{{ ___('Locations:') }} {{ $cards['deliverect']['locations'] }}</p>
                        @elseif($key === 'webhooks' && ($cards['webhooks']['count'] ?? 0) > 0)
                            <p class="ui-muted">{{ ___('Endpoints:') }} {{ $cards['webhooks']['count'] }}</p>
                        @endif
                        <div>
                            @if($meta['action'])
                                <a href="{{ $meta['action'] }}" class="ui-btn ui-btn--sm ui-btn--secondary">{{ $meta['cta'] }}</a>
                            @else
                                <span class="ui-muted">{{ ___('Coming soon') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
