{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Allergy fields shared by the create and edit panels. $allergy is null on create. --}}
@php
    $__img = $allergy
        ? (str($allergy->image)->isUrl() ? $allergy->image : asset('storage/allergies/'.$allergy->image))
        : asset('storage/allergies/default.png');
@endphp
<x-ui.image-field name="image" :label="___('Image')" :src="$__img" :size="40" :hint="___('Allowed JPG, JPEG or PNG.')" />
<x-ui.translated-field name="title" :label="___('Title')" :value="$allergy?->getRawOriginal('title')" :translations="$allergy?->translations" />
<input type="hidden" name="active" value="0">
<x-ui.switch name="active" :checked="$allergy ? (bool) $allergy->active : true" :label="___('Enable/Disable')" />
