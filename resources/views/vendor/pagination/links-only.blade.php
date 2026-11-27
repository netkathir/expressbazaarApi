{{-- Page-number buttons only (‹ 1 2 3 ›). The "Total n items" text and size selector live in partials/pagination. --}}
@if ($paginator->hasPages())
    <nav class="pager-pages" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pager-btn is-disabled" aria-disabled="true">&lsaquo;</span>
        @else
            <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')">&lsaquo;</a>
        @endif

        <span class="pager-mobile-info">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pager-btn pager-num is-disabled" aria-disabled="true">&hellip;</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager-btn pager-num is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pager-btn pager-num" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')">&rsaquo;</a>
        @else
            <span class="pager-btn is-disabled" aria-disabled="true">&rsaquo;</span>
        @endif
    </nav>
@endif
