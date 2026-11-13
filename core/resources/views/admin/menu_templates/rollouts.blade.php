{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('A/B Rollouts'))
@section('page_sub', ___('Apply a template to a random slice (the variant) of a store segment, leave the rest as control, and compare orders per store between the two arms. Variant stores change theme immediately — every rollout is revertable from Recent migrations.'))

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="store" :href="route('admin.menu-templates.stores')">{{ ___('Store Themes') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="filter" :href="route('admin.menu-templates.segments')">{{ ___('Assign by Segment') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.menu-templates.index')">{{ ___('Back to templates') }}</x-ui.button>
@endsection

@section('content')
@php
    // Overview counts from the same data the cards below render — no controller change.
    $rTotal = $rollouts->count();
    $rRunning = $rollouts->filter->isRunning()->count();
    $rStores = 0;
    $rBestLift = null;
    foreach ($rollouts as $ro) {
        $rr = $results[$ro->id];
        $rStores += $rr['control']['stores'] + $rr['variant']['stores'];
        if ($rr['lift'] !== null) {
            $rBestLift = $rBestLift === null ? $rr['lift'] : max($rBestLift, $rr['lift']);
        }
    }
@endphp
<div class="ui-stack">
    {{-- Overview strip --}}
    <section class="ui-kpis" aria-label="{{ ___('A/B Rollouts') }}">
        <x-ui.stat :value="$rTotal" :label="___('Rollouts')" icon="chart" />
        <x-ui.stat :value="$rRunning" :label="___('Running')" icon="refresh" tone="success" />
        <x-ui.stat :value="$rStores" :label="___('Stores in tests')" icon="store" />
        <x-ui.stat :value="$rBestLift === null ? '—' : ($rBestLift > 0 ? '+'.$rBestLift : $rBestLift).'%'" :label="___('Best lift')" icon="trend-up"
                   :tone="($rBestLift ?? 0) > 0 ? 'success' : null" />
    </section>

    {{-- Start a rollout --}}
    <x-ui.card :title="___('Start an A/B rollout')">
        <form action="{{ route('admin.menu-templates.rollouts.start') }}" method="post" class="ui-stack"
              data-ui-confirm="{{ ___('Start this rollout? Variant stores change theme now — this is undoable from Recent migrations.') }}" data-ui-danger>
            @csrf
            <p class="ui-small ui-text-2">{{ ___('Pick a store segment, choose a variant template and the % of the segment that gets it. The rest stay as control.') }}</p>
            <div class="ui-form-grid">
                <x-ui.field name="name" id="ro-name" :label="___('Name (optional)')" :placeholder="___('e.g. Aurora vs current')" />
                <x-ui.field type="select" name="plan" id="ro-plan" :label="___('Plan')"
                            :options="['' => ___('Any plan')] + \Juwri\Core\Support\ViewHelpers::propOptions($plans, 'id', 'name', true)" />
                <x-ui.field type="date" name="date_from" id="ro-date-from" :label="___('Created from')" />
                <x-ui.field type="date" name="date_to" id="ro-date-to" :label="___('Created to')" />
                <x-ui.field type="select" name="verified" id="ro-verified" :label="___('Verification')"
                            :options="['' => ___('Any'), '1' => ___('Verified'), '0' => ___('Unverified')]" />
                <x-ui.field type="select" name="current_template" id="ro-current" :label="___('Currently on theme')"
                            :options="['' => ___('Any theme')] + \Juwri\Core\Support\ViewHelpers::templateSlugOptions($all, true)" />
                <x-ui.field type="select" name="to" id="ro-to" :label="___('Variant theme')"
                            :options="\Juwri\Core\Support\ViewHelpers::templateSlugOptions($available, false)" />
                <x-ui.field type="number" name="percentage" id="ro-percentage" :label="___('Variant split %')" value="50" min="1" max="99" prefix="%" />
            </div>
            <div class="ui-cluster">
                <x-ui.button type="submit" variant="danger" icon="sparkle">{{ ___('Start rollout') }}</x-ui.button>
                <small class="ui-text-2">{{ ___('Variant stores re-theme now; undo anytime from Recent migrations.') }}</small>
            </div>
        </form>
    </x-ui.card>

    {{-- Existing rollouts + live results --}}
    @forelse($rollouts as $rollout)
        @php
            $res = $results[$rollout->id];
            $armTotal = $res['control']['stores'] + $res['variant']['stores'];
            $vShare = $armTotal ? round($res['variant']['stores'] / $armTotal * 100) : 0;
            $cShare = 100 - $vShare;
            $variantImg = $all[$rollout->target_template]['image'] ?? null;
            $variantName = $all[$rollout->target_template]['name'] ?? $rollout->target_template;
        @endphp
        <x-ui.card :variant="$rollout->isRunning() ? 'accent' : null">
            <div class="ui-stack">
                <div class="ui-cluster ui-cluster--between ui-cluster--top">
                    <div>
                        <h2 class="ui-section-title">
                            {{ $rollout->name ?: (___('Rollout').' #'.$rollout->id) }}
                            <x-ui.tag :tone="$rollout->isRunning() ? 'success' : 'neutral'">{{ ___($rollout->status) }}</x-ui.tag>
                        </h2>
                        <small class="ui-text-2">
                            {{ ___('Variant') }}: <code>{{ $rollout->target_template }}</code> · {{ $rollout->percentage }}% ·
                            {{ ___('started') }} {{ $rollout->started_at?->diffForHumans() }} · {{ $rollout->actor->email ?? '—' }}
                        </small>
                    </div>
                    @if($rollout->isRunning())
                        <form action="{{ route('admin.menu-templates.rollouts.stop', $rollout->id) }}" method="post"
                              data-ui-confirm="{{ ___('Stop this rollout? Variant stores keep their theme.') }}" data-ui-danger>
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="pause" class="ui-text-danger">{{ ___('Stop') }}</x-ui.button>
                        </form>
                    @endif
                </div>

                {{-- Split proportion bar --}}
                <div>
                    <div class="ui-cluster ui-cluster--between ui-small">
                        <span class="ui-text-accent ui-strong">{{ ___('Variant') }} {{ $res['variant']['stores'] }} ({{ $vShare }}%)</span>
                        <span class="ui-text-2">{{ ___('Control') }} {{ $res['control']['stores'] }} ({{ $cShare }}%)</span>
                    </div>
                    <div class="ui-meter" role="img" aria-label="{{ $vShare }}% {{ ___('variant') }}, {{ $cShare }}% {{ ___('control') }}">
                        <div class="ui-meter__bar" style="inline-size: {{ $vShare }}%"></div>
                    </div>
                </div>

                {{-- Arm comparison --}}
                <div class="ui-columns" style="--ui-col-min: 240px">
                    <x-ui.card variant="inset" as="div">
                        <div class="ui-cluster ui-cluster--between">
                            <span class="ui-overline">{{ ___('Control') }}</span>
                            <x-ui.tag>{{ ___('unchanged') }}</x-ui.tag>
                        </div>
                        <div class="ui-fs-xl ui-strong">{{ $res['control']['per_store'] }}</div>
                        <div class="ui-small ui-text-2">{{ ___('orders / store') }}</div>
                        <div class="ui-cluster ui-cluster--between ui-small ui-text-2 ui-rule-top">
                            <span>{{ $res['control']['stores'] }} {{ ___('stores') }}</span>
                            <span>{{ $res['control']['orders'] }} {{ ___('orders') }}</span>
                        </div>
                    </x-ui.card>
                    <x-ui.card variant="inset" as="div">
                        <div class="ui-cluster">
                            @if($variantImg)
                                <img src="{{ $variantImg }}" width="40" height="40" style="object-fit: cover; border-radius: 9px" alt="{{ $variantName }}" loading="lazy">
                            @endif
                            <span class="ui-overline ui-text-accent">{{ ___('Variant') }} · <code>{{ $rollout->target_template }}</code></span>
                        </div>
                        <div class="ui-fs-xl ui-strong ui-text-accent">{{ $res['variant']['per_store'] }}</div>
                        <div class="ui-small ui-text-2">{{ ___('orders / store') }}</div>
                        <div class="ui-cluster ui-cluster--between ui-small ui-text-2 ui-rule-top">
                            <span>{{ $res['variant']['stores'] }} {{ ___('stores') }}</span>
                            <span>{{ $res['variant']['orders'] }} {{ ___('orders') }}</span>
                        </div>
                    </x-ui.card>
                </div>

                {{-- Lift summary --}}
                <div class="ui-cluster">
                    <span class="ui-text-2">{{ ___('Variant lift (orders / store vs control)') }}:</span>
                    @if($res['lift'] === null)
                        <x-ui.tag>—</x-ui.tag>
                        <small class="ui-text-2">{{ ___('needs control orders to compare') }}</small>
                    @else
                        <x-ui.tag :tone="$res['lift'] > 0 ? 'success' : ($res['lift'] < 0 ? 'danger' : 'neutral')">{{ $res['lift'] > 0 ? '+' : '' }}{{ $res['lift'] }}%</x-ui.tag>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.empty icon="chart" :title="___('No rollouts yet')" :body="___('Start one above to compare a new template against the current one on real stores.')" />
        </x-ui.card>
    @endforelse
</div>
@endsection
