{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.licence-notice /> — P1-13 13b: operator notice while the Enterprise licence is in its 14-day grace
  period, or runs on a development licence ("Development licence — not for production") (admin layout).
  Community and a valid production licence render nothing.
--}}
@php $__licenceNotice = app(\Juwri\Core\Contracts\Editions\Edition::class)->notice(); @endphp
@if ($__licenceNotice)
    <x-ui.banner tone="warning" class="ui-notice" data-licence-notice>{{ $__licenceNotice }}</x-ui.banner>
@endif
