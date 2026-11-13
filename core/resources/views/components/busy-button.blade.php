{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-busy-button> — shared dashboard busy button (owner-ui kit).

  Disables itself + shows a spinner + swaps its label on click, and guards
  against double-submit. Drop-in replacement for a normal <button>.

  ATTRIBUTES
    type       submit|button|reset            (default: submit)
    variant    primary|secondary|danger|ghost|light|success|warning
                                               (default: primary)
    size       sm|md|lg                        (default: md)
    busy-label text shown while busy           (default: "Please wait…")
    icon       optional kit/Tabler icon name shown when idle
    class      extra classes are merged
  SLOT         the idle button label.

  BEHAVIOUR
    - First click: sets busy (disabled + spinner + label swap). For a submit
      button inside a <form> the form submits and the button stays busy through
      navigation. Any second click while busy is swallowed (double-submit guard).
    - For fetch / AJAX flows (button that does NOT navigate): reset it when your
      request settles by dispatching a `busy-reset` event on the element, e.g.
          this.$refs.saveBtn.dispatchEvent(new CustomEvent('busy-reset'))
      or from Alpine child scope: $dispatch('busy-reset').
    - Emits no toast itself; pair with window.dashToast() in your handler.

  EXAMPLE
    <x-busy-button variant="primary" :busy-label="___('Saving…')">
        {{ ___('Save changes') }}
    </x-busy-button>

    <x-busy-button type="button" x-ref="saveBtn" @click="save()">
        {{ ___('Publish') }}
    </x-busy-button>
--}}
@props([
    'type' => 'submit',
    'variant' => 'primary',
    'size' => 'md',
    'busyLabel' => null,
    'icon' => null,
])
@php
    // Owner-ui kit button (the Bootstrap branch left with the old-theme/NexLink themes, WI-16h).
    $kitMap = [
        'primary' => null,
        'secondary' => 'ui-btn--secondary',
        'danger' => 'ui-btn--danger',
        'ghost' => 'ui-btn--ghost',
        'light' => 'ui-btn--ghost',
        'success' => 'ui-btn--success',
        'warning' => 'ui-btn--secondary',
    ];
    $classes = collect([
        'ui-btn',
        $kitMap[$variant] ?? null,
        $size === 'sm' ? 'ui-btn--sm' : ($size === 'lg' ? 'ui-btn--lg' : null),
    ])->filter()->implode(' ');
    $busyText = $busyLabel ?: ___('Please wait…');
@endphp
<button
    {{ $attributes->merge(['class' => $classes]) }}
    type="{{ $type }}"
    x-data="{ busy: false }"
    x-bind:aria-busy="busy ? 'true' : 'false'"
    {{-- Guard double-submit synchronously via `busy`; defer the disabled
         attribute to a macrotask so we never cancel this click's own form
         submit (disabling a submit button inside its click handler aborts it). --}}
    x-on:click="if (busy) { $event.preventDefault(); $event.stopImmediatePropagation(); return; } busy = true; setTimeout(() => { if (busy) $el.setAttribute('disabled', ''); }, 0);"
    x-on:busy-reset="busy = false; $el.removeAttribute('disabled');"
>
    <span class="ui-btn__spinner" role="status" aria-hidden="true" x-cloak x-show="busy"></span>
    @if($icon)
        <span x-show="!busy"><x-ui.shell.glyph :name="$icon === 'save' ? 'device-floppy' : $icon" :kit="$icon === 'save' ? 'check' : 'dots'" size="16" /></span>
    @endif
    <span x-show="!busy">{{ $slot }}</span>
    <span x-cloak x-show="busy">{{ $busyText }}</span>
</button>
