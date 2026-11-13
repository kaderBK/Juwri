{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Social Login'))
@section('content')
<div class="ui-stack">
    <x-ui.card :title="___('Platform Social Login')">
        <p class="ui-text-2">
            {{ ___('Set platform-default OAuth keys so customer social sign-in works for every store with zero owner setup. Merchant BYOK keys take precedence. Toggle a provider off to disable it platform-wide.') }}
        </p>
    </x-ui.card>

    @foreach($providers as $key => $p)
        <x-ui.card>
            <div class="ui-card__head">
                <h2 class="ui-card__title">
                    {{ $p['label'] }} — {{ ___('Platform Default') }}
                    <small class="ui-text-2">({{ $key }})</small>
                </h2>
                <div class="ui-card__actions">
                    {{-- The switch sits outside the form and joins it through form="…" (posts active=1 only when on). --}}
                    <x-ui.switch name="active" form="admin-social-form-{{ $key }}" :checked="(bool) $p['active']"
                                 aria-label="{{ $p['label'] }} — {{ ___('Active') }}" />
                </div>
            </div>
            <form method="post" action="{{ route('admin.social-providers.update') }}" id="admin-social-form-{{ $key }}" class="ui-stack">
                @csrf
                <input type="hidden" name="provider" value="{{ $key }}">

                <p class="ui-small">
                    {{ ___('Redirect URI') }}:
                    <code class="ui-mono ui-break">{{ route('customer.social.callback', $key) }}</code>
                </p>

                @if($key === 'apple')
                    <div class="ui-form-grid">
                        <x-ui.field :id="'social-'.$key.'-client-id'" name="secrets[client_id]" :label="___('Services ID (client_id)')" :value="$p['secrets']['client_id'] ?? ''" />
                        <x-ui.field :id="'social-'.$key.'-team-id'" name="secrets[team_id]" :label="___('Team ID')" :value="$p['secrets']['team_id'] ?? ''" />
                        <x-ui.field :id="'social-'.$key.'-key-id'" name="secrets[key_id]" :label="___('Key ID')" :value="$p['secrets']['key_id'] ?? ''" />
                    </div>
                    <x-ui.field type="textarea" :id="'social-'.$key.'-private-key'" name="secrets[private_key]" :label="___('Private key (.p8 contents)')"
                                :value="! empty($p['secrets']['private_key']) ? '••••••••' : ''" placeholder="-----BEGIN PRIVATE KEY-----"
                                :help="___('Leave the masked value to keep the current key; empty to clear it.')" class="ui-mono" spellcheck="false" />
                @else
                    <div class="ui-form-grid">
                        <x-ui.field :id="'social-'.$key.'-client-id'" name="secrets[client_id]" :label="___('Client ID')" :value="$p['secrets']['client_id'] ?? ''" />
                        {{-- The stored secret is encrypted; the page holds only its mask and saving the mask keeps it. --}}
                        <div class="ui-field">
                            <label class="ui-field__label" for="social-{{ $key }}-secret">{{ ___('Client Secret') }}</label>
                            <div class="ui-field__control">
                                <input type="password" id="social-{{ $key }}-secret" name="secrets[client_secret]" autocomplete="new-password"
                                       value="{{ $p['has_key'] ? '••••••••' : '' }}" aria-describedby="social-{{ $key }}-secret-help">
                            </div>
                            <span class="ui-field__help" id="social-{{ $key }}-secret-help">{{ ___('Leave the masked value to keep the current key; empty to clear it.') }}</span>
                        </div>
                    </div>
                @endif

                <div class="ui-cluster">
                    <x-ui.button type="submit" icon="device-floppy" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                    {{-- Posts this form's fields to the test route and toasts the {success, message} answer. --}}
                    <x-ui.button variant="secondary" icon="refresh" :data-ui-form-post="route('admin.social-providers.test')" data-provider="{{ $key }}">{{ ___('Test configuration') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endforeach
</div>
@endsection
