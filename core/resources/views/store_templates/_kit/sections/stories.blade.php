{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php($__st = $vm['features']['stories'] ?? [])
@if ($__st)
<section class="c-stories" aria-label="{{ $kit->s('Offers') }}"><ul class="c-rail">
@foreach ($__st as $__i => $__x)
<li><a class="c-story" href="#story-{{ $__x['id'] ?? $__i }}">@if (! empty($__x['image']))<img class="c-story__img" src="{{ $__x['image']['src'] }}" alt="" width="64" height="64" decoding="async" loading="lazy">@endif<span class="c-story__label">{{ $__x['title'] ?? '' }}</span></a></li>
@endforeach
</ul></section>
@endif
