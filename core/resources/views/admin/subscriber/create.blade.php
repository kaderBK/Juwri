{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add Subscriber')" :action="route('admin.subscriber.store')">
    @include('admin.subscriber._form', ['subscriber' => null])
</x-ui.panel>
