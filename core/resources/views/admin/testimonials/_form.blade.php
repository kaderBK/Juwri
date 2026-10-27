{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Testimonial fields shared by the create and edit panels. $testimonial is null on create. --}}
<x-ui.image-field name="image" :label="___('Image')" :size="72" round :hint="___('Allowed JPG, JPEG or PNG.')"
    :src="$testimonial ? asset('storage/testimonials/'.$testimonial->image) : asset('storage/profile/default_user.png')" />
<x-ui.field name="name" :label="___('Name').' *'" :value="$testimonial?->name" required />
<x-ui.translated-field name="designation" :label="___('Designation')" :value="$testimonial?->designation" :translations="$testimonial?->translations" />
<x-ui.translated-field name="content" type="textarea" :label="___('Content')" :value="$testimonial?->content" :translations="$testimonial?->translations" />
