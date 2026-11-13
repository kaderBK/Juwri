{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: logo_marquee. Live stores using the platform as a static,
     wrapped logo row (marketing.sections.trust: marquee_stores() and
     active_store_count(), cached; renders nothing without logos). --}}
@if (marquee_stores()->isNotEmpty())
    <div class="sb-block" data-site-block="logo_marquee">
        @include('marketing.sections.trust')
    </div>
@endif
