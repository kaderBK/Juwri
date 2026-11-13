{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit Allergy')" :action="route('admin.allergies.update', $allergy->id)" method="PUT" files>
    @include('admin.allergies._form', ['allergy' => $allergy])
</x-ui.panel>
