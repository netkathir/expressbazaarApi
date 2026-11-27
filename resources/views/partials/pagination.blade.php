{{--
    Pagination bar, right-aligned:  Total 15 items   ‹ 1 2 ›   [10 / page ▾]
    Usage: @include('partials.pagination', ['paginator' => $orders])
--}}
@php
    $paginator = $paginator->withQueryString();
    $perPageOptions = \App\Support\PerPage::optionsFor($paginator->perPage());
    $pageName = $paginator->getPageName();
@endphp

@once
    <style>
        .pager-bar { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: .5rem 1rem; font-size: .875rem; }
        .pager-total { color: var(--bs-body-color, #212529); }
        .pager-pages { display: inline-flex; align-items: center; gap: .35rem; }
        .pager-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; height: 2rem; padding: 0 .5rem; border: 1px solid transparent; border-radius: .375rem; color: var(--bs-body-color, #212529); text-decoration: none; background: transparent; }
        a.pager-btn:hover { border-color: var(--bs-primary, #1f7a5c); color: var(--bs-primary, #1f7a5c); }
        .pager-btn.is-active { border-color: var(--bs-primary, #1f7a5c); color: var(--bs-primary, #1f7a5c); font-weight: 600; }
        .pager-btn.is-disabled { color: #adb5bd; cursor: not-allowed; }
        .pager-size-wrap { position: relative; }
        .pager-size-btn { display: inline-flex; align-items: center; justify-content: space-between; gap: .5rem; min-width: 7rem; height: 2rem; padding: 0 .75rem; font-size: .875rem; color: var(--bs-body-color, #212529); background: var(--bs-body-bg, #fff); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem; cursor: pointer; }
        .pager-size-btn:hover, .pager-size-btn[aria-expanded="true"] { border-color: var(--bs-primary, #1f7a5c); }
        .pager-size-btn svg { transition: transform .15s; }
        .pager-size-btn[aria-expanded="true"] svg { transform: rotate(180deg); }
        .pager-size-menu { position: absolute; right: 0; bottom: calc(100% + .35rem); z-index: 1050; min-width: 100%; margin: 0; padding: .25rem; list-style: none; background: var(--bs-body-bg, #fff); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .625rem; box-shadow: 0 .5rem 1.25rem rgba(0,0,0,.15); }
        .pager-size-menu[hidden] { display: none; }
        .pager-size-menu li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .45rem .65rem; border-radius: .4rem; white-space: nowrap; cursor: pointer; outline: none; }
        .pager-size-menu li:hover, .pager-size-menu li:focus { background: rgba(31,122,92,.1); }
        .pager-size-menu li.is-selected { color: var(--bs-primary, #1f7a5c); font-weight: 600; }
        .pager-bar { max-width: 100%; min-width: 0; }
        .pager-pages { max-width: 100%; min-width: 0; }
        .pager-mobile-info { display: none; }
        @media (max-width: 575.98px) {
            .pager-bar { justify-content: space-between; width: 100%; }
            .pager-total { flex: 1 0 100%; text-align: center; }
            .pager-pages { gap: .5rem; }
            .pager-pages .pager-num { display: none; }
            .pager-mobile-info { display: inline-block; min-width: 4rem; text-align: center; font-weight: 600; color: var(--bs-primary, #1f7a5c); }
            .pager-btn { min-width: 2.25rem; height: 2.25rem; font-size: 1.1rem; border-color: var(--bs-border-color, #dee2e6); }
            .pager-btn.is-disabled { opacity: .5; }
            .pager-size-btn { height: 2.25rem; }
            .pager-size-menu li { padding: .6rem .8rem; }
        }
    </style>
    <script>
        document.addEventListener('click', function (e) {
            var wrap = e.target.closest('.pager-size-wrap');
            document.querySelectorAll('.pager-size-wrap').forEach(function (w) {
                if (w !== wrap) { w.querySelector('.pager-size-menu').hidden = true; w.querySelector('.pager-size-btn').setAttribute('aria-expanded', 'false'); }
            });
            if (!wrap) return;
            var menu = wrap.querySelector('.pager-size-menu'), btn = wrap.querySelector('.pager-size-btn');
            var item = e.target.closest('li[data-value]');
            if (item) {
                var u = new window.URL(window.location.href);
                u.searchParams.set('per_page', item.dataset.value);
                u.searchParams.delete(wrap.dataset.pageName);
                window.location.href = u.toString();
            } else if (e.target.closest('.pager-size-btn')) {
                menu.hidden = !menu.hidden;
                btn.setAttribute('aria-expanded', String(!menu.hidden));
            }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.pager-size-menu li')) { e.preventDefault(); e.target.click(); }
            if (e.key === 'Escape') document.querySelectorAll('.pager-size-menu').forEach(function (m) { m.hidden = true; });
        });
    </script>
@endonce

@if ($paginator->total() > 0)
    <div class="pager-bar">
        <span class="pager-total">Total {{ $paginator->total() }} {{ \Illuminate\Support\Str::plural('item', $paginator->total()) }}</span>

        @if ($paginator->hasPages())
            {{ $paginator->links('vendor.pagination.links-only') }}
        @else
            <nav class="pager-pages" aria-label="Pagination">
                <span class="pager-btn is-disabled" aria-disabled="true">&lsaquo;</span>
                <span class="pager-btn is-active" aria-current="page">1</span>
                <span class="pager-btn is-disabled" aria-disabled="true">&rsaquo;</span>
            </nav>
        @endif

        <div class="pager-size-wrap" data-page-name="{{ $pageName }}">
            <button type="button" class="pager-size-btn" aria-haspopup="listbox" aria-expanded="false">
                <span>{{ $paginator->perPage() }} / page</span>
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6l5 5 5-5"/></svg>
            </button>
            <ul class="pager-size-menu" role="listbox" hidden>
                @foreach ($perPageOptions as $option)
                    <li role="option" tabindex="0" data-value="{{ $option }}" aria-selected="{{ $paginator->perPage() === $option ? 'true' : 'false' }}" @class(['is-selected' => $paginator->perPage() === $option])>
                        <span>{{ $option }} / page</span>
                        @if ($paginator->perPage() === $option)
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
