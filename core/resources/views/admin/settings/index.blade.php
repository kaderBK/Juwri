{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Settings'))
@section('content')
@php
    $__sections = [
        ['quick_settings_general', 'settings', ___('General'), 'general'],
        ['quick_feature_controls', 'shield', ___('Feature Controls'), 'feature-controls'],
        ['quick_theme_setting', 'sparkle', ___('Theme Settings'), 'theme_setting'],
        ['quick_logo_favicon', 'upload', ___('Logo & Favicon'), 'logo'],
        ['quick_international', 'world', ___('International'), 'international'],
        ['quick_smtp', 'send', ___('SMTP Details'), 'smtp'],
        ['quick_billing', 'receipt', ___('Billing Details'), 'billing-details'],
        ['quick_wallet', 'wallet', ___('Wallet Top-up'), 'wallet'],
        ['quick_legal', 'file', ___('Legal & compliance'), 'legal'],
        ['quick_social_logins', 'users', ___('Social Logins'), 'social-logins'],
        ['quick_integrations', 'bolt', ___('Integrations'), 'integrations'],
        ['quick_blog', 'book-open', ___('Blog Settings'), 'blog'],
        ['quick_testimonial', 'chat', ___('Testimonial Settings'), 'testimonial'],
        ['quick_addons', 'layout-grid', ___('Add Ons'), 'addons'],
        ['quick_custom_code', 'file', ___('Custom Code'), 'custom-code'],
        ['quick_system_info', 'server-2', ___('System Info'), 'system-info'],
    ];
@endphp
<div class="ui-settings" data-ui-tabs>
    <nav class="ui-settings__nav" role="tablist" aria-label="{{ ___('Settings') }}" aria-orientation="vertical">
        @foreach ($__sections as [$__id, $__icon, $__label])
            <button type="button" class="ui-settings__tab" role="tab" id="tab-{{ $__id }}" aria-controls="{{ $__id }}"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}" @unless ($loop->first) tabindex="-1" @endunless>
                <x-ui.icon :name="$__icon" size="18" /><span>{{ $__label }}</span>
            </button>
        @endforeach
    </nav>
    <div class="ui-settings__panes">
        @foreach ($__sections as [$__id, , , $__view])
            @include('admin.settings.'.$__view, ['active' => $loop->first])
        @endforeach
    </div>
</div>
@endsection
