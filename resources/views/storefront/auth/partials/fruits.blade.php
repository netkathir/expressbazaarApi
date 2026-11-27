{{-- Decorative fruits the visitor can drag around; they return to these spots on refresh. --}}
<div class="sf-fruits" aria-hidden="true">
    <div class="sf-fruit sf-fruit-lemon" style="left:12%;top:8%;--s:70px;--d:0s"><span></span></div>
    <div class="sf-fruit sf-fruit-orange" style="left:6%;top:36%;--s:84px;--d:-1s"><span></span></div>
    <div class="sf-fruit sf-fruit-chilli" style="left:14%;top:66%;--s:76px;--d:-2s"><span></span></div>
    <div class="sf-fruit sf-fruit-apple" style="left:6%;top:84%;--s:90px;--d:-3s"><span></span></div>
    <div class="sf-fruit sf-fruit-apple" style="right:13%;top:10%;--s:84px;--d:-1.5s"><span></span></div>
    <div class="sf-fruit sf-fruit-chilli" style="right:9%;top:42%;--s:76px;--d:-2.5s"><span></span></div>
    <div class="sf-fruit sf-fruit-orange" style="right:14%;top:72%;--s:80px;--d:-0.5s"><span></span></div>
    <div class="sf-fruit sf-fruit-lemon" style="right:6%;top:86%;--s:84px;--d:-3.5s"><span></span></div>
</div>
<script>
    (function () {
        document.querySelectorAll('.sf-fruit').forEach(function (el) {
            var startX = 0, startY = 0, x = 0, y = 0;
            el.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                el.setPointerCapture(e.pointerId);
                el.classList.add('is-dragging');
                startX = e.clientX - x;
                startY = e.clientY - y;
            });
            el.addEventListener('pointermove', function (e) {
                if (!el.classList.contains('is-dragging')) return;
                x = e.clientX - startX;
                y = e.clientY - startY;
                el.style.transform = 'translate(' + x + 'px,' + y + 'px)';
            });
            ['pointerup', 'pointercancel'].forEach(function (type) {
                el.addEventListener(type, function () { el.classList.remove('is-dragging'); });
            });
        });
    })();
</script>
