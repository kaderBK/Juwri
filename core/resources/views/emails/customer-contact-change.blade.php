{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P6-304: confirm a new e-mail (`confirm_email`), or tell the old address that a change was asked for (`notice_old`). --}}
@php
    $isConfirm = $kind === 'confirm_email';
    $mailTitle = $isConfirm ? ___('Confirm your new email') : ___('Your contact details are changing');
@endphp
<x-mail.frame :title="$mailTitle" :brand="$restaurantName" :eyebrow="$mailTitle" :heading="$restaurantName">
    <p style="margin:0 0 8px;">
        {{ $customerName ? ___('Hi :name,', ['name' => $customerName]) : ___('Hi,') }}
    </p>
    @if ($isConfirm)
        <p style="margin:0;">{{ ___('Confirm this address to use it for your account at :store.', ['store' => $restaurantName]) }}</p>
        @if ($confirmUrl)
            <x-mail.button :href="$confirmUrl">{{ ___('Confirm my email') }}</x-mail.button>
        @endif
    @else
        <p style="margin:0 0 8px;">
            {{ $field === 'email'
                ? ___('Someone asked to change the email on your account to :value.', ['value' => $newValueMasked])
                : ___('Someone asked to change the phone number on your account to :value.', ['value' => $newValueMasked]) }}
        </p>
        <p style="margin:0;">{{ ___('If this was not you, sign in and change your password, or contact :store.', ['store' => $restaurantName]) }}</p>
    @endif

    <x-slot:footer>
        @if ($isConfirm)
            {{ ___('This link expires in 24 hours. If you did not ask for this, ignore this email.') }}<br>
        @endif
        {{ $restaurantName }}
    </x-slot:footer>
</x-mail.frame>
