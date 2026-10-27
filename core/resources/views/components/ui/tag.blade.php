{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- <x-ui.tag tone="danger">{{ ___('Sold out') }}</x-ui.tag> — small label. tone: success|warning|danger|neutral|accent. --}}
@props(['tone' => 'neutral'])
<span {{ $attributes->class('ui-tag') }} data-tone="{{ $tone }}">{{ $slot }}</span>
