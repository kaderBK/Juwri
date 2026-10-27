{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.panel> — body of the superadmin side panel (loaded into the drawer by
  assets/owner-ui/admin.js). Wraps one form; Save posts it with fetch and reads
  the {success, message} JSON answer (Juwri\Core\Http\Requests\Admin\PanelFormRequest).

    title   panel heading
    action  form action URL; null → a read-only panel (no form, no Save)
    method  POST (default) | PUT | PATCH | DELETE (sent as _method)
    files   true → multipart form (uploads)
    slot `after`: markup below the form (e.g. a second, separate form)

    <x-ui.panel :title="___('Edit tax')" :action="route('admin.taxes.update', $tax->id)" method="PUT">
        <x-ui.field name="name" :label="___('Name')" :value="$tax->name" required />
    </x-ui.panel>
--}}
@props(['title', 'action' => null, 'method' => 'POST', 'files' => false])
@php $__verb = strtoupper((string) $method); @endphp
<div class="ui-drawer__head ui-panel__head">
    <h2 class="ui-panel__title" id="ui-admin-panel-title">{{ $title }}</h2>
    <div class="ui-panel__actions">
        @if ($action)
            <button type="button" class="ui-btn ui-btn--sm" data-ui-panel-save>
                <span class="ui-btn__spinner" aria-hidden="true"></span>
                <span class="ui-btn__icon"><x-ui.icon name="check" size="16" /></span>{{ ___('Save') }}
            </button>
        @endif
        <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-ui-panel-close aria-label="{{ ___('Close') }}">
            <x-ui.icon name="x" size="18" />
        </button>
    </div>
</div>
<div class="ui-drawer__body ui-panel__body">
    @if ($action)
        <form action="{{ $action }}" method="post" id="sidePanel_form" @if ($files) enctype="multipart/form-data" @endif {{ $attributes }}>
            @csrf
            @if ($__verb !== 'POST')
                @method($__verb)
            @endif
            {{ $slot }}
        </form>
        {{ $after ?? '' }}
    @else
        {{ $slot }}
    @endif
</div>
