{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php $__v = fn (string $key) => demo_mode() ? '*****' : ($settings->{$key} ?? null); @endphp
<section id="quick_social_logins" role="tabpanel" aria-labelledby="tab-quick_social_logins" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Social Logins')">
            <div class="ui-stack">
                @foreach ([
                    'facebook' => [___('Facebook'), 'facebook_app_id', ___('Facebook App Id'), 'facebook_app_secret', ___('Facebook App Secret'), ___('Facebook Callback Url')],
                    'google' => [___('Google'), 'google_app_id', ___('Google Client Id'), 'google_app_secret', ___('Google Client Secret'), ___('Google Callback Url')],
                ] as $__provider => [$__title, $__idKey, $__idLabel, $__secretKey, $__secretLabel, $__cbLabel])
                    <details class="ui-disclosure">
                        <summary class="ui-disclosure__head">{{ $__title }}</summary>
                        <div class="ui-disclosure__body ui-stack">
                            <x-ui.switch :name="$__provider.'_login'" off="0" :checked="($settings->{$__provider.'_login'} ?? '') == '1'" :label="___('Status')" />
                            <div class="ui-form-grid">
                                <x-ui.field :name="$__idKey" :label="$__idLabel" :value="$__v($__idKey)" autocomplete="off" />
                                <x-ui.field :name="$__secretKey" :label="$__secretLabel" :value="$__v($__secretKey)" autocomplete="off" />
                            </div>
                            @include('admin.billing.gateways._url', ['label' => $__cbLabel, 'url' => url('/').'/auth/'.$__provider.'/callback'])
                        </div>
                    </details>
                @endforeach
            </div>
            @include('admin.settings._save', ['marker' => 'social_logins_settings'])
        </x-ui.card>
    </form>
</section>
