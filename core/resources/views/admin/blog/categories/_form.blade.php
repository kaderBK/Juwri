{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Blog category fields shared by the create and edit panels. $category is null on create. --}}
<input type="hidden" name="active" value="0">
<x-ui.switch name="active" :checked="$category ? (bool) $category->active : true" :label="___('Active')" />
<x-ui.field name="title" :label="___('Category title').' *'" :value="$category?->title" required />
<x-ui.field name="slug" :label="___('Slug')" :value="$category?->slug" />
