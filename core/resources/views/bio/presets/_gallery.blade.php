{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Preset picker gallery — renders every preset thumbnail in a responsive grid. Reference grid
     for Lane B's settings picker; fully inline-styled, no external CSS. Pass nothing. --}}
@php
    $files = glob(resource_path('views/bio/presets/*.json')) ?: [];
    sort($files);
@endphp
<div class="bio-thumb-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:14px;padding:16px">
    @foreach ($files as $f)
        @include('bio.presets.thumbs.'.basename($f, '.json'))
    @endforeach
</div>
