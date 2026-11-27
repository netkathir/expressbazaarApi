{{--
    Replaces the OS/browser select popup with a compact themed dropdown for
    select[data-custom-select]. The native <select> stays in the DOM (hidden), so
    form submit and existing JS (option rebuilds, .value, "change" listeners) keep working.
--}}
<style>
    .cs-wrap { position: relative; }
    .cs-wrap > select { position: absolute; inset: 0; opacity: 0; pointer-events: none; }
    .cs-btn { display: flex; align-items: center; justify-content: space-between; gap: .5rem; width: 100%; min-height: 2.5rem; padding: .45rem .75rem; text-align: left; font: inherit; font-size: .9rem; color: inherit; background: #fff; border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem; cursor: pointer; }
    .cs-btn:focus-visible, .cs-btn[aria-expanded="true"] { outline: 0; border-color: var(--sf-purple, #1f7a5c); box-shadow: 0 0 0 .15rem rgba(31,122,92,.15); }
    .cs-btn.is-placeholder .cs-label { color: #6c757d; }
    .cs-btn:disabled { background: #f1f3f5; cursor: not-allowed; opacity: .7; }
    .cs-btn svg { flex: none; transition: transform .15s; }
    .cs-btn[aria-expanded="true"] svg { transform: rotate(180deg); }
    .cs-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cs-menu { position: absolute; left: 0; right: 0; top: calc(100% + .3rem); z-index: 1060; max-height: 15rem; overflow-y: auto; margin: 0; padding: .25rem; list-style: none; background: #fff; border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .625rem; box-shadow: 0 .5rem 1.25rem rgba(0,0,0,.15); -webkit-overflow-scrolling: touch; }
    .cs-menu[hidden] { display: none; }
    .cs-menu.is-up { top: auto; bottom: calc(100% + .3rem); }
    .cs-menu li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .5rem .65rem; font-size: .9rem; border-radius: .4rem; cursor: pointer; }
    .cs-menu li.is-placeholder { color: #6c757d; }
    .cs-menu li:hover, .cs-menu li.is-active { background: rgba(31,122,92,.1); }
    .cs-menu li.is-selected { color: var(--sf-purple, #1f7a5c); font-weight: 600; }
    .cs-menu li.is-empty { color: #6c757d; cursor: default; }
</style>
<script>
    (function () {
        var CHEVRON = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6l5 5 5-5"/></svg>';
        var CHECK = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5l3.5 3.5L13 4.5"/></svg>';
        var all = [];

        function closeAll(except) {
            all.forEach(function (c) { if (c !== except) c.close(); });
        }

        function enhance(select) {
            if (select.dataset.csReady) return;
            select.dataset.csReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'cs-wrap';
            select.parentNode.insertBefore(wrap, select);
            wrap.appendChild(select);
            select.tabIndex = -1;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cs-btn';
            btn.setAttribute('aria-haspopup', 'listbox');
            btn.setAttribute('aria-expanded', 'false');
            btn.innerHTML = '<span class="cs-label"></span>' + CHEVRON;
            var menu = document.createElement('ul');
            menu.className = 'cs-menu';
            menu.setAttribute('role', 'listbox');
            menu.hidden = true;
            wrap.appendChild(btn);
            wrap.appendChild(menu);

            var label = btn.querySelector('.cs-label');
            var active = -1;

            function sync() {
                var opt = select.options[select.selectedIndex];
                var isPlaceholder = !opt || opt.value === '';
                label.textContent = opt ? opt.text : '';
                btn.classList.toggle('is-placeholder', isPlaceholder);
                btn.disabled = select.disabled;
            }

            function build() {
                menu.innerHTML = '';
                Array.prototype.forEach.call(select.options, function (opt, i) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.dataset.index = i;
                    li.className = (opt.value === '' ? 'is-placeholder ' : '') + (i === select.selectedIndex ? 'is-selected' : '');
                    li.innerHTML = '<span></span>' + (i === select.selectedIndex ? CHECK : '');
                    li.firstChild.textContent = opt.text;
                    if (opt.disabled) li.style.opacity = '.5';
                    menu.appendChild(li);
                });
                if (!select.options.length) {
                    var empty = document.createElement('li');
                    empty.className = 'is-empty';
                    empty.textContent = 'No options available';
                    menu.appendChild(empty);
                }
                sync();
            }

            function setActive(i) {
                var items = menu.querySelectorAll('li[data-index]');
                if (!items.length) return;
                active = Math.max(0, Math.min(items.length - 1, i));
                items.forEach(function (li, n) { li.classList.toggle('is-active', n === active); });
                items[active].scrollIntoView({ block: 'nearest' });
            }

            function open() {
                if (btn.disabled) return;
                closeAll(api);
                build();
                menu.hidden = false;
                var rect = btn.getBoundingClientRect();
                var below = window.innerHeight - rect.bottom;
                menu.classList.toggle('is-up', below < 200 && rect.top > below);
                btn.setAttribute('aria-expanded', 'true');
                setActive(select.selectedIndex);
            }

            function close() {
                menu.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            }

            function choose(i) {
                var opt = select.options[i];
                if (!opt || opt.disabled) return;
                close();
                if (select.selectedIndex !== i) {
                    select.selectedIndex = i;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
                sync();
                btn.focus();
            }

            var api = { close: close };
            all.push(api);

            btn.addEventListener('click', function () { menu.hidden ? open() : close(); });
            menu.addEventListener('click', function (e) {
                var li = e.target.closest('li[data-index]');
                if (li) choose(Number(li.dataset.index));
            });
            btn.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (menu.hidden) open(); else setActive(active + (e.key === 'ArrowDown' ? 1 : -1));
                } else if ((e.key === 'Enter' || e.key === ' ') && !menu.hidden) {
                    e.preventDefault();
                    choose(active);
                } else if (e.key === 'Escape') {
                    close();
                }
            });

            // Keep in sync when other scripts rebuild options, toggle disabled, or set .value
            new MutationObserver(function () { sync(); if (!menu.hidden) build(); })
                .observe(select, { childList: true, attributes: true, attributeFilter: ['disabled'] });
            select.addEventListener('change', sync);
            var desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
            Object.defineProperty(select, 'value', {
                configurable: true,
                get: function () { return desc.get.call(this); },
                set: function (v) { desc.set.call(this, v); sync(); }
            });
            // Label click / form reset
            if (select.form) select.form.addEventListener('reset', function () { setTimeout(sync, 0); });

            build();
        }

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.cs-wrap')) closeAll();
        });
        document.querySelectorAll('select[data-custom-select]').forEach(enhance);
    })();
</script>
