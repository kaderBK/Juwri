{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Create Page')" :action="route('admin.pages.store')">
    @include('admin.pages._form', ['page' => null])
</x-ui.panel>
