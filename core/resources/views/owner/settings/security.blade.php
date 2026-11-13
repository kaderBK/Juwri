{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Security'))
@section('page_title', ___('Security'))
@section('page_sub', ___('Two-factor authentication protects your account with a one-time code.'))

@section('content')

<div class="ui-card">
    <div class="ui-card__body">

        @if($enabled)
            <div class="ui-note" data-tone="success">{{ ___('Two-factor authentication is enabled.') }}</div>

            <form method="POST" action="{{ route('2fa.recovery.regenerate') }}">
                @csrf
                <button type="submit" class="ui-btn ui-btn--secondary">{{ ___('Regenerate recovery codes') }}</button>
            </form>

            <form method="POST" action="{{ route('2fa.disable') }}">
                @csrf
                <label class="ui-label" for="password">{{ ___('Confirm password to disable') }}</label>
                <div class="ui-input-group" style="max-width:420px">
                    <input id="password" name="password" type="password" class="ui-input" @error('password') aria-invalid="true" @enderror>
                    <button type="submit" class="ui-btn ui-btn--danger">{{ ___('Disable 2FA') }}</button>
                </div>
                @error('password')<div class="ui-text-danger ui-small">{{ $message }}</div>@enderror
            </form>

        @elseif(!empty($pendingConfirm))
            <p>{{ ___('Scan this QR code with your authenticator app, then enter a code to confirm.') }}</p>
            <div>{{-- raw: server-generated 2FA QR SVG --}}{!! $qrSvg !!}</div>
            <p class="ui-muted">{{ ___('Or enter this key manually:') }} <code>{{ $secret }}</code></p>

            <form method="POST" action="{{ route('2fa.confirm') }}">
                @csrf
                <label class="ui-label" for="code">{{ ___('Authentication code') }}</label>
                <div class="ui-input-group" style="max-width:420px">
                    <input id="code" name="code" type="text" inputmode="numeric" class="ui-input" @error('code') aria-invalid="true" @enderror placeholder="123456">
                    <button type="submit" class="ui-btn">{{ ___('Confirm') }}</button>
                </div>
                @error('code')<div class="ui-text-danger ui-small">{{ $message }}</div>@enderror
            </form>

        @else
            <p>{{ ___('Add an extra layer of security by requiring a one-time code at login.') }}</p>
            <form method="POST" action="{{ route('2fa.enable') }}">
                @csrf
                <button type="submit" class="ui-btn">{{ ___('Enable two-factor authentication') }}</button>
            </form>
        @endif

    </div>
</div>

@include('auth.partials.passkeys-manage', ['from' => 'owner'])

{{-- P1-23: the owner's own legal acceptances (read-only). --}}
@if(($legalAcceptances ?? collect())->isNotEmpty())
<div class="ui-card" id="legal-acceptances">
    <div class="ui-card__body">
        <h3 class="ui-section-title">{{ ___('Terms you accepted') }}</h3>
        <p class="ui-muted">{{ ___('The legal documents you accepted: which version, when, and where.') }}</p>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Document') }}</th><th>{{ ___('Version') }}</th><th>{{ ___('Accepted at') }}</th><th>{{ ___('Source') }}</th>
                </tr></thead>
                <tbody>
                @foreach($legalAcceptances as $a)
                    <tr>
                        <td data-primary data-label="{{ ___('Document') }}">{{ \Juwri\Core\Legal\LegalDocuments::label($a->document) }}</td>
                        <td data-label="{{ ___('Version') }}"><span class="ui-ltr">{{ $a->version }}</span></td>
                        <td data-label="{{ ___('Accepted at') }}"><span class="ui-ltr">{{ $a->accepted_at?->format('Y-m-d H:i') }}</span></td>
                        <td data-label="{{ ___('Source') }}">{{ ['signup' => ___('At sign-up'), 'banner' => ___('Change notice'), 'checkout' => ___('At checkout')][$a->source] ?? $a->source }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@endsection
