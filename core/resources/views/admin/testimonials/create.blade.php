{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add Testimonial')" :action="route('admin.testimonials.store')" files>
    @include('admin.testimonials._form', ['testimonial' => null])
</x-ui.panel>
