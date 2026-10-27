{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Payment Gateways'))
@section('header_buttons')
    @if (Route::has('admin.paylink.onboardings'))
        <x-ui.button variant="secondary" size="sm" icon="link" :href="route('admin.paylink.onboardings')">{{ ___('Paylink Partner Onboarding') }}</x-ui.button>
    @endif
@endsection
@section('content')
<div class="ui-stack">
    <label class="ui-dt__search">
        <span class="ui-visually-hidden">{{ ___('Search') }}</span>
        <input type="search" class="ui-input" placeholder="{{ ___('Search') }}" data-ui-table-search="gateways-table" autocomplete="off">
    </label>
    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table" id="gateways-table">
                <thead>
                    <tr>
                        <th>{{ ___('Logo') }}</th>
                        <th>{{ ___('Title') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-end"><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($gateways as $gateway)
                        <tr>
                            <td data-label="{{ ___('Logo') }}">
                                <img class="ui-cell-img" src="{{ asset('storage/payment_gateways/'.$gateway->payment_folder.'.png') }}" width="100" alt="{{ $gateway->payment_folder }}" loading="lazy">
                            </td>
                            <td data-primary data-label="{{ ___('Title') }}"><strong>{{ $gateway->payment_title }}</strong></td>
                            <td data-label="{{ ___('Status') }}">
                                @if ($gateway->payment_install)
                                    <x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                                @else
                                    <x-ui.tag tone="danger">{{ ___('Disabled') }}</x-ui.tag>
                                @endif
                            </td>
                            <td class="ui-end" data-label="">
                                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-toggle="slidePanel" data-url="{{ route('admin.gateways.edit', $gateway->id) }}"
                                        title="{{ ___('Edit') }}" aria-label="{{ ___('Edit') }}"><x-ui.icon name="pencil" size="18" /></button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
