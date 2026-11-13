{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared listing-description renderer for the in-dashboard App/Theme Store
     detail pages. Parses the marketplace description format (hook paragraphs +
     "• Feature — benefit" bullet blocks) into prose paragraphs and a checkmark
     feature list, matching the public store pages. Expects: $text (string). --}}
@php $descBlocks = preg_split('/\n\s*\n/', trim((string) ($text ?? ''))); @endphp
@foreach($descBlocks as $descBlock)
    @if(trim($descBlock) === '')
        @continue
    @elseif(preg_match('/^\s*•/u', $descBlock))
        <ul class="ui-richdesc__list">
            @foreach(preg_split('/\n+/', trim($descBlock)) as $descLine)
                @php
                    $descLine = preg_replace('/^•\s*/u', '', trim($descLine));
                    $descParts = preg_split('/\s+—\s+/u', $descLine, 2);
                @endphp
                <li class="ui-richdesc__item">
                    <x-ui.icon name="check" size="16" class="ui-richdesc__tick" />
                    <span>@if(count($descParts) === 2)<strong>{{ $descParts[0] }}</strong> — {{ $descParts[1] }}@else{{ $descLine }}@endif</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="ui-richdesc__p">{{ trim($descBlock) }}</p>
    @endif
@endforeach
