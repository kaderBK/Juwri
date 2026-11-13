{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.legacy-scripts /> — TEMPORARY compatibility layer (WI-16a).

  jQuery (kept for any page-stack script that still expects it), ApexCharts
  (owner dashboard + reports charts; re-vendored to assets/vendor in WI-10i),
  the owner services script (owner-ui/services.js: data-lm hooks, toast store,
  dashConfirm/dashPrompt/dashToast), Alpine, and the shared toast + flash
  partials. Bootstrap, Popper, SimpleBar, Waves and the NexLink init script
  left with WI-10i (no owner screen uses them; the shims guard for their
  absence). Removed with WI-16h.
--}}
@php
    
@endphp
<script src="{{ asset('assets/global/js/jquery.min.js') }}" data-turbo-eval="false"></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/vendor/apexcharts/apexcharts.min.js') }}" data-turbo-eval="false"></script>
<script defer src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/services.js') }}" data-turbo-eval="false"></script>
<script defer src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/vendor/alpinejs/alpine.min.js') }}" data-turbo-eval="false"></script>
@include('owner.partials.flash')
@include('owner.partials.toast')
