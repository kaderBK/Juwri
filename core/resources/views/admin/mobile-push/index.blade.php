{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Mobile push (Firebase)'))
@section('content')

    <x-ui.card :title="___('Platform Firebase service account')">
        <div class="ui-stack">
            <p class="ui-text-2">{{ ___('The owner mobile app sends every notification through this account. Staff and driver apps use a store\'s own Firebase account first and fall back to this one. The key is stored encrypted and is never shown again.') }}</p>

            <dl class="ui-dl" data-mobile-push-status>
                <div>
                    <dt>{{ ___('Status') }}</dt>
                    <dd>
                        @if($status['configured'])
                            <x-ui.tag tone="success">{{ ___('Configured') }}</x-ui.tag>
                            @if($status['mode'] === 'legacy')
                                <x-ui.tag tone="warning">{{ ___('Legacy server key (from .env)') }}</x-ui.tag>
                            @elseif(! $status['stored'])
                                <x-ui.tag>{{ ___('From .env') }}</x-ui.tag>
                            @endif
                        @else
                            <x-ui.tag tone="danger">{{ ___('Not configured') }}</x-ui.tag>
                        @endif
                    </dd>
                </div>
                @if($status['project_id'])
                    <div>
                        <dt>{{ ___('Project ID') }}</dt>
                        <dd><code class="ui-mono">{{ $status['project_id'] }}</code></dd>
                    </div>
                @endif
                @if($status['client_email'])
                    <div>
                        <dt>{{ ___('Service account') }}</dt>
                        <dd><code class="ui-mono">{{ $status['client_email'] }}</code></dd>
                    </div>
                @endif
            </dl>

            <form method="post" action="{{ route('admin.mobile-push.update') }}" class="ui-stack">
                @csrf
                {{-- The key is never echoed back, so the textarea always starts empty (no old() input). --}}
                @error('service_account')
                    @php $__saError = $message; @endphp
                @enderror
                <div @class(['ui-field', 'ui-field--error' => isset($__saError)])>
                    <label class="ui-field__label" for="service_account">{{ $status['stored'] ? ___('Replace the service account JSON') : ___('Service account JSON') }}</label>
                    <div class="ui-field__control">
                        <textarea id="service_account" name="service_account" rows="6" class="ui-mono" autocomplete="off" spellcheck="false"
                                  placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }" aria-describedby="service_account-help"
                                  @isset($__saError) aria-invalid="true" @endisset></textarea>
                    </div>
                    <span class="ui-field__help" id="service_account-help">{{ $__saError ?? ___('Use a dedicated service account with only the Firebase Cloud Messaging Admin role.') }}</span>
                </div>
                <div class="ui-cluster">
                    <x-ui.button type="submit" icon="device-floppy" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                </div>
            </form>

            @if($status['stored'])
                <form method="post" action="{{ route('admin.mobile-push.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" icon="trash">{{ ___('Remove') }}</x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>

@endsection
