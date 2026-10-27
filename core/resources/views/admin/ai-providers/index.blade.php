{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('AI Providers'))
@section('content')
<div class="ui-stack">
    <x-ui.card :title="___('Platform AI Providers')">
        <p class="ui-text-2">
            {{ ___('Set the platform API key and model for every AI provider. A key saved here overrides the matching environment variable at runtime (no redeploy needed). Merchant and reseller BYOK keys still take precedence; clearing a key here falls back to the environment default.') }}
        </p>
    </x-ui.card>

    @foreach($providers as $key => $p)
        <x-ui.card>
            <div class="ui-card__head">
                <h2 class="ui-card__title">
                    {{ $p['label'] }} — {{ ___('Platform Default') }}
                    <small class="ui-text-2">({{ $p['label_hint'] }})</small>
                    @if($p['supports_transcription'])
                        {{-- ZERO-TOUCH Track B4: voice (STT) capability badge --}}
                        <x-ui.tag tone="accent" title="{{ ___('Can transcribe audio (speech-to-text)') }}">{{ ___('Voice (STT)') }}</x-ui.tag>
                    @endif
                </h2>
                <div class="ui-card__actions">
                    {{-- The switch sits outside the form and joins it through form="…" (posts active=1 only when on). --}}
                    <x-ui.switch name="active" form="admin-ai-form-{{ $key }}" :checked="(bool) $p['active']"
                                 aria-label="{{ $p['label'] }} — {{ ___('Active') }}" />
                </div>
            </div>
            <form method="post" action="{{ route('admin.ai-providers.update') }}" id="admin-ai-form-{{ $key }}" class="ui-stack">
                @csrf
                <input type="hidden" name="provider" value="{{ $key }}">

                <div class="ui-form-grid">
                    <div class="ui-field">
                        <label class="ui-field__label" for="ai-{{ $key }}-api-key">{{ ___('API Key') }}</label>
                        <div class="ui-field__control">
                            <input type="password" id="ai-{{ $key }}-api-key" name="secrets[api_key]" placeholder="sk-..." autocomplete="new-password"
                                   value="{{ $p['has_key'] ? '••••••••' : '' }}" aria-describedby="ai-{{ $key }}-api-key-help">
                        </div>
                        <span class="ui-field__help" id="ai-{{ $key }}-api-key-help">{{ ___('Leave the masked value to keep the current key; empty to clear it.') }}</span>
                    </div>
                    <div class="ui-field">
                        <label class="ui-field__label" for="ai-{{ $key }}-model">{{ ___('Model') }}</label>
                        <div class="ui-field__control">
                            @if(count($p['allowed_models']))
                                <input type="text" id="ai-{{ $key }}-model" name="secrets[model]" list="ai-models-{{ $key }}"
                                       placeholder="{{ $p['env_model'] }}" value="{{ $p['saved_model'] }}" aria-describedby="ai-{{ $key }}-model-help">
                                <datalist id="ai-models-{{ $key }}">
                                    @foreach($p['allowed_models'] as $m)
                                        <option value="{{ $m }}"></option>
                                    @endforeach
                                </datalist>
                            @else
                                <input type="text" id="ai-{{ $key }}-model" name="secrets[model]"
                                       placeholder="{{ $p['env_model'] }}" value="{{ $p['saved_model'] }}" aria-describedby="ai-{{ $key }}-model-help">
                            @endif
                        </div>
                        <span class="ui-field__help" id="ai-{{ $key }}-model-help">{{ ___('Blank uses the environment default') }}: <code class="ui-mono">{{ $p['env_model'] ?: '—' }}</code></span>
                    </div>
                    @if($p['supports_transcription'])
                        {{-- ZERO-TOUCH Track B4: per-provider transcription (STT) model --}}
                        <div class="ui-field">
                            <label class="ui-field__label" for="ai-{{ $key }}-stt">{{ ___('Transcription model (speech-to-text)') }}</label>
                            <div class="ui-field__control">
                                <input type="text" id="ai-{{ $key }}-stt" name="secrets[transcription_model]"
                                       placeholder="{{ $p['env_transcription_model'] }}" value="{{ $p['saved_transcription_model'] }}" aria-describedby="ai-{{ $key }}-stt-help">
                            </div>
                            <span class="ui-field__help" id="ai-{{ $key }}-stt-help">{{ ___('Blank uses the environment default') }}: <code class="ui-mono">{{ $p['env_transcription_model'] ?: '—' }}</code></span>
                        </div>
                    @endif
                </div>

                <div class="ui-cluster">
                    <x-ui.button type="submit" icon="device-floppy" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                    {{-- Posts this form's fields to the test route and toasts the {success, message} answer. --}}
                    <x-ui.button variant="secondary" icon="refresh" :data-ui-form-post="route('admin.ai-providers.test')" data-provider="{{ $key }}">{{ ___('Test Connection') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endforeach
</div>
@endsection
