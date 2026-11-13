{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@foreach ($vm['features']['custom_blocks'] ?? [] as $__b)
<div class="c-custom" data-block="{{ $__b['id'] }}">{{ $kit->sanitised($__b['html']) }}</div>
@endforeach
