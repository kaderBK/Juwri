{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Edit Menu Template'))
@section('page_sub'){{ $template['name'] }} ({{ $slug }})@endsection

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.menu-templates.index')">{{ ___('Cancel') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-columns ui-columns--wide-first">
    <x-ui.card :title="___('Edit Menu Template').': '.$template['name']">
        <form action="{{ route('admin.menu-templates.update', $slug) }}" method="post" enctype="multipart/form-data" class="ui-stack">
            @csrf
            <p class="ui-small ui-text-2">{{ ___('Leave name or description empty to use the values shipped with the template.') }}</p>

            <x-ui.field name="display_name" :label="___('Display Name')" :value="$meta->display_name" :placeholder="$template['name']" />

            <x-ui.field type="textarea" name="description" :label="___('Description')" :value="$meta->description" :placeholder="$template['description']" rows="3" />

            <x-ui.image-field name="preview_image" :label="___('Preview Image')" :src="$template['image']" :size="160"
                              accept="image/png, image/jpeg, image/webp"
                              :hint="___('Allowed JPG, JPEG, PNG or WEBP.').' '.___('Replaces the preview shown to restaurant owners.')" />

            <x-ui.field type="number" name="sort_order" :label="___('Sort Order')" :value="$meta->sort_order ?? 0" min="0" max="9999"
                        :help="___('Lower numbers appear first in the template picker.')" />

            <x-ui.switch name="featured" off="0" :label="___('Featured')"
                         :checked="(bool) old('featured', $meta->exists ? (int) $meta->featured : (int) $template['featured'])" />

            <x-ui.switch name="enabled" off="0" :label="___('Enable/Disable')"
                         :description="___('Disabling hides this template from the picker. Restaurants already using it keep it until reassigned.')"
                         :checked="(bool) old('enabled', $meta->exists ? (int) $meta->enabled : 1)" />

            <div class="ui-cluster ui-cluster--end">
                <x-ui.button variant="secondary" :href="route('admin.menu-templates.index')">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" data-ui-busy>{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
