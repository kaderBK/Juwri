{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Public Media Library'))
@section('content')
<div class="ui-stack">
    {{-- Upload --}}
    <x-ui.card :title="___('Add curated images')">
        <form method="post" action="{{ route('admin.public-media.store') }}" enctype="multipart/form-data" class="ui-stack">
            @csrf
            <p class="ui-text-2">{{ ___('These images are shared with every store. Owners can copy them into their own library (a copy is made — the shared file is never hotlinked).') }}</p>
            <div class="ui-form-grid">
                <x-ui.field type="select" id="pm-upload-category" name="category" :label="___('Category')"
                            :options="\Juwri\Core\Support\ViewHelpers::ucfirstOptions($categories, true)" />
                <x-ui.field id="pm-upload-title" name="title" :label="___('Title').' ('.___('optional').')'" :placeholder="___('e.g. Classic burger')" />
                <x-ui.field id="pm-upload-tags" name="tags" :label="___('Tags').' ('.___('comma separated').')'" placeholder="burger, beef, grill" />
                <div class="ui-field">
                    <label class="ui-field__label" for="pm-upload-images">{{ ___('Images') }}</label>
                    <input type="file" id="pm-upload-images" name="images[]" class="ui-input" accept="image/*" multiple required>
                </div>
            </div>
            <div class="ui-cluster">
                <x-ui.button type="submit" icon="upload" data-ui-busy>{{ ___('Upload') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Filter --}}
    <form method="get" class="ui-cluster" role="search">
        <input type="search" name="q" value="{{ $q }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Title or tag…') }}" aria-label="{{ ___('Search') }}">
        <select name="category" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Category') }}">
            <option value="">{{ ___('All categories') }}</option>
            @foreach ($categories as $c)
                <option value="{{ $c }}" @selected($category === $c)>{{ ___(ucfirst($c)) }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit" variant="secondary" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    {{-- Grid --}}
    <x-ui.card :title="___('Curated pool')">
        <x-slot:actions><x-ui.tag>{{ $assets->total() }}</x-ui.tag></x-slot:actions>
        @if($assets->isEmpty())
            <x-ui.empty icon="upload" :title="___('No curated images yet. Upload some above.')" />
        @else
            <ul class="ui-grid" style="--ui-grid-min:160px;--ui-gap:var(--ui-space-3);list-style:none;margin:0;padding:0">
                @foreach ($assets as $a)
                    <li class="ui-card ui-card--inset ui-stack" style="--ui-gap:var(--ui-space-2);padding:var(--ui-space-2){{ $a->active ? '' : ';opacity:.5' }}">
                        <img src="{{ $a->url }}" alt="{{ $a->title }}" loading="lazy"
                             style="inline-size:100%;aspect-ratio:1;object-fit:cover;border-radius:var(--ui-r-input)">
                        <div>
                            <div class="ui-small ui-break" title="{{ $a->title }}"><strong>{{ $a->title ?: $a->original_name }}</strong></div>
                            <div class="ui-small ui-text-2">{{ ___(ucfirst($a->category ?: 'other')) }}</div>
                        </div>
                        <div class="ui-cluster ui-cluster--between">
                            <form method="post" action="{{ route('admin.public-media.toggle', $a->id) }}">
                                @csrf
                                <x-ui.button type="submit" :variant="$a->active ? 'secondary' : 'success'" size="sm">
                                    {{ $a->active ? ___('Hide') : ___('Show') }}
                                </x-ui.button>
                            </form>
                            <form method="post" action="{{ route('admin.public-media.destroy', $a->id) }}" data-ui-confirm="{{ ___('Delete this image?') }}" data-ui-danger>
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($assets->hasPages())
                <div class="ui-card__foot">{{ $assets->links() }}</div>
            @endif
        @endif
    </x-ui.card>
</div>
@endsection
