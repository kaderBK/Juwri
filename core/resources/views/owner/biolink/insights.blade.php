{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Bio insights'))
@section('page_title', ___('Bio insights'))
@section('page_sub', ___('Views, clicks and where your bio-page traffic comes from.'))

@section('page_actions')
    <div class="ui-cluster">
        <x-ui.button :href="route('biolink.index')" variant="secondary" icon="settings">{{ ___('Settings') }}</x-ui.button>
        <x-ui.button :href="route('biolink.builder')" variant="secondary" icon="more">{{ ___('Builder') }}</x-ui.button>
        {{-- D4: CSV export is bio_analytics_pro (server-gated in BioInsightsController; this
             hides the control for a non-entitled store, but the export route 403s on its own
             regardless — the UI hide is cosmetic, never the only gate). --}}
        @if(! empty($extra['analytics_pro']))
            <x-ui.button :href="route('biolink.insights.export', ['range' => $range])" variant="secondary" icon="download" data-busy-download data-busy-label="{{ ___('Preparing…') }}">{{ ___('Export CSV') }}</x-ui.button>
        @endif
    </div>
@endsection

@section('content')

@php
    $rangeLabels = ['24h' => ___('24h'), '7d' => ___('7 days'), '30d' => ___('30 days'), '90d' => ___('90 days')];

    // Normalise the sources/devices maps to [label => count], tolerant of either
    // an associative map or a list of {source/device, count} rows.
    $sources = \Juwri\Core\Support\Views\PublicSiteViewHelpers::normaliseInsightsRows($stats['sources'] ?? []);
    $devices = \Juwri\Core\Support\Views\PublicSiteViewHelpers::normaliseInsightsRows($stats['devices'] ?? []);
    $sourceTotal = array_sum($sources);
    $deviceTotal = array_sum($devices);

    $sourceLabels = ['qr' => ___('QR scan'), 'social' => ___('Social'), 'direct' => ___('Direct'), 'share' => ___('Share'), 'other' => ___('Other')];
    $deviceLabels = ['mobile' => ___('Mobile'), 'desktop' => ___('Desktop'), 'tablet' => ___('Tablet')];
    $deviceColors = ['mobile' => 'var(--bs-primary)', 'desktop' => 'var(--bs-info)', 'tablet' => 'var(--bs-warning)'];

    $ctr = (float) ($stats['ctr'] ?? 0);
    $ctrPct = $ctr <= 1 ? $ctr * 100 : $ctr; // accept ratio (0..1) or already-percent
@endphp

{{-- Range chips (anchors, not buttons — the Spotlight pattern) --}}
<div class="ui-cluster" role="group" aria-label="{{ ___('Date range') }}">
    @foreach($ranges as $r)
        <a href="{{ route('biolink.insights', ['range' => $r]) }}"
           class="ui-btn ui-btn--sm {{ $range === $r ? '' : 'ui-btn--secondary' }}">
            {{ $rangeLabels[$r] ?? $r }}
        </a>
    @endforeach
</div>

{{-- KPI strip --}}
<div class="ui-form-grid">
    <div>
        <x-ui.stat :label="___('Views')" :value="number_format((int) ($stats['views'] ?? 0))" />
    </div>
    <div>
        <x-ui.stat :label="___('Clicks')" :value="number_format((int) ($stats['clicks'] ?? 0))" />
    </div>
    <div>
        <x-ui.stat :label="___('Click-through rate')" :value="number_format($ctrPct, 1).'%'" />
    </div>
    <div>
        <x-ui.stat :label="___('QR scans')" :value="number_format((int) ($stats['qr_scans'] ?? 0))" />
    </div>
</div>

<div class="ui-form-grid">
    {{-- Per-block leaderboard --}}
    <div>
        <x-ui.card :title="___('Top blocks')" class="ui-fill">
            @php $perBlock = (array) ($stats['per_block'] ?? []); @endphp
            @if(empty($perBlock))
                <x-ui.empty :title="___('No clicks yet')" :body="___('Block clicks will appear here once guests start tapping.')" />
            @else
                @php $max = 0; foreach ($perBlock as $b) { $max = max($max, (int) ($b['clicks'] ?? 0)); } $max = max($max, 1); @endphp
                <div class="ui-stack">
                    @foreach($perBlock as $b)
                        @php
                            $label = $b['label'] ?? (! empty($b['type']) ? \Illuminate\Support\Str::headline($b['type']) : ___('Block'));
                            $c = (int) ($b['clicks'] ?? 0);
                            $pct = round($c / $max * 100);
                        @endphp
                        <div>
                            <div class="ui-cluster ui-cluster--between ui-small">
                                <span class="ui-truncate">{{ $label }}</span>
                                <strong>{{ number_format($c) }}</strong>
                            </div>
                            <div class="ui-meter" role="progressbar" aria-valuenow="{{ $c }}" aria-valuemin="0" aria-valuemax="{{ $max }}">
                                <div class="ui-meter__bar" style="width:{{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Sources --}}
    <div>
        <x-ui.card :title="___('Sources')" class="ui-fill">
            @if($sourceTotal === 0)
                <x-ui.empty :title="___('No traffic yet')" />
            @else
                <div class="ui-stack">
                    @foreach($sources as $key => $count)
                        @php $pct = round($count / max($sourceTotal, 1) * 100); @endphp
                        <div>
                            <div class="ui-cluster ui-cluster--between ui-small">
                                <span>{{ $sourceLabels[$key] ?? ucfirst($key) }}</span>
                                <span class="ui-text-2">{{ $pct }}%</span>
                            </div>
                            <div class="ui-meter" role="progressbar" aria-valuenow="{{ $count }}" aria-valuemin="0" aria-valuemax="{{ $sourceTotal }}">
                                <div class="ui-meter__bar" style="width:{{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Devices donut (pure SVG) --}}
    <div>
        <x-ui.card :title="___('Devices')" class="ui-fill">
            @if($deviceTotal === 0)
                <x-ui.empty :title="___('No devices yet')" />
            @else
                @php $circ = 2 * M_PI * 42; $offset = 0; @endphp
                <div class="ui-center">
                    <svg viewBox="0 0 120 120" width="140" height="140" role="img" aria-label="{{ ___('Devices') }}">
                        <circle cx="60" cy="60" r="42" fill="none" stroke="var(--bs-tertiary-bg)" stroke-width="16"></circle>
                        @foreach($devices as $key => $count)
                            @php
                                $frac = $count / max($deviceTotal, 1);
                                $len = $circ * $frac;
                                $dash = $len.' '.($circ - $len);
                            @endphp
                            <circle cx="60" cy="60" r="42" fill="none"
                                    stroke="{{ $deviceColors[$key] ?? 'var(--bs-secondary)' }}" stroke-width="16"
                                    stroke-dasharray="{{ $dash }}" stroke-dashoffset="{{ -$offset }}"
                                    transform="rotate(-90 60 60)"></circle>
                            @php $offset += $len; @endphp
                        @endforeach
                        <text x="60" y="64" text-anchor="middle" font-size="16" fill="var(--bs-body-color)">{{ number_format($deviceTotal) }}</text>
                    </svg>
                    <div class="ui-stack">
                        @foreach($devices as $key => $count)
                            <div class="ui-cluster ui-cluster--between ui-small">
                                <span class="ui-cluster ui-inline">
                                    <span class="ui-inline" style="width:10px;height:10px;background:{{ $deviceColors[$key] ?? 'var(--bs-secondary)' }};"></span>
                                    {{ $deviceLabels[$key] ?? ucfirst($key) }}
                                </span>
                                <strong>{{ number_format($count) }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>

{{-- bio-t15 Lane D — D5: country + referrer breakdown cards. Data comes from
     BioInsightsService::extra() (country/referrer are bio_analytics_pro/PRO —
     gated server-side in the service; the badge/upsell here is cosmetic). --}}
<div class="ui-form-grid">
    <div>
        <x-ui.card class="ui-fill">
<div class="ui-card__head">{{ ___('Countries') }}
                @if(empty($extra['analytics_pro']))
                    <span class="ui-tag" data-tone="warning">{{ ___('PRO') }}</span>
                @endif</div>
            @if(empty($extra['analytics_pro']))
                <x-ui.empty :title="___('Upgrade to see visitor countries')" :body="___('Country breakdown is part of the analytics add-on.')" />
            @elseif(empty($extra['geo_available']))
                <x-ui.empty :title="___('Country data not available')" :body="___('Ask your platform owner to enable a geo source for this install.')" />
            @else
                @php
                    $countries = (array) ($extra['countries'] ?? []);
                    $cTotal = array_sum(array_column($countries, 'count'));
                @endphp
                @if(empty($countries) || $cTotal === 0)
                    <x-ui.empty :title="___('No country data yet')" />
                @else
                    <div class="ui-stack">
                        @foreach($countries as $row)
                            @php
                                $code = (string) ($row['country'] ?? '--');
                                $label = $code === '--' ? ___('Unknown') : (function_exists('get_country_name') ? (get_country_name($code) ?: $code) : $code);
                                $count = (int) ($row['count'] ?? 0);
                                $pct = round($count / max($cTotal, 1) * 100);
                            @endphp
                            <div>
                                <div class="ui-cluster ui-cluster--between ui-small">
                                    <span>{{ $label }}</span>
                                    <span class="ui-text-2">{{ $pct }}%</span>
                                </div>
                                <div class="ui-meter" role="progressbar" aria-valuenow="{{ $count }}" aria-valuemin="0" aria-valuemax="{{ $cTotal }}">
                                <div class="ui-meter__bar" style="width:{{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-ui.card>
    </div>

    <div>
        <x-ui.card class="ui-fill">
<div class="ui-card__head">{{ ___('Top referrers') }}
                @if(empty($extra['analytics_pro']))
                    <span class="ui-tag" data-tone="warning">{{ ___('PRO') }}</span>
                @endif</div>
            @if(empty($extra['analytics_pro']))
                <x-ui.empty :title="___('Upgrade to see referrer sites')" :body="___('Referrer breakdown is part of the analytics add-on.')" />
            @else
                @php
                    $referrers = (array) ($extra['referrers'] ?? []);
                    $rTotal = array_sum(array_column($referrers, 'count'));
                @endphp
                @if(empty($referrers) || $rTotal === 0)
                    <x-ui.empty :title="___('No referrer data yet')" :body="___('Referrer sites will appear here once guests arrive from an external link.')" />
                @else
                    <div class="ui-stack">
                        @foreach($referrers as $row)
                            @php
                                $host = (string) ($row['ref'] ?? 'other');
                                $count = (int) ($row['count'] ?? 0);
                                $pct = round($count / max($rTotal, 1) * 100);
                            @endphp
                            <div>
                                <div class="ui-cluster ui-cluster--between ui-small">
                                    <span class="ui-truncate">{{ $host === 'other' ? ___('Other') : $host }}</span>
                                    <span class="ui-text-2">{{ $pct }}%</span>
                                </div>
                                <div class="ui-meter" role="progressbar" aria-valuenow="{{ $count }}" aria-valuemin="0" aria-valuemax="{{ $rTotal }}">
                                <div class="ui-meter__bar" style="width:{{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-ui.card>
    </div>
</div>

@endsection
