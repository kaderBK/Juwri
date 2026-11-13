{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Edit listing') . ' — ' . $theme->name)

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.theme-reviews.index')">{{ ___('Back to reviews') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-stack">

    @if($errors->any())
        <x-ui.banner tone="danger">
            <ul class="ui-plain-list">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </x-ui.banner>
    @endif

    {{-- Metadata --}}
    <x-ui.card>
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Store listing') }}: {{ $theme->name }} <code class="ui-mono ui-small">{{ $theme->slug }}</code></h2>
                <p class="ui-text-2 ui-small">{{ ___('These describe the theme in the store. Editing them never changes its price or review status.') }}</p>
            </div>
        </div>
        <form action="{{ route('admin.theme-reviews.listing.save', $theme->id) }}" method="post" class="ui-stack">@csrf
            <div class="ui-form-grid">
                <div class="ui-field">
                    <label class="ui-field__label" for="listing-category">{{ ___('Category') }}</label>
                    <div class="ui-field__control">
                        <select name="category" id="listing-category">
                            <option value="">{{ ___('Uncategorized') }}</option>
                            @foreach($industries as $key)
                                <option value="{{ $key }}" @selected($theme->category === $key)>{{ ___($key) }}</option>
                            @endforeach
                        </select>
                        <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                    </div>
                </div>
                <x-ui.field name="tagline" :label="___('Tagline').' ('.___('max 140').')'" :value="$theme->tagline" maxlength="140" />
                <x-ui.field type="url" name="support_url" :label="___('Support URL')" :value="$theme->support_url" placeholder="https://…" />
                <x-ui.field name="demo_notes" :label="___('Demo notes')" :value="$theme->demo_notes" maxlength="255" />
            </div>
            <div class="ui-cluster ui-cluster--end">
                <x-ui.button type="submit" data-ui-busy>{{ ___('Save listing') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Screenshots --}}
    <x-ui.card>
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Screenshots') }}
                    <x-ui.tag>{{ $theme->screenshots()->count() }} / {{ config('marketplace.media.max_screenshots') }}</x-ui.tag>
                </h2>
                <p class="ui-text-2 ui-small">{{ ___('Shown in the store gallery in this order. First image is the card thumbnail.') }}</p>
            </div>
        </div>
        <div class="ui-stack">
            <form action="{{ route('admin.theme-reviews.media.upload', $theme->id) }}" method="post" enctype="multipart/form-data">@csrf
                <label class="ui-visually-hidden" for="listing-screenshots">{{ ___('Screenshots') }}</label>
                <div class="ui-input-group">
                    <input type="file" id="listing-screenshots" name="screenshots[]" class="ui-input" accept="image/png,image/jpeg,image/webp" multiple required aria-describedby="listing-screenshots-help">
                    <x-ui.button type="submit" variant="success" icon="upload">{{ ___('Upload') }}</x-ui.button>
                </div>
                <span class="ui-field__help" id="listing-screenshots-help">{{ ___('PNG / JPG / WebP, up to :mb MB each.', ['mb' => round(config('marketplace.media.max_kb') / 1024, 1)]) }}</span>
            </form>

            @php $shots = $theme->screenshots()->orderBy('position')->get(); @endphp
            @if($shots->isEmpty())
                <x-ui.empty icon="layout" :title="___('No screenshots yet.')" />
            @else
                <div class="ui-grid" id="shot-grid" style="--ui-grid-min:180px;--ui-gap:var(--ui-space-3)">
                    @foreach($shots as $i => $shot)
                        <x-ui.card variant="inset" as="div" class="ui-stack">
                            <img src="{{ $shot->url() }}" alt="" class="ui-img-fluid" style="aspect-ratio:16/10;object-fit:cover;border-radius:var(--ui-r-input)">
                            <div class="ui-cluster">
                                <form action="{{ route('admin.theme-reviews.media.move', $theme->id) }}" method="post">@csrf
                                    <input type="hidden" name="media_id" value="{{ $shot->id }}">
                                    <input type="hidden" name="dir" value="up">
                                    <button type="submit" class="ui-icon-btn ui-icon-btn--ghost" @disabled($i === 0) title="{{ ___('Move earlier') }}" aria-label="{{ ___('Move earlier') }}"><x-ui.icon name="chevron-down" size="18" style="transform:rotate(180deg)" /></button>
                                </form>
                                <form action="{{ route('admin.theme-reviews.media.move', $theme->id) }}" method="post">@csrf
                                    <input type="hidden" name="media_id" value="{{ $shot->id }}">
                                    <input type="hidden" name="dir" value="down">
                                    <button type="submit" class="ui-icon-btn ui-icon-btn--ghost" @disabled($i === $shots->count() - 1) title="{{ ___('Move later') }}" aria-label="{{ ___('Move later') }}"><x-ui.icon name="chevron-down" size="18" /></button>
                                </form>
                                <form action="{{ route('admin.theme-reviews.media.delete', $shot->id) }}" method="post" class="ui-ms-auto"
                                      data-ui-confirm="{{ ___('Delete this screenshot?') }}" data-ui-danger>@csrf
                                    <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                </form>
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.card>

</div>
@endsection
