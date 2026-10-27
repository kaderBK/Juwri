{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- tc-card-select — generic visual card grid for customizer select fields that
     carry option_previews (rev/customizer-cards SPEC 2). Designed to sit INSIDE
     the field loop of theme-customizer.blade.php where `f`, `values` and
     `set()` are in Alpine scope. Cards are native radio inputs (visually
     hidden) inside label tiles: single tab stop, arrow-key movement and radio
     semantics for free. Fields without option_previews keep the plain select.
     Preview paths are asset-relative (repo-root assets/); tcc-anim--<opt>
     hooks the per-concept hover micro-animation in tc-cards.css. --}}
@once
    @push('styles')
        <link rel="stylesheet"
              href="{{ asset('assets/global/stories-entries/previews/tc-cards.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/global/stories-entries/previews/tc-cards.css'))) }}">
    @endpush
@endonce
<template x-if="f.type==='select' && f.option_previews">
    <div class="tcc-grid" role="radiogroup" :aria-label="f.label">
        <template x-for="opt in (f.options||[])" :key="'tcc-'+f.id+'-'+opt">
            <label class="tcc-card" :class="'tcc-anim--' + opt">
                <input type="radio" class="tcc-card__radio" :name="'tccr-' + f.id" :value="opt"
                       :checked="values[f.id]===opt" @change="set(f.id, opt)">
                <span class="tcc-thumbwrap">
                    <img class="tcc-thumb" :src="'{{ asset('assets') }}/' + f.option_previews[opt]"
                         alt="" loading="lazy" x-show="!!f.option_previews[opt]">
                </span>
                <span class="tcc-label" x-text="(f.option_labels && f.option_labels[opt]) || opt"></span>
                <span class="tcc-sub" x-show="f.option_sublabels && f.option_sublabels[opt]"
                      x-text="(f.option_sublabels && f.option_sublabels[opt]) || ''"></span>
            </label>
        </template>
    </div>
</template>
