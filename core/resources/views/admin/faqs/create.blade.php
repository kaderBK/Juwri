{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add FAQ')" :action="route('admin.faqs.store')">
    @include('admin.faqs._form', ['faq' => null])
</x-ui.panel>
