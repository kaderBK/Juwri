{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Theme customizer'))
@section('page_title', ___('Theme customizer'))
@section('page_sub', ___('Restyle your menu — colours, fonts, sections — no code. Draft, preview, publish.'))

@php
    // Theme Studio B0.8: choices for the category / item_list / branch picker
    // fields — the ACTIVE store's own rows (page() already authorized it).
    // Switching store reloads the page, so this stays current; three small
    // queries, so they run whatever template is applied (a template switch
    // inside the page does not reload it).
    $studioPickers = ['category' => [], 'item_list' => [], 'branch' => []];
    if (!empty($activeId)) {
        $studioPickerStore = \Juwri\Core\Models\Store::find((int) $activeId);
        if ($studioPickerStore) {
            $studioPickers = \App\Services\ThemeCustomizerService::pickerOptions($studioPickerStore);
        }
    }
@endphp

{{-- Customizer v2 shell: a rail + searchable panel + preview, with a sticky
     action bar so Save/Publish are always reachable no matter how far the owner
     has scrolled. Styles live in assets/owner-ui/screens.css (.ui-tc). --}}
@section('content')
{{-- Alpine 3 calls the component's init() itself; a second call from x-init ran it twice (double polling / listeners; the customizer's second load() overwrote edits made meanwhile). --}}
<div class="ui-scope ui-tc" x-data="themeCustomizer()">
    <section class="ui-card ui-tc__hero">
        <div>
            <span class="ui-overline">{{ ___('Storefront') }}</span>
            <h2 class="ui-tc__hero-title">{{ ___('Make it yours') }}</h2>
            <span class="ui-tc__hero-sub" x-text="template ? ('{{ ___('Editing theme') }}: ' + template) : '{{ ___('Select a store') }}'"></span>
        </div>
        <div class="ui-tc__hero-tools">
            <form method="POST" action="{{ route('dashboard.context') }}">
                @csrf
                <input type="hidden" name="context_action" value="store">
                <input type="hidden" name="redirect_to" :value="'{{ route('theme.customizer.page', [], false) }}?store=' + activeId">
                <select name="store_id" class="ui-input ui-input--sm" x-model="activeId" aria-label="{{ ___('Select a store') }}"
                        @focus="$el.dataset.previous=activeId" @change="switchStore($event)">
                    @foreach($stores as $r)
                        <option value="{{ $r->id }}" @selected($activeId==$r->id)>{{ $r->title }}</option>
                    @endforeach
                </select>
            </form>
            <a class="ui-btn ui-btn--ghost ui-btn--sm" :href="storeUrl" target="_blank" rel="noopener" x-show="storeUrl">
                <span class="ui-btn__icon"><x-ui.icon name="external" :flip="true" /></span>{{ ___('View live') }}
            </a>
            <x-ui.button :href="route('user.store.themes')" variant="secondary" size="sm" icon="store">{{ ___('Browse Theme Store') }}</x-ui.button>
            {{-- Reverse link to the template picker (store settings → Design tab). --}}
            <a class="ui-btn ui-btn--secondary ui-btn--sm" :href="'{{ url('restaurants') }}/' + activeId + '/edit#design'" x-show="activeId">{{ ___('Change template') }}</a>
        </div>
    </section>

    @if($stores->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('Create a store first.')">
                @if (Route::has('stores.create'))
                    <x-ui.button :href="route('stores.create')" icon="plus">{{ ___('Create store') }}</x-ui.button>
                @endif
            </x-ui.empty>
        </div>
    @else
    <div class="tc-shell">

        {{-- Icon rail: one entry per settings group, plus the section list. --}}
        <div hidden data-tc-glyphs>
            @foreach (['palette', 'pencil', 'sparkles', 'search', 'picture', 'apps', 'list-check', 'settings-sliders'] as $tcGlyph)
                <span data-glyph="{{ $tcGlyph }}"><x-ui.shell.nav-icon :glyph="$tcGlyph" size="18" /></span>
            @endforeach
        </div>
        <nav class="tc-rail" aria-label="{{ ___('Setting groups') }}">
            <template x-for="(g, gi) in groups" :key="'rail-'+gi">
                <button type="button" :class="{'is-active': activeGroup === gi}" @click="focusGroup(gi)"
                        :aria-current="activeGroup === gi ? 'true' : 'false'" :title="g.group">
                    <span class="ui-tc__fi" x-html="groupGlyph(g.icon)" aria-hidden="true"></span>
                    <span x-text="g.group"></span>
                </button>
            </template>
            <button type="button" x-show="(schema.sections||[]).length" :class="{'is-active': activeGroup === 'sections'}"
                    @click="focusGroup('sections')" title="{{ ___('Sections') }}">
                <x-ui.icon name="list" />
                <span>{{ ___('Sections') }}</span>
            </button>
            {{-- Phase 3: theme library — switch the store's template without leaving the editor. --}}
            <button type="button" :class="{'is-active': activeGroup === 'library'}"
                    @click="focusGroup('library')" title="{{ ___('Theme library') }}">
                <x-ui.icon name="more" />
                <span>{{ ___('Themes') }}</span>
            </button>
        </nav>

        {{-- Settings panel --}}
        <div>
        <section class="ui-card">
            <div class="ui-tc__search">
                <input class="ui-input" type="search" x-model="search" x-ref="search"
                       placeholder="{{ ___('Search settings…') }}" aria-label="{{ ___('Search settings…') }}" @keydown.escape="search=''">
                <button type="button" class="ui-icon-btn" @click="undo()" :disabled="!canUndo"
                        title="{{ ___('Undo') }} (Ctrl+Z)" aria-label="{{ ___('Undo') }}">
                    <svg class="ui-flip-rtl" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
                </button>
                <button type="button" class="ui-icon-btn" @click="redo()" :disabled="!canRedo"
                        title="{{ ___('Redo') }} (Ctrl+Shift+Z)" aria-label="{{ ___('Redo') }}">
                    <svg class="ui-flip-rtl" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg>
                </button>
            </div>

            {{-- Presets (WI-S1) — curated starting points. Hover shows it live in
                 the preview via the U3 channel; click stages it as a draft the owner
                 keeps editing from. Hidden while searching. --}}
            <div class="tc-group" x-show="((schema.presets||[]).length || hasColorRoles) && !search" x-cloak>
                <h6 class="ui-tc__h">{{ ___('Presets') }}</h6>
                <div class="tc-presets" x-show="(schema.presets||[]).length">
                    <template x-for="p in (schema.presets||[])" :key="p.id">
                        <button type="button" class="tc-preset" :class="{'is-active': activePreset === p.id}"
                                @mouseenter="hoverPreset(p)" @mouseleave="unhoverPreset()"
                                @focus="hoverPreset(p)" @blur="unhoverPreset()"
                                @click="applyPreset(p)">
                            <img :src="p.thumb" alt="" class="tc-preset-thumb" x-show="p.thumb" loading="lazy">
                            <span class="tc-preset-name" x-text="p.label"></span>
                            <span class="tc-preset-tag" x-show="activePreset === p.id && presetModified">{{ ___('Modified') }}</span>
                            <span class="tc-preset-tag" x-show="activePreset === p.id && !presetModified">{{ ___('Applied') }}</span>
                        </button>
                    </template>
                </div>

                {{-- WI-S4 — curated colour palettes applied onto the colour roles,
                     plus "from my logo" (GD dominant-colour extraction). Only shown
                     when the theme exposes colour roles to apply them to. --}}
                <template x-if="hasColorRoles">
                    <div class="ui-tc__block ui-tc__block--flat">
                        <p class="ui-tc__muted">{{ ___('Or start from a colour palette') }}</p>
                        <div class="tc-palettes">
                            <template x-for="pal in palettesWithSwatch" :key="pal.id">
                                <button type="button" class="tc-palette" :title="pal.label" :aria-label="pal.label"
                                        @mouseenter="hoverPalette(pal)" @mouseleave="unhoverPreset()"
                                        @click="applyPalette(pal)">
                                    <template x-for="c in pal.swatch" :key="c">
                                        <span class="tc-palette-chip" :style="'background:'+c"></span>
                                    </template>
                                </button>
                            </template>
                        </div>
                        <div class="ui-tc__row">
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="paletteFromLogo()" :disabled="paletteBusy">
                                <span class="ui-btn__icon"><x-ui.icon name="upload" /></span>{{ ___('From my logo') }}
                            </button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="shuffleRoles()">{{ ___('Shuffle roles') }}</button>
                        </div>
                        <p class="ui-tc__muted" x-show="paletteMsg" x-text="paletteMsg"></p>
                    </div>
                </template>
            </div>

            <template x-if="!groups.length">
                <p class="tc-empty" x-text="search ? '{{ ___('No setting matches that search.') }}' : '{{ ___('This theme has no customizable settings yet.') }}'"></p>
            </template>

            <template x-for="(group, gi) in groups" :key="'g-'+gi">
                <div class="tc-group" :class="{'is-collapsed': isCollapsed(group.group) && !search}" :id="'tc-group-'+gi">
                    <button type="button" class="tc-group-head" @click="toggleGroup(group.group)">
                        <span class="ui-tc__fi" x-html="groupGlyph(group.icon)" aria-hidden="true"></span>
                        <h6 x-text="group.group"></h6>
                        <span class="tc-badge" x-show="changedIn(group)" x-text="changedIn(group)"></span>
                        <x-ui.icon name="chevron-down" size="16" class="tc-chev" />
                    </button>

                    <div x-show="!isCollapsed(group.group) || search" x-cloak>
                    {{-- Splash v2.1 — curated splash looks. One click stages a full
                         splash.* value set through set() (undo-able, dirty, previewed
                         via Replay splash). Same idiom as the colour palettes. --}}
                    <template x-if="isSplashGroup(group) && !search">
                        <div class="tc-field">
                            <p class="ui-tc__muted">{{ ___('Splash looks') }}</p>
                            <div class="tc-splash-presets">
                                <template x-for="p in splashPresets" :key="p.id">
                                    <button type="button" class="tc-splash-preset" @click="applySplashPreset(p)" :title="p.label">
                                        <span class="tc-splash-preset__mock" :style="'background:' + p.mock.bg + ';color:' + p.mock.fg">
                                            <span class="tc-splash-preset__logo" :style="'border-radius:' + p.mock.logoR"></span>
                                            <span class="tc-splash-preset__line"></span>
                                            <span class="tc-splash-preset__cta" x-show="p.mock.cta"></span>
                                        </span>
                                        <span class="tc-splash-preset__name" x-text="p.label"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-for="f in group.fields" :key="f.id">
                        <div class="tc-field">
                            <div class="tc-field-head">
                                <label x-text="f.label" :for="'tcf-'+f.id"></label>
                                <button type="button" class="tc-reset" x-show="isChanged(f)" @click="resetField(f)"
                                        :title="'{{ ___('Reset to default') }}'" aria-label="{{ ___('Reset to default') }}"><x-ui.icon name="refresh" size="14" /></button>
                                <span class="tc-crumb" x-show="search" x-text="group.group"></span>
                            </div>

                            <template x-if="f.type==='color'">
                                <div class="ui-tc__row">
                                    <input type="color" class="ui-input" :id="'tcf-'+f.id"
                                           :value="values[f.id] || f.default || '#000000'" @input="set(f.id, $event.target.value)">
                                    <input class="ui-input tc-hex ui-ltr" type="text" dir="ltr" :aria-label="f.label"
                                           :value="values[f.id] || ''" @change="set(f.id, $event.target.value)" placeholder="#rrggbb">
                                </div>
                            </template>

                            {{-- CC run: a select carrying option_previews renders as the
                                 visual card grid (lane-A partial — @includeIf so the page
                                 still renders before that partial lands); a preview-less
                                 select keeps the plain control below. --}}
                            @includeIf('owner.design.partials.tc-card-select')
                            <template x-if="f.type==='select' && !f.option_previews">
                                <select class="ui-input" :id="'tcf-'+f.id" :value="values[f.id]" @change="set(f.id, $event.target.value)">
                                    <template x-for="opt in (f.options||[])" :key="opt">
                                        <option :value="opt" :selected="values[f.id]===opt" x-text="(f.option_labels && f.option_labels[opt]) || opt"></option>
                                    </template>
                                </select>
                            </template>

                            {{-- Plain text field (not localized, or the store has one language). --}}
                            <template x-if="f.type==='text' && !isLocalized(f)">
                                <input class="ui-input" type="text" :id="'tcf-'+f.id" :value="values[f.id] ?? ''"
                                       @input="set(f.id, $event.target.value)" :placeholder="f.default || ''" dir="auto">
                            </template>

                            {{-- WI-L1 — localized text: one tab per store language, editing a
                                 {locale: value} map. Empty locales fall back at render time. --}}
                            <template x-if="f.type==='text' && isLocalized(f)">
                                <div class="ui-stack ui-tc__tight">
                                    <div class="ui-chips" role="group">
                                        <template x-for="l in languages" :key="'lt-'+f.id+l.code">
                                            <button type="button" class="ui-chip ui-ltr"
                                                    :aria-pressed="textLocale === l.code ? 'true' : 'false'" @click="textLocale = l.code"
                                                    x-text="l.code.toUpperCase()"></button>
                                        </template>
                                    </div>
                                    <input class="ui-input" type="text" :id="'tcf-'+f.id"
                                           :value="localizedValue(f.id, textLocale)"
                                           @input="setLocalized(f.id, textLocale, $event.target.value)"
                                           :placeholder="f.default || ''" :dir="localeDir(textLocale)">
                                </div>
                            </template>

                            <template x-if="f.type==='number'">
                                <input class="ui-input" type="number" :id="'tcf-'+f.id" :value="values[f.id]"
                                       :min="f.min" :max="f.max" @input="set(f.id, $event.target.value === '' ? null : Number($event.target.value))">
                            </template>

                            <template x-if="f.type==='image'">
                                <div>
                                    <div class="ui-tc__row">
                                        <input class="ui-input ui-tc__grow" type="text" :id="'tcf-'+f.id" :value="values[f.id] ?? ''"
                                               @input="set(f.id, $event.target.value)"
                                               placeholder="{{ ___('Image URL (leave blank to use logo)') }}">
                                        {{-- WI-L4 — pick from the store's media library instead of pasting a URL. --}}
                                        <button type="button" class="ui-icon-btn"
                                                @click="openMedia(f.id)" title="{{ ___('Choose from library') }}" aria-label="{{ ___('Choose from library') }}">
                                            <x-ui.icon name="more" />
                                        </button>
                                    </div>
                                    <template x-if="values[f.id]">
                                        <img :src="values[f.id]" alt="" class="tc-thumb">
                                    </template>
                                </div>
                            </template>

                            {{-- Theme Studio B0.8 — richer setting types for Liquid (Studio) themes.
                                 Every value is re-validated server-side; these controls only shape input. --}}
                            <template x-if="f.type==='range'">
                                <div class="ui-tc__row">
                                    <input class="ui-range ui-tc__grow" type="range" :id="'tcf-'+f.id"
                                           :min="f.min ?? 0" :max="f.max ?? 100" :step="f.step ?? 1"
                                           :value="values[f.id] ?? f.default ?? f.min ?? 0"
                                           @input="set(f.id, Number($event.target.value))">
                                    <span class="tc-range-val ui-ltr"
                                          x-text="(values[f.id] ?? f.default ?? '') + (f.unit ? ' ' + f.unit : '')"></span>
                                </div>
                            </template>

                            <template x-if="f.type==='textarea'">
                                <textarea class="ui-input" rows="3" :id="'tcf-'+f.id" dir="auto" maxlength="5000"
                                          :placeholder="f.default || ''" @input="set(f.id, $event.target.value)"
                                          :value="values[f.id] ?? ''"></textarea>
                            </template>

                            <template x-if="f.type==='richtext'">
                                <div class="tc-richtext ui-stack ui-tc__tight">
                                    <div class="ui-chips" role="group" aria-label="{{ ___('Formatting') }}">
                                        <button type="button" class="ui-chip" @mousedown.prevent="richCmd('bold')" title="{{ ___('Bold') }}" aria-label="{{ ___('Bold') }}"><strong>B</strong></button>
                                        <button type="button" class="ui-chip" @mousedown.prevent="richCmd('italic')" title="{{ ___('Italic') }}" aria-label="{{ ___('Italic') }}"><em>I</em></button>
                                        <button type="button" class="ui-chip" @mousedown.prevent="richCmd('underline')" title="{{ ___('Underline') }}" aria-label="{{ ___('Underline') }}"><u>U</u></button>
                                        <button type="button" class="ui-chip" @mousedown.prevent="richLink()" title="{{ ___('Link') }}" aria-label="{{ ___('Link') }}"><x-ui.icon name="external" size="16" /></button>
                                    </div>
                                    <div class="ui-input tc-richtext__area" contenteditable="true" role="textbox" aria-multiline="true"
                                         :id="'tcf-'+f.id" dir="auto" x-init="$el.innerHTML = values[f.id] ?? ''"
                                         @input="set(f.id, $event.target.innerHTML)"></div>
                                </div>
                            </template>

                            <template x-if="f.type==='url'">
                                <input class="ui-input" type="text" inputmode="url" dir="ltr" :id="'tcf-'+f.id"
                                       :value="values[f.id] ?? ''" :class="{'ui-input--error': !urlOk(values[f.id])}"
                                       @input="set(f.id, $event.target.value)" placeholder="https://… {{ ___('or') }} /page">
                            </template>

                            <template x-if="f.type==='font_pair' || f.type==='color_token'">
                                <select class="ui-input" :id="'tcf-'+f.id" @change="set(f.id, $event.target.value)">
                                    <template x-for="opt in (f.options||[])" :key="opt">
                                        <option :value="opt" :selected="(values[f.id] ?? f.default)===opt"
                                                x-text="f.type==='color_token' ? tokenLabel(opt) : ((f.option_labels && f.option_labels[opt]) || opt)"></option>
                                    </template>
                                </select>
                            </template>

                            <template x-if="f.type==='category' || f.type==='branch'">
                                <select class="ui-input" :id="'tcf-'+f.id"
                                        @change="set(f.id, $event.target.value === '' ? null : Number($event.target.value))">
                                    <option value="">{{ ___('— Choose —') }}</option>
                                    <template x-for="row in (pickers[f.type]||[])" :key="row.id">
                                        <option :value="row.id" :selected="Number(values[f.id])===row.id" x-text="row.name"></option>
                                    </template>
                                </select>
                            </template>

                            <template x-if="f.type==='item_list'">
                                <div class="ui-stack ui-tc__tight">
                                    <input class="ui-input" type="search" x-model="itemQuery"
                                           placeholder="{{ ___('Filter items…') }}" aria-label="{{ ___('Filter items…') }}">
                                    <p class="ui-tc__muted ui-ltr"
                                       x-text="(values[f.id]||[]).length + ' / ' + (f.max||24) + ' {{ ___('selected') }}'"></p>
                                    <div class="tc-itemlist">
                                        <template x-for="row in pickerMatches('item_list')" :key="row.id">
                                            <label class="ui-check">
                                                <input type="checkbox" :checked="(values[f.id]||[]).includes(row.id)"
                                                       :disabled="!(values[f.id]||[]).includes(row.id) && (values[f.id]||[]).length >= (f.max||24)"
                                                       @change="toggleListItem(f, row.id, $event.target.checked)">
                                                <span x-text="row.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-if="f.type==='image_picker'">
                                <div>
                                    <div class="ui-tc__row ui-tc__row--nowrap">
                                        <template x-if="values[f.id] && mediaUrls[values[f.id]]">
                                            <img :src="mediaUrls[values[f.id]]" alt="" class="tc-thumb tc-thumb--sq" width="56" height="56">
                                        </template>
                                        <span class="tc-filename" dir="ltr"
                                              x-text="values[f.id] || '{{ ___('No image') }}'"></span>
                                        <button type="button" class="ui-icon-btn"
                                                @click="openMedia(f.id, 'filename')" title="{{ ___('Choose from library') }}" aria-label="{{ ___('Choose from library') }}">
                                            <x-ui.icon name="more" />
                                        </button>
                                        <label class="ui-icon-btn tc-upload" title="{{ ___('Upload') }}" aria-label="{{ ___('Upload') }}">
                                            <x-ui.icon name="upload" />
                                            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="ui-hidden"
                                                   @change="uploadMediaFor(f.id, $event)">
                                        </label>
                                        <button type="button" class="ui-icon-btn ui-menu-item__delete" x-show="values[f.id]"
                                                @click="set(f.id, '')" title="{{ ___('Remove') }}" aria-label="{{ ___('Remove') }}"><x-ui.icon name="x" /></button>
                                    </div>
                                    <p class="ui-tc__help" x-show="mediaUploading===f.id">{{ ___('Uploading…') }}</p>
                                </div>
                            </template>

                            <template x-if="f.type==='toggle'">
                                <div class="ui-check">
                                    <input type="checkbox" role="switch" :id="'tcf-'+f.id"
                                           :checked="!!values[f.id]" @change="set(f.id, $event.target.checked)">
                                </div>
                            </template>

                            <p class="ui-tc__help" x-show="f.help" x-text="f.help"></p>
                        </div>
                    </template>
                    </div>
                </div>
            </template>

            <template x-if="(schema.sections||[]).length && !search">
                <div class="tc-group" id="tc-group-sections">
                    <h6 class="ui-tc__h">{{ ___('Sections') }}</h6>
                    <p class="ui-tc__muted" x-show="orderableSections.length > 1">{{ ___('Drag to reorder. Toggle to show or hide.') }}</p>
                    <template x-for="(s, si) in orderedSections" :key="s.id">
                        <div class="tc-section"
                             :draggable="s.orderable ? 'true' : 'false'"
                             @dragstart="dragSection = si" @dragover.prevent
                             @drop.prevent="dropSection(si)">
                            <span class="tc-drag" x-show="s.orderable" title="{{ ___('Drag to reorder') }}">
                                <x-ui.icon name="grip" />
                            </span>
                            <input type="checkbox" :aria-label="s.label"
                                   :checked="values['sections.'+s.id] !== false"
                                   @change="set('sections.'+s.id, $event.target.checked)">
                            <span class="tc-section__label" x-text="s.label"></span>
                            <template x-if="s.orderable">
                                <span class="ui-tc__row ui-tc__row--nowrap ui-tc__row--tight">
                                    <button type="button" class="tc-reorder" :disabled="si===0" @click="moveSection(si,-1)" title="{{ ___('Move up') }}" aria-label="{{ ___('Move up') }}">↑</button>
                                    <button type="button" class="tc-reorder" :disabled="si===orderedSections.length-1" @click="moveSection(si,1)" title="{{ ___('Move down') }}" aria-label="{{ ___('Move down') }}">↓</button>
                                </span>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Phase 3: theme library — switch the store's template in place.
                 Settings are keyed per template (theme_settings.template), so a
                 switch never loses the previous theme's customizations; switching
                 back restores them. --}}
            <div class="tc-group" id="tc-group-library" x-show="!search" x-cloak>
                <h6 class="ui-tc__h">{{ ___('Theme library') }}</h6>
                <input class="ui-input" type="search" x-model="libSearch"
                       placeholder="{{ ___('Search templates') }}" aria-label="{{ ___('Search templates') }}">
                <div class="tc-library">
                    @foreach($templates as $tpl)
                        <button type="button" class="tc-libcard"
                                :class="{'is-active': template === @js($tpl['folder']), 'is-busy': switching === @js($tpl['folder'])}"
                                :disabled="switching !== ''"
                                x-show="libMatch(@js(mb_strtolower($tpl['name'])))"
                                @click="applyTemplate(@js($tpl['folder']), @js($tpl['name']))"
                                title="{{ ___($tpl['description'] ?? 'A premium QR menu template.') }}">
                            <img src="{{ $tpl['image'] }}" alt="" loading="lazy" data-ui-do="invisible-on-error">
                            <span class="tc-libcard__name">{{ ___($tpl['name']) }}</span>
                            <span class="tc-libcard__badge" x-show="template === @js($tpl['folder'])">{{ ___('Active') }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Tools (WI-V2/V3) — export, import, copy to another store. --}}
            <div class="tc-group" x-show="!search" x-cloak>
                <h6 class="ui-tc__h">{{ ___('Tools') }}</h6>
                <div class="ui-tc__row">
                    <a class="ui-btn ui-btn--secondary ui-btn--sm" :href="'{{ url('theme-customizer') }}/'+activeId+'/export'">
                        <span class="ui-btn__icon"><x-ui.icon name="download" /></span>{{ ___('Export') }}
                    </a>
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="$refs.importFile.click()">
                        <span class="ui-btn__icon"><x-ui.icon name="upload" /></span>{{ ___('Import') }}
                    </button>
                    <input type="file" x-ref="importFile" accept="application/json,.json" class="ui-hidden" @change="importFile($event)">
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="copyTargets.length" @click="openCopy()">
                        <span class="ui-btn__icon"><x-ui.icon name="share" /></span>{{ ___('Apply to another store') }}
                    </button>
                </div>
                <p class="ui-tc__help" x-show="toolMsg" x-text="toolMsg"></p>

                {{-- Copy picker --}}
                <div class="ui-tc__row ui-tc__copy" x-show="copyOpen" x-cloak>
                    <select class="ui-input ui-tc__grow" x-model="copyTarget" aria-label="{{ ___('Choose a store…') }}">
                        <option value="">{{ ___('Choose a store…') }}</option>
                        <template x-for="t in copyTargets" :key="t.id">
                            <option :value="t.id" x-text="t.title"></option>
                        </template>
                    </select>
                    <button type="button" class="ui-btn ui-btn--sm" :disabled="!copyTarget" @click="copyToStore()">{{ ___('Apply') }}</button>
                </div>
            </div>

            {{-- History (WI-V1) — every publish is a version; revert restores one as
                 a new version, never destructively. Hidden while searching. --}}
            <div class="tc-group" x-show="history.length && !search" x-cloak>
                <button type="button" class="tc-group-head" @click="historyOpen = !historyOpen" :aria-expanded="historyOpen ? 'true' : 'false'">
                    <x-ui.icon name="clock" size="18" />
                    <h4 class="ui-section-title">{{ ___('History') }}</h4>
                    <x-ui.icon name="chevron-down" size="16" class="tc-chev" x-bind:class="historyOpen ? '' : 'is-closed'" />
                </button>
                <div x-show="historyOpen">
                    <template x-for="(v, vi) in history" :key="v.id">
                        <div class="tc-version">
                            <span class="tc-version-dot" :class="{'is-head': vi === 0}"></span>
                            <div class="tc-version__body">
                                <div class="tc-version__label" x-text="versionLabel(v)"></div>
                                <div class="tc-version__at" x-text="v.at ? relTime(v.at) : ''"></div>
                            </div>
                            <span class="tc-version__changed" x-show="v.changed"
                                  x-text="v.changed + ' {{ ___('changed') }}'"></span>
                            <button type="button" class="ui-btn ui-btn--link ui-btn--sm" @click="showDiff(v)">{{ ___('Diff') }}</button>
                            <button type="button" class="ui-btn ui-btn--link ui-btn--sm" x-show="vi !== 0" @click="revert(v)">{{ ___('Revert') }}</button>
                        </div>
                    </template>
                    <div class="tc-diff" x-show="diffVersion" x-cloak>
                        <div class="tc-diff__head">
                            <strong x-text="'{{ ___('Changes vs now') }}'"></strong>
                            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="diffVersion=null" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
                        </div>
                        <template x-if="!diffRows.length"><span class="ui-tc__muted">{{ ___('No differences.') }}</span></template>
                        <template x-for="d in diffRows" :key="d.key">
                            <div class="tc-diff__row">
                                <code x-text="d.key"></code>
                                <span x-text="String(d.from)"></span> → <span x-text="String(d.to)"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Page View — Mode B: choose the display source (live data vs uploaded PDF) --}}
            <template x-if="template==='pageview'">
                <div class="ui-tc__block">
                    <h6 class="ui-tc__h">{{ ___('Menu display source') }}</h6>
                    <p class="ui-tc__muted">{{ ___('Page a booklet from your live menu, or upload a print-ready PDF to display as-is.') }}</p>

                    <div>
                        <label class="ui-label">{{ ___('Source') }}</label>
                        <select class="ui-input" x-model="pv.source">
                            <option value="data">{{ ___('Live menu data') }}</option>
                            <option value="pdf">{{ ___('Uploaded PDF') }}</option>
                        </select>
                    </div>

                    <div x-show="pv.source==='pdf'" x-cloak>
                        <label class="ui-label">{{ ___('PDF file') }}</label>
                        <input type="file" class="ui-input" accept="application/pdf" @change="pv.file = $event.target.files[0] || null">
                        <p x-show="pv.pdfUrl && !pv.file" x-cloak class="ui-tc__help">
                            <a :href="pv.pdfUrl" target="_blank" rel="noopener">{{ ___('Current PDF') }} ↗</a>
                        </p>
                        {{-- P6-212: display the host's real per-file cap instead of the hard-coded 20 MB claim. --}}
                        @if(! empty($hostUploadMaxMb))
                            <p class="ui-tc__help">{{ ___('PDF up to :mb MB (set by your host). Pages render in the viewer.', ['mb' => $hostUploadMaxMb]) }}</p>
                        @else
                            <p class="ui-tc__help">{{ ___('PDF up to 20 MB. Pages render in the viewer.') }}</p>
                        @endif
                    </div>

                    <x-ui.button size="sm" icon="check" x-bind:disabled="pvSaving" @click="savePv()">{{ ___('Save display source') }}</x-ui.button>

                    {{-- Menu-scan seam: reuse the uploaded PDF to also import structured items --}}
                    <div x-show="pv.source==='pdf' && pv.pdfUrl" x-cloak class="ui-tc__block">
                        <p class="ui-tc__muted">{{ ___('Turn this PDF into editable items items too? This adds items — existing ones are not replaced.') }}</p>
                        <x-ui.button variant="secondary" size="sm" icon="sparkle" x-bind:disabled="pvImporting" @click="importFromPdf()">{{ ___('Also import items to my menu') }}</x-ui.button>
                        <p x-show="pvImportMsg" x-text="pvImportMsg" x-cloak class="ui-tc__help"></p>
                    </div>

                    <p x-show="pvMsg" x-text="pvMsg" x-cloak class="ui-tc__ok"></p>
                </div>
            </template>

            {{-- Folio — flip-book source, layout + toolbar (writes the rev/folio-theme SPEC §3 store_options contract) --}}
            <template x-if="template==='folio'">
                <div class="ui-tc__block" data-folio-panel>
                    <h6 class="ui-tc__h">{{ ___('Flip book') }}</h6>
                    <p class="ui-tc__muted">{{ ___('Show your menu as a page-flip book from uploaded images or a print-ready PDF.') }}</p>

                    <div>
                        <label class="ui-label">{{ ___('Source') }}</label>
                        <select class="ui-input" x-model="folio.source">
                            <option value="images">{{ ___('Menu images') }}</option>
                            <option value="pdf">{{ ___('Uploaded PDF') }}</option>
                        </select>
                    </div>

                    <div x-show="folio.source==='pdf'" x-cloak>
                        <label class="ui-label">{{ ___('PDF file') }}</label>
                        <input type="file" class="ui-input" accept="application/pdf" @change="folio.file = $event.target.files[0] || null">
                        <p x-show="folio.pdfUrl && !folio.file" x-cloak class="ui-tc__help">
                            <a :href="folio.pdfUrl" target="_blank" rel="noopener">{{ ___('Current PDF') }} ↗</a>
                        </p>
                        {{-- P6-212: same real-cap hint as Page View, so the owner never claims 20 MB on a 4 MB host. --}}
                        @if(! empty($hostUploadMaxMb))
                            <p class="ui-tc__help">{{ ___('PDF up to :mb MB (set by your host). Pages render in the viewer.', ['mb' => $hostUploadMaxMb]) }}</p>
                        @else
                            <p class="ui-tc__help">{{ ___('PDF up to 20 MB. Pages render in the viewer.') }}</p>
                        @endif
                        <p x-show="folioRasterMsg" x-text="folioRasterMsg" x-cloak class="ui-tc__ok"></p>
                    </div>

                    <div class="ui-tc__split">
                        <div>
                            <label class="ui-label">{{ ___('Page spread') }}</label>
                            <select class="ui-input" x-model="folio.display">
                                <option value="auto">{{ ___('Auto') }}</option>
                                <option value="single">{{ ___('Single page') }}</option>
                                <option value="double">{{ ___('Double spread') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="ui-label">{{ ___('Page turn') }}</label>
                            <select class="ui-input" x-model="folio.anim">
                                <option value="flip">{{ ___('Flip animation') }}</option>
                                <option value="swipe">{{ ___('Simple swipe') }}</option>
                            </select>
                        </div>
                    </div>

                    <label class="ui-check">
                        <input type="checkbox" :checked="folio.hardcover==='1'" @change="folio.hardcover = $event.target.checked ? '1' : '0'">
                        <span>{{ ___('Hard cover') }}</span>
                    </label>
                    <label class="ui-check">
                        <input type="checkbox" :checked="folio.tb_thumbs==='1'" @change="folio.tb_thumbs = $event.target.checked ? '1' : '0'">
                        <span>{{ ___('Thumbnail strip') }}</span>
                    </label>
                    <label class="ui-check">
                        <input type="checkbox" :checked="folio.tb_zoom==='1'" @change="folio.tb_zoom = $event.target.checked ? '1' : '0'">
                        <span>{{ ___('Zoom') }}</span>
                    </label>
                    <label class="ui-check">
                        <input type="checkbox" :checked="folio.tb_fullscreen==='1'" @change="folio.tb_fullscreen = $event.target.checked ? '1' : '0'">
                        <span>{{ ___('Fullscreen') }}</span>
                    </label>
                    <label class="ui-check">
                        <input type="checkbox" :checked="folio.tb_download==='1'" @change="folio.tb_download = $event.target.checked ? '1' : '0'">
                        <span>{{ ___('Download button (PDF source)') }}</span>
                    </label>

                    <div class="ui-tc__split">
                        <div>
                            <label class="ui-label">{{ ___('Background') }}</label>
                            <input type="color" class="ui-input ui-w-full" x-model="folio.bg">
                        </div>
                        <div>
                            <label class="ui-label">{{ ___('Accent') }}</label>
                            <input type="color" class="ui-input ui-w-full" x-model="folio.accent">
                        </div>
                    </div>

                    {{-- FP B2: ambient page texture --}}
                    <div>
                        <label class="ui-label">{{ ___('Page texture') }}</label>
                        <select class="ui-input" x-model="folio.texture">
                            <option value="">{{ ___('None (flat colour)') }}</option>
                            <option value="linen">{{ ___('Linen') }}</option>
                            <option value="wood">{{ ___('Wood') }}</option>
                            <option value="marble">{{ ___('Marble') }}</option>
                            <option value="paper">{{ ___('Paper') }}</option>
                        </select>
                    </div>

                    {{-- FP B2: page-turn sound --}}
                    <label class="ui-check">
                        <input type="checkbox" :checked="folio.sound==='1'" @change="folio.sound = $event.target.checked ? '1' : '0'">
                        <span>{{ ___('Page-turn sound') }}</span>
                    </label>

                    {{-- FP B2: order call-to-action --}}
                    <div>
                        <label class="ui-label">{{ ___('Order button') }}</label>
                        <select class="ui-input" x-model="folio.cta">
                            <option value="">{{ ___('Hidden') }}</option>
                            <option value="whatsapp">{{ ___('WhatsApp') }}</option>
                            <option value="link">{{ ___('Custom link') }}</option>
                        </select>
                    </div>
                    <div x-show="folio.cta==='link'" x-cloak>
                        <label class="ui-label">{{ ___('Order link') }}</label>
                        <input type="url" class="ui-input" dir="ltr" x-model="folio.cta_url" placeholder="https://">
                        <p class="ui-tc__help">{{ ___('Must start with http:// or https://') }}</p>
                    </div>
                    <div x-show="folio.cta==='whatsapp' || folio.cta==='link'" x-cloak>
                        <label class="ui-label">{{ ___('Button label') }}</label>
                        <input type="text" class="ui-input" x-model="folio.cta_label" maxlength="60" placeholder="{{ ___('Order') }}">
                    </div>

                    <x-ui.button size="sm" icon="check" x-bind:disabled="folioSaving" @click="saveFolio()">{{ ___('Save flip book settings') }}</x-ui.button>
                    <p x-show="folioMsg" x-text="folioMsg" x-cloak class="ui-tc__ok"></p>

                    {{-- FP B7: auto cover page --}}
                    <div class="ui-tc__block">
                        <h6 class="ui-tc__h">{{ ___('Cover page') }}</h6>
                        <p class="ui-tc__muted">{{ ___('Build a branded first page from your colours, logo and name. Re-generating replaces the previous cover.') }}</p>
                        <x-ui.button size="sm" variant="secondary" icon="sparkle" x-bind:disabled="folioCoverSaving" @click="generateFolioCover()">{{ ___('Generate cover page') }}</x-ui.button>
                        <p x-show="folioCoverMsg" x-text="folioCoverMsg" x-cloak class="ui-tc__ok"></p>
                    </div>

                    {{-- FP B3: time-based books + per-page tagging --}}
                    <div class="ui-tc__block">
                        <h6 class="ui-tc__h">{{ ___('Time-based books') }}</h6>
                        <p class="ui-tc__muted">{{ ___('Show a different set of pages by time of day (e.g. breakfast, dinner). Untagged pages always show.') }}</p>

                        <template x-for="(bk, i) in folio.books" :key="i">
                            <div class="tc-book">
                                <div class="ui-tc__row ui-tc__row--nowrap">
                                    <input type="text" class="ui-input" x-model="bk.label" placeholder="{{ ___('Book name') }}" aria-label="{{ ___('Book name') }}" maxlength="40">
                                    <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="folio.books.splice(i,1)" aria-label="{{ ___('Remove book') }}"><x-ui.icon name="x" /></button>
                                </div>
                                <div class="ui-tc__split">
                                    <div>
                                        <label class="ui-label">{{ ___('From') }}</label>
                                        <input type="time" class="ui-input" x-model="bk.start">
                                    </div>
                                    <div>
                                        <label class="ui-label">{{ ___('To') }}</label>
                                        <input type="time" class="ui-input" x-model="bk.end">
                                    </div>
                                </div>
                                <template x-if="folio.pages && folio.pages.length">
                                    <div>
                                        <label class="ui-label">{{ ___('Pages in this book') }}</label>
                                        <div class="ui-tc__row">
                                            <template x-for="pg in folio.pages" :key="pg.id">
                                                <label class="ui-check">
                                                    <input type="checkbox" :checked="folioPageIdx[pg.id]===i" @change="setFolioPage(pg.id, i, $event.target.checked)">
                                                    <span x-text="pg.name"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="ui-tc__row">
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="folio.books.length < 6" @click="folio.books.push({ key: '', label: '', start: '09:00', end: '17:00' })">
                                <span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Add book') }}
                            </button>
                            <x-ui.button size="sm" icon="check" x-bind:disabled="folioBooksSaving" @click="saveFolioBooks()">{{ ___('Save books') }}</x-ui.button>
                        </div>
                        <p x-show="folioBooksMsg" x-text="folioBooksMsg" x-cloak class="ui-tc__ok"></p>
                    </div>

                    {{-- FP B8: page-view insights (last 30 days) --}}
                    <div class="ui-tc__block">
                        <h6 class="ui-tc__h">{{ ___('Insights') }}</h6>
                        <p class="ui-tc__muted">{{ ___('Flip-book page views over the last 30 days.') }}</p>
                        <template x-if="!folio.insights || !folio.insights.total">
                            <p class="ui-tc__muted">{{ ___('No views yet — share your menu link to start collecting.') }}</p>
                        </template>
                        <template x-if="folio.insights && folio.insights.total">
                            <div>
                                <p class="ui-tc__muted"><strong class="ui-ltr" x-text="folio.insights.total"></strong> {{ ___('total page views') }}</p>
                                <div class="ui-stack ui-tc__tight">
                                    <template x-for="row in folioInsightRows()" :key="row.page">
                                        <div class="tc-insight">
                                            <span>{{ ___('Page') }} <span class="ui-ltr" x-text="row.page"></span></span>
                                            <span class="ui-ov-meter" aria-hidden="true"><span :style="`inline-size:${row.pct}%`"></span></span>
                                            <span class="ui-ltr" x-text="row.views"></span>
                                        </div>
                                    </template>
                                </div>
                                <p x-show="folio.insights.dropoff" x-cloak class="ui-tc__help">
                                    {{ ___('Biggest drop-off after page') }} <strong class="ui-ltr" x-text="folio.insights.dropoff"></strong>
                                </p>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </section>

        {{-- Contrast guard (WI-T6, warn-only) — never blocks publish; lists the
             pairs a shopper would struggle to read, with a one-click fix. --}}
        <div class="ui-banner ui-tc__contrast" data-tone="warning" x-show="contrast.length" x-cloak role="status">
            <span class="ui-banner__icon"><x-ui.icon name="alert" size="18" /></span>
            <div class="ui-banner__body">
                <strong>{{ ___('Some colours may be hard to read') }}</strong>
                <template x-for="w in contrast" :key="w.pair + w.field">
                    <div class="tc-contrast__row">
                        <span class="tc-contrast__sw" :style="'background:'+w.foreground"></span>
                        <span x-text="w.label"></span>
                        <span class="ui-ltr" x-text="'(' + w.ratio + ':1)'"></span>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="w.suggestion"
                                @click="applyContrastFix(w)"
                                x-text="'{{ ___('Use') }} ' + w.suggestion"></button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Sticky action bar: always reachable, however long the settings list is. --}}
        <div class="tc-actions">
            <x-ui.button variant="secondary" size="sm" x-bind:disabled="!dirty" @click="saveDraft()">{{ ___('Save draft') }}</x-ui.button>
            <x-ui.button size="sm" icon="check" x-bind:disabled="saving" @click="publish()">{{ ___('Publish') }}</x-ui.button>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-menu-item__delete" x-show="hasDraft" @click="discard()">{{ ___('Discard draft') }}</button>
            <span class="tc-status" :class="err ? 'is-err' : ''">
                <span class="tc-dot" x-show="dirty" x-cloak></span>
                <span x-text="statusText"></span>
            </span>
        </div>
        </div>

        {{-- Preview --}}
        <div class="tc-preview-col">
        <section class="ui-card">
            <div class="tc-preview-bar">
                <span class="ui-overline">{{ ___('Live menu preview') }}</span>
                <div class="ui-chips" role="group" aria-label="{{ ___('Preview size') }}">
                    <button type="button" class="ui-chip" :aria-pressed="device==='mobile' ? 'true' : 'false'" @click="device='mobile'">{{ ___('Mobile') }}</button>
                    <button type="button" class="ui-chip" :aria-pressed="device==='desktop' ? 'true' : 'false'" @click="device='desktop'">{{ ___('Desktop') }}</button>
                </div>
                {{-- Language tabs come from the store's own menu languages, so a
                     single-language store never sees a pointless switch. --}}
                <div class="ui-chips" role="group" aria-label="{{ ___('Preview language') }}" x-show="languages.length > 1" x-cloak>
                    <template x-for="l in languages" :key="l.code">
                        <button type="button" class="ui-chip ui-ltr" :aria-pressed="previewLang === l.code ? 'true' : 'false'"
                                @click="setPreviewLang(l.code)" :title="l.name" x-text="l.code.toUpperCase()"></button>
                    </template>
                </div>
                {{-- WI-V4: click-to-edit — turn Select on, then click a section in the
                     preview to jump to its group in the editor. --}}
                <button type="button" class="ui-chip" :aria-pressed="selectMode ? 'true' : 'false'"
                        @click="toggleSelectMode()" :title="'{{ ___('Click a section in the preview to edit it') }}'">
                    <x-ui.icon name="pin" size="16" /> {{ ___('Select') }}
                </button>
                {{-- Splash tuning is blind without this: the shared splash layer
                     shows once per session (sessionStorage), so after the first
                     view the owner never sees their changes. Clear the gate and
                     reload the frame. --}}
                <button type="button" class="ui-chip" @click="replaySplash()"
                        :title="'{{ ___('Reload the preview with the intro splash') }}'">
                    <x-ui.icon name="sparkle" size="16" /> {{ ___('Replay splash') }}
                </button>
            </div>
            <div class="tc-preview-frame" :class="{'is-mobile': device==='mobile'}">
                <iframe x-ref="preview" :src="previewUrl"
                        title="{{ ___('Live menu preview') }}"></iframe>
            </div>
        </section>
        </div>
    </div>
    @endif

    {{-- WI-L4 — media library picker. Lists the store's existing images (the
         media-library endpoint, ownership-scoped); pick one to fill the field. --}}
    <div class="tc-media-backdrop" x-show="mediaOpen" x-cloak @click.self="mediaOpen=false">
        <div class="tc-media" role="dialog" aria-modal="true" aria-label="{{ ___('Media library') }}">
            <div class="tc-media__head">
                <strong>{{ ___('Media library') }}</strong>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="mediaOpen=false" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
            </div>
            <input class="ui-input" type="search" x-model="mediaQuery"
                   @input.debounce.300ms="loadMedia()" placeholder="{{ ___('Search images…') }}" aria-label="{{ ___('Search images…') }}">
            <template x-if="mediaLoading"><p class="ui-tc__muted">{{ ___('Loading…') }}</p></template>
            <template x-if="!mediaLoading && !mediaAssets.length">
                <x-ui.empty icon="upload" :title="___('No images in your library yet — upload from the Media page.')" />
            </template>
            <div class="tc-media-grid">
                <template x-for="a in mediaAssets" :key="a.id">
                    <button type="button" class="tc-media-item" @click="pickMedia(a)">
                        <img :src="a.url" :alt="a.name" loading="lazy">
                    </button>
                </template>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
function themeCustomizer() {
    return {
        activeId: '{{ $activeId }}',
        template: '', schema: {}, values: {}, published: {}, saving: false, msg: '', err: false,
        csrf: '{{ csrf_token() }}',
        previewUrl: '', device: 'desktop',
        search: '', activeGroup: 0, collapsed: {}, hasDraft: false, dirty: false, saveAgain: false, savePending: null,
        // Bounded value-stack. Every committed change pushes the PREVIOUS value, so
        // undo walks backwards through what the owner actually typed.
        undoStack: [], redoStack: [], coalesceTimer: null, coalesceKey: null,
        // Live preview: the frame announces itself once its bridge script runs.
        bridgeReady: false, previewTimer: null, contrastTimer: null,
        languages: [], previewLang: '',
        contrast: [],
        activePreset: '', presetModified: false, presetSnapshot: null,
        paletteBusy: false, paletteMsg: '',
        history: [], historyOpen: true, diffVersion: null, diffRows: [],
        copyTargets: [], copyOpen: false, copyTarget: '', toolMsg: '', selectMode: false,
        dragSection: null,
        textLocale: '',
        mediaOpen: false, mediaField: '', mediaAssets: [], mediaQuery: '', mediaLoading: false,
        // Theme Studio B0.8: image_picker stores the media FILENAME (the store's
        // own library); mediaUrls maps filename -> URL for the thumbnail.
        mediaMode: 'url', mediaUrls: {}, mediaUploading: '',
        // The store's own categories / items / branches for the picker fields,
        // resolved server-side for the active store (ownership already checked).
        pickers: @json($studioPickers), itemQuery: '',
        // Curated global palettes (WI-S4): each maps onto the five colour roles.
        palettes: [
            { id:'emerald', label:'Emerald', values:{'colors.primary':'#12b886','colors.accent':'#f59f00','colors.background':'#0c1512','colors.surface':'#16211c','colors.text':'#eef7f2'} },
            { id:'sunset',  label:'Sunset',  values:{'colors.primary':'#ff6b3d','colors.accent':'#ffd23f','colors.background':'#1a0f0c','colors.surface':'#261813','colors.text':'#f8ece4'} },
            { id:'ocean',   label:'Ocean',   values:{'colors.primary':'#1c7ed6','colors.accent':'#22b8cf','colors.background':'#f2f6fb','colors.surface':'#ffffff','colors.text':'#0f1a2a'} },
            { id:'grape',   label:'Grape',   values:{'colors.primary':'#7048e8','colors.accent':'#e64980','colors.background':'#120f1c','colors.surface':'#1c1728','colors.text':'#efeaf7'} },
            { id:'rose',    label:'Rose',    values:{'colors.primary':'#e64980','colors.accent':'#f76707','colors.background':'#fff5f8','colors.surface':'#ffffff','colors.text':'#2a0a16'} },
            { id:'forest',  label:'Forest',  values:{'colors.primary':'#2f9e44','colors.accent':'#c2a83e','colors.background':'#f3f7f2','colors.surface':'#ffffff','colors.text':'#0f2013'} },
            { id:'ink',     label:'Ink',     values:{'colors.primary':'#e8e8ea','colors.accent':'#9a9aa0','colors.background':'#0e0e0f','colors.surface':'#1a1a1c','colors.text':'#f5f5f6'} },
            { id:'sand',    label:'Sand',    values:{'colors.primary':'#d9a441','colors.accent':'#a0522d','colors.background':'#faf4ea','colors.surface':'#ffffff','colors.text':'#241a10'} },
        ],
        // Page View Mode B state
        pv: { source: 'data', pdf: null, pdfUrl: null, file: null },
        pvSaving: false, pvMsg: '', pvImporting: false, pvImportMsg: '',
        // Folio flip-book state (rev/folio-theme SPEC §3 contract)
        folio: { source: 'images', display: 'auto', hardcover: '0', anim: 'flip', tb_thumbs: '1', tb_zoom: '1', tb_fullscreen: '1', tb_download: '0', bg: '#1f2430', accent: '#6d28d9', texture: '', sound: '0', cta: '', cta_url: '', cta_label: '', books: [], page_books: {}, pages: [], insights: { total: 0, pages: {}, dropoff: null }, pdf: null, pdfUrl: null, file: null },
        folioSaving: false, folioMsg: '',
        folioBooksSaving: false, folioBooksMsg: '', folioPageIdx: {},
        folioRasterMsg: '',
        folioCoverSaving: false, folioCoverMsg: '',
        slugs: { @foreach($stores as $r) '{{ $r->id }}': @json($r->slug), @endforeach },

        // ── group icons ──────────────────────────────────────────────────────
        // The rail is built client-side from the schema, so the SVG for each
        // group glyph is rendered once in the page ([data-tc-glyphs], via
        // the shell nav-icon component: Tabler when vendored, kit icon otherwise) and
        // copied from there by name.
        // A group may name its own icon — the shared fragments do; otherwise the
        // group's English name from theme.json picks one.
        groupGlyph(name) {
            var box = document.querySelector('[data-tc-glyphs]');
            var el = box && (box.querySelector('[data-glyph="' + name + '"]') || box.querySelector('[data-glyph="settings-sliders"]'));
            return el ? el.innerHTML : '';
        },
        iconFor(group) {
            const glyphs = {
                palette: 'palette', pencil: 'pencil', sparkles: 'sparkles', search: 'search',
                images: 'picture', 'layout-dashboard': 'apps', 'list-checks': 'list-check',
                'sliders-horizontal': 'settings-sliders',
            };
            const byName = {
                colors: 'palette', colours: 'palette', branding: 'palette', brand: 'palette',
                tokens: 'palette', typography: 'pencil', fonts: 'pencil', texts: 'pencil',
                text: 'pencil', layout: 'layout-dashboard', content: 'list-checks',
                splash: 'sparkles', search: 'search', stories: 'images',
            };
            const name = group.icon || byName[(group.from || group.group || '').toLowerCase()] || 'sliders-horizontal';
            return glyphs[name] || 'settings-sliders';
        },

        // Phase 3: theme library state.
        libSearch: '', switching: '',
        libMatch(name) {
            const q = this.libSearch.trim().toLowerCase();
            return !q || name.includes(q);
        },
        async applyTemplate(folder, name) {
            if (folder === this.template || this.switching) return;
            if (this.dirty && !(await window.dashConfirm('{{ ___('You have unsaved changes. Leave them behind?') }}'))) return;
            this.switching = folder;
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/template', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ template: folder }),
                });
                if (!r.ok) throw new Error('switch failed');
                // New template → new schema + settings; per-template settings mean
                // the previous theme's customizations survive a switch back.
                this.dirty = false;
                await this.load();
                // The preview URL is unchanged (same slug), so setting previewUrl
                // again is a no-op — force the frame itself to reload.
                this.reloadPreview();
                if (window.dashToast) window.dashToast('success', '{{ ___('Template applied.') }}' + ' ' + name);
            } catch (e) {
                if (window.dashToast) window.dashToast('danger', '{{ ___('Could not switch the template.') }}');
            }
            this.switching = '';
        },

        init() {
            // Deep link from store settings: ?store=<id>#sections / #library jumps
            // to that panel once the schema has rendered.
            this.load().then(() => {
                const hash = (window.location.hash || '');
                if (hash === '#sections' || hash === '#library') {
                    this.$nextTick(() => this.focusGroup(hash.slice(1)));
                }
            });
            window.addEventListener('beforeunload', (e) => {
                if (!this.dirty) return;
                e.preventDefault();
                e.returnValue = '';
            });
            window.addEventListener('keydown', (e) => {
                if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== 'z') return;
                e.preventDefault();
                e.shiftKey ? this.redo() : this.undo();
            });
            window.addEventListener('message', (e) => {
                if (e.origin !== window.location.origin) return;
                if (!e.data) return;
                if (e.data.type === 'tc-ready') { this.bridgeReady = true; if (this.selectMode) this.sendSelectMode(true); }
                if (e.data.type === 'tc-select') this.jumpToSection(e.data.section);
            });
        },

        get storeUrl() {
            const slug = this.slugs[this.activeId];
            return slug ? '{{ url('/') }}/' + slug : '';
        },
        get canUndo() { return this.undoStack.length > 0; },
        get hasColorRoles() {
            // Palettes only make sense where the theme actually exposes the roles.
            const ids = (this.schema.settings||[]).flatMap((g) => (g.fields||[]).map((f) => f.id));
            return ids.includes('colors.primary');
        },
        get orderableSections() { return (this.schema.sections||[]).filter((s) => s.orderable); },
        // WI-L1 — a text field is localized when the schema says so AND the store
        // has more than one language to fill in.
        isLocalized(field) {
            return field.type === 'text' && !!(this.schema.texts && this.schema.texts.localized) && this.languages.length > 1;
        },
        localizedValue(id, locale) {
            const v = this.values[id];
            if (v && typeof v === 'object') return v[locale] ?? '';
            // A plain-string legacy value shows under the store's first language.
            return (locale === (this.languages[0] || {}).code) ? (v ?? '') : '';
        },
        localeDir(code) {
            const l = this.languages.find((x) => x.code === code);
            return l && l.rtl ? 'rtl' : 'auto';
        },
        // The section list in the owner's chosen order: any saved `sections._order`
        // first (WI-L2), then anything it does not mention, in schema order.
        get orderedSections() {
            const all = this.schema.sections || [];
            const order = this.values['sections._order'];
            if (!Array.isArray(order) || !order.length) return all;
            const byId = {}; all.forEach((s) => { byId[s.id] = s; });
            const out = [];
            order.forEach((id) => { if (byId[id]) { out.push(byId[id]); delete byId[id]; } });
            all.forEach((s) => { if (byId[s.id]) out.push(s); });
            return out;
        },
        get palettesWithSwatch() {
            return this.palettes.map((p) => Object.assign({}, p, {
                swatch: ['colors.primary','colors.accent','colors.background','colors.text'].map((k) => p.values[k]),
            }));
        },
        get canRedo() { return this.redoStack.length > 0; },
        get statusText() {
            if (this.msg) return this.msg;
            if (this.dirty) return '{{ ___('Unsaved changes') }}';
            return this.hasDraft ? '{{ ___('Draft saved — not published yet') }}' : '{{ ___('Everything is published') }}';
        },

        /**
         * Groups the panel should render: fragment includes expanded by the server,
         * fields whose `visible_if` fails removed, then the search filter. Hiding a
         * field is a rendering decision only — the server still validates its value.
         */
        get groups() {
            const q = this.search.trim().toLowerCase();
            return (this.schema.settings || []).map((g) => {
                const fields = (g.fields || []).filter((f) => {
                    if (!this.isVisible(f)) return false;
                    if (!q) return true;
                    return (f.label || '').toLowerCase().includes(q)
                        || (f.id || '').toLowerCase().includes(q)
                        || (g.group || '').toLowerCase().includes(q);
                });
                return Object.assign({}, g, { fields, icon: this.iconFor(g) });
            }).filter((g) => g.fields.length);
        },

        isVisible(field) {
            const cond = field.visible_if;
            if (!cond) return true;
            return Object.keys(cond).every((key) => {
                const actual = this.values[key];
                const want = cond[key];
                return Array.isArray(want) ? want.some((w) => w == actual) : want == actual;
            });
        },

        isChanged(field) {
            if (!(field.id in this.values)) return false;
            const now = this.values[field.id];
            if (now === null || now === undefined || now === '') return false;
            return now !== field.default;
        },
        changedIn(group) {
            return (group.fields || []).filter((f) => this.isChanged(f)).length || '';
        },

        isCollapsed(name) { return !!this.collapsed[name]; },
        toggleGroup(name) {
            this.collapsed[name] = !this.collapsed[name];
            try { localStorage.setItem(this.collapseKey(), JSON.stringify(this.collapsed)); } catch (e) {}
        },
        collapseKey() { return 'tc-collapsed-' + this.activeId; },
        focusGroup(gi) {
            this.activeGroup = gi;
            const el = document.getElementById('tc-group-' + gi);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        /** Commit a field change, remembering the previous value for undo. */
        set(id, value) {
            const prev = this.values[id];
            if (prev === value) return;
            // Keystrokes in one field coalesce into a single undo step (300ms),
            // so undo does not walk back one character at a time.
            const coalescing = this.coalesceKey === id && this.coalesceTimer;
            if (!coalescing) {
                this.undoStack.push({ id, value: prev });
                if (this.undoStack.length > 50) this.undoStack.shift();
                this.redoStack = [];
            }
            this.coalesceKey = id;
            clearTimeout(this.coalesceTimer);
            this.coalesceTimer = setTimeout(() => { this.coalesceTimer = null; this.coalesceKey = null; }, 300);

            this.values[id] = value;
            this.dirty = true;
            this.msg = '';
            if (this.activePreset && !this.presetModified) this.presetModified = true;
            this.pushPreview(id);
        },

        // WI-L1 — write one locale of a localized text field, keeping the others.
        setLocalized(id, locale, value) {
            const current = (this.values[id] && typeof this.values[id] === 'object') ? this.values[id] : {};
            const next = Object.assign({}, current, { [locale]: value });
            this.set(id, next);
        },

        // ── media library picker (WI-L4) ────────────────────────────────────
        openMedia(fieldId, mode) { this.mediaField = fieldId; this.mediaMode = mode || 'url'; this.mediaOpen = true; this.mediaQuery = ''; this.loadMedia(); },
        async loadMedia() {
            this.mediaLoading = true;
            try {
                const url = '{{ route('media.list') }}?store_id=' + this.activeId + '&q=' + encodeURIComponent(this.mediaQuery);
                const r = await fetch(url, { headers: { Accept: 'application/json' } });
                this.mediaAssets = r.ok ? ((await r.json()).assets || []) : [];
            } catch (e) { this.mediaAssets = []; }
            this.rememberMediaUrls(this.mediaAssets);
            this.mediaLoading = false;
        },
        pickMedia(asset) {
            // Store the asset URL — the library owns the file (MediaReferences keeps
            // it ref-counted), the customizer only points at it. Theme Studio
            // image_picker fields store the bare filename instead (validated
            // server-side against this store's media library).
            this.set(this.mediaField, this.mediaMode === 'filename' ? asset.filename : asset.url);
            this.mediaOpen = false;
        },
        rememberMediaUrls(assets) {
            const next = Object.assign({}, this.mediaUrls);
            (assets || []).forEach(a => { if (a.filename && a.url) next[a.filename] = a.url; });
            this.mediaUrls = next;
        },
        // B0.8: thumbnails for image_picker values already saved — one library
        // listing when the loaded schema has any image_picker field.
        async primeMediaUrls() {
            const has = (this.schema.settings || []).some(g => (g.fields || []).some(f => f.type === 'image_picker'));
            if (!has) return;
            try {
                const r = await fetch('{{ route('media.list') }}?store_id=' + this.activeId, { headers: { Accept: 'application/json' } });
                if (r.ok) this.rememberMediaUrls((await r.json()).assets || []);
            } catch (e) { /* thumbnails are cosmetic */ }
        },
        // B0.8: upload straight into the store's media library (the existing
        // owner-authenticated media.store endpoint), then pick the new file.
        async uploadMediaFor(fieldId, ev) {
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = '';
            if (!file) return;
            this.mediaUploading = fieldId;
            try {
                const fd = new FormData();
                fd.append('store_id', this.activeId);
                fd.append('folder', 'theme');
                fd.append('images[]', file);
                const r = await fetch('{{ route('media.store') }}', {
                    method: 'POST', body: fd,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const d = r.ok ? await r.json() : {};
                const a = (d.assets || [])[0];
                if (a) { this.rememberMediaUrls([a]); this.set(fieldId, a.filename); }
                else { this.msg = '{{ ___('Upload failed.') }}'; this.err = true; }
            } catch (e) { this.msg = '{{ ___('Upload failed.') }}'; this.err = true; }
            this.mediaUploading = '';
        },

        // ── Theme Studio B0.8 field helpers ─────────────────────────────────
        urlOk(v) { return !v || /^https?:\/\/[^\s\/?#]+/i.test(v) || /^\/(?!\/)/.test(v); },
        tokenLabel(t) {
            return ({ primary: '{{ ___('Primary') }}', background: '{{ ___('Background') }}', surface: '{{ ___('Surface') }}',
                      text: '{{ ___('Text') }}', accent: '{{ ___('Accent') }}' })[t] || t;
        },
        pickerMatches(kind) {
            const q = this.itemQuery.trim().toLowerCase();
            const rows = this.pickers[kind] || [];
            return q ? rows.filter(r => r.name.toLowerCase().includes(q)) : rows;
        },
        toggleListItem(f, id, on) {
            const cur = Array.isArray(this.values[f.id]) ? this.values[f.id].slice() : [];
            const at = cur.indexOf(id);
            if (on && at === -1 && cur.length < (f.max || 24)) cur.push(id);
            if (!on && at !== -1) cur.splice(at, 1);
            this.set(f.id, cur);
        },
        // Rich text: the browser's own editing commands; whatever markup they
        // produce is rebuilt from the allow-list on save (RichText::clean).
        richCmd(cmd) { document.execCommand(cmd, false, null); this.syncRich(); },
        async richLink() {
            // The dashboard's own prompt (native prompt() is banned — DashboardNativeDialogRatchetTest).
            // It is async and takes focus, so keep the editor selection and put it back first.
            const sel = window.getSelection();
            const range = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
            const href = await window.dashPrompt({ title: '{{ ___('Link address') }}', value: 'https://' });
            if (!href || !this.urlOk(href)) return;
            if (range) { sel.removeAllRanges(); sel.addRange(range); }
            document.execCommand('createLink', false, href);
            this.syncRich();
        },
        syncRich() {
            const el = document.activeElement;
            if (el && el.classList.contains('tc-richtext__area')) el.dispatchEvent(new Event('input', { bubbles: true }));
        },

        // ── live preview (WI-U3) ─────────────────────────────────────────────
        // Token and text changes are patched into the preview frame as they are
        // typed. Anything structural — a section toggled, a splash style swapped —
        // changes the MARKUP, which no patch can express, so those debounce into a
        // reload instead.
        pushPreview(id) {
            if (id.startsWith('colors.')) {
                clearTimeout(this.contrastTimer);
                this.contrastTimer = setTimeout(() => this.checkContrast(), 350);
            }
            const structural = id.startsWith('sections.') || id === 'splash.enabled' || id === 'splash.style';
            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => {
                structural ? this.reloadPreview() : this.patchPreview();
            }, structural ? 400 : 150);
        },

        async patchPreview() {
            if (!this.bridgeReady || !this.$refs.preview) return;
            // The derived ramp is computed by the same server-side Tokens service
            // that renders the storefront normally — reimplementing the mix maths
            // here is how the preview and the published page drift apart.
            let vars = {};
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/tokens', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ values: this.values }),
                });
                if (!r.ok) return;
                vars = (await r.json()).vars || {};
            } catch (e) { return; }

            const texts = {};
            Object.keys(this.values).forEach((k) => {
                if (!k.startsWith('texts.') && !k.startsWith('splash.')) return;
                let v = this.values[k];
                // WI-L1 locale maps: resolve to the previewed language here —
                // the bridge (rightly) refuses non-strings.
                if (v && typeof v === 'object') {
                    v = v[this.previewLang]
                        ?? v[(this.languages[0] || {}).code]
                        ?? Object.values(v).find((x) => typeof x === 'string' && x !== '')
                        ?? '';
                }
                texts[k] = v;
            });

            this.$refs.preview.contentWindow.postMessage(
                { type: 'tc-patch', vars, texts }, window.location.origin
            );
        },
        undo() {
            const step = this.undoStack.pop();
            if (!step) return;
            this.redoStack.push({ id: step.id, value: this.values[step.id] });
            this.values[step.id] = step.value;
            this.dirty = true;
        },
        redo() {
            const step = this.redoStack.pop();
            if (!step) return;
            this.undoStack.push({ id: step.id, value: this.values[step.id] });
            this.values[step.id] = step.value;
            this.dirty = true;
        },
        resetField(field) { this.set(field.id, field.default); },

        applyContrastFix(warning) {
            if (!warning.suggestion) return;
            // The fix nudges the FOREGROUND role (the text/label), which is what
            // restores legibility without repainting the whole page.
            this.set(warning.field, warning.suggestion);
            this.contrast = this.contrast.filter((w) => w !== warning);
        },

        // ── presets (WI-S1) ─────────────────────────────────────────────────
        // Hovering shows a preset in the preview without committing anything: the
        // token vars are patched via the U3 channel, and the current values are
        // snapshotted so the preview can be put back on mouse-out.
        hoverPreset(preset) {
            if (!this.bridgeReady || this.activePreset === preset.id) return;
            if (!this.presetSnapshot) this.presetSnapshot = Object.assign({}, this.values);
            this.previewValues(Object.assign({}, this.values, preset.values || {}));
        },
        unhoverPreset() {
            if (!this.presetSnapshot) return;
            const restore = this.presetSnapshot; this.presetSnapshot = null;
            this.previewValues(restore);
        },

        // Push an arbitrary value set into the preview frame (hover only — never
        // persisted). Reuses the same server-computed token map as patchPreview.
        async previewValues(values) {
            if (!this.bridgeReady || !this.$refs.preview) return;
            let vars = {};
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/tokens', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ values }),
                });
                if (!r.ok) return;
                vars = (await r.json()).vars || {};
            } catch (e) { return; }
            const texts = {};
            Object.keys(values).forEach((k) => {
                if (k.startsWith('texts.') || k.startsWith('splash.')) texts[k] = values[k];
            });
            this.$refs.preview.contentWindow.postMessage({ type: 'tc-patch', vars, texts }, window.location.origin);
        },

        async applyPreset(preset) {
            if (this.dirty && !(await window.dashConfirm('{{ ___('Apply this preset over your unsaved changes?') }}'))) return;
            this.presetSnapshot = null;
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/preset', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                body: JSON.stringify({ preset: preset.id }),
            });
            if (!r.ok) { this.err = true; this.msg = '{{ ___('Could not apply that preset.') }}'; return; }
            const d = await r.json();
            // The preset is staged as the draft; adopt its values and keep editing.
            this.values = Object.assign({}, this.values, d.values || {});
            this.activePreset = preset.id;
            this.presetModified = false;
            this.hasDraft = true; this.dirty = false;
            this.undoStack = []; this.redoStack = [];
            this.msg = '{{ ___('Preset applied — customize from here, then publish.') }}';
            this.reloadPreview();
        },

        // ── palettes + logo (WI-S4) ─────────────────────────────────────────
        hoverPalette(pal) {
            if (!this.bridgeReady) return;
            if (!this.presetSnapshot) this.presetSnapshot = Object.assign({}, this.values);
            this.previewValues(Object.assign({}, this.values, pal.values));
        },
        applyPalette(pal) { this.applyColorValues(pal.values); },

        // ── Splash v2.1 — curated splash looks ────────────────────────────
        // Each preset stages a full splash.* value set through set(), so every
        // change is undo-able and marks the editor dirty; the owner previews it
        // with Replay splash and keeps editing from there. Colours '' = brand.
        splashPresets: [
            { id: 'minimal', label: '{{ ___('Minimal logo') }}',
              mock: { bg: 'linear-gradient(135deg,#7c5cff,#a78bfa)', fg: '#fff', logoR: '10px', cta: false },
              values: { 'splash.style': 'shared', 'splash.background': '', 'splash.background_image': '', 'splash.text_color': '',
                        'splash.animation': 'fade', 'splash.logo_size': 'm', 'splash.logo_shape': 'round',
                        'splash.cta_label': '', 'splash.frequency': 'session', 'splash.duration': 2000 } },
            { id: 'bold', label: '{{ ___('Bold brand') }}',
              mock: { bg: 'linear-gradient(135deg,#7c5cff,#5b3df0)', fg: '#fff', logoR: '6px', cta: true },
              values: { 'splash.style': 'shared', 'splash.background': '', 'splash.background_image': '', 'splash.text_color': '',
                        'splash.animation': 'pop', 'splash.logo_size': 'l', 'splash.logo_shape': 'rounded',
                        'splash.cta_label': '{{ ___('View menu') }}', 'splash.frequency': 'session', 'splash.duration': 2300 } },
            { id: 'photo', label: '{{ ___('Photo backdrop') }}',
              mock: { bg: 'linear-gradient(135deg,#3f3f46,#18181b)', fg: '#fff', logoR: '10px', cta: true },
              values: { 'splash.style': 'shared', 'splash.background': '', 'splash.text_color': '#FFFFFF',
                        'splash.animation': 'fade', 'splash.logo_size': 's', 'splash.logo_shape': 'round',
                        'splash.cta_label': '{{ ___('View menu') }}', 'splash.frequency': 'session', 'splash.duration': 2800 } },
                        // splash.background_image deliberately untouched: the owner
                        // picks the photo; the rest of the look is staged around it.
            { id: 'luxe', label: '{{ ___('Dark luxe') }}',
              mock: { bg: 'linear-gradient(135deg,#0d0d0f,#1c1917)', fg: '#e7d8b0', logoR: '2px', cta: false },
              values: { 'splash.style': 'shared', 'splash.background': '#0D0D0F', 'splash.background_image': '', 'splash.text_color': '#E7D8B0',
                        'splash.animation': 'rise', 'splash.logo_size': 'm', 'splash.logo_shape': 'square',
                        'splash.cta_label': '', 'splash.frequency': 'session', 'splash.duration': 2600 } },
        ],
        isSplashGroup(group) {
            return (group.fields || []).some((f) => f.id === 'splash.enabled');
        },
        applySplashPreset(p) {
            Object.keys(p.values).forEach((k) => this.set(k, p.values[k]));
            this.set('splash.enabled', true);
            if (window.dashToast) window.dashToast('info', '{{ ___('Splash look staged — Save draft, then Replay splash to see it.') }}');
        },

        // Swap primary <-> accent, so a palette can be tried both ways round.
        shuffleRoles() {
            const p = this.values['colors.primary'], a = this.values['colors.accent'];
            if (p === undefined && a === undefined) return;
            this.set('colors.primary', a);
            this.set('colors.accent', p);
        },

        async paletteFromLogo() {
            this.paletteBusy = true; this.paletteMsg = '';
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/palette-from-logo', {
                    method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                });
                const d = await r.json().catch(() => ({}));
                if (r.ok && d.values) { this.applyColorValues(d.values); this.paletteMsg = ''; }
                else { this.paletteMsg = d.error || '{{ ___('Could not read a colour from your logo.') }}'; }
            } catch (e) { this.paletteMsg = '{{ ___('Could not read a colour from your logo.') }}'; }
            this.paletteBusy = false;
        },

        // Apply a set of colour values as a live edit (they go through set(), so
        // undo, the dirty guard, contrast and the preview all react normally).
        applyColorValues(values) {
            this.presetSnapshot = null;
            this.activePreset = '';
            Object.keys(values).forEach((k) => this.set(k, values[k]));
        },

        // ── version history (WI-V1) ─────────────────────────────────────────
        async loadHistory() {
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/history', { headers: { Accept: 'application/json' } });
                if (r.ok) this.history = (await r.json()).history || [];
            } catch (e) { this.history = []; }
        },
        versionLabel(v) {
            const map = {
                'publish': '{{ ___('Published') }}',
                'revert': '{{ ___('Reverted') }}',
                'pre-v2': '{{ ___('Before the redesign') }}',
            };
            return map[v.label] || v.label;
        },
        relTime(iso) {
            const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
            if (secs < 90) return '{{ ___('just now') }}';
            if (secs < 5400) return Math.round(secs / 60) + ' {{ ___('min ago') }}';
            if (secs < 129600) return Math.round(secs / 3600) + ' {{ ___('h ago') }}';
            return Math.round(secs / 86400) + ' {{ ___('d ago') }}';
        },
        async showDiff(v) {
            if (this.diffVersion === v.id) { this.diffVersion = null; return; }
            this.diffVersion = v.id; this.diffRows = [];
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/version/' + v.id + '/diff', { headers: { Accept: 'application/json' } });
                if (r.ok) this.diffRows = (await r.json()).diff || [];
            } catch (e) { this.diffRows = []; }
        },
        async revert(v) {
            if (!(await window.dashConfirm('{{ ___('Restore this version? It becomes a new entry in the history.') }}'))) return;
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/version/' + v.id + '/revert', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
            });
            if (!r.ok) { this.err = true; this.msg = '{{ ___('Could not restore that version.') }}'; return; }
            await this.load();
            this.msg = '{{ ___('Version restored.') }}';
            this.reloadPreview();
        },

        // ── export / import / copy (WI-V2/V3) ───────────────────────────────
        async loadCopyTargets() {
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/copy-targets', { headers: { Accept: 'application/json' } });
                this.copyTargets = r.ok ? ((await r.json()).targets || []) : [];
            } catch (e) { this.copyTargets = []; }
        },
        async importFile(event) {
            const file = event.target.files[0]; event.target.value = '';
            if (!file) return;
            const fd = new FormData(); fd.append('file', file);
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/import', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' }, body: fd,
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok) { this.toolMsg = d.error || '{{ ___('That file could not be imported.') }}'; return; }
            this.values = Object.assign({}, this.values, d.values || {});
            this.hasDraft = true; this.dirty = false; this.activePreset = '';
            const dropped = (d.dropped || []).length;
            this.toolMsg = dropped
                ? '{{ ___('Imported as a draft.') }} ' + dropped + ' {{ ___('setting(s) did not apply.') }}'
                : '{{ ___('Imported as a draft — review it, then publish.') }}';
            this.reloadPreview();
        },
        openCopy() { this.copyOpen = !this.copyOpen; },
        async copyToStore() {
            if (!this.copyTarget) return;
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/copy-to', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                body: JSON.stringify({ target: this.copyTarget }),
            });
            const d = await r.json().catch(() => ({}));
            this.toolMsg = r.ok
                ? '{{ ___('Applied to the other store as a draft.') }}'
                : (d.error || '{{ ___('Could not apply to that store.') }}');
            if (r.ok) { this.copyOpen = false; this.copyTarget = ''; }
        },

        // ── click-to-edit (WI-V4) ───────────────────────────────────────────
        toggleSelectMode() { this.selectMode = !this.selectMode; this.sendSelectMode(this.selectMode); },
        sendSelectMode(on) {
            if (this.$refs.preview) this.$refs.preview.contentWindow.postMessage({ type: 'tc-select-mode', on }, window.location.origin);
        },
        jumpToSection(sectionId) {
            this.selectMode = false; this.sendSelectMode(false);
            // A section maps to the Sections group (its visibility) and, where the
            // theme has a matching settings group, to that. Scroll to the Sections
            // panel and flash the row for the clicked section.
            const el = document.getElementById('tc-group-sections');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.add('tc-flash');
                setTimeout(() => el.classList.remove('tc-flash'), 1200);
            }
        },

        // ── section reorder (WI-L2) ─────────────────────────────────────────
        commitOrder(list) {
            // Persist the full section order (orderable ones can move; fixed ones
            // keep their schema slot). theme_sections() reads this key.
            this.set('sections._order', list.map((s) => s.id));
        },
        moveSection(index, delta) {
            const list = this.orderedSections.slice();
            const target = index + delta;
            if (target < 0 || target >= list.length) return;
            const [item] = list.splice(index, 1);
            list.splice(target, 0, item);
            this.commitOrder(list);
        },
        dropSection(index) {
            if (this.dragSection === null || this.dragSection === index) { this.dragSection = null; return; }
            const list = this.orderedSections.slice();
            const [item] = list.splice(this.dragSection, 1);
            list.splice(index, 0, item);
            this.dragSection = null;
            this.commitOrder(list);
        },

        async checkContrast() {
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/contrast', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ values: this.values }),
                });
                if (r.ok) this.contrast = (await r.json()).contrast || [];
            } catch (e) { /* the banner is advisory; a failed check just leaves it as-is */ }
        },

        async switchStore(event) {
            if (this.dirty && !(await window.dashConfirm('{{ ___('You have unsaved changes. Leave them behind?') }}'))) {
                this.activeId = event.target.dataset.previous || this.activeId;
                return;
            }
            this.dirty = false;
            event.target.form.submit();
        },

        async load() {
            if (!this.activeId) return;
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId, { headers: { Accept: 'application/json' } });
            const d = await r.json();
            this.template = d.template || '';
            this.schema = d.schema || {};
            this.published = d.settings || {};
            this.values = Object.assign({}, d.settings || {}, d.draft || {});
            this.hasDraft = !!d.draft;
            this.dirty = false;
            this.contrast = [];
            this.activePreset = ''; this.presetModified = false; this.presetSnapshot = null;
            this.undoStack = []; this.redoStack = [];
            this.msg = ''; this.err = false;
            try { this.collapsed = JSON.parse(localStorage.getItem(this.collapseKey()) || '{}'); } catch (e) { this.collapsed = {}; }
            this.languages = d.languages || [];
            this.previewLang = (this.languages[0] || {}).code || '';
            this.textLocale = this.previewLang;
            this.pv = Object.assign({ source: 'data', pdf: null, pdfUrl: null, file: null }, d.pv || {});
            this.pvMsg = ''; this.pvImportMsg = '';
            this.folio = Object.assign({ source: 'images', display: 'auto', hardcover: '0', anim: 'flip', tb_thumbs: '1', tb_zoom: '1', tb_fullscreen: '1', tb_download: '0', bg: '#1f2430', accent: '#6d28d9', texture: '', sound: '0', cta: '', cta_url: '', cta_label: '', books: [], page_books: {}, pages: [], insights: { total: 0, pages: {}, dropoff: null }, pdf: null, pdfUrl: null, file: null }, d.folio || {});
            this.folioMsg = ''; this.folioBooksMsg = '';
            this.syncFolioPageIdx();
            this.diffVersion = null; this.diffRows = [];
            this.loadHistory();
            this.loadCopyTargets();
            this.primeMediaUrls();
            this.copyOpen = false; this.copyTarget = ''; this.toolMsg = '';
            this.refreshPreview();
        },

        async savePv() {
            this.pvSaving = true; this.pvMsg = '';
            const fd = new FormData();
            fd.append('source', this.pv.source);
            if (this.pv.file) fd.append('pdf', this.pv.file);
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/pageview-source', {
                    method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' }, body: fd,
                });
                const d = await r.json().catch(() => ({}));
                if (r.ok) {
                    this.pv = Object.assign(this.pv, d.pv || {}, { file: null });
                    this.pvMsg = '{{ ___('Saved. Your Page View now shows this source.') }}';
                    this.reloadPreview();
                } else {
                    this.pvMsg = '{{ ___('Upload failed — check the file is a PDF under 20 MB.') }}';
                }
            } catch (e) { this.pvMsg = '{{ ___('Upload failed — check the file is a PDF under 20 MB.') }}'; }
            this.pvSaving = false;
        },

        // Folio flip-book: upload the PDF (if picked) then persist the §3 display
        // options. Both writes land in store_options, so the storefront shows
        // exactly what was saved here (publish-parity — no draft layer).
        async saveFolio() {
            this.folioSaving = true; this.folioMsg = '';
            try {
                if (this.folio.file) {
                    const pdfFile = this.folio.file;
                    const fd = new FormData();
                    fd.append('pdf', pdfFile);
                    fd.append('source', this.folio.source);
                    const up = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/folio-pdf', {
                        method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' }, body: fd,
                    });
                    const ud = await up.json().catch(() => ({}));
                    if (!up.ok || !ud.success) throw new Error('upload');
                    this.folio = Object.assign(this.folio, ud.folio || {}, { file: null });
                    // FP B5: pre-raster the PDF in this browser to page images
                    // (best-effort — a failure leaves the legacy pdf.js path live).
                    try { await this.rasterizeFolioPdf(pdfFile); }
                    catch (e) { this.folioRasterMsg = '{{ ___('Could not pre-render pages — the flip book still works.') }}'; }
                }
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/folio-options', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({
                        source: this.folio.source, display: this.folio.display, hardcover: this.folio.hardcover,
                        anim: this.folio.anim, tb_thumbs: this.folio.tb_thumbs, tb_zoom: this.folio.tb_zoom,
                        tb_fullscreen: this.folio.tb_fullscreen, tb_download: this.folio.tb_download,
                        bg: this.folio.bg, accent: this.folio.accent,
                        texture: this.folio.texture, sound: this.folio.sound,
                        cta: this.folio.cta, cta_url: this.folio.cta_url, cta_label: this.folio.cta_label,
                    }),
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error('save');
                this.folio = Object.assign(this.folio, d.folio || {}, { file: null });
                this.folioMsg = '{{ ___('Saved. Your flip book now uses these settings.') }}';
                this.reloadPreview();
            } catch (e) {
                this.folioMsg = '{{ ___('Save failed — check the values and try again.') }}';
            }
            this.folioSaving = false;
        },

        // FP B3: rebuild the client page→book-index map from the server's
        // {imageMenuId: bookKey} map, matching each key to its book position.
        syncFolioPageIdx() {
            const idx = {};
            const pb = this.folio.page_books || {};
            Object.keys(pb).forEach(id => {
                const at = (this.folio.books || []).findIndex(b => b.key === pb[id]);
                if (at >= 0) idx[id] = at;
            });
            this.folioPageIdx = idx;
        },

        // A page belongs to at most one book: ticking book i tags it there,
        // unticking clears it only when it was tagged to that same book.
        setFolioPage(id, i, checked) {
            if (checked) this.folioPageIdx[id] = i;
            else if (this.folioPageIdx[id] === i) delete this.folioPageIdx[id];
        },

        // FP B3: persist books (server slugs the keys) + page tags (sent as
        // book indices so re-slugging can never orphan a tag).
        async saveFolioBooks() {
            this.folioBooksSaving = true; this.folioBooksMsg = '';
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/folio-books', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({
                        books: (this.folio.books || []).map(b => ({ label: b.label, start: b.start, end: b.end })),
                        page_books: this.folioPageIdx,
                    }),
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error('save');
                this.folio = Object.assign(this.folio, d.folio || {});
                this.syncFolioPageIdx();
                this.folioBooksMsg = '{{ ___('Saved. Your books are live.') }}';
                this.reloadPreview();
            } catch (e) {
                this.folioBooksMsg = '{{ ___('Save failed — check the times and try again.') }}';
            }
            this.folioBooksSaving = false;
        },

        // FP B5: render the just-uploaded PDF to page images IN THIS BROWSER
        // (vendored pdf.js, scale 2) → JPEG .85 → POST to folio-pdf-pages, so
        // diners get crisp images with no pdf.js on their device.
        async rasterizeFolioPdf(file) {
            this.folioRasterMsg = '{{ ___('Preparing pages…') }}';
            await new Promise((res, rej) => {
                if (window.renderFilesToImages) return res();
                const s = document.createElement('script');
                s.src = '{{ asset('assets/global/pdfjs/render-pdf.js') }}';
                s.onload = res; s.onerror = () => rej(new Error('pdfjs'));
                document.head.appendChild(s);
            });
            const pngs = await window.renderFilesToImages([file], {
                maxPages: 60,
                onProgress: (p, n) => { this.folioRasterMsg = '{{ ___('Rendering page') }} ' + p + '/' + n; },
            });
            if (!pngs.length) throw new Error('empty');
            const fd = new FormData();
            for (let i = 0; i < pngs.length; i++) {
                const jpeg = await this.blobToJpeg(pngs[i]);
                fd.append('pages[]', jpeg, 'p' + (i + 1) + '.jpg');
            }
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/folio-pdf-pages', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' }, body: fd,
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok || !d.success) throw new Error('pages');
            this.folioRasterMsg = '{{ ___('Pages ready — guests see crisp images.') }}';
        },

        // FP B8: top-10 page-view bars (desc by views) for the Insights panel.
        folioInsightRows() {
            const pages = (this.folio.insights && this.folio.insights.pages) || {};
            const rows = Object.keys(pages).map(p => ({ page: +p, views: pages[p] }));
            rows.sort((a, b) => b.views - a.views);
            const top = rows.slice(0, 10);
            const max = top.reduce((m, r) => Math.max(m, r.views), 0) || 1;
            top.forEach(r => { r.pct = Math.round(r.views / max * 100); });
            return top;
        },

        // FP B7: generate/replace the branded cover page server-side.
        async generateFolioCover() {
            this.folioCoverSaving = true; this.folioCoverMsg = '';
            try {
                const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/folio-cover', {
                    method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok || !d.success) throw new Error('cover');
                this.folioCoverMsg = '{{ ___('Cover page created — see it in your images.') }}';
                this.reloadPreview();
            } catch (e) {
                this.folioCoverMsg = '{{ ___('Could not generate the cover — try again.') }}';
            }
            this.folioCoverSaving = false;
        },

        // Re-encode a rendered PNG blob to JPEG .85 (smaller, under the 2 MB cap).
        blobToJpeg(blob) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    const c = document.createElement('canvas');
                    c.width = img.naturalWidth; c.height = img.naturalHeight;
                    c.getContext('2d').drawImage(img, 0, 0);
                    c.toBlob(b => { URL.revokeObjectURL(img.src); resolve(b || blob); }, 'image/jpeg', 0.85);
                };
                img.onerror = () => { URL.revokeObjectURL(img.src); resolve(blob); };
                img.src = URL.createObjectURL(blob);
            });
        },

        // Menu-scan seam: render the display PDF to page images and feed each to the
        // existing AI extractor (restaurants.menu.aiGenerate → aiSave). Live model
        // calls are metered and require a provider key.
        async importFromPdf() {
            if (this.pvImporting) return;
            this.pvImporting = true; this.pvImportMsg = '{{ ___('Reading your menu…') }}';
            try {
                await new Promise((res, rej) => {
                    if (window.renderFilesToImages) return res();
                    const s = document.createElement('script');
                    s.src = '{{ asset('assets/global/pdfjs/render-pdf.js') }}';
                    s.onload = res; s.onerror = () => rej(new Error('pdfjs'));
                    document.head.appendChild(s);
                });
                const file = this.pv.file || new File(
                    [await (await fetch(this.pv.pdfUrl)).blob()], 'menu.pdf', { type: 'application/pdf' });
                const images = await window.renderFilesToImages([file], {
                    maxPages: 20,
                    onProgress: (p, n) => { this.pvImportMsg = '{{ ___('Reading page') }} ' + p + '/' + n; },
                });
                const base = '{{ url('restaurants') }}/' + this.activeId + '/menu';
                let drafts = [];
                for (let i = 0; i < images.length; i++) {
                    const fd = new FormData();
                    fd.append('image', images[i], 'page-' + (i + 1) + '.png');
                    const r = await fetch(base + '/ai-generate', {
                        method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' }, body: fd,
                    });
                    const d = await r.json().catch(() => ({}));
                    if (d && d.success && d.data) drafts.push(d.data);
                }
                if (!drafts.length) { this.pvImportMsg = '{{ ___('Could not read items from this PDF.') }}'; this.pvImporting = false; return; }
                // Merge multi-page drafts server-side into one reviewed draft, then save.
                let merged = drafts[0];
                if (drafts.length > 1) {
                    const m = await fetch(base + '/ai-merge', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                        body: JSON.stringify({ drafts: drafts }),
                    });
                    const md = await m.json().catch(() => ({}));
                    if (!m.ok || !md.data) { this.pvImportMsg = '{{ ___('Could not read items from this PDF.') }}'; this.pvImporting = false; return; }
                    merged = md.data;
                }
                const save = await fetch(base + '/ai-save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                    body: JSON.stringify({ categories: (merged && merged.categories) || [] }),
                });
                this.pvImportMsg = save.ok
                    ? '{{ ___('Items imported to your menu.') }}'
                    : '{{ ___('Could not read items from this PDF.') }}';
            } catch (e) {
                this.pvImportMsg = '{{ ___('Could not read items from this PDF.') }}';
            }
            this.pvImporting = false;
        },

        refreshPreview() {
            // owner sees the DRAFT via ?preview_theme=1 (the layout applies draft for the owner)
            let url = '{{ url('/') }}/' + (this.slugs[this.activeId] || '') + '?preview_theme=1';
            // preview_lang is request-scoped on purpose: switching the preview to
            // Arabic must not flip the owner's own dashboard, which is what any of
            // the normal session-backed switchers would do.
            if (this.previewLang) url += '&preview_lang=' + encodeURIComponent(this.previewLang);
            this.previewUrl = url;
        },
        setPreviewLang(code) {
            if (this.previewLang === code) return;
            this.previewLang = code;
            this.bridgeReady = false;
            this.refreshPreview();
        },
        reloadPreview() {
            // The bridge re-announces itself after the reload; until it does, patches
            // are skipped rather than shouted into a frame that cannot hear them.
            this.bridgeReady = false;
            if (this.$refs.preview) this.$refs.preview.contentWindow.location.reload();
        },
        replaySplash() {
            // The shared splash layer session-gates itself under mb_splash_<storeId>;
            // clear just that key (never sessionStorage.clear() — carts live there)
            // and reload so the splash plays again. Theme-style splashes replay on
            // every load anyway, so the reload alone covers them.
            try {
                // Splash v2: 'daily' / 'once' frequencies gate via localStorage
                // under the same key — clear both stores.
                this.$refs.preview.contentWindow.sessionStorage.removeItem('mb_splash_' + this.activeId);
                this.$refs.preview.contentWindow.localStorage.removeItem('mb_splash_' + this.activeId);
            } catch (e) { /* cross-origin or storage denied — reload still helps the theme style */ }
            // Re-assign src rather than contentWindow.reload(): a reload() that
            // races the frame's FIRST navigation wedges it on about:blank (the
            // src attribute is unchanged, so the binding never re-fires).
            this.bridgeReady = false;
            if (this.$refs.preview && this.previewUrl) this.$refs.preview.src = this.previewUrl;
        },

        // Save draft. A save asked for while one is in flight (a second click, publish(), an edit made meanwhile) is
        // never dropped: it runs again with the latest values once the first answer is back, and the editor stays
        // dirty until the server holds exactly what is on screen. A throttled (429), failed (5xx) or lost request is
        // retried (honouring Retry-After) and otherwise REPORTED — the edits stay dirty, never silently discarded.
        // before: the answer to the first save set dirty=false over edits made while it was in flight, the second
        // click was swallowed by the disabled button, and any non-422 failure read "Some values were invalid."
        saveDraft() {
            if (this.saving) { this.saveAgain = true; return this.savePending; }
            this.savePending = this.runSaveDraft();
            return this.savePending;
        },

        async runSaveDraft() {
            this.saving = true; this.msg = ''; this.err = false;
            try {
                for (let round = 0; round < 5; round++) {
                    this.saveAgain = false;
                    const sent = JSON.stringify(this.values);
                    const r = await this.postDraft(sent);
                    if (!r || !r.ok) { await this.reportSaveFailure(r); return; }
                    if (!this.saveAgain && JSON.stringify(this.values) === sent) {
                        this.dirty = false;
                        break;
                    }
                }
                this.msg = '{{ ___('Draft saved.') }}';
                this.hasDraft = true;
                this.reloadPreview();
            } finally {
                this.saving = false;
                this.savePending = null;
            }
        },

        async postDraft(valuesJson) {
            for (let attempt = 0; ; attempt++) {
                let r = null;
                try {
                    r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/draft', {
                        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                        body: '{"values":' + valuesJson + '}',
                    });
                } catch (e) { r = null; }
                const transient = !r || r.status === 429 || r.status >= 500;
                if (!transient || attempt >= 2) return r;
                const after = r ? parseInt(r.headers.get('Retry-After') || '', 10) : NaN;
                await new Promise((res) => setTimeout(res, (after > 0 ? Math.min(after, 10) : attempt + 1) * 1000));
            }
        },

        async reportSaveFailure(r) {
            this.err = true;
            if (!r || r.status !== 422) {
                this.msg = '{{ ___('Could not save. Please try again.') }}';
                return;
            }
            // A validation failure names the exact fields; say which, rather than
            // making the owner hunt through every group for the bad one.
            const d = await r.json().catch(() => ({}));
            const bad = Object.keys(d.errors || {});
            this.msg = bad.length
                ? '{{ ___('Some values were invalid') }}: ' + bad.join(', ')
                : '{{ ___('Some values were invalid.') }}';
            if (bad.length) this.flashField(bad[0]);
        },

        flashField(id) {
            const el = document.getElementById('tcf-' + id);
            if (!el) return;
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.focus({ preventScroll: true });
        },

        async publish() {
            if (this.dirty) await this.saveDraft();
            if (this.err) return;
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/publish', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
            });
            this.err = !r.ok;
            this.msg = r.ok ? '{{ ___('Published! Your menu is updated.') }}' : '{{ ___('Publish failed.') }}';
            if (r.ok) {
                const d = await r.json().catch(() => ({}));
                this.published = d.settings || this.published;
                this.hasDraft = false; this.dirty = false;
                // Warn-only: publish already happened; the banner just informs.
                this.contrast = d.contrast || [];
                this.loadHistory();
                this.reloadPreview();
            }
        },

        async discard() {
            if (!(await window.dashConfirm({ body: @js(___('Discard the draft and go back to what is published?')), danger: true }))) return;
            const r = await fetch('{{ url('theme-customizer') }}/' + this.activeId + '/discard', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
            });
            if (!r.ok) { this.err = true; this.msg = '{{ ___('Could not discard the draft.') }}'; return; }
            await this.load();
            this.msg = '{{ ___('Draft discarded.') }}';
            this.reloadPreview();
        },
    };
}
</script>
@endpush
