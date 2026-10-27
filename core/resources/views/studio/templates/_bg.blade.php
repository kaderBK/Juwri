{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- studio-templates P4-1: shared background-art layering. Art sits under a
     bg-tinted scrim (contrast guard); all sheet content lifts above it. The
     $background value is a server-built data URI from session storage — never
     user input. --}}
<style nonce="{{ csp_nonce() }}">
.sheet { position: relative; }
.sheet::before {
    content: ''; position: absolute; inset: 0;
    background-image: url('{{ $background }}');
    background-size: cover; background-position: center;
}
.sheet::after {
    content: ''; position: absolute; inset: 0;
    background: color-mix(in srgb, var(--bg) 80%, transparent);
}
.sheet > * { position: relative; z-index: 1; }
</style>
