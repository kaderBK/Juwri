{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Owner self-service account deletion (Apple 5.1.1(v); owner-app server item S3).
     States exactly what happens now, what is deleted after the grace period, what is
     kept for tax law, and how to restore. --}}
@extends('owner.layout')

@section('title', ___('Delete account'))
@section('page_title', ___('Delete account'))
@section('page_sub', ___('Close your account and permanently delete your data.'))

@section('content')
<div class="ui-scope ui-delacct">
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <x-ui.card>
        <h2 class="ui-delacct__h">{{ ___('What happens right away') }}</h2>
        <ul class="ui-delacct__list">
            <li><x-ui.icon name="x" size="18" /><span>{{ ___('You are signed out and can no longer log in.') }}</span></li>
            <li><x-ui.icon name="store" size="18" /><span>{{ ___('Your stores go offline: customers can no longer see menus or place orders.') }}</span></li>
            <li><x-ui.icon name="card" size="18" /><span>{{ ___('Your subscription is cancelled. Payments already made are not refunded.') }}</span></li>
            <li><x-ui.icon name="users" size="18" /><span>{{ ___('Staff logins, app sign-ins, API tokens and notification devices stop working.') }}</span></li>
        </ul>

        <h2 class="ui-delacct__h">{{ ___('After :days days', ['days' => $graceDays]) }}</h2>
        <ul class="ui-delacct__list">
            <li><x-ui.icon name="trash" size="18" /><span>{{ ___('Your stores, menus, photos, customers, reviews, staff and settings are permanently deleted.') }}</span></li>
            <li><x-ui.icon name="book-open" size="18" /><span>{{ ___('Invoices and order records that tax law requires us to keep are retained without your customers’ personal details.') }}</span></li>
        </ul>

        <x-ui.banner>{{ ___('Changed your mind? We email you a link to restore your account within :days days.', ['days' => $graceDays]) }}</x-ui.banner>
    </x-ui.card>

    <form method="POST" action="{{ route('user.account.delete') }}" class="ui-card ui-delacct__form">
        @csrf
        @if($confirmWithEmail)
            <x-ui.field type="email" id="confirm_email" name="confirm_email" :label="___('Type your account email to confirm')" autocomplete="off" required />
        @else
            <x-ui.field type="password" id="password" name="password" :label="___('Your password')" autocomplete="current-password" required />
        @endif

        <x-ui.field type="textarea" id="reason" name="reason" :label="___('Why are you leaving? (optional)')" :rows="2" maxlength="500" />

        <div class="ui-check">
            <input type="checkbox" value="1" id="understand" name="understand" required>
            <label for="understand">{{ ___('I understand that my account will be closed now and my data permanently deleted after :days days.', ['days' => $graceDays]) }}</label>
        </div>

        <div class="ui-delacct__actions">
            <x-ui.button :href="route('settings')" variant="secondary">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="danger" icon="trash">{{ ___('Delete my account') }}</x-ui.button>
        </div>
    </form>
</div>
@endsection
