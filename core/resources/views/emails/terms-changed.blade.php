{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="__('site.mail.terms_changed.subject', ['date' => $date])">
    <p style="margin:0 0 12px;">{{ __('sysmail.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 12px;">{{ __('sysmail.terms_changed.intro', ['brand' => brand_title(), 'date' => $date]) }}</p>
    <p style="margin:0;">{{ __('sysmail.terms_changed.notice') }}</p>
    <x-mail.button :href="$termsUrl">{{ __('sysmail.terms_changed.cta') }}</x-mail.button>
</x-mail.frame>
