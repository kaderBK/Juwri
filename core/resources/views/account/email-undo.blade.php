{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- BL-036 A1a: "This wasn't me — undo" page (EmailChangeUndoController). Standalone guest
     page on the platform host. CSP-safe: no inline <script>/<style>; styling is
     external-redirect.css (shared card look) + email-undo.css. GET renders; only the
     POST form changes anything. Every value is escaped by Blade. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ ___('Undo email change') }} · {{ $brand }}</title>
    <link rel="stylesheet" href="{{ asset('assets/global/css/external-redirect.css?v=1') }}">
    <link rel="stylesheet" href="{{ asset('assets/global/css/email-undo.css?v=1') }}">
</head>
<body>
    <main class="er-card" data-undo-state="{{ $state }}">
        <p class="er-name">{{ $brand }}</p>
        @switch($state)
            @case('confirm')
                <h1 class="er-title">{{ ___('Undo email change') }}</h1>
                <p class="er-text">{{ ___('The sign-in email for your account was changed to :new. If you did not make or ask for this change, undo it below.', ['new' => $undo->new_email]) }}</p>
                <ul class="eu-list">
                    <li>{{ ___('The sign-in email goes back to :old.', ['old' => $undo->old_email]) }}</li>
                    <li>{{ ___('Everyone signed in to the account is signed out, and the current password stops working.') }}</li>
                    <li>{{ ___('We email :old a link to choose a new password.', ['old' => $undo->old_email]) }}</li>
                </ul>
                <form method="POST" action="{{ $action }}" class="eu-form">
                    @csrf
                    <button type="submit" class="er-btn">{{ ___('This wasn\'t me — undo the change') }}</button>
                </form>
                <p class="er-hint">{{ ___('If you made this change yourself, just close this page.') }}</p>
                @break
            @case('done')
                <h1 class="er-title">{{ ___('Email change undone') }}</h1>
                <p class="er-text">{{ ___('Your sign-in email is :old again. Everyone was signed out. Check that inbox for a link to choose a new password — you need it to sign in.', ['old' => $undo->old_email]) }}</p>
                <p class="er-text">{{ ___('All API tokens and connected apps were signed out. Create new ones after you sign in.') }}</p>
                @php($pausedWebhooks = count($undo->deactivatedWebhookIds()))
                @if ($pausedWebhooks > 0)
                    <p class="er-text" data-undo-webhooks="{{ $pausedWebhooks }}">{{ ___('We paused :count webhook endpoint(s) added or changed after the email change. After you sign in, review them on the API & Webhooks page and delete any you do not recognise before resuming.', ['count' => $pausedWebhooks]) }}</p>
                @endif
                @php($disabledStaff = count($undo->disabledStaffIds()))
                @if ($disabledStaff > 0)
                    <p class="er-text" data-undo-staff="{{ $disabledStaff }}">{{ ___('We switched off :count staff account(s) added or changed after the email change. After you sign in, check them on the Staff page and switch back on only the ones you recognise.', ['count' => $disabledStaff]) }}</p>
                @endif
                @break
            @case('used')
                <h1 class="er-title">{{ ___('This link was already used') }}</h1>
                <p class="er-text">{{ ___('This undo link works only once. If you still cannot sign in, use "Forgot password" with your original email.') }}</p>
                @break
            @case('taken')
                <h1 class="er-title">{{ ___('We could not undo this automatically') }}</h1>
                <p class="er-text">{{ ___('Your previous email is now used by another account. Please contact support so we can secure your account.') }}</p>
                @if ($contact !== '')
                    <p class="er-text eu-contact">{{ $contact }}</p>
                @endif
                @break
            @default
                <h1 class="er-title">{{ ___('This link is no longer valid') }}</h1>
                <p class="er-text">{{ ___('Undo links expire after 7 days or when a later change was undone. If you did not change your email, contact support.') }}</p>
                @if ($contact !== '')
                    <p class="er-text eu-contact">{{ $contact }}</p>
                @endif
        @endswitch
    </main>
</body>
</html>
