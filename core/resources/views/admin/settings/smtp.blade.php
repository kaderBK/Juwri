{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php $__v = fn (string $key) => demo_mode() ? '' : ($settings->{$key} ?? null); @endphp
<section id="quick_smtp" role="tabpanel" aria-labelledby="tab-quick_smtp" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('SMTP details')">
            <div class="ui-stack">
                <x-ui.field type="email" name="admin_email" :label="___('Admin Email Address').' *'" :value="$__v('admin_email')" required
                            :help="___('This is the email address to which the contact and feedback emails will be sent.')" />
                <div class="ui-form-grid">
                    <x-ui.field type="email" name="smtp_from_email" :label="___('From Email Address').' *'" :value="$__v('smtp_from_email')" required
                                :help="___('This email will be used to send emails.')" />
                    <x-ui.field name="smtp_from_name" :label="___('From Name').' *'" :value="$__v('smtp_from_name')" required />
                </div>
                <p class="ui-section-title">{{ ___('Mail server') }}</p>
                <div class="ui-form-grid">
                    <x-ui.field type="select" name="smtp_mailer" :label="___('Mailer')" :value="$settings->smtp_mailer ?? null"
                                :options="['smtp' => ___('SMTP'), 'sendmail' => ___('SENDMAIL'), 'log' => ___('Log')]" />
                    <x-ui.field name="smtp_host" :label="___('Host')" :value="$__v('smtp_host')" autocomplete="off" />
                    <x-ui.field name="smtp_port" :label="___('Port')" :value="$__v('smtp_port')" inputmode="numeric" />
                    <x-ui.field name="smtp_username" :label="___('Username')" :value="$__v('smtp_username')" autocomplete="off" />
                    {{-- The stored password is encrypted; the page holds only its mask and saving the mask keeps it. --}}
                    <div class="ui-field">
                        <label class="ui-field__label" for="smtp_password">{{ ___('Password') }}</label>
                        <div class="ui-field__control">
                            <input type="password" id="smtp_password" name="smtp_password" value="{{ $__v('smtp_password') }}" autocomplete="new-password">
                        </div>
                    </div>
                    <x-ui.field type="select" name="smtp_secure" :label="___('Encryption')" :value="$settings->smtp_secure ?? null" :options="['tls' => ___('TLS'), 'ssl' => ___('SSL')]"
                                :help="___('If your e-mail service provider supported secure connections, you can choose security method on list.')" />
                </div>
            </div>
            @include('admin.settings._save', ['marker' => 'smtp_settings'])
        </x-ui.card>
    </form>

    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Test SMTP')">
            <x-ui.field type="email" name="email" :label="___('Email Address').' *'" :value="request()->user()->email" placeholder="john@example.com" />
            <div class="ui-card__foot">
                <input type="hidden" name="smtp_test" value="1">
                <x-ui.button type="submit" variant="secondary" icon="send">{{ ___('Send') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</section>
