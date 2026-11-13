{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared vertical page: profile header, ordered blocks, powered-by. Used by the stack-type families (list, menu_card, swiss, brutalist, type_lite). --}}
<div class="bio-in">
    @includeUnless(! empty($noProfile ?? false), 'bio._profile')
    @include('bio._blocks')
    @include('bio._pow')
</div>
