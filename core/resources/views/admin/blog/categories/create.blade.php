{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Create New Category')" :action="route('admin.blog.category.store')">
    @include('admin.blog.categories._form', ['category' => null])
</x-ui.panel>
