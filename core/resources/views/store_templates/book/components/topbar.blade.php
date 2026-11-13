{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the top bar of a step page — back, the page title (h1), the step, the store --}}
<header class="c-topbar"><a class="c-iconbtn c-topbar__back" href="{{ $back }}" aria-label="{{ $kit->s('Back') }}">{{ $kit->icon('arrow-left') }}</a><h1 class="c-topbar__title">{{ $title }}</h1>@if (! empty($step))<p class="c-bktop__step">{{ $step }}</p>@endif<a class="c-topbar__brand" href="{{ $vm['endpoints']['home'] }}">@include('store_templates.book.components.logo', ['cls' => 'c-bklogo c-bklogo--sm'])<span class="c-topbar__store">{{ $vm['store']['name'] }}</span></a></header>
