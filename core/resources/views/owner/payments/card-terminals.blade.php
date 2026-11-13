{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Card terminals'))
@section('page_title', ___('Card terminals'))
@section('page_sub', ___('Take card payments on a phone with your own Geidea or EdfaPay account.'))

@section('page_actions')
    @if (!empty($stores) && $stores->isNotEmpty())
        @include('owner.partials.context-store-select', ['showBranch' => false])
    @endif
@endsection

@section('content')
@php
    // P5-29: per-provider UI labels and the write-only credential fields (name => label key).
    $providerLabels = [
        'geidea_softpos'  => 'Geidea',
        'edfapay_softpos' => 'EdfaPay',
    ];
    $credentialFields = [
        'geidea_softpos'  => ['public_key' => 'Public key', 'api_password' => 'API password'],
        'edfapay_softpos' => ['api_key' => 'API key', 'callback_secret' => 'Callback secret'],
    ];
@endphp

<div class="ui-scope ui-res">
    <x-ui.banner>{{ ___('Card terminals stay off until Juwri confirms your provider') }}</x-ui.banner>

    @if (empty($providers))
        <x-ui.empty :title="___('No card terminal provider is available yet.')" icon="card" />
    @else
        @foreach ($providers as $provider)
            @php
                $account = $accounts[$provider] ?? null;
                $masked  = (array) (is_array($account) ? ($account['credentials'] ?? []) : []);
                $formId  = 'card-terminal-'.str_replace('_', '-', $provider);
            @endphp
            <x-ui.card :title="$providerLabels[$provider] ?? $provider">
                <form id="{{ $formId }}" class="ui-form-grid" data-terminal-form>
                    @csrf
                    <input type="hidden" name="provider" value="{{ $provider }}">

                    <div class="ui-form-grid__full">
                        <x-ui.switch name="enabled" value="1" :checked="$account['enabled'] ?? false"
                                     :label="___('Enabled')" />
                    </div>

                    <x-ui.field name="merchant_id" type="text" :label="___('Merchant ID')"
                                :value="$account['merchant_id'] ?? ''" maxlength="191" required />

                    @foreach ($credentialFields[$provider] ?? [] as $fieldName => $fieldLabel)
                        @php
                            $fid = $formId.'-'.str_replace('_', '-', $fieldName);
                            $describedBy = trim(($masked[$fieldName] ?? '') !== '' ? $fid.'-hint ' : '').$fid.'-help';
                        @endphp
                        <div class="ui-field">
                            <label class="ui-field__label" for="{{ $fid }}">{{ ___($fieldLabel) }}</label>
                            <div class="ui-field__control">
                                <input type="password" id="{{ $fid }}" class="ui-input" name="credentials[{{ $fieldName }}]"
                                       value="" autocomplete="new-password" data-terminal-secret data-secret-key="{{ $fieldName }}"
                                       aria-describedby="{{ $describedBy }}">
                            </div>
                            @if (($masked[$fieldName] ?? '') !== '')
                                <small class="ui-field__help" id="{{ $fid }}-hint">{{ $masked[$fieldName] }}</small>
                            @endif
                            <span class="ui-field__help" id="{{ $fid }}-help">{{ ___('Leave empty to keep the saved value.') }}</span>
                        </div>
                    @endforeach
                    <small class="ui-field__help ui-form-grid__full">{{ ___('Secret fields are write-only and are never shown again.') }}</small>

                    <div class="ui-form-grid__full">
                        <label class="ui-field__label" for="{{ $formId }}-callback">{{ ___('Callback URL') }}</label>
                        <span class="ui-cluster">
                            <input type="text" id="{{ $formId }}-callback" class="ui-input ui-ltr" readonly dir="ltr"
                                   value="{{ $callbackUrls[$provider] ?? '' }}"
                                   aria-describedby="{{ $formId }}-callback-help">
                            <x-ui.button type="button" variant="secondary" icon="copy"
                                         data-copy-url="{{ $callbackUrls[$provider] ?? '' }}" data-copy-status="{{ $formId }}-copy-status">{{ ___('Copy') }}</x-ui.button>
                        </span>
                        <span class="ui-field__help" id="{{ $formId }}-callback-help">{{ ___("Set this URL as the callback in your provider's dashboard.") }}</span>
                        <span role="status" aria-live="polite" id="{{ $formId }}-copy-status" class="ui-muted"></span>
                    </div>

                    <div class="ui-form-grid__full">
                        <x-ui.button type="submit" icon="check">{{ ___('Save card terminal') }}</x-ui.button>
                        <span role="status" aria-live="polite" data-terminal-status class="ui-muted"></span>
                    </div>
                </form>
            </x-ui.card>
        @endforeach
    @endif
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var live = document.getElementById(btn.dataset.copyStatus);
            navigator.clipboard.writeText(btn.dataset.copyUrl).then(function () {
                if (live) { live.textContent = @js(___('Copied')); }
            });
        });
    });

    document.querySelectorAll('[data-terminal-form]').forEach(function (form) {
        var status = form.querySelector('[data-terminal-status]');
        var submit = form.querySelector('[type="submit"]');
        var saveFailed = @js(___('Could not save. Check the fields and try again.'));

        function readSecrets() {
            var credentials = {};
            form.querySelectorAll('[data-terminal-secret]').forEach(function (inp) {
                credentials[inp.dataset.secretKey] = inp.value.trim();
            });
            return credentials;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            status.textContent = '';
            submit.disabled = true;

            fetch('{{ route('pos.terminal.accounts.save') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    provider: form.querySelector('[name="provider"]').value,
                    merchant_id: form.querySelector('[name="merchant_id"]').value.trim(),
                    enabled: form.querySelector('[name="enabled"]').checked,
                    credentials: readSecrets()
                })
            }).then(function (r) {
                return r.json().catch(function () { return null; }).then(function (data) {
                    return { ok: r.ok, data: data };
                });
            }).then(function (res) {
                if (res.ok) {
                    status.textContent = @js(___('Saved.'));
                    form.querySelectorAll('[data-terminal-secret]').forEach(function (inp) { inp.value = ''; });
                } else {
                    status.textContent = saveFailed;
                }
            }).catch(function () {
                status.textContent = saveFailed;
            }).finally(function () {
                submit.disabled = false;
            });
        });
    });
</script>
@endpush