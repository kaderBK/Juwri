{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Fiscalization'))
@section('page_title', ___('Fiscalization'))
@section('page_sub', ___('Tax-authority compliance for your receipts.'))

@section('page_actions')
    <form method="get" action="{{ route('fiscal.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
<div class="ui-scope ui-fiscal">
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

<x-ui.card :title="___('Fiscal profile')">
    @if($profile)
        <dl class="ui-account__facts ui-fiscal__facts">
            <div><dt>{{ ___('Country') }}</dt><dd>{{ $profile->countryName() }} <span class="ui-ltr">({{ $country }})</span></dd></div>
            <div><dt>{{ ___('Default VAT rate') }}</dt><dd class="ui-ltr">{{ $profile->defaultVatRate() }}%</dd></div>
            <div><dt>{{ ___('Driver mode') }}</dt><dd><code>{{ $profile->driverMode() }}</code></dd></div>
        </dl>
        @unless($countryEnabled)
            <x-ui.banner tone="warning" class="ui-fiscal__gap">{{ ___('This country is not enabled by the platform yet.') }}</x-ui.banner>
        @endunless
    @else
        <x-ui.empty icon="book-open" :title="___('No fiscal country set. Choose one in your store settings (Tax & Compliance).')">
            @if($rid && Route::has('stores.edit'))
                <x-ui.button :href="route('stores.edit', $rid)" variant="secondary" icon="settings">{{ ___('Store settings') }}</x-ui.button>
            @endif
        </x-ui.empty>
    @endif
</x-ui.card>

@if($profile && $country === 'SA' && $countryEnabled)
<x-ui.card :title="___('ZATCA device onboarding')">
    <p class="ui-fiscal__lead">{{ ___('Generate an OTP in the FATOORA portal, then onboard this branch device.') }}</p>
    <form method="post" action="{{ route('fiscal.zatca.onboard') }}" class="ui-fiscal__form">
        @csrf
        <x-ui.field name="device_key" :label="___('Device key (per branch)')" required />
        <x-ui.field name="branch_id" :label="___('Branch ID (optional)')" />
        <x-ui.field name="otp" :label="___('FATOORA OTP')" inputmode="numeric" required />
        <x-ui.button type="submit" data-ui-busy>{{ ___('Onboard') }}</x-ui.button>
    </form>
</x-ui.card>
<x-ui.card :title="___('ZATCA certificate renewal')">
    <p class="ui-fiscal__lead">{{ ___('Before the production certificate expires, generate a new OTP in the FATOORA portal and renew here. The old certificate keeps working until the new one is stored.') }}</p>
    <form method="post" action="{{ route('fiscal.zatca.renew') }}" class="ui-fiscal__form">
        @csrf
        <x-ui.field name="device_key" :label="___('Device key (per branch)')" required />
        <x-ui.field name="otp" :label="___('FATOORA OTP')" inputmode="numeric" required />
        <x-ui.button type="submit" data-ui-busy>{{ ___('Renew') }}</x-ui.button>
    </form>
</x-ui.card>
@endif

@if($profile && $country === 'JO' && $countryEnabled)
<x-ui.card :title="___('JoFotara credentials')">
    <p class="ui-fiscal__lead">{{ ___('From the JoFotara portal: Client ID, Secret Key and Activity Number.') }}</p>
    <form method="post" action="{{ route('fiscal.credentials') }}" class="ui-fiscal__form">
        @csrf
        <x-ui.field name="secrets[client_id]" :label="___('Client ID')" required />
        <x-ui.field name="secrets[secret]" :label="___('Secret Key')" required />
        <x-ui.field name="secrets[activity_number]" :label="___('Activity No.')" required />
        <x-ui.button type="submit" data-ui-busy>{{ ___('Save') }}</x-ui.button>
    </form>
</x-ui.card>
@endif

@if($profile && $country === 'EG' && $countryEnabled)
<x-ui.card :title="___('ETA credentials')">
    <p class="ui-fiscal__lead">{{ ___('From the ETA portal: taxpayer RIN and POS device client id/secret.') }}</p>
    <form method="post" action="{{ route('fiscal.credentials') }}" class="ui-fiscal__form">
        @csrf
        <x-ui.field name="secrets[rin]" :label="___('RIN')" required />
        <x-ui.field name="secrets[client_id]" :label="___('Client ID')" required />
        <x-ui.field name="secrets[secret]" :label="___('Client Secret')" required />
        <x-ui.button type="submit" data-ui-busy>{{ ___('Save') }}</x-ui.button>
    </form>
</x-ui.card>
@endif

@if($profile && $country === 'EG' && $countryEnabled)
<x-ui.card :title="___('Register POS device')">
    <form method="post" action="{{ route('fiscal.eta.device') }}" class="ui-fiscal__form">
        @csrf
        <x-ui.field name="serial" :label="___('Device serial')" required />
        <x-ui.field name="branch_code" :label="___('Branch code')" />
        <x-ui.field name="branch_id" :label="___('Branch ID (optional)')" />
        <x-ui.button type="submit" data-ui-busy>{{ ___('Register') }}</x-ui.button>
    </form>
</x-ui.card>
@endif

@if($devices->isNotEmpty())
<section class="ui-card ui-card--flush">
    <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Devices') }}</h2></div>
    <table class="ui-table">
        <thead><tr><th>{{ ___('Device') }}</th><th>{{ ___('Status') }}</th></tr></thead>
        <tbody>
        @foreach($devices as $d)
            <tr><td data-primary><code>{{ $d->device_key }}</code></td><td data-label="{{ ___('Status') }}"><x-ui.pill>{{ $d->status }}</x-ui.pill>
                @php($held = $d->meta['held']['reason'] ?? null)
                @if($held)
                    <x-ui.pill tone="danger">{{ match ($held) {
                        'cert_expired' => ___('Invoices are on hold: the production certificate has expired. Renew the certificate.'),
                        'env_mismatch' => ___('Invoices are on hold: this device was onboarded on another ZATCA gateway. Onboard it again.'),
                        'not_onboarded' => ___('Invoices are on hold: this device has no certificate. Onboard it again.'),
                        default => ___('Invoices are on hold: this device has no production certificate yet. Finish onboarding.'),
                    } }}</x-ui.pill>
                @endif
            </td></tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif

@if(!empty($offlineTills))
<section class="ui-card ui-card--flush" data-offline-tills>
    <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Offline tills') }}</h2></div>
    <table class="ui-table">
        <thead><tr>
            <th>{{ ___('Till') }}</th><th>{{ ___('Last sync') }}</th><th class="ui-table__num">{{ ___('Pending invoices') }}</th>
            <th>{{ ___('Oldest pending') }}</th><th>{{ ___('Certificate expires') }}</th>
        </tr></thead>
        <tbody>
        @foreach($offlineTills as $t)
            @php($certDays = $t['cert_expires_at'] ? (int) floor(now()->diffInDays($t['cert_expires_at'], false)) : null)
            <tr>
                <td data-primary><span class="ui-ltr">{{ $t['till'] }}</span> @if($t['status'] !== 'production')<x-ui.pill>{{ $t['status'] }}</x-ui.pill>@endif</td>
                <td data-label="{{ ___('Last sync') }}"><span class="ui-ltr">{{ $t['last_sync'] ? $t['last_sync']->format('Y-m-d H:i') : ___('Never') }}</span></td>
                <td class="ui-table__num" data-label="{{ ___('Pending invoices') }}">{{ $t['pending'] }}</td>
                <td data-label="{{ ___('Oldest pending') }}">
                    @if($t['oldest_pending_hours'] !== null)
                        <x-ui.pill :tone="$t['oldest_pending_hours'] > 20 ? 'danger' : ($t['oldest_pending_hours'] > (int) config('pos.offline.zatca_watch.pending_warn_hours', 12) ? 'warning' : 'neutral')">{{ ___(':hours h', ['hours' => $t['oldest_pending_hours']]) }}</x-ui.pill>
                    @else &mdash; @endif
                </td>
                <td data-label="{{ ___('Certificate expires') }}">
                    @if($certDays !== null)
                        <x-ui.pill :tone="$certDays <= 14 ? 'danger' : ($certDays <= (int) config('services.zatca.renew_warn_days', 45) ? 'warning' : 'success')"><span class="ui-ltr">{{ $t['cert_expires_at']->format('Y-m-d') }}</span></x-ui.pill>
                    @else &mdash; @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif

<section class="ui-card ui-card--flush">
    <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Document log') }}</h2></div>
    @if($documents->isEmpty())
        <x-ui.empty icon="orders" :title="___('No fiscal documents yet.')" />
    @else
    <table class="ui-table">
        <thead><tr>
            <th>{{ ___('Number') }}</th><th>{{ ___('Type') }}</th><th>{{ ___('Status') }}</th>
            <th class="ui-table__num">{{ ___('Total') }}</th><th>{{ ___('Date') }}</th><th><span class="ui-visually-hidden">{{ ___('Resubmit') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach($documents as $doc)
            <tr>
                <td data-primary><span class="ui-ltr">{{ $doc->number }}</span></td>
                <td data-label="{{ ___('Type') }}">{{ $doc->type }}</td>
                <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$doc->status === 'accepted' ? 'success' : ($doc->status === 'rejected' ? 'danger' : 'neutral')">{{ $doc->status }}</x-ui.pill></td>
                <td class="ui-table__num" data-label="{{ ___('Total') }}"><span class="ui-ltr">{{ number_format($doc->total, 2) }} {{ $doc->currency }}</span></td>
                <td data-label="{{ ___('Date') }}"><span class="ui-ltr">{{ optional($doc->created_at)->format('Y-m-d H:i') }}</span></td>
                <td class="ui-catalog__row-actions">
                    @if($doc->status === 'rejected')
                    <form method="post" action="{{ route('fiscal.documents.resubmit', $doc->id) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Resubmit') }}</x-ui.button>
                    </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</section>
</div>
@endsection
