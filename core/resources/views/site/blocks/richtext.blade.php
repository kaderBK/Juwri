{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: richtext. Body is purified on save; re-cleaned on output as
     defence in depth — this is the ONLY {!! !!} in site blocks. --}}
@php
    $sbHtml = \Juwri\Core\Support\Html::clean($block['content']['body'] ?? '', 'richtext');
@endphp
<section class="mk-sec sb-block" data-site-block="richtext">
    <div class="mk-wrap">
        <div class="mk-prose">{!! $sbHtml !!}</div>
    </div>
</section>
