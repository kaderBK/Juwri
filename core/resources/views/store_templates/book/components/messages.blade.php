{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: what the last step answered (a refused step's reasons, a done step's notice) --}}
@foreach (($messages ?? []) as $__m)
<p class="c-banner {{ $__m['kind'] === 'error' ? 'c-banner--danger' : 'c-banner--success' }}" role="{{ $__m['kind'] === 'error' ? 'alert' : 'status' }}">{{ $kit->icon($__m['kind'] === 'error' ? 'alert-triangle' : 'check') }}<span>{{ $__m['text'] }}</span></p>
@endforeach
