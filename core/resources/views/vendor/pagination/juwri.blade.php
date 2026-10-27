{{--
  Default paginator view ($paginator->links()). Kit classes (ui-pager*) carry the
  look on kit pages; the Bootstrap names stay alongside so screens that still
  load Bootstrap (admin, WI-10) keep a styled pager until they move to the kit.
--}}
@if ($paginator->hasPages())
<nav class="ui-pager" role="navigation" aria-label="{{ ___('Pagination') }}">
    <ul class="ui-pager__list pagination">
        @if ($paginator->onFirstPage())
            <li class="ui-pager__item page-item disabled"><span class="ui-pager__link page-link" aria-disabled="true" aria-label="{{ ___('Previous page') }}"><x-ui.icon name="back" size="16" :flip="true" /></span></li>
        @else
            <li class="ui-pager__item page-item"><a class="ui-pager__link page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ ___('Previous page') }}"><x-ui.icon name="back" size="16" :flip="true" /></a></li>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <li class="ui-pager__item page-item disabled"><span class="ui-pager__link ui-pager__gap page-link">{{ $element }}</span></li>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li class="ui-pager__item page-item active" aria-current="page"><span class="ui-pager__link page-link ui-num">{{ $page }}</span></li>
                    @else
                        <li class="ui-pager__item page-item"><a class="ui-pager__link page-link ui-num" href="{{ $url }}">{{ $page }}</a></li>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <li class="ui-pager__item page-item"><a class="ui-pager__link page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ ___('Next page') }}"><x-ui.icon name="chevron" size="16" :flip="true" /></a></li>
        @else
            <li class="ui-pager__item page-item disabled"><span class="ui-pager__link page-link" aria-disabled="true" aria-label="{{ ___('Next page') }}"><x-ui.icon name="chevron" size="16" :flip="true" /></span></li>
        @endif
    </ul>
</nav>
@endif
