{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Kiosk'))
@section('page_title', ___('Kiosk'))
@section('page_sub', ___('Turn any tablet into a self-order kiosk.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-kiosk">
@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

<div class="ui-push__grid">
    <x-ui.card :title="___('Settings')">
        <form method="post" action="{{ route('stores.kiosk.update') }}?store_id={{ $rid }}" class="ui-kiosk__form">
            @csrf
            <div class="ui-kiosk__full"><x-ui.switch name="kiosk_enabled" off="0" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_enabled') == '1'" :label="___('Enable kiosk mode')" /></div>
            <div class="ui-kiosk__fields">
                <div>
                    <label class="ui-label" for="kiosk-idle">{{ ___('Idle reset (seconds)') }}</label>
                    <input type="number" id="kiosk-idle" name="kiosk_idle_seconds" class="ui-input" min="15" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_idle_seconds', 60) }}">
                </div>
                <div>
                    <label class="ui-label" for="kiosk-exit-pin">{{ ___('Staff exit PIN') }}</label>
                    <input id="kiosk-exit-pin" name="kiosk_exit_pin" class="ui-input" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" value="" placeholder="{{ ($pinSet['kiosk_exit_pin'] ?? false) ? ___('Set. Leave blank to keep.') : '' }}">
                    @if ($pinWeak['kiosk_exit_pin'] ?? false)
                        <small class="ui-kiosk__help ui-kiosk__warn" data-pin-weak="kiosk_exit_pin">{{ ___('This PIN was set before the stronger PIN rules. Set a new PIN of at least 6 digits.') }}</small>
                    @endif
                    @if ($pinSet['kiosk_exit_pin'] ?? false)
                        <label class="ui-kiosk__help"><input type="checkbox" name="kiosk_exit_pin_clear" value="1"> {{ ___('Remove this PIN') }}</label>
                    @endif
                </div>
                <div>
                    <label class="ui-label" for="kiosk-setup-pin">{{ ___('Device setup PIN') }}</label>
                    <input id="kiosk-setup-pin" name="kiosk_setup_pin" class="ui-input" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" value="" placeholder="{{ ($pinSet['kiosk_setup_pin'] ?? false) ? ___('Set. Leave blank to keep.') : '' }}">
                    @if ($pinWeak['kiosk_setup_pin'] ?? false)
                        <small class="ui-kiosk__help ui-kiosk__warn" data-pin-weak="kiosk_setup_pin">{{ ___('This PIN was set before the stronger PIN rules. Set a new PIN of at least 6 digits.') }}</small>
                    @endif
                    @if ($pinSet['kiosk_setup_pin'] ?? false)
                        <label class="ui-kiosk__help"><input type="checkbox" name="kiosk_setup_pin_clear" value="1"> {{ ___('Remove this PIN') }}</label>
                    @endif
                    <small class="ui-kiosk__help">{{ ___('Optional. Required once per new tablet.') }}</small>
                </div>
            </div>

            <h3 class="ui-kiosk__h">{{ ___('Loyalty at the kiosk') }}</h3>
            <div class="ui-kiosk__full"><x-ui.switch name="kiosk_loyalty" off="0" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_loyalty') == '1'" :label="___('Let customers use their account at the kiosk (favourites, reorder, points). They opt in and confirm with a code sent to their phone or the pass QR from their account.')" /></div>
            <h3 class="ui-kiosk__h">{{ ___('Sunmi receipt printing') }}</h3>
            <div class="ui-kiosk__full"><x-ui.switch name="kiosk_auto_print" off="0" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_auto_print') == '1'" :label="___('Auto-print receipt on order (Sunmi device)')" /></div>
            <div class="ui-kiosk__full"><x-ui.switch name="kiosk_open_drawer" off="0" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_open_drawer') == '1'" :label="___('Kick cash drawer on print')" /></div>
            <div class="ui-kiosk__fields">
                <div>
                    <label class="ui-label" for="kiosk-width">{{ ___('Printer width') }}</label>
                    <select id="kiosk-width" name="kiosk_printer_width" class="ui-input">
                        <option value="48" @selected(\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_printer_width', 48) == 48)>{{ ___('72mm (48 columns)') }}</option>
                        <option value="32" @selected(\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_printer_width') == 32)>{{ ___('58mm (32 columns)') }}</option>
                    </select>
                    <small class="ui-kiosk__help"><a href="{{ route('integrations.docs', 'sunmi-kiosk') }}" target="_blank" rel="noopener">{{ ___('Sunmi device + kiosk-lock setup guide') }}</a></small>
                </div>
            </div>
            <div><x-ui.button type="submit" data-ui-busy>{{ ___('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Open on the tablet')" class="ui-kiosk__open">
        <div class="ui-kiosk__qr">{!! $kioskQr !!}</div>
        <div class="ui-kiosk__url">
            <input class="ui-input ui-ltr" dir="ltr" value="{{ $kioskUrl }}" readonly data-ui-do="select" aria-label="{{ ___('Open on the tablet') }}">
            <x-ui.button :href="$kioskUrl" variant="secondary" target="_blank" rel="noopener">{{ ___('Open') }}</x-ui.button>
        </div>
        @if(app(\Juwri\Core\Contracts\Editions\Edition::class)->companionClients())
        <small class="ui-kiosk__help">{{ ___('For a locked-down tablet, install the Android kiosk app (see docs).') }}</small>
        @endif
    </x-ui.card>
</div>
</div>
@endsection
