{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_testimonial" role="tabpanel" aria-labelledby="tab-quick_testimonial" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Testimonials Settings')">
            <div class="ui-form-grid">
                <x-ui.switch name="testimonials_enable" off="0" :checked="($settings->testimonials_enable ?? '') == '1'" :label="___('Testimonials')" />
                <x-ui.switch name="show_testimonials_home" off="0" :checked="($settings->show_testimonials_home ?? '') == '1'" :label="___('Show On Home Page')" />
                <x-ui.switch name="show_testimonials_blog" off="0" :checked="($settings->show_testimonials_blog ?? '') == '1'" :label="___('Show On Blog Page')" />
            </div>
            @include('admin.settings._save', ['marker' => 'testimonial_settings'])
        </x-ui.card>
    </form>
</section>
