{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Owner app — in-app purchase billing screen (server item P5-22).
    Plan list + current status + buy/restore through the native `purchase` bridge
    component (assets/owner-app/bridge.js) and the server verify endpoints.
    All text comes from ___() here and is handed to billing.js as one JSON object
    in data-i18n (key = the English source string). No inline handlers, no store
    link (Apple 3.1.3): the footer is plain text.
--}}
@extends('owner.layout')

@php
    $i18n = [
        'Subscription' => ___('Subscription'),
        'Choose a plan' => ___('Choose a plan'),
        'Your plan' => ___('Your plan'),
        'No active subscription' => ___('No active subscription'),
        'Renews on :date' => ___('Renews on :date'),
        'Payment problem. Your plan stays active until :date while the store retries.' => ___('Payment problem. Your plan stays active until :date while the store retries.'),
        'Cancelled. Your plan stays active until :date.' => ___('Cancelled. Your plan stays active until :date.'),
        'Expired on :date.' => ___('Expired on :date.'),
        'Refunded. This plan is no longer active.' => ___('Refunded. This plan is no longer active.'),
        'The store removed access to this plan.' => ___('The store removed access to this plan.'),
        'Subscribe' => ___('Subscribe'),
        'Restore purchases' => ___('Restore purchases'),
        'Restoring…' => ___('Restoring…'),
        'Purchases restored.' => ___('Purchases restored.'),
        'No purchases to restore.' => ___('No purchases to restore.'),
        'Purchase complete.' => ___('Purchase complete.'),
        'Purchase cancelled.' => ___('Purchase cancelled.'),
        'Waiting for approval from the store.' => ___('Waiting for approval from the store.'),
        'Something went wrong. Please try again.' => ___('Something went wrong. Please try again.'),
        'Billing is not available right now.' => ___('Billing is not available right now.'),
        'Loading plans…' => ___('Loading plans…'),
        'per month' => ___('per month'),
        'per year' => ___('per year'),
        'Manage or cancel your subscription in the App Store or Google Play settings.' => ___('Manage or cancel your subscription in the App Store or Google Play settings.'),
        'Active' => ___('Active'),
        'Grace period' => ___('Grace period'),
        'Cancels at period end' => ___('Cancels at period end'),
        'Expired' => ___('Expired'),
        'Refunded' => ___('Refunded'),
        'Removed' => ___('Removed'),
    ];
    $overviewUrl = \Illuminate\Support\Facades\Route::has('owner-app.billing.overview')
        ? route('owner-app.billing.overview')
        : url('owner-app/billing/overview');
@endphp

@section('title', ___('Subscription'))

@section('content')
    <div class="ui-scope owner-billing"
         data-controller="bridge--purchase"
         data-owner-billing
         data-i18n='@json($i18n)'
         data-endpoint-overview="{{ $overviewUrl }}"
         data-endpoint-token="{{ route('owner-app.billing.token') }}"
         data-endpoint-apple="{{ route('owner-app.billing.apple') }}"
         data-endpoint-google="{{ route('owner-app.billing.google') }}">

        <div data-billing-main>
            <x-ui.page-header :title="___('Subscription')" />

            <x-ui.card :title="___('Your plan')">
                <div class="owner-billing__status-body">
                    <x-ui.pill data-status-badge tone="neutral">{{ ___('No active subscription') }}</x-ui.pill>
                    <p class="owner-billing__plan-name" data-status-plan hidden></p>
                    <p class="owner-billing__sentence" data-status-sentence hidden></p>
                </div>
            </x-ui.card>

            <section class="owner-billing__plans">
                <h2 class="owner-billing__heading">{{ ___('Choose a plan') }}</h2>
                <p class="owner-billing__footer" data-plans-loading>{{ ___('Loading plans…') }}</p>
                <div class="owner-billing__plans-list" data-plans-list hidden></div>
            </section>

            <div class="owner-billing__restore">
                <x-ui.button variant="secondary" data-restore data-busy-text="{{ ___('Restoring…') }}">{{ ___('Restore purchases') }}</x-ui.button>
            </div>
        </div>

        <div class="owner-billing__unavailable" data-billing-error hidden>
            <p data-billing-error-text>{{ ___('Billing is not available right now.') }}</p>
        </div>

        <p class="owner-billing__footer">{{ ___('Manage or cancel your subscription in the App Store or Google Play settings.') }}</p>
    </div>
@endsection

@push('styles')
    @php $billingCss = 'assets/owner-app/billing.css'; @endphp
    <link rel="stylesheet" href="{{ asset($billingCss) }}{{ is_file(public_path($billingCss)) ? '?v='.filemtime(public_path($billingCss)) : '' }}">
@endpush

@push('scripts')
    @php $billingJs = 'assets/owner-app/billing.js'; @endphp
    <script nonce="{{ csp_nonce() }}" src="{{ asset($billingJs) }}{{ is_file(public_path($billingJs)) ? '?v='.filemtime(public_path($billingJs)) : '' }}" data-turbo-eval="false"></script>
@endpush
