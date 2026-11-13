{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Menu Templates'))
@section('page_sub', ___('Manage the storefront templates restaurants can use — enable or retire them, feature the best, set the display order, and choose the signup default and the fallback for broken stores.'))

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="store" :href="route('admin.menu-templates.stores')">{{ ___('Store Themes') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="filter" :href="route('admin.menu-templates.segments')">{{ ___('Assign by Segment') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="chart" :href="route('admin.menu-templates.rollouts')">{{ ___('A/B Rollouts') }}</x-ui.button>
@endsection

{{-- CC run: card grid + tile styles shared with the theme-customizer picker
     (lane A ships the file; selectors are tcc- prefixed so nothing leaks). --}}
@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/global/stories-entries/previews/tc-cards.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/global/stories-entries/previews/tc-cards.css'))) }}">
@endpush

@section('content')
@php
    // Overview counts — derived from the same data the grid already uses, so the
    // strip stays in sync with the cards without a controller change.
    $mtTotal = count($templates);
    $mtEnabled = 0;
    $mtFeatured = 0;
    foreach ($templates as $t) {
        if ($t['enabled']) { $mtEnabled++; }
        if ($t['featured']) { $mtFeatured++; }
    }
    $mtDisabled = $mtTotal - $mtEnabled;
    $mtStores = array_sum($usage);
    $mtCleanup = count($missing) + count($disabledInUse);
    $mtEnabledOptions = \Juwri\Core\Support\ViewHelpers::templateFolderOptions($templates, true);
@endphp
<div class="ui-stack">
    {{-- Overview strip --}}
    <section class="ui-kpis" aria-label="{{ ___('Menu Templates') }}">
        <x-ui.stat :value="$mtTotal" :label="___('Templates')" icon="layout-grid" />
        <x-ui.stat :value="$mtEnabled" :label="___('Enabled')" icon="check" tone="success" />
        <x-ui.stat :value="$mtDisabled" :label="___('Disabled')" icon="ban" />
        <x-ui.stat :value="$mtFeatured" :label="___('Featured')" icon="star" tone="warning" />
        <x-ui.stat :value="$mtStores" :label="___('Stores')" icon="store" />
        <x-ui.stat :value="$mtCleanup" :label="___('Needs cleanup')" icon="alert" :tone="$mtCleanup > 0 ? 'danger' : 'success'" />
    </section>

    {{-- Fallback (rescues broken existing stores) + Default (seeds new
         signups) sit side by side — sibling settings, different jobs. --}}
    <div class="ui-columns" style="--ui-col-min: 300px">
        <x-ui.card :title="___('Fallback Template')">
            <form action="{{ route('admin.menu-templates.fallback') }}" method="post" class="ui-stack">
                @csrf
                <p class="ui-small ui-text-2">{{ ___('Restaurants whose template was deleted or is no longer available will render this template instead.') }}</p>
                <x-ui.field type="select" name="fallback_store_theme" id="mt-fallback" :label="___('Fallback Template')" :value="$fallback" :options="$mtEnabledOptions" />
                <div><x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Default Template')">
            <form action="{{ route('admin.menu-templates.default') }}" method="post" class="ui-stack">
                @csrf
                <p class="ui-small ui-text-2">{{ ___('New restaurants are created on this template. (The fallback only rescues stores whose template was deleted.)') }}</p>
                <x-ui.field type="select" name="default_post_template" id="mt-default" :label="___('Default Template')" :value="$default" :options="$mtEnabledOptions" />
                <div><x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    {{-- SE run §6: platform-wide story entry default. A Folio/Page View store
         whose customizer select is left on "Inherit" (or unset) falls back to
         this; an unset option here falls back to the code default "ribbon". --}}
    <x-ui.card :title="___('Stories entry default')">
        <div class="ui-stack">
            <p class="ui-small ui-text-2">{{ ___('The story entry style Folio and Page View stores use when their customizer is left on “Inherit”.') }}</p>
            {{-- CC run: the same visual card picker the customizer's
                 "Stories entry style" uses — server-rendered radios,
                 11 tiles (no 'inherit': the platform default must
                 name a concrete style). Form/route/name/validation
                 are the SE-run originals, untouched. --}}
            <form action="{{ route('admin.menuTemplates.storiesEntryDefault') }}" method="post" class="ui-stack">
                @csrf
                <div class="tcc-grid" role="radiogroup" aria-label="{{ ___('Stories entry default') }}">
                    @foreach($storiesEntryStyles as $slug => $label)
                        <label class="tcc-card tcc-anim--{{ $slug }}">
                            <input type="radio" class="tcc-card__radio" name="stories_entry_default"
                                   value="{{ $slug }}" @checked(($storiesEntryDefault ?: 'ribbon') === $slug)>
                            <span class="tcc-thumbwrap">
                                <img class="tcc-thumb" src="{{ asset('assets/'.$storiesEntryPreviews[$slug]) }}" alt="" loading="lazy">
                            </span>
                            <span class="tcc-label">{{ ___($label) }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="ui-cluster">
                    <x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                    <small class="ui-text-2">
                        {{ ___('Current') }}: <code>{{ $storiesEntryDefault ?: 'ribbon' }}</code>@unless($storiesEntryDefault) <span class="ui-text-2">({{ ___('code default') }})</span>@endunless
                    </small>
                </div>
            </form>
        </div>
    </x-ui.card>

    {{-- Cleanup warnings, side by side so they don't push the grid down a screen. --}}
    @if(!empty($missing) || !empty($disabledInUse))
        <div class="ui-columns">
            @if(!empty($missing))
                <x-ui.card variant="flush" :title="___('Deleted templates still in use')">
                    <div class="ui-stack">
                        <x-ui.banner tone="danger">{{ ___('These templates were removed from disk. Their restaurants currently render the fallback template. Reassign them to finish the cleanup.') }}</x-ui.banner>
                        <div class="ui-table-wrap">
                            <table class="ui-table">
                                <thead>
                                    <tr>
                                        <th>{{ ___('Template') }}</th>
                                        <th>{{ ___('Stores') }}</th>
                                        <th class="ui-end">{{ ___('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($missing as $slug => $count)
                                        <tr>
                                            <td data-primary data-label="{{ ___('Template') }}"><code>{{ $slug }}</code></td>
                                            <td data-label="{{ ___('Stores') }}"><x-ui.tag tone="danger">{{ $count }}</x-ui.tag></td>
                                            <td class="ui-end" data-label="">
                                                <x-ui.button variant="secondary" size="sm" class="ui-text-danger" :href="route('admin.menu-templates.restaurants', $slug)">{{ ___('View & Reassign') }}</x-ui.button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </x-ui.card>
            @endif

            @if(!empty($disabledInUse))
                <x-ui.card variant="flush" :title="___('Disabled templates still in use')">
                    <div class="ui-stack">
                        <x-ui.banner tone="warning">{{ ___('These templates are hidden from the picker but restaurants already using them keep rendering them. Reassign them to complete the retirement.') }}</x-ui.banner>
                        <div class="ui-table-wrap">
                            <table class="ui-table">
                                <thead>
                                    <tr>
                                        <th>{{ ___('Template') }}</th>
                                        <th>{{ ___('Stores') }}</th>
                                        <th class="ui-end">{{ ___('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($disabledInUse as $slug => $row)
                                        <tr>
                                            <td data-primary data-label="{{ ___('Template') }}">{{ ___($row['name']) }} <code>{{ $slug }}</code></td>
                                            <td data-label="{{ ___('Stores') }}"><x-ui.tag tone="warning">{{ $row['count'] }}</x-ui.tag></td>
                                            <td class="ui-end" data-label="">
                                                <x-ui.button variant="secondary" size="sm" :href="route('admin.menu-templates.restaurants', $slug)">{{ ___('View & Reassign') }}</x-ui.button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </x-ui.card>
            @endif
        </div>
    @endif

    {{-- Grid control bar: client-side search / filter / sort (left, no form so
         Enter can't submit) + bulk actions (right, its own form). --}}
    <x-ui.card>
        <div class="ui-stack">
            <div class="ui-cluster">
                <input type="search" id="templateSearch" class="ui-input ui-input--sm ui-input--auto"
                       placeholder="{{ ___('Search templates') }}" aria-label="{{ ___('Search templates') }}">
                <select id="templateFilter" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Filter templates') }}">
                    <option value="all">{{ ___('All') }}</option>
                    <option value="enabled">{{ ___('Enabled') }}</option>
                    <option value="disabled">{{ ___('Disabled') }}</option>
                    <option value="featured">{{ ___('Featured') }}</option>
                    <option value="unused">{{ ___('Unused') }}</option>
                    <option value="modern">{{ ___('Modern') }}</option>
                    <option value="legacy">{{ ___('Legacy') }}</option>
                </select>
                <select id="templateSort" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Sort templates') }}">
                    <option value="order">{{ ___('Display order') }}</option>
                    <option value="name">{{ ___('Name') }}</option>
                    <option value="usage">{{ ___('Most used') }}</option>
                </select>
                <span id="templateShownCount" class="ui-small ui-text-2" aria-live="polite"
                      data-label="{{ ___(':n of :m shown') }}"></span>
            </div>

            <div class="ui-cluster ui-cluster--between">
                {{-- Bulk actions form. The grid checkboxes reference it by id
                     (form="bulkTemplatesForm"), so it can live here on its own.
                     The page script refuses an empty selection before the kit's
                     data-ui-confirm question. --}}
                <form id="bulkTemplatesForm" action="{{ route('admin.menu-templates.bulk') }}" method="post"
                      class="ui-cluster" data-ui-confirm="{{ ___('Apply this action to the selected templates?') }}">
                    @csrf
                    <label class="ui-small ui-text-2" for="mt-bulk-action">{{ ___('Bulk') }}:</label>
                    <select name="bulk_action" id="mt-bulk-action" class="ui-input ui-input--sm ui-input--auto">
                        <option value="enable">{{ ___('Enable') }}</option>
                        <option value="disable">{{ ___('Disable') }}</option>
                        <option value="feature">{{ ___('Mark Featured') }}</option>
                        <option value="unfeature">{{ ___('Remove Featured') }}</option>
                    </select>
                    <x-ui.button type="submit" size="sm">{{ ___('Apply') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" data-mt-check="1">{{ ___('Select all') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" data-mt-check="0">{{ ___('Clear') }}</x-ui.button>
                </form>

                {{-- Cross-template migration: moves every store on the checked templates
                     onto one target. Shares the grid's slugs[] checkboxes — the pushed
                     script copies whichever are checked into this form on submit. --}}
                <form id="bulkReassignForm" action="{{ route('admin.menu-templates.bulk-reassign') }}" method="post"
                      class="ui-cluster" data-ui-confirm="{{ ___('Migrate stores') }}?" data-ui-danger>
                    @csrf
                    <label class="ui-small ui-text-2" for="mt-bulk-to">{{ ___('Migrate selected to') }}:</label>
                    <select name="to" id="mt-bulk-to" class="ui-input ui-input--sm ui-input--auto">
                        @foreach($templates as $tpl)
                            @if($tpl['enabled'])
                                <option value="{{ $tpl['folder'] }}">{{ ___($tpl['name']) }}</option>
                            @endif
                        @endforeach
                    </select>
                    <x-ui.button type="submit" variant="secondary" size="sm" class="ui-text-danger">{{ ___('Migrate stores') }}</x-ui.button>
                </form>
            </div>
        </div>
    </x-ui.card>

    {{-- Template cards --}}
    <div class="ui-grid" style="--ui-grid-min: 240px" id="templateGrid">
        @foreach($templates as $tpl)
            @php
                $isModern = \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::isModern($tpl['folder']);
                $tplUsage = $usage[$tpl['folder']] ?? 0;
                $isFallback = $fallback == $tpl['folder'];
                // I4: disabling a template is destructive-ish (hides it from the picker),
                // so the disable submit asks first, naming the usage count; extra-warn if
                // it's the fallback. Enabling is always safe → never prompts.
                $toggleConfirm = null;
                if ($tpl['enabled']) {
                    $toggleConfirm = ___('Disable').' "'.$tpl['name'].'"? '.$tplUsage.' '.___('restaurants currently use it.');
                    if ($isFallback) {
                        $toggleConfirm .= ' '.___('This is the fallback template — the picker will hide it but broken stores still render it. Continue?');
                    }
                }
            @endphp
            <article class="ui-card ui-card--flush" @if(! $tpl['enabled']) style="opacity: .55" @endif
                 data-name="{{ Str::lower($tpl['name']) }}"
                 data-slug="{{ $tpl['folder'] }}"
                 data-enabled="{{ $tpl['enabled'] ? 1 : 0 }}"
                 data-featured="{{ $tpl['featured'] ? 1 : 0 }}"
                 data-modern="{{ $isModern ? 1 : 0 }}"
                 data-usage="{{ $tplUsage }}"
                 data-order="{{ $loop->index }}"
                 data-category="{{ $tpl['category'] }}">
                {{-- Thumbnail with the reorder nudge overlaid top-left and the
                     select checkbox top-right, so the footer isn't overloaded. --}}
                <div class="ui-relative">
                    <img src="{{ $tpl['image'] }}" class="ui-img-fluid" style="inline-size: 100%; aspect-ratio: 16 / 10; object-fit: cover; object-position: top center" loading="lazy" alt="{{ $tpl['name'] }}">
                    <div class="ui-absolute ui-top-0 ui-start-0 ui-cluster" style="--ui-gap: 4px; margin: 8px">
                        <form action="{{ route('admin.menu-templates.sort', $tpl['folder']) }}" method="post">
                            @csrf
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="ui-icon-btn ui-icon-btn--filled" title="{{ ___('Move up') }}" aria-label="{{ ___('Move up') }}"><x-ui.icon name="chevron-down" size="18" style="transform: rotate(180deg)" /></button>
                        </form>
                        <form action="{{ route('admin.menu-templates.sort', $tpl['folder']) }}" method="post">
                            @csrf
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="ui-icon-btn ui-icon-btn--filled" title="{{ ___('Move down') }}" aria-label="{{ ___('Move down') }}"><x-ui.icon name="chevron-down" size="18" /></button>
                        </form>
                    </div>
                    <input type="checkbox" class="ui-absolute ui-top-0 ui-end-0 bulk-template-check" style="margin: 12px; inline-size: 20px; block-size: 20px"
                           name="slugs[]" value="{{ $tpl['folder'] }}" form="bulkTemplatesForm"
                           aria-label="{{ ___('Select') }} {{ ___($tpl['name']) }}">
                </div>
                <div class="ui-stack" style="--ui-gap: 10px; padding: 16px">
                    <div>
                        <h3 class="ui-section-title">{{ ___($tpl['name']) }}</h3>
                        <small class="ui-text-2">{{ $tpl['folder'] }}</small>
                    </div>
                    <div class="ui-cell-tags">
                        @if($tpl['enabled'])
                            <x-ui.tag tone="success">{{ ___('Enabled') }}</x-ui.tag>
                        @else
                            <x-ui.tag tone="danger">{{ ___('Disabled') }}</x-ui.tag>
                        @endif
                        @if($tpl['featured'])
                            <x-ui.tag tone="warning">{{ ___('Featured') }}</x-ui.tag>
                        @endif
                        @if($isFallback)
                            <x-ui.tag tone="accent">{{ ___('Fallback') }}</x-ui.tag>
                        @endif
                        @if($default == $tpl['folder'])
                            <x-ui.tag tone="accent" title="{{ ___('New restaurants are created on this template.') }}">{{ ___('Default') }}</x-ui.tag>
                        @endif
                        @if($isModern)
                            {{-- M13: a "Modern" template loads the shared storefront sections
                                 (order type, offers, reviews, scheduling) and skips the legacy
                                 jQuery bundle. If a template you added lacks this badge, add it to
                                 Juwri\Modules\Storefront\Services\Theme\KeptTemplates::MODERN. --}}
                            <x-ui.tag title="{{ ___('Loads the shared storefront sections (order type, offers, reviews, scheduling) and skips the legacy jQuery bundle.') }}">{{ ___('Modern') }}</x-ui.tag>
                        @endif
                    </div>
                    <p class="ui-small ui-text-2">{{ Str::limit($tpl['description'], 90) }}</p>
                    <div class="ui-cluster">
                        {{-- Zero-usage reads as a neutral tag (not the "active" accent) so the
                             admin can tell at a glance which templates are safe to disable. --}}
                        <a href="{{ route('admin.menu-templates.restaurants', $tpl['folder']) }}">
                            <x-ui.tag :tone="$tplUsage > 0 ? 'accent' : 'neutral'">{{ $tplUsage }} {{ ___('stores') }}</x-ui.tag>
                        </a>
                        @if($tplUsage === 0)
                            <small class="ui-text-2">{{ ___('unused — safe to disable') }}</small>
                        @endif
                    </div>
                    {{-- Primary actions on top, secondary (set-fallback / preview) beneath. --}}
                    <div class="ui-cluster">
                        <x-ui.button size="sm" icon="pencil" class="ui-grow" :href="route('admin.menu-templates.edit', $tpl['folder'])">{{ ___('Edit') }}</x-ui.button>
                        <form action="{{ route('admin.menu-templates.toggle', $tpl['folder']) }}" method="post"
                              @if($toggleConfirm) data-ui-confirm="{{ $toggleConfirm }}" data-ui-danger @endif>
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" :class="$tpl['enabled'] ? 'ui-text-danger' : 'ui-text-success'">
                                {{ $tpl['enabled'] ? ___('Disable') : ___('Enable') }}
                            </x-ui.button>
                        </form>
                    </div>
                    <div class="ui-cluster" style="--ui-gap: 6px">
                        {{-- One-click "make this the fallback". Only meaningful for enabled
                             templates that aren't already the fallback — reuses the existing
                             fallback route, no controller change. --}}
                        @if($tpl['enabled'] && ! $isFallback)
                            <form action="{{ route('admin.menu-templates.fallback') }}" method="post">
                                @csrf
                                <input type="hidden" name="fallback_store_theme" value="{{ $tpl['folder'] }}">
                                <x-ui.button type="submit" variant="ghost" size="sm" icon="shield" title="{{ ___('Make this the fallback template') }}">{{ ___('Set fallback') }}</x-ui.button>
                            </form>
                        @endif
                        {{-- Live preview on the newest store via ?preview_template (Lane B
                             wires the override in publicView). Only shown when a store exists. --}}
                        @if($previewStore)
                            <x-ui.button variant="ghost" size="sm" icon="eye" target="_blank" rel="noopener"
                                         :href="route('publicView', $previewStore).'?preview_template='.$tpl['folder']"
                                         title="{{ ___('Preview this template on a live store') }}">{{ ___('Preview') }}</x-ui.button>
                        @endif
                    </div>
                    {{-- Quick-migrate: move ALL of this template's stores onto another
                         one without opening the restaurants page. Only shown when it
                         actually has stores. Reuses the reassign route (no post_ids
                         = move them all). --}}
                    @if($tplUsage > 0)
                        <form action="{{ route('admin.menu-templates.reassign') }}" method="post"
                              class="ui-cluster js-card-migrate" style="--ui-gap: 6px" data-ui-confirm="{{ ___('Migrate') }}?" data-ui-danger>
                            @csrf
                            <input type="hidden" name="from" value="{{ $tpl['folder'] }}">
                            <select name="to" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Move all this template’s stores to the chosen one') }}"
                                    data-count="{{ $tplUsage }}" data-from="{{ $tpl['name'] }}">
                                @foreach($templates as $opt)
                                    @if($opt['enabled'] && $opt['folder'] !== $tpl['folder'])
                                        <option value="{{ $opt['folder'] }}">{{ ___($opt['name']) }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh" class="ui-text-danger" title="{{ ___('Move all this template’s stores to the chosen one') }}">
                                {{ ___('Migrate') }} {{ $tplUsage }}
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    {{-- Recent migrations — the revertable audit trail. Each store→theme move is
         one row; undo restores each store to its recorded pre-move theme (only if
         it is still on the migrated-to theme, so later re-themes aren't clobbered). --}}
    @if($migrations->isNotEmpty())
        <x-ui.card variant="flush" :title="___('Recent migrations')">
            <div class="ui-stack">
                <p class="ui-small ui-text-2" style="padding-inline: 20px">{{ ___('Every store→theme move can be reverted. Undo restores only stores still on the template they were moved to.') }}</p>
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('When') }}</th>
                                <th>{{ ___('Admin') }}</th>
                                <th>{{ ___('Move') }}</th>
                                <th>{{ ___('Stores') }}</th>
                                <th class="ui-end">{{ ___('Undo') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($migrations as $mig)
                                <tr @if($mig->undone_at) class="ui-text-2" @endif>
                                    <td data-primary data-label="{{ ___('When') }}">{{ $mig->created_at?->diffForHumans() }}</td>
                                    <td data-label="{{ ___('Admin') }}" class="ui-text-2">{{ $mig->actor->email ?? '—' }}</td>
                                    <td data-label="{{ ___('Move') }}">
                                        <x-ui.tag>{{ $mig->kind }}</x-ui.tag>
                                        &rarr; <code>{{ $mig->to_template }}</code>
                                    </td>
                                    <td data-label="{{ ___('Stores') }}"><x-ui.tag tone="accent">{{ $mig->count }}</x-ui.tag></td>
                                    <td class="ui-end" data-label="">
                                        @if($mig->undone_at)
                                            <x-ui.tag>{{ ___('Reverted') }}</x-ui.tag>
                                        @else
                                            <form action="{{ route('admin.menu-templates.migrations.undo', $mig->id) }}" method="post" data-ui-danger
                                                  data-ui-confirm="{{ ___('Undo this migration? Restores up to :n stores that are still on ":to" to their previous theme.', ['n' => $mig->count, 'to' => $mig->to_template]) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="secondary" size="sm" icon="arrow-back-up" class="ui-text-danger">{{ ___('Undo') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Recent template activity — audit trail, collapsed by default (native details). --}}
    <details class="ui-disclosure">
        <summary class="ui-disclosure__head">{{ ___('Recent template activity') }}</summary>
        <div class="ui-disclosure__body">
            @if($activity->isEmpty())
                <p class="ui-text-2">{{ ___('No template activity yet.') }}</p>
            @else
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('When') }}</th>
                                <th>{{ ___('Admin') }}</th>
                                <th>{{ ___('Action') }}</th>
                                <th>{{ ___('Details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activity as $row)
                                @php $m = $row->meta ?? []; @endphp
                                <tr>
                                    <td data-primary data-label="{{ ___('When') }}">{{ $row->created_at?->diffForHumans() }}</td>
                                    <td data-label="{{ ___('Admin') }}" class="ui-text-2">{{ $row->actor->email ?? '—' }}</td>
                                    <td data-label="{{ ___('Action') }}"><code>{{ Str::after($row->action, 'admin.menu_template.') }}</code></td>
                                    <td data-label="{{ ___('Details') }}" class="ui-small">
                                        <span class="ui-cell-tags">
                                            @if(!empty($m['slug'])) <code>{{ $m['slug'] }}</code> @endif
                                            @if(!empty($m['from'])) <span><code>{{ is_array($m['from']) ? implode(', ', $m['from']) : $m['from'] }}</code> &rarr; <code>{{ $m['to'] ?? '?' }}</code></span> @endif
                                            @if(isset($m['stores'])) <x-ui.tag>{{ $m['stores'] }}</x-ui.tag> @endif
                                            @if(!empty($m['partial'])) <x-ui.tag tone="accent">{{ ___('partial') }}</x-ui.tag> @endif
                                            @if(!empty($m['direction'])) <x-ui.tag>{{ $m['direction'] }}</x-ui.tag> @endif
                                            @if(!empty($m['action'])) <x-ui.tag>{{ $m['action'] }}</x-ui.tag> @endif
                                            @if(!empty($m['enabled'])) <x-ui.tag tone="success">{{ ___('enabled') }}</x-ui.tag> @endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </details>
</div>
@endsection

@push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        (function () {
            var grid = document.getElementById('templateGrid');
            var search = document.getElementById('templateSearch');
            var filter = document.getElementById('templateFilter');
            var sort = document.getElementById('templateSort');
            var counter = document.getElementById('templateShownCount');
            if (!grid || !search || !filter || !sort || !counter) { return; }

            // Snapshot once — data-order preserves the server display order
            // so switching back to "Display order" is loss-free.
            var cols = Array.prototype.slice.call(grid.querySelectorAll('[data-slug]'));

            function matches(col, term, mode) {
                if (term) {
                    var hay = (col.dataset.name + ' ' + col.dataset.slug + ' ' + (col.dataset.category || '')).toLowerCase();
                    if (hay.indexOf(term) === -1) { return false; }
                }
                switch (mode) {
                    case 'enabled': return col.dataset.enabled === '1';
                    case 'disabled': return col.dataset.enabled === '0';
                    case 'featured': return col.dataset.featured === '1';
                    case 'unused': return parseInt(col.dataset.usage, 10) === 0;
                    case 'modern': return col.dataset.modern === '1';
                    case 'legacy': return col.dataset.modern === '0';
                }
                return true;
            }

            function apply() {
                var term = (search.value || '').trim().toLowerCase();
                var mode = filter.value;
                var by = sort.value;

                var ordered = cols.slice().sort(function (a, b) {
                    if (by === 'name') { return a.dataset.name.localeCompare(b.dataset.name); }
                    if (by === 'usage') { return parseInt(b.dataset.usage, 10) - parseInt(a.dataset.usage, 10); }
                    return parseInt(a.dataset.order, 10) - parseInt(b.dataset.order, 10);
                });

                var shown = 0;
                ordered.forEach(function (col) {
                    var ok = matches(col, term, mode);
                    col.style.display = ok ? '' : 'none';
                    if (ok) { shown++; }
                    grid.appendChild(col); // re-append = re-sort in place
                });

                counter.textContent = (counter.dataset.label || ':n / :m')
                    .replace(':n', String(shown)).replace(':m', String(cols.length));
            }

            var debounceTimer = null;
            search.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(apply, 150);
            });
            filter.addEventListener('change', apply);
            sort.addEventListener('change', apply);
            apply();
        })();
    </script>

    {{-- Migration + bulk controls: the bulk form refuses an empty selection; the
         cross-template bulk form copies whichever grid checkboxes are ticked into
         itself as slugs[], and both it and the per-card quick-migrate confirm
         with an explicit, irreversible summary (dry-run) before the mass move.
         The kit's form[data-ui-confirm] asks; these listeners run first (on the
         form, before the document-level kit handler) and set the question. --}}
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        (function () {
            var L = {
                pick: @js(___('Select at least one template to migrate.')),
                moveFrom: @js(___('Move all stores from')),
                selectedTpls: @js(___('selected templates to')),
                moveAll: @js(___('Move all')),
                storesFrom: @js(___('stores from')),
                to: @js(___('to')),
                undo: @js(___('This cannot be undone.'))
            };

            function targetText(sel) {
                return sel && sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : (sel ? sel.value : '');
            }
            function checkedBoxes() {
                return document.querySelectorAll('.bulk-template-check:checked');
            }

            document.querySelectorAll('[data-mt-check]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var on = btn.getAttribute('data-mt-check') === '1';
                    document.querySelectorAll('.bulk-template-check').forEach(function (c) { c.checked = on; });
                });
            });

            var bulkToggle = document.getElementById('bulkTemplatesForm');
            if (bulkToggle) {
                bulkToggle.addEventListener('submit', function (e) {
                    if (!checkedBoxes().length) { e.preventDefault(); e.stopPropagation(); }
                });
            }

            var bulk = document.getElementById('bulkReassignForm');
            if (bulk) {
                bulk.addEventListener('submit', function (e) {
                    bulk.querySelectorAll('input[data-injected]').forEach(function (n) { n.remove(); });
                    var checked = checkedBoxes();
                    if (!checked.length) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (window.adminUi) { window.adminUi.toast(L.pick, 'danger'); }
                        return;
                    }
                    var target = targetText(bulk.querySelector('select[name="to"]'));
                    bulk.setAttribute('data-ui-confirm', L.moveFrom + ' ' + checked.length + ' ' + L.selectedTpls + ' "' + target + '"? ' + L.undo);
                    checked.forEach(function (c) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'slugs[]';
                        inp.value = c.value;
                        inp.setAttribute('data-injected', '1');
                        bulk.appendChild(inp);
                    });
                });
            }

            document.querySelectorAll('.js-card-migrate').forEach(function (form) {
                var sel = form.querySelector('select[name="to"]');
                if (!sel) { return; }
                function sync() {
                    form.setAttribute('data-ui-confirm', L.moveAll + ' ' + (sel.getAttribute('data-count') || '') + ' ' + L.storesFrom + ' "'
                        + (sel.getAttribute('data-from') || '') + '" ' + L.to + ' "' + targetText(sel) + '"? ' + L.undo);
                }
                sel.addEventListener('change', sync);
                sync();
            });
        })();
    </script>
@endpush
