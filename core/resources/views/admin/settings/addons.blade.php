{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php $__v = fn ($value) => demo_mode() ? '*****' : $value; @endphp
<section id="quick_addons" role="tabpanel" aria-labelledby="tab-quick_addons" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Add Ons')">
            <div class="ui-stack">
                <details class="ui-disclosure">
                    <summary class="ui-disclosure__head">{{ ___('Google reCAPTCHA') }}</summary>
                    <div class="ui-disclosure__body ui-stack">
                        <div class="ui-small">
                            <p><strong>{{ ___('Instructions') }}</strong></p>
                            <p>{{ ___('To find your Site Key and Secret Key, follow the below steps:') }}</p>
                            <ol>
                                <li><a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">{{ ___('Go to the Google reCAPTCHA and register a new site.') }}</a></li>
                                <li>{{ ___('Enter label and select "reCAPTCHA v2" -> "I\'m not a robot" Checkbox in reCAPTCHA type field.') }}</li>
                                <li>{{ ___('Enter your domain url.') }}</li>
                                <li>{{ ___('Accept Terms of Service and click on the Submit button.') }}</li>
                                <li>{{ ___('Look for the Site Key and Secret Key. Use them in the form below on this page.') }}</li>
                            </ol>
                        </div>
                        <x-ui.switch name="recaptcha_mode" off="0" :checked="($settings->recaptcha_mode ?? '') == '1'" :label="___('Status')" />
                        <div class="ui-form-grid">
                            <x-ui.field name="recaptcha_public_key" :label="___('ReCAPTCHA Site Key')" :value="$__v($settings->recaptcha_public_key ?? null)" autocomplete="off" />
                            <x-ui.field name="recaptcha_private_key" :label="___('ReCAPTCHA Secret Key')" :value="$__v($settings->recaptcha_private_key ?? null)" autocomplete="off" />
                        </div>
                    </div>
                </details>
                <details class="ui-disclosure">
                    <summary class="ui-disclosure__head">{{ ___('Google Analytics 4') }}</summary>
                    <div class="ui-disclosure__body ui-stack">
                        <x-ui.switch name="google_analytics[status]" off="0" :checked="($settings->google_analytics->status ?? '') == '1'" :label="___('Status')" />
                        <x-ui.field name="google_analytics[measurement_id]" id="ga-measurement-id" :label="___('Measurement Id')" :value="$__v($settings->google_analytics->measurement_id ?? null)" autocomplete="off" />
                    </div>
                </details>
                <details class="ui-disclosure">
                    <summary class="ui-disclosure__head">{{ ___('Tawk.to (Live Chat)') }}</summary>
                    <div class="ui-disclosure__body ui-stack">
                        <x-ui.switch name="tawk_to[status]" off="0" :checked="($settings->tawk_to->status ?? '') == '1'" :label="___('Status')" />
                        <x-ui.field name="tawk_to[chat_link]" id="tawk-chat-link" :label="___('Direct Chat Link')" :value="$__v($settings->tawk_to->chat_link ?? null)" autocomplete="off" />
                        <a class="ui-small" href="https://help.tawk.to/article/direct-chat-link" target="_blank" rel="noopener">{{ ___('You can find here.') }}</a>
                    </div>
                </details>
            </div>
            @include('admin.settings._save', ['marker' => 'addons_settings'])
        </x-ui.card>
    </form>
</section>
