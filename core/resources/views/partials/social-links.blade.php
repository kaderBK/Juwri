{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    #101 Presence — Instagram / Facebook links. Include in the customer menu
    footer: @include('partials.social-links', ['socialPost' => $store])
    Renders nothing if no social URLs are set on the restaurant.
--}}
@php($__ig = isset($socialPost) ? store_options($socialPost->id, 'instagram_url') : null)
@php($__fb = isset($socialPost) ? store_options($socialPost->id, 'facebook_url') : null)
@if($__ig || $__fb)
<div class="social-links" style="display:flex;gap:14px;align-items:center;justify-content:center;padding:12px 0">
    @if($__ig)
        <a href="{{ $__ig }}" target="_blank" rel="noopener" aria-label="Instagram" style="color:inherit;text-decoration:none">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
        </a>
    @endif
    @if($__fb)
        <a href="{{ $__fb }}" target="_blank" rel="noopener" aria-label="Facebook" style="color:inherit;text-decoration:none">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 21v-8h2.7l.4-3h-3.1V8c0-.9.3-1.5 1.6-1.5H17V3.9c-.3 0-1.3-.1-2.4-.1-2.4 0-4.1 1.5-4.1 4.2V10H7.7v3h2.8v8h3z"/></svg>
        </a>
    @endif
</div>
@endif
