{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit FAQ')" :action="route('admin.faqs.update', $faq->id)" method="PUT">
    @include('admin.faqs._form', ['faq' => $faq])
</x-ui.panel>
