{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_theme_setting" role="tabpanel" aria-labelledby="tab-quick_theme_setting" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Theme Settings')">
            <div class="ui-stack">
                <x-ui.field type="color" name="theme_color" :label="___('Primary Color').' *'" :value="$settings->theme_color ?? '#000000'" />
                <p class="ui-section-title">{{ ___('Contact Details') }}</p>
                <x-ui.field name="contact_address" :label="___('Contact Page Address')" :value="$settings->contact_address ?? null" />
                <div class="ui-form-grid">
                    <x-ui.field type="email" name="contact_email" :label="___('Contact Page Email')" :value="$settings->contact_email ?? null" />
                    <x-ui.field type="tel" name="contact_phone" :label="___('Contact Page Phone')" :value="$settings->contact_phone ?? null" />
                </div>
                <p class="ui-section-title">{{ ___('Footer Social Links') }}</p>
                <div class="ui-form-grid">
                    @foreach ([
                        'facebook_link' => ___('Footer Facebook Page Link'), 'twitter_link' => ___('Footer X (Twitter) Page Link'),
                        'instagram_link' => ___('Footer Instagram Page Link'), 'linkedin_link' => ___('Footer LinkedIn Page Link'),
                        'pinterest_link' => ___('Footer Pinterest Page Link'), 'youtube_link' => ___('Footer Youtube Page/Video Link'),
                    ] as $__name => $__label)
                        <x-ui.field :name="$__name" :label="$__label" :value="$settings->{$__name} ?? null" inputmode="url" />
                    @endforeach
                </div>
            </div>
            @include('admin.settings._save', ['marker' => 'theme_setting'])
        </x-ui.card>
    </form>
</section>
