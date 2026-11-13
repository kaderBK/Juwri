{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Trusted HTML') . ' - ' . ($page->title ?? ___('Page')))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="$backUrl">{{ ___('Back to pages') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="eye" :href="$previewUrl" target="_blank" rel="noopener">{{ ___('Preview') }}</x-ui.button>
    <x-ui.button size="sm" icon="check" id="mbTrustedSave">{{ ___('Save') }}</x-ui.button>
@endsection

@section('content')
    <div class="ui-stack">
        <x-ui.banner tone="danger">
            <strong>{{ ___('Trusted HTML — no sanitiser.') }}</strong>
            {{ ___('This page renders exactly as written, including <script>. Only paste code you trust. This mode is available to admins only.') }}
        </x-ui.banner>
        <x-ui.card>
            <textarea id="mbTrustedCode" class="ui-input ui-mono ui-code-area" spellcheck="false" aria-label="{{ ___('Trusted HTML') }}">{{ $page->content }}</textarea>
            <p id="mbTrustedStatus" class="ui-muted" role="status" aria-live="polite"></p>
        </x-ui.card>
    </div>

    <script nonce="{{ csp_nonce() }}">
        (function () {
            var saveUrl = @json($saveUrl);
            var csrf = @json(csrf_token());
            var btn = document.getElementById('mbTrustedSave');
            var code = document.getElementById('mbTrustedCode');
            var status = document.getElementById('mbTrustedStatus');
            function save() {
                btn.setAttribute('aria-busy', 'true'); btn.disabled = true;
                status.textContent = @json(___('Saving…'));
                fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ html: code.value })
                }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                  .then(function (d) { status.textContent = @json(___('Saved')) + (d.saved_at ? ' · ' + new Date(d.saved_at).toLocaleTimeString() : ''); })
                  .catch(function () { status.textContent = @json(___('Save failed — please retry.')); })
                  .then(function () { btn.removeAttribute('aria-busy'); btn.disabled = false; });
            }
            btn.addEventListener('click', save);
            code.addEventListener('keydown', function (e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 's') { e.preventDefault(); save(); }
            });
        })();
    </script>
@endsection
