{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Media library'))
@section('page_title', ___('Media library'))
@section('page_sub', ___('Reusable images. Upload once, then pick anywhere — items, categories, extras, banners.'))

@section('content')
<div class="ui-scope ui-media" x-data="mediaPage()">
    <form method="get" action="{{ route('media.index') }}" class="ui-card ui-media__filters">
        @if($stores->count() > 1)
            <div class="ui-media__field">
                <label class="ui-label" for="media-restaurant">{{ ___('Store') }}</label>
                <select id="media-restaurant" name="store_id" class="ui-input" data-ui-do="submit">
                    <option value="">{{ ___('Select store…') }}</option>
                    @foreach ($stores as $r)
                        <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <input type="hidden" name="store_id" value="{{ $storeId }}">
        @endif
        <div class="ui-media__field">
            <label class="ui-label" for="media-folder">{{ ___('Folder') }}</label>
            <select id="media-folder" name="folder" class="ui-input" data-ui-do="submit">
                <option value="">{{ ___('All folders') }}</option>
                @foreach (['menu','category','extra','variant','banner','other'] as $f)
                    <option value="{{ $f }}" @selected($folder === $f)>{{ ___(ucfirst($f)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-media__field ui-media__field--grow">
            <label class="ui-label" for="media-q">{{ ___('Search') }}</label>
            <input id="media-q" class="ui-input" name="q" value="{{ $query }}" placeholder="{{ ___('File name…') }}">
        </div>
        <x-ui.button type="submit" variant="secondary" icon="search">{{ ___('Filter') }}</x-ui.button>
    </form>

    @if(!$storeId)
        <div class="ui-card"><x-ui.empty :title="___('Pick a store to manage its media library.')" icon="store" /></div>
    @else
        <x-ui.card :title="___('Upload images')">
            {{-- L4: real determinate upload progress via shared <x-upload-progress>.
                 Uploads land in the currently-filtered folder (defaults to "menu"). --}}
            <div x-data @upload-complete="setTimeout(() => window.location.reload(), 600)">
                <x-upload-progress
                    :url="route('media.store')"
                    name="images[]"
                    accept="image/*"
                    multiple
                    dropzone
                    :field="['store_id' => $storeId, 'folder' => ($folder ?: 'menu')]"
                    :button-label="___('Upload images')" />
                <p class="ui-media__hint">
                    {{ ___('Uploads are saved to the :folder folder. Switch folders with the filter above.', ['folder' => ___(ucfirst($folder ?: 'menu'))]) }}
                </p>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Library')">
            <x-slot:actions><x-ui.tag>{{ $assets->count() }}</x-ui.tag></x-slot:actions>
            @if($assets->isEmpty())
                <x-ui.empty :title="___('No images yet. Upload some above.')" icon="upload" />
            @else
                <ul class="ui-media__grid">
                    @foreach ($assets as $a)
                        <li class="ui-media__tile" :class="{ 'ui-hidden': removed.includes({{ $a->id }}) }" id="media-{{ $a->id }}">
                            <img src="{{ $a->url }}" alt="{{ $a->original_name }}" loading="lazy"
                                 data-ui-img-fallback="{{ asset('storage/menu/default.png') }}">
                            <div class="ui-media__meta">
                                <span class="ui-media__name" title="{{ $a->original_name }}">{{ $a->original_name ?: $a->filename }}</span>
                                <x-ui.tag>{{ ___(ucfirst($a->folder)) }}</x-ui.tag>
                            </div>
                            <button type="button" class="ui-icon-btn ui-media__delete" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"
                                    @click="deleteAsset({{ $a->id }})">
                                <x-ui.icon name="trash" size="18" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function mediaPage() {
        return {
            previewCount: 0,
            removed: [],
            csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
            async deleteAsset(id) {
                if (!(await window.dashConfirm({ body: @js(___('Delete this image? It will be removed from the library.')), danger: true }))) return;
                const res = await fetch(`{{ url('media') }}/${id}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams({ _method: 'DELETE' }),
                });
                if (res.ok) {
                    this.removed.push(id);
                    const el = document.getElementById('media-' + id);
                    if (el) el.remove();
                } else {
                    window.dashToast('danger', @js(___('Could not delete.')));
                }
            },
        };
    }
</script>
@endpush
@endsection
