{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}
{{-- P6-138: hosting health card (platform admin only; results cached 5 minutes by App\Services\HostingHealthCard). --}}
<x-ui.card :title="___('Hosting health')" id="hosting-health">
    @if (count($health['warnings']))
        <ul class="ui-list-box">
            @foreach ($health['warnings'] as $warning)
                <li class="ui-list-box__item" data-hosting-warning="{{ $warning['code'] }}">
                    <span class="ui-dot" data-tone="warning"></span>
                    <div class="ui-grow">
                        <strong>{{ $warning['title'] }}</strong>
                        <p class="ui-muted">{{ ___('How to fix') }}: {{ $warning['fix'] }}
                            <a href="{{ $warning['url'] }}" target="_blank" rel="noopener">{{ ___('Hosting guide') }}</a></p>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="ui-muted">{{ ___('No hosting problems found.') }}</p>
    @endif
    @foreach ($health['skipped'] as $note)
        <p class="ui-muted">{{ $note }}</p>
    @endforeach
</x-ui.card>
