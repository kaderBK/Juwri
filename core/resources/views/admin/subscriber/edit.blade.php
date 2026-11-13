{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit')" :action="route('admin.subscriber.update', $subscriber->id)" method="PUT">
    @include('admin.subscriber._form', ['subscriber' => $subscriber])
</x-ui.panel>
