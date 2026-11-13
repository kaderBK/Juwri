{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.licensed-to /> — admin footer line "Licensed to <licensee> · <licence_id>" on a verified (or development)
  licence. Community renders nothing. Data comes from the Edition contract; output is escaped.
--}}
@php $__licensedTo = app(\Juwri\Core\Contracts\Editions\Edition::class)->licensedTo(); @endphp
@if ($__licensedTo)
    <span data-licensed-to>{{ ___('Licensed to :licensee', ['licensee' => $__licensedTo['licensee']]) }} · {{ $__licensedTo['licence_id'] }}</span>
@endif
