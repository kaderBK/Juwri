{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // Stored secrets are never put in the page: a masked tail only; saving the mask keeps the stored value.
    $__secret = function (string $feature, string $provider, string $key) use ($credentials) {
        $cred = $credentials->get($feature.'_'.$provider);
        if ($cred && isset($cred->secrets[$key])) {
            $val = (string) $cred->secrets[$key];

            return strlen($val) > 4 ? '••••••••'.substr($val, -4) : '••••••••';
        }

        return '';
    };
    $__plain = fn (string $feature, string $provider, string $key) => optional($credentials->get($feature.'_'.$provider))->secrets[$key] ?? '';
    $__active = fn (string $feature, string $provider) => (bool) optional($credentials->get($feature.'_'.$provider))->active;
    // provider cards: [feature, provider, title, fields: [name, label, placeholder, kind secret|plain, type]]
    $__cards = [
        ['ai', 'openai', 'OpenAI (GPT Suite) — Platform Default', [['api_key', 'OpenAI API Key', 'sk-...', 'secret', 'password']], ___('Test Connection')],
        ['ai', 'anthropic', 'Anthropic Claude (Default Copilot) — Platform Default', [['api_key', 'Anthropic API Key', 'sk-ant-...', 'secret', 'password']], ___('Test Connection')],
        ['sms', 'twilio', 'Twilio SMS — Platform Default', [
            ['sid', 'Twilio Account SID', 'AC...', 'secret', 'text'],
            ['auth_token', 'Twilio Auth Token', 'token', 'secret', 'password'],
            ['from_number', 'Twilio From Phone Number', '+1...', 'plain', 'text'],
        ], ___('Test Connection')],
        ['sms', 'msegat', 'Msegat SMS (KSA) — Platform Default', [
            ['username', 'Msegat Username', 'user', 'secret', 'text'],
            ['api_key', 'Msegat API Key', 'api key', 'secret', 'password'],
            ['sender', 'Sender Name (CST/Jaber registered)', 'juwri', 'plain', 'text'],
        ], ___('Test Connection')],
        ['storage', 's3', 'S3 Storage Offloading — Platform Default', [
            ['key', 'S3 Key / Access Key', 'AKIA...', 'secret', 'text'],
            ['secret', 'S3 Secret Key', 'secret', 'secret', 'password'],
            ['region', 'S3 Region', 'us-east-1', 'plain', 'text'],
            ['bucket', 'S3 Bucket Name', 'my-bucket', 'plain', 'text'],
            ['cdn_url', 'CDN / Custom Base URL (Optional)', 'https://cdn.example.com', 'plain', 'text'],
        ], ___('Test Configuration')],
    ];
@endphp
<section id="quick_integrations" role="tabpanel" aria-labelledby="tab-quick_integrations" @if (($active ?? true) === false) hidden @endif>
    @foreach ($__cards as [$__feature, $__provider, $__title, $__fields, $__testLabel])
        <form method="POST" action="{{ route('admin.settings.integrations.update') }}" id="admin-form-{{ $__provider }}">
            @csrf
            <input type="hidden" name="feature" value="{{ $__feature }}">
            <input type="hidden" name="provider" value="{{ $__provider }}">
            <x-ui.card :title="$__title">
                <x-slot:actions>
                    <x-ui.switch name="active" off="0" :checked="$__active($__feature, $__provider)" :aria-label="___('Active')" />
                </x-slot:actions>
                <div class="ui-form-grid">
                    @foreach ($__fields as [$__key, $__label, $__placeholder, $__kind, $__type])
                        <div class="ui-field">
                            <label class="ui-field__label" for="int-{{ $__provider }}-{{ $__key }}">{{ $__label }}</label>
                            <div class="ui-field__control">
                                <input type="{{ $__type }}" id="int-{{ $__provider }}-{{ $__key }}" name="secrets[{{ $__key }}]" placeholder="{{ $__placeholder }}" autocomplete="off"
                                       value="{{ $__kind === 'secret' ? $__secret($__feature, $__provider, $__key) : $__plain($__feature, $__provider, $__key) }}">
                            </div>
                            @if ($__provider === 'msegat' && $__key === 'sender')
                                <span class="ui-field__help"><a href="{{ route('integrations.docs', 'msegat-sender-registration') }}" target="_blank" rel="noopener">{{ ___('Sender-name registration guide') }}</a></span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="ui-card__foot ui-cluster">
                    <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                    <x-ui.button variant="secondary" icon="refresh" :data-ui-form-post="route('admin.settings.integrations.test')" data-feature="{{ $__feature }}" data-provider="{{ $__provider }}">{{ $__testLabel }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @endforeach
</section>
