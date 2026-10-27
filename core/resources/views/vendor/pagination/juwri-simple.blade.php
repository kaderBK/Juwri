{{-- Simple paginator view (simplePaginate()->links()): previous / next only. See juwri.blade.php. --}}
@if ($paginator->hasPages())
<nav class="ui-pager" role="navigation" aria-label="{{ ___('Pagination') }}">
    <ul class="ui-pager__list pagination">
        @if ($paginator->onFirstPage())
            <li class="ui-pager__item page-item disabled"><span class="ui-pager__link page-link" aria-disabled="true" aria-label="{{ ___('Previous page') }}"><x-ui.icon name="back" size="16" :flip="true" /></span></li>
        @else
            <li class="ui-pager__item page-item"><a class="ui-pager__link page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ ___('Previous page') }}"><x-ui.icon name="back" size="16" :flip="true" /></a></li>
        @endif
        @if ($paginator->hasMorePages())
            <li class="ui-pager__item page-item"><a class="ui-pager__link page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ ___('Next page') }}"><x-ui.icon name="chevron" size="16" :flip="true" /></a></li>
        @else
            <li class="ui-pager__item page-item disabled"><span class="ui-pager__link page-link" aria-disabled="true" aria-label="{{ ___('Next page') }}"><x-ui.icon name="chevron" size="16" :flip="true" /></span></li>
        @endif
    </ul>
</nav>
@endif
