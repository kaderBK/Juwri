{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@if($stores->count() > 1)
<form method="get" action="{{ url()->current() }}">
    <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
        @foreach ($stores as $s)
            <option value="{{ $s->id }}" @selected($store->id == $s->id)>{{ $s->title }}</option>
        @endforeach
    </select>
</form>
@endif
