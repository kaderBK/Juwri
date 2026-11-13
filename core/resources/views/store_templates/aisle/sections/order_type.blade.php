{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: shipping or picking up in store is a delivery METHOD chosen at checkout (with dates and fees), not a page-level switch.
     Only a goods store that cannot ship (collection only) keeps the shared order-type block. --}}
@if (! \Juwri\Core\Support\ViewHelpers::hasKey($vm['context']['order_types'] ?? [], 'ship') && count($vm['context']['order_types'] ?? []) > 1)
@include('store_templates._kit.sections.order_type')
@endif
