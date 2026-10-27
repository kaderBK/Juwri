{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit Testimonial')" :action="route('admin.testimonials.update', $testimonial->id)" method="PUT" files>
    @include('admin.testimonials._form', ['testimonial' => $testimonial])
</x-ui.panel>
