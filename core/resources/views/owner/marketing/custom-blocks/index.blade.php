{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Custom blocks'))
@section('page_title', ___('Custom blocks'))
@section('page_sub', ___('Design your own storefront sections and choose where they appear.'))

@php
    /** @var \Juwri\Core\Models\Store $store */
    $cbStoreQ = ['store' => $store->id];
    $cbUsed = $blocks->count();
@endphp

@push('styles')
@php $cbCss = 'assets/theme-studio/custom-blocks.css'; $cbCssV = @filemtime(public_path($cbCss)); @endphp
<link rel="stylesheet" href="{{ asset($cbCss) }}{{ $cbCssV ? '?v='.$cbCssV : '' }}">
@endpush

@section('content')
<div class="ui-scope cb-page">
    @if (session('success'))
        <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
    @endif
    @if (session('error'))
        <x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>
    @endif

    @if ($stores->count() > 1)
        <form method="get" action="{{ route('custom-blocks.index') }}" class="cb-store">
            <label class="ui-visually-hidden" for="cb-store">{{ ___('Store') }}</label>
            <div class="ui-field__control cb-store__control">
                <x-ui.icon name="store" size="18" class="cb-store__icon" />
                <select id="cb-store" name="store" data-cb-autosubmit>
                    @foreach ($stores as $s)
                        <option value="{{ $s->id }}" @selected((int) $s->id === (int) $store->id)>{{ $s->title }}</option>
                    @endforeach
                </select>
                <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
            </div>
            <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Switch store') }}</x-ui.button>
        </form>
    @endif

    <x-ui.card :title="___('Your blocks')" variant="flush" class="cb-blocks">
        <x-slot:actions>
            <x-ui.tag tone="neutral">{{ $limit === null ? ___(':used blocks', ['used' => $cbUsed]) : ___(':used of :limit blocks', ['used' => $cbUsed, 'limit' => $limit]) }}</x-ui.tag>
        </x-slot:actions>

        @if ($blocks->isEmpty())
            <x-ui.empty icon="sparkle" :title="___('No custom blocks yet')"
                :body="___('Start from a template below — a promo, an event, your story, a FAQ — then publish it to your storefront.')" />
        @else
            <x-ui.list class="cb-list">
                @foreach ($blocks as $block)
                    @php
                        $cbLive = $block->status === \App\Models\StoreCustomBlock::STATUS_PUBLISHED;
                        $cbWhere = $block->placement ? ($placements[$block->placement] ?? $block->placement) : ___('Theme Studio section only');
                        $cbLang = $block->locale ? ($locales[$block->locale] ?? $block->locale) : ___('All languages');
                        $cbQ = ['id' => $block->id] + $cbStoreQ;
                    @endphp
                    <x-ui.row icon="pencil" :tone="$cbLive ? 'success' : 'neutral'" :title="$block->name" :sub="$cbWhere.' · '.$cbLang" class="cb-row" data-cb-block="{{ $block->id }}">
                        <x-slot:trail>
                            <span class="cb-row__actions">
                                <x-ui.pill :tone="$cbLive ? 'success' : 'neutral'">{{ $cbLive ? ___('Published') : ___('Draft') }}</x-ui.pill>
                                @if ($cbLive)
                                    <form method="post" action="{{ route('custom-blocks.unpublish', $cbQ) }}">@csrf
                                        <x-ui.button size="sm" variant="ghost" type="submit" data-ui-busy>{{ ___('Unpublish') }}</x-ui.button>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('custom-blocks.publish', $cbQ) }}">@csrf
                                        <x-ui.button size="sm" variant="success" type="submit" data-ui-busy>{{ ___('Publish') }}</x-ui.button>
                                    </form>
                                @endif
                                <x-ui.button size="sm" variant="secondary" icon="pencil" :href="route('custom-blocks.edit', $cbQ)">{{ ___('Edit design') }}</x-ui.button>
                                <x-ui.icon-button icon="settings" variant="ghost" :label="___('Block settings').' — '.$block->name" :title="___('Block settings')" data-ui-open="cb-settings-{{ $block->id }}" />
                            </span>
                        </x-slot:trail>
                    </x-ui.row>
                @endforeach
            </x-ui.list>

            {{-- Sheets sit after the list so the rows stay siblings (kit row dividers). --}}
            @foreach ($blocks as $block)
                @php $cbQ = ['id' => $block->id] + $cbStoreQ; @endphp
                <x-ui.sheet :id="'cb-settings-'.($block->id)" :title="___('Block settings')" :description="___('Rename the block and choose where and in which language it shows.')">
                    <form method="post" action="{{ route('custom-blocks.update', $cbQ) }}" id="cb-settings-form-{{ $block->id }}" class="ui-stack">
                        @csrf
                        <x-ui.field name="name" :label="___('Name')" :value="$block->name" :id="'cb-name-'.$block->id" required maxlength="120" />
                        <x-ui.field type="select" name="placement" :label="___('Placement')" :value="$block->placement" :id="'cb-placement-'.$block->id"
                            :options="['' => ___('Theme Studio section only')] + $placements"
                            :help="___('Where the block appears on classic themes. On Theme Studio themes, add it as a Custom block section.')" />
                        <x-ui.field type="select" name="locale" :label="___('Language')" :value="$block->locale" :id="'cb-locale-'.$block->id"
                            :options="['' => ___('All languages')] + $locales" />
                    </form>
                    <form method="post" action="{{ route('custom-blocks.destroy', $cbQ) }}" id="cb-delete-form-{{ $block->id }}"
                          data-confirm="{{ ___('Delete this block? It leaves your storefront and cannot be restored.') }}" data-confirm-danger>
                        @csrf
                        @method('DELETE')
                    </form>
                    <x-slot:footer>
                        <x-ui.button variant="danger" type="submit" form="cb-delete-form-{{ $block->id }}" class="cb-sheet__delete">{{ ___('Delete block') }}</x-ui.button>
                        <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
                        <x-ui.button variant="dark" type="submit" form="cb-settings-form-{{ $block->id }}" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.sheet>
            @endforeach
        @endif
    </x-ui.card>

    <x-ui.card :title="___('New block')" class="cb-new">
        @if ($atLimit)
            <x-ui.banner tone="warning">{{ ___('You have reached your plan’s custom block limit. Delete a block or upgrade your plan to add more.') }}</x-ui.banner>
        @else
            <form method="post" action="{{ route('custom-blocks.store', $cbStoreQ) }}" class="cb-new__form" data-cb-create>
                @csrf
                <x-ui.field name="name" :label="___('Name')" :placeholder="___('e.g. Weekend offer')" required maxlength="120" />
                <x-ui.field type="select" name="template" :label="___('Start from')"
                    :options="\Juwri\Core\Support\Views\PublicSiteViewHelpers::templateOptionLabels($templates)"
                    :value="\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController::DEFAULT_TEMPLATE" />
                <x-ui.field type="select" name="placement" :label="___('Placement')"
                    :options="['' => ___('Theme Studio section only')] + $placements" value="after-hero" />
                <x-ui.field type="select" name="locale" :label="___('Language')" :options="['' => ___('All languages')] + $locales" />
                <div class="cb-new__actions">
                    <x-ui.button type="submit" icon="plus" data-ui-busy>{{ ___('Create and design') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    (function () {
        var sel = document.querySelector('[data-cb-autosubmit]');
        if (sel && sel.form) { sel.addEventListener('change', function () { sel.form.submit(); }); }
    })();
</script>
@endpush
