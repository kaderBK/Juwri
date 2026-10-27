{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Theme Studio B0.7 — widget dialog header (title + close). Expects $mbwId, $mbwTitle. --}}
<div class="mbw-head">
    <h2 class="mbw-title" id="{{ $mbwId }}-title">{{ $mbwTitle }}</h2>
    <button type="button" class="mbw-x" data-mbw-close aria-label="{{ ___('Close') }}">
        <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
</div>
