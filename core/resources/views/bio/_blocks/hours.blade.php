{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- hours block: opening hours / open-now. bio-t15 A1 SPEC §2:
     config: {source:'manual'|'auto', tz?, week:{mon:[[start,end],...],...}, note?, show_status?}.
     `week`/`_status` are resolved server-side by BioLinkService::resolveHoursBlocks() (SSR,
     no client clock). Falls back to the legacy {rows:[{label,value,now?}], title?} shape
     (and branch.hours) for blocks saved before this run. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $title = trim((string) ($b['label'] ?? $cfg['title'] ?? '')) ?: ___('Hours');
    $week = is_array($cfg['week'] ?? null) ? $cfg['week'] : [];
    $hasWeek = false;
    foreach ($week as $__pairs) { if (! empty($__pairs)) { $hasWeek = true; break; } }
    $dayLabels = [
        'mon' => ___('Mon'), 'tue' => ___('Tue'), 'wed' => ___('Wed'), 'thu' => ___('Thu'),
        'fri' => ___('Fri'), 'sat' => ___('Sat'), 'sun' => ___('Sun'),
    ];
    $today = (string) ($cfg['_status']['today'] ?? '');
    $isOpen = $cfg['_status']['open'] ?? null;
    $showStatus = array_key_exists('show_status', $cfg) ? (bool) $cfg['show_status'] : true;
    $note = trim((string) ($cfg['note'] ?? ''));
@endphp
@if ($hasWeek)
    <div class="bio-card bio-hours-card">
        <p class="bio-kicker">{{ $title }}</p>
        @if ($showStatus && $isOpen !== null)
            <span class="bio-status bio-status--hours{{ $isOpen ? '' : ' is-closed' }}"><i></i>{{ $isOpen ? ___('Open Now') : ___('Closed now') }}</span>
        @endif
        <div class="bio-hours">
            @foreach (\Juwri\Modules\Biolink\Services\BioLinkService::HOURS_DAYS as $day)
                @php
                    $pairs = is_array($week[$day] ?? null) ? $week[$day] : [];
                    $value = empty($pairs) ? ___('Closed') : \Juwri\Core\Support\ViewHelpers::hoursPairs($pairs);
                @endphp
                <div class="r {{ $day === $today ? 'now' : '' }}"><span>{{ $dayLabels[$day] }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        @if ($note !== '')<p class="bio-card__text">{{ $note }}</p>@endif
    </div>
@else
    @php
        $rows = is_array($cfg['rows'] ?? null) ? $cfg['rows'] : [];
        if (empty($rows)) {
            $bh = data_get($branch ?? null, 'hours');
            if (is_string($bh)) { $bh = json_decode($bh, true); }
            if (is_array($bh)) {
                foreach ($bh as $day => $val) {
                    if (is_array($val)) {
                        $val = trim((string) ($val['open'] ?? '').' – '.(string) ($val['close'] ?? ''), ' –');
                    }
                    $rows[] = ['label' => is_string($day) ? $day : '', 'value' => (string) $val];
                }
            }
        }
    @endphp
    @if (! empty($rows))
        <div class="bio-card bio-hours-card">
            <p class="bio-kicker">{{ $title }}</p>
            <div class="bio-hours">
                @foreach ($rows as $r)
                    @php $rl = trim((string) ($r['label'] ?? '')); $rv = trim((string) ($r['value'] ?? '')); @endphp
                    @if ($rl !== '' || $rv !== '')
                        <div class="r {{ ! empty($r['now']) ? 'now' : '' }}"><span>{{ $rl }}</span><span>{{ $rv }}</span></div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
@endif
