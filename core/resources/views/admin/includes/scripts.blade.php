{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Superadmin end-of-body scripts (admin.layout): page stacks and the one-shot
  flash message (app_alert()/session flashes) shown as a kit toast.
--}}
@stack('scripts')
@stack('scripts_at_bottom')

@php
    $__flashes = [];
    foreach (['success', 'info', 'warning', 'danger', 'error'] as $__key) {
        if (session()->has($__key) && is_string(session($__key))) {
            $__flashes[] = ['tone' => in_array($__key, ['danger', 'error'], true) ? 'danger' : 'success', 'message' => session($__key)];
        }
    }
    if (session()->has('app_alert_message')) {
        $__flashes[] = [
            'tone' => in_array(session('app_alert_type'), ['error', 'danger'], true) ? 'danger' : 'success',
            'message' => (string) session('app_alert_message'),
        ];
    }
@endphp
@if (count($__flashes))
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('DOMContentLoaded', function () {
            (@js($__flashes)).forEach(function (f) {
                if (window.ownerUi && window.ownerUi.toast) { window.ownerUi.toast(f.message, { tone: f.tone }); }
            });
        });
    </script>
@endif
