{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- BL-036 A1b: account-deletion restore link (AccountDeletionController::restoreConfirm).
     Standalone guest page. GET renders this page only (no state change, so a mail scanner
     pre-fetching the link restores nothing); the POST form (CSRF, same signed URL) restores.
     CSP-safe: no inline <script>/<style>. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ ___('Restore your account') }} · {{ $brand }}</title>
    <link rel="stylesheet" href="{{ asset('assets/global/css/external-redirect.css?v=1') }}">
    <link rel="stylesheet" href="{{ asset('assets/global/css/email-undo.css?v=1') }}">
</head>
<body>
    <main class="er-card" data-restore-state="{{ $state }}">
        <p class="er-name">{{ $brand }}</p>
        @if ($state === 'confirm')
            <h1 class="er-title">{{ ___('Restore your account') }}</h1>
            <p class="er-text">{{ ___('Your account is closed and will be permanently deleted on :date. Restore it to sign in again and keep your data.', ['date' => $deletion->purge_after->translatedFormat('j F Y')]) }}</p>
            <form method="POST" action="{{ $action }}" class="eu-form">
                @csrf
                <button type="submit" class="er-btn">{{ ___('Restore my account') }}</button>
            </form>
            <p class="er-hint">{{ ___('If you want the account deleted, just close this page.') }}</p>
        @else
            <h1 class="er-title">{{ ___('This restore link is no longer valid.') }}</h1>
            <p class="er-text">{{ ___('The account was already restored or deleted, or the restore period has ended.') }}</p>
        @endif
    </main>
</body>
</html>
