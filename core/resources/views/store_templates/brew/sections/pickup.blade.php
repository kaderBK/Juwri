{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the pickup hero and the order type as one block (the hero is the primary card on a phone; on desktop the control sits inside it) --}}
<div class="c-pickup">
@include('store_templates.brew.sections.hero')
@include('store_templates.brew.sections.order_type', ['withHero' => true])
</div>
