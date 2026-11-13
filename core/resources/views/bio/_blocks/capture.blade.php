{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- capture block: email/phone capture -> store subscriber rail (Lane A endpoint). config: {title?, text?, placeholder?, consent?, mode?} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $title = trim((string) ($b['label'] ?? $cfg['title'] ?? '')) ?: ___('Join the list');
    $text = trim((string) ($cfg['text'] ?? ''));
    $consent = trim((string) ($cfg['consent'] ?? ''));
    $placeholder = trim((string) ($cfg['placeholder'] ?? '')) ?: ___('your@email.com');
    $phoneMode = ($cfg['mode'] ?? 'email') === 'phone';
    $action = rtrim(url()->current(), '/').'/subscribe';
    $id = $b['id'] ?? '';
@endphp
<form class="bio-capture" method="POST" action="{{ $action }}" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
    @csrf
    <b>{{ $title }}</b>
    @if ($text !== '')<p>{{ $text }}</p>@endif
    <div class="bio-hp" aria-hidden="true"><label>{{ ___('Leave this empty') }}<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
    <div class="bio-capture__field">
        @if ($phoneMode)
            <input type="tel" name="phone" inputmode="tel" placeholder="{{ $placeholder }}" aria-label="{{ ___('Phone') }}" required>
        @else
            <input type="email" name="email" inputmode="email" placeholder="{{ $placeholder }}" aria-label="{{ ___('Email') }}" required>
        @endif
        <button type="submit">{{ ___('Join') }}</button>
    </div>
    @if ($consent !== '')<p class="bio-consent">{{ $consent }}</p>@endif
</form>
