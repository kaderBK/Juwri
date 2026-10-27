{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Stories'))
@section('page_title', ___('Stories'))
@section('page_sub', ___('Full-screen story reels that play from the top of your customer menu.'))

@section('content')
<div class="ui-stack" x-data="storiesPage({ token: @js(csrf_token()), reorderUrl: @js(route('stories.reorder')), failedText: @js(___('Unable to save.')) })">
    <form method="get" action="{{ route('stories.index') }}">
        @if($stores->count() > 1)
            <select name="store_id" class="ui-input" style="max-width:320px" data-ui-do="submit">
                <option value="">{{ ___('Select store…') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="store_id" value="{{ $storeId }}">
        @endif
    </form>

    @if(!$storeId)
        <div class="ui-card"><div class="ui-text-2 ui-card__body ui-center">{{ ___('Pick a store to manage its stories.') }}</div></div>
    @else
        <x-ui.card :title="___('Add story')">
            <form method="post" action="{{ route('stories.store') }}" class="ui-form-grid"
                  x-data="imagePickerField({ target: 'story-add', storeId: {{ $storeId }}, folder: 'banner' })">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <input type="hidden" name="media_url" :value="filename">
                <div class="ui-form-grid__full">
                    <div class="ui-cluster">
                        <img x-show="url" :src="url" alt="" x-cloak style="width:56px;height:56px;object-fit:cover;">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Choose image') }} *</button>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                    </div>
                </div>
                <div class="ui-form-grid__full">
                    <label class="ui-label">{{ ___('Caption') }}</label>
                    <input class="ui-input" name="caption" maxlength="500" placeholder="{{ ___('Freshly baked, wood-fired daily') }}">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Button label') }}</label>
                    <input class="ui-input" name="cta_label" maxlength="120" placeholder="{{ ___('Order now') }}">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Button link') }}</label>
                    <input class="ui-input" name="cta_url" type="url" maxlength="500" placeholder="https://">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Starts at') }}</label>
                    <input class="ui-input" name="starts_at" type="datetime-local">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Expires at') }}</label>
                    <input class="ui-input" name="expires_at" type="datetime-local">
                </div>
                <div class="ui-form-grid__full">
                    <label class="ui-check"><input type="checkbox" name="active" value="1" checked><span>{{ ___('Active') }}</span></label>
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" icon="plus" ::disabled="!filename">{{ ___('Add story') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Stories')">
            <x-slot:actions><x-ui.tag tone="neutral">{{ $stories->count() }}</x-ui.tag></x-slot:actions>
            @if($stories->isEmpty())
                <div class="ui-text-2 ui-center">{{ ___('No stories yet. Add one above.') }}</div>
            @else
                <p class="ui-muted">{{ ___('Drag to reorder. Only active stories inside their date window show on the storefront.') }}</p>
                <div class="story-list ui-stack" @dragover.prevent="dragOver($event)" @drop.prevent>
                    @foreach ($stories as $s)
                        <article class="story-card ui-card" data-drag-id="{{ $s->id }}" draggable="true"
                                 @dragstart="dragStart($event)" @dragend="dragEnd($event)">
                            <div class="ui-cluster ui-card__body">
                                <span class="ui-text-2 story-card__handle" style="cursor:grab" aria-hidden="true">
                                    <x-ui.icon name="grip" size="16" />
                                </span>
                                <img src="{{ $s->media_full_url }}" alt="{{ $s->caption }}" loading="lazy"
                                     data-ui-img-fallback="{{ asset('storage/menu/default.png') }}" style="width:44px;height:64px;object-fit:cover;">
                                <div class="ui-grow min-w-0">
                                    <strong class="ui-block">{{ $s->caption ?: ___('(no caption)') }}</strong>
                                    @if($s->cta_label)<span class="ui-muted ui-block">{{ $s->cta_label }}@if($s->cta_url) — {{ $s->cta_url }}@endif</span>@endif
                                    @if($s->starts_at || $s->expires_at)
                                        <small class="ui-text-2">
                                            {{ $s->starts_at ? $s->starts_at->format('Y-m-d H:i') : ___('Now') }}
                                            &rarr;
                                            {{ $s->expires_at ? $s->expires_at->format('Y-m-d H:i') : ___('No end') }}
                                        </small>
                                    @endif
                                </div>
                                <div class="ui-cluster">
                                    @if($s->active)<x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                                    @else<x-ui.tag tone="neutral">{{ ___('Hidden') }}</x-ui.tag>@endif
                                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm"
                                        @click="edit = @js(['caption'=>$s->caption,'cta_label'=>$s->cta_label,'cta_url'=>$s->cta_url,'media_url'=>$s->media_url,'media_full_url'=>$s->media_full_url,'starts_at'=>optional($s->starts_at)->format('Y-m-d\TH:i'),'expires_at'=>optional($s->expires_at)->format('Y-m-d\TH:i'),'active'=>(bool)$s->active]); editId = {{ $s->id }}; $refs.storyDialog.showModal()">
                                        {{ ___('Edit') }}
                                    </button>
                                    <form method="post" action="{{ route('stories.destroy', $s->id) }}" data-confirm="{{ ___('Delete this story?') }}" data-confirm-danger>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Delete') }}</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        {{-- Edit dialog --}}
        <dialog class="ui-dialog" x-ref="storyDialog">
            <div class="ui-dialog__panel ui-card">
                <form method="post" :action="@js(url('stories')) + '/' + editId"
                      x-data="imagePickerField({ target: 'story-edit', storeId: {{ $storeId }}, folder: 'banner' })"
                      x-effect="filename = edit.media_url || ''; url = edit.media_full_url || ''">
                    @csrf @method('PUT')
                    <div class="ui-cluster ui-cluster--between ui-card__head">
                        <h4 class="ui-section-title">{{ ___('Edit story') }}</h4>
                        <button type="button" class="ui-icon-btn" @click="$refs.storyDialog.close()" aria-label="{{ ___('Close') }}">&times;</button>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <input type="hidden" name="media_url" :value="filename">
                        <div class="ui-cluster">
                            <img x-show="url" :src="url" alt="" x-cloak style="width:56px;height:56px;object-fit:cover;">
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Choose image') }}</button>
                        </div>
                        <div><label class="ui-label">{{ ___('Caption') }}</label><input class="ui-input" name="caption" maxlength="500" x-model="edit.caption"></div>
                        <div><label class="ui-label">{{ ___('Button label') }}</label><input class="ui-input" name="cta_label" maxlength="120" x-model="edit.cta_label"></div>
                        <div><label class="ui-label">{{ ___('Button link') }}</label><input class="ui-input" name="cta_url" type="url" maxlength="500" x-model="edit.cta_url"></div>
                        <div class="ui-form-grid">
                            <div><label class="ui-label">{{ ___('Starts at') }}</label><input class="ui-input" name="starts_at" type="datetime-local" x-model="edit.starts_at"></div>
                            <div><label class="ui-label">{{ ___('Expires at') }}</label><input class="ui-input" name="expires_at" type="datetime-local" x-model="edit.expires_at"></div>
                        </div>
                        <label class="ui-check"><input type="checkbox" name="active" value="1" :checked="edit.active" @change="edit.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
                    </div>
                    <div class="ui-cluster ui-cluster--end ui-card__foot">
                        <x-ui.button type="button" variant="ghost" @click="$refs.storyDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </dialog>

        @include('owner.media._picker')
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.storiesPage = function (config) {
        return {
            editId: null, edit: {},
            _drag: null, _container: null,
            dragStart(e) { this._drag = e.target.closest('[data-drag-id]'); this._container = this._drag.parentNode; this._drag.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); },
            dragOver(e) {
                const t = e.target.closest('[data-drag-id]');
                if (!t || t === this._drag || t.parentNode !== this._container) return;
                const r = t.getBoundingClientRect();
                this._container.insertBefore(this._drag, (e.clientY < r.top + r.height / 2) ? t : t.nextSibling);
            },
            dragEnd() {
                if (!this._drag) return;
                this._drag.classList.remove('is-dragging');
                // StoryController@reorder reads `sort[]` and stores the array index as `sort`.
                const ids = Array.from(this._container.querySelectorAll(':scope > [data-drag-id]')).map(el => el.getAttribute('data-drag-id'));
                const fd = new FormData(); ids.forEach(id => fd.append('sort[]', id));
                fetch(config.reorderUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).catch(() => {});
                this._drag = null; this._container = null;
            },
        };
    };
</script>
@endpush
@endsection
