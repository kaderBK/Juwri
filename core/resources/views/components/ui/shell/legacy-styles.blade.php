{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.legacy-styles /> — TEMPORARY compatibility layer (WI-16a).

  The kit shell and every owner screen are on the kit (WI-16c); the NexLink
  stylesheets left with WI-10i. What is left: Turbo (+ our compat shim) and
  owner-ui/shell-extras.css (formerly old-theme-skin.css), which still styles the shared toast (`.dash-toast*`,
  owner/partials/toast). Delete this component with WI-16h.
--}}
@props(['rtl' => false])
@php
    
@endphp
{{-- P4-19: Turbo injects its progress-bar <style> at run time; it takes the CSP nonce from this meta (without it the style is a CSP violation in Safari AND Chrome, and is blocked once the policy is enforced). --}}
<meta name="csp-nonce" content="{{ csp_nonce() }}">
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/vendor/turbo/turbo.min.js') }}" data-turbo-eval="false"></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/turbo-compat.js') }}" data-turbo-eval="false"></script>
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/shell-extras.css') }}">
