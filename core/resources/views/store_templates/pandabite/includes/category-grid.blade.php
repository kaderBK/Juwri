{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="pb-categories">
    <h3 class="pb-section-title">{{ ___('Categories') }}</h3>
    <div class="pb-category-grid" id="pb-category-grid">
        <button type="button" class="pb-category-tile active" data-category="all">
            <div class="pb-category-tile__img pb-category-tile__img--fallback">
                <span>🍽️</span>
            </div>
            <span class="pb-category-tile__name">{{ ___('All') }}</span>
        </button>
        @foreach($jsCategories as $cat)
            <button type="button" class="pb-category-tile" data-category="{{ $cat['id'] }}">
                <div class="pb-category-tile__img {{ $cat['img'] ? '' : 'pb-category-tile__img--fallback' }}">
                    @if($cat['img'])
                        @if((string) ($cat['img']) !== '')<img src="{{ $cat['img'] }}" alt="" loading="lazy" data-csp-error="parent-class" data-csp-class="pb-category-tile__img--fallback" data-csp-show="span" data-csp-remove="true">@endif
                        <span style="display:none">{{ $cat['emoji'] }}</span>
                    @else
                        <span>{{ $cat['emoji'] }}</span>
                    @endif
                </div>
                <span class="pb-category-tile__name">{{ $cat['name'] }}</span>
            </button>
        @endforeach
    </div>
</section>
