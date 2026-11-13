{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit Page')" :action="route('admin.pages.update', $page->id)" method="PUT">
    @include('admin.pages._form', ['page' => $page])
</x-ui.panel>
