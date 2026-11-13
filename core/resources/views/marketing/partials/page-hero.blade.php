{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Inner-page hero (WI-16f-2).
     Pass: $title (required), $eyebrow, $sub, $actions (array of
     ['url' => …, 'label' => …, 'ghost' => bool]), $crumbs (array of
     ['name' => …, 'url' => …|null]; shown above the title). A page can add
     extra content under the actions with the `slot` string ($after, HTML). --}}
<section class="mk-page-hero">
    <div class="mk-wrap">
        @if (! empty($crumbs))
            <nav class="mk-crumbs" aria-label="{{ ___('Breadcrumbs') }}">
                <ol>
                    @foreach ($crumbs as $mkCrumb)
                        <li>
                            @if (! empty($mkCrumb['url']))
                                <a href="{{ $mkCrumb['url'] }}">{{ $mkCrumb['name'] }}</a>
                            @else
                                <span aria-current="page">{{ $mkCrumb['name'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
        @if (! empty($eyebrow))
            <span class="mk-eyebrow">{{ $eyebrow }}</span>
        @endif
        <h1 class="mk-h1">{{ $title }}</h1>
        @if (! empty($sub))
            <p class="mk-lead">{{ $sub }}</p>
        @endif
        @if (! empty($actions))
            <div class="mk-actions">
                @foreach ($actions as $mkAct)
                    <a href="{{ $mkAct['url'] }}" class="ui-btn{{ ! empty($mkAct['ghost']) ? ' ui-btn--secondary' : '' }}">{{ $mkAct['label'] }}</a>
                @endforeach
            </div>
        @endif
        {!! $after ?? '' !!}
    </div>
</section>
