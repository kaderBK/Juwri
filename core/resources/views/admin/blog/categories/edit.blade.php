{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit Category')" :action="route('admin.blog.category.update', $category->id)" method="PUT">
    @include('admin.blog.categories._form', ['category' => $category])
</x-ui.panel>
