{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add Allergy')" :action="route('admin.allergies.store')" files>
    @include('admin.allergies._form', ['allergy' => null])
</x-ui.panel>
