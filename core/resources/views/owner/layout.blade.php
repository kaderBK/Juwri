{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Owner dashboard layout for section-based screens: @extends('owner.layout').

  The page shell is the owner-ui kit (<x-ui.layout>); this view hands the
  screen's `content` section to it. `title`, `page_title`, `page_sub`,
  `page_actions` and `body_class` sections and the `styles` / `head` /
  `scripts` / `scripts_at_bottom` stacks are read by the kit layout.
--}}
<x-ui.layout :body-class="$bodyClass ?? ''">
@yield('content')
</x-ui.layout>
