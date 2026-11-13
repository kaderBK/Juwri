{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', ___('Order hub'))</title>
    <style nonce="{{ csp_nonce() }}">
        /* Snoonu-style marketplace tokens (B12 hub redesign) */
        :root {
            --hub-bg: #f6f6f7; --hub-surface: #fff; --hub-ink: #17181c; --hub-ink-2: #6b7280;
            --hub-accent: #e4002b; --hub-accent-soft: #fdeaee; --hub-ok: #16a34a;
            --hub-star: #f5a623; --hub-line: #ececef; --hub-r: 16px; --hub-r-sm: 12px;
            --hub-shadow: 0 1px 2px rgba(16,24,40,.05), 0 6px 20px rgba(16,24,40,.06);
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Saudi Riyal", Inter, system-ui, sans-serif; background: var(--hub-bg); color: var(--hub-ink); }
        a { color: inherit; text-decoration: none; }
        img { display: block; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 16px 96px; }

        /* Topbar */
        .hub-top { position: sticky; top: 0; z-index: 50; background: var(--hub-surface); border-bottom: 1px solid var(--hub-line); }
        .hub-top-in { max-width: 1080px; margin: 0 auto; padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
        .hub-brand { font-weight: 800; font-size: 18px; letter-spacing: -.2px; white-space: nowrap; }
        .hub-brand b { color: var(--hub-accent); }
        .hub-cartbtn { margin-inline-start: auto; display: inline-flex; align-items: center; gap: 8px; background: var(--hub-ink); color: #fff; border-radius: 999px; padding: 10px 16px; font-weight: 600; font-size: 14px; min-height: 44px; }
        .hub-cartbtn .cnt { background: var(--hub-accent); border-radius: 999px; min-width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; padding: 0 6px; }

        /* Search */
        .hub-search { position: relative; margin: 16px 0; }
        .hub-search input { width: 100%; min-height: 48px; border: 0; border-radius: 999px; background: var(--hub-surface); box-shadow: var(--hub-shadow); padding: 12px 46px; font-size: 15px; color: var(--hub-ink); }
        .hub-search svg { position: absolute; inset-inline-start: 16px; top: 50%; transform: translateY(-50%); color: var(--hub-ink-2); }

        /* Chips */
        .hub-chips { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 12px; scrollbar-width: none; }
        .hub-chips::-webkit-scrollbar { display: none; }
        .hub-chip { flex: 0 0 auto; background: var(--hub-surface); border: 1px solid var(--hub-line); border-radius: 999px; padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; min-height: 44px; }
        .hub-chip.on { background: var(--hub-ink); color: #fff; border-color: var(--hub-ink); }

        /* Section headers */
        .hub-h { display: flex; align-items: baseline; justify-content: space-between; margin: 18px 0 12px; }
        .hub-h h2 { margin: 0; font-size: 20px; letter-spacing: -.3px; }
        .hub-h .muted { color: var(--hub-ink-2); font-size: 13px; }

        /* Store cards */
        .hub-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        .hub-store { background: var(--hub-surface); border-radius: var(--hub-r); overflow: hidden; box-shadow: var(--hub-shadow); transition: transform .12s ease; }
        .hub-store:hover { transform: translateY(-2px); }
        .hub-store .cov { position: relative; aspect-ratio: 16/9; background: linear-gradient(120deg, #ffd9e0, #ffe9d6); }
        .hub-store .cov img { width: 100%; height: 100%; object-fit: cover; }
        .hub-store .logo { position: absolute; inset-inline-start: 12px; bottom: -18px; width: 52px; height: 52px; border-radius: 14px; background: #fff; box-shadow: var(--hub-shadow); object-fit: cover; border: 2px solid #fff; }
        .hub-store .bd { padding: 24px 14px 14px; }
        .hub-store .nm { font-weight: 700; font-size: 16px; }
        .hub-store .meta { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-size: 13px; color: var(--hub-ink-2); flex-wrap: wrap; }
        .hub-star { color: var(--hub-star); font-weight: 700; }
        .hub-tags { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
        .hub-tag { background: var(--hub-bg); border-radius: 999px; padding: 4px 10px; font-size: 12px; color: var(--hub-ink-2); }

        /* Store page hero */
        .hub-hero { position: relative; border-radius: var(--hub-r); overflow: hidden; margin-top: 16px; background: linear-gradient(120deg, #ffd9e0, #ffe9d6); aspect-ratio: 21/8; min-height: 160px; }
        .hub-hero img.cover { width: 100%; height: 100%; object-fit: cover; }
        .hub-hero .back { position: absolute; top: 12px; inset-inline-start: 12px; background: #fff; border-radius: 999px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; box-shadow: var(--hub-shadow); }
        .hub-storehead { background: var(--hub-surface); border-radius: var(--hub-r); box-shadow: var(--hub-shadow); padding: 16px; margin-top: -34px; position: relative; display: flex; gap: 14px; align-items: center; }
        .hub-storehead img.logo { width: 64px; height: 64px; border-radius: 16px; object-fit: cover; border: 1px solid var(--hub-line); background:#fff; }
        .hub-storehead h1 { margin: 0; font-size: 22px; letter-spacing: -.3px; }
        .hub-storehead .meta { margin-top: 4px; font-size: 13px; color: var(--hub-ink-2); display: flex; gap: 10px; flex-wrap: wrap; }

        /* Category tabs (store page) */
        .hub-tabs { position: sticky; top: 64px; z-index: 40; background: var(--hub-bg); padding: 10px 0; }

        /* Item cards */
        .hub-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
        .hub-item { display: flex; gap: 12px; background: var(--hub-surface); border-radius: var(--hub-r-sm); box-shadow: var(--hub-shadow); padding: 12px; align-items: center; }
        .hub-item .tx { flex: 1; min-width: 0; }
        .hub-item .nm { font-weight: 600; font-size: 15px; }
        .hub-item .ds { color: var(--hub-ink-2); font-size: 13px; margin-top: 2px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .hub-item .pr { margin-top: 6px; font-weight: 700; color: var(--hub-accent); }
        .hub-item .im { position: relative; width: 96px; height: 96px; flex: 0 0 auto; }
        .hub-item .im img, .hub-item .im .ph { width: 96px; height: 96px; border-radius: 12px; object-fit: cover; }
        .hub-item .im .ph { background: var(--hub-bg); display: flex; align-items: center; justify-content: center; font-size: 30px; }

        /* Stepper */
        .hub-step { position: absolute; bottom: -8px; inset-inline-end: -6px; display: flex; align-items: center; gap: 0; background: #fff; border-radius: 999px; box-shadow: var(--hub-shadow); }
        .hub-step button { width: 34px; height: 34px; border: 0; background: none; font-size: 18px; font-weight: 700; cursor: pointer; color: var(--hub-ink); border-radius: 999px; }
        .hub-step button.add1 { background: var(--hub-accent); color: #fff; }
        .hub-step .q { min-width: 20px; text-align: center; font-weight: 700; font-size: 14px; }
        .hub-step[data-q="0"] .dec, .hub-step[data-q="0"] .q { display: none; }

        /* Sticky cart bar */
        .hub-bar { position: fixed; bottom: 12px; inset-inline: 12px; z-index: 60; max-width: 560px; margin: 0 auto; background: var(--hub-accent); color: #fff; border-radius: 999px; box-shadow: 0 10px 30px rgba(228,0,43,.35); display: none; align-items: center; justify-content: space-between; padding: 14px 20px; font-weight: 700; }
        .hub-bar.show { display: flex; }

        /* Cards, checkout, misc */
        .hub-card { background: var(--hub-surface); border-radius: var(--hub-r); box-shadow: var(--hub-shadow); padding: 16px; margin-bottom: 14px; }
        .hub-line { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--hub-line); }
        .hub-line:last-child { border-bottom: 0; }
        .hub-line img, .hub-line .ph { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; background: var(--hub-bg); flex: 0 0 auto; display:flex; align-items:center; justify-content:center; }
        .hub-line .q { color: var(--hub-ink-2); font-size: 13px; }
        .hub-line .amt { margin-inline-start: auto; font-weight: 700; white-space: nowrap; }
        .hub-total { display: flex; justify-content: space-between; font-size: 17px; font-weight: 800; padding-top: 12px; }
        .hub-input { width: 100%; min-height: 48px; border: 1px solid var(--hub-line); border-radius: var(--hub-r-sm); padding: 12px 14px; font-size: 15px; margin-top: 8px; background: var(--hub-surface); }
        .hub-cta { display: flex; width: 100%; min-height: 52px; align-items: center; justify-content: center; border: 0; border-radius: 999px; background: var(--hub-accent); color: #fff; font-size: 16px; font-weight: 800; cursor: pointer; margin-top: 14px; }
        .hub-cta.secondary { background: var(--hub-ink); }
        .hub-empty { text-align: center; color: var(--hub-ink-2); padding: 48px 16px; }
        .hub-empty .e { font-size: 42px; margin-bottom: 8px; }
        .alert { padding: 12px 16px; border-radius: var(--hub-r-sm); margin: 12px 0; font-size: 14px; }
        .alert.ok { background: #dcfce7; color: #166534; }
        .alert.err { background: #fee2e2; color: #991b1b; }
        .hub-toast { position: fixed; bottom: 80px; inset-inline: 0; margin: 0 auto; width: fit-content; max-width: 90%; background: var(--hub-ink); color: #fff; border-radius: 999px; padding: 10px 18px; font-size: 14px; z-index: 70; opacity: 0; transform: translateY(10px); transition: opacity .2s, transform .2s; pointer-events: none; }
        .hub-toast.show { opacity: 1; transform: translateY(0); }

        /* ── Motion pack (Snoonu-feel) ─────────────────────────────────────── */
        @keyframes hubUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        .hub-anim { animation: hubUp .45s cubic-bezier(.2,.7,.3,1) both; animation-delay: calc(var(--i, 0) * 55ms); }
        @keyframes hubPop { 0% { transform: scale(1); } 45% { transform: scale(1.35); } 100% { transform: scale(1); } }
        .hub-pop { animation: hubPop .28s ease; }
        @keyframes hubBounce { 0% { transform: scale(1); } 40% { transform: scale(1.25); } 100% { transform: scale(1); } }
        .hub-bounce { animation: hubBounce .3s ease; }
        .hub-store, .hub-chip, .hub-cta, .hub-cartbtn, .hub-step button, .hub-fcard { transition: transform .12s ease, box-shadow .12s ease; }
        .hub-store:active, .hub-chip:active, .hub-cta:active, .hub-fcard:active { transform: scale(.97); }
        .hub-step button:active { transform: scale(.88); }
        .hub-bar { transform: translateY(80px); opacity: 0; transition: transform .28s cubic-bezier(.2,.7,.3,1), opacity .28s; }
        .hub-bar.show { transform: none; opacity: 1; }
        /* Spinner-in-button (Snoonu CTA loading) */
        .hub-cta.busy { pointer-events: none; opacity: .85; }
        .hub-cta.busy .lbl { visibility: hidden; }
        .hub-cta .spin { display: none; position: absolute; width: 22px; height: 22px; border: 3px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: hubSpin .7s linear infinite; }
        .hub-cta.busy .spin { display: block; }
        .hub-cta { position: relative; }
        @keyframes hubSpin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            .hub-anim, .hub-pop, .hub-bounce { animation: none !important; }
            .hub-bar { transition: none; }
        }

        /* Horizontal snap carousels ("Featured" / "Best selling") */
        .hub-hscroll { display: flex; gap: 12px; overflow-x: auto; padding: 4px 2px 14px; scroll-snap-type: x mandatory; scrollbar-width: none; }
        .hub-hscroll::-webkit-scrollbar { display: none; }
        .hub-hscroll > * { scroll-snap-align: start; flex: 0 0 auto; }
        .hub-fcard { width: 150px; background: var(--hub-surface); border-radius: var(--hub-r); box-shadow: var(--hub-shadow); overflow: hidden; }
        .hub-fcard .cv { aspect-ratio: 1/1; background: linear-gradient(120deg,#ffd9e0,#ffe9d6); position: relative; }
        .hub-fcard .cv img { width: 100%; height: 100%; object-fit: cover; }
        .hub-fcard .bd { padding: 10px 12px; }
        .hub-fcard .nm { font-weight: 700; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hub-fcard .mt { font-size: 12px; color: var(--hub-ink-2); margin-top: 2px; }
        .hub-fcard .pr { font-size: 13px; font-weight: 700; color: var(--hub-accent); margin-top: 2px; }

        /* Favorite heart */
        .hub-heart { position: absolute; top: 10px; inset-inline-end: 10px; width: 36px; height: 36px; border: 0; border-radius: 999px; background: rgba(255,255,255,.92); box-shadow: var(--hub-shadow); cursor: pointer; font-size: 17px; line-height: 1; color: #b9bec9; display: flex; align-items: center; justify-content: center; z-index: 2; transition: transform .12s ease; }
        .hub-heart:active { transform: scale(.85); }
        .hub-heart.on { color: var(--hub-accent); }

        /* Bottom sheet (item detail / note editor) */
        .hub-sheetwrap { position: fixed; inset: 0; z-index: 80; display: none; }
        .hub-sheetwrap.open { display: block; }
        .hub-sheetwrap .bk { position: absolute; inset: 0; background: rgba(10,12,16,.45); opacity: 0; transition: opacity .25s; }
        .hub-sheetwrap.open .bk { opacity: 1; }
        .hub-sheet { position: absolute; bottom: 0; inset-inline: 0; margin: 0 auto; max-width: 560px; background: var(--hub-surface); border-radius: 20px 20px 0 0; box-shadow: 0 -10px 40px rgba(16,24,40,.2); transform: translateY(100%); transition: transform .3s cubic-bezier(.2,.7,.3,1); max-height: 88vh; display: flex; flex-direction: column; }
        .hub-sheetwrap.open .hub-sheet { transform: none; }
        .hub-sheet .grab { width: 44px; height: 5px; border-radius: 999px; background: var(--hub-line); margin: 10px auto 0; }
        .hub-sheet .sc { overflow-y: auto; padding: 12px 18px 18px; }
        .hub-sheet .big { width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: var(--hub-r); background: var(--hub-bg); }
        .hub-sheet h3 { margin: 12px 0 4px; font-size: 19px; }
        .hub-sheet .ds { color: var(--hub-ink-2); font-size: 14px; }
        .hub-sheet .pr { color: var(--hub-accent); font-weight: 800; font-size: 17px; margin-top: 6px; }
        .hub-sheet textarea { width: 100%; border: 1px solid var(--hub-line); border-radius: var(--hub-r-sm); padding: 10px 12px; font-size: 14px; min-height: 64px; resize: vertical; margin-top: 8px; font-family: inherit; }
        .hub-sheet .ft { display: flex; gap: 12px; align-items: center; padding: 12px 18px calc(14px + env(safe-area-inset-bottom)); border-top: 1px solid var(--hub-line); }
        .hub-sheet .qtybox { display: inline-flex; align-items: center; gap: 6px; background: var(--hub-bg); border-radius: 999px; padding: 4px; }
        .hub-sheet .qtybox button { width: 38px; height: 38px; border: 0; border-radius: 999px; background: var(--hub-surface); box-shadow: var(--hub-shadow); font-size: 18px; font-weight: 700; cursor: pointer; }
        .hub-sheet .qtybox .q { min-width: 26px; text-align: center; font-weight: 800; }
        .hub-sheet .ft .hub-cta { margin-top: 0; flex: 1; }

        /* Checkout line steppers + note row */
        .hub-line .mini { display: inline-flex; align-items: center; gap: 4px; background: var(--hub-bg); border-radius: 999px; padding: 3px; }
        .hub-line .mini button { width: 30px; height: 30px; border: 0; border-radius: 999px; background: var(--hub-surface); box-shadow: var(--hub-shadow); font-weight: 700; cursor: pointer; }
        .hub-line .mini .q { min-width: 20px; text-align: center; font-weight: 700; font-size: 13px; }
        .hub-noterow { font-size: 12.5px; color: var(--hub-ink-2); margin-top: 2px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .hub-noterow.set { color: var(--hub-ink); font-style: italic; }

        @media (max-width: 640px) {
            .hub-grid { grid-template-columns: 1fr 1fr; }
            .hub-store .nm { font-size: 14px; }
            .hub-items { grid-template-columns: 1fr; }
        }
    </style>
    {{-- Saudi Riyal (U+20C1) / Dirham (U+20C3) glyph font, so store prices in SAR/AED render the sign instead of a tofu box. --}}
    @include('partials.riyal-font')
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
    <div class="hub-top">
        <div class="hub-top-in">
            <a class="hub-brand" href="{{ route('orderhub.index') }}">{{ ___('Order') }}<b>{{ ___('Hub') }}</b></a>
            @yield('topbar-middle')
            <a class="hub-cartbtn" href="{{ route('orderhub.checkout') }}" id="hubCartBtn">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                <span>{{ ___('Cart') }}</span>
                @php $hubCount = \Juwri\Core\Support\ViewHelpers::cartQuantity($cart ?? []); @endphp
                <span class="cnt" id="hubCartCount" @if(!$hubCount) style="display:none" @endif>{{ $hubCount }}</span>
            </a>
        </div>
    </div>

    <div class="wrap">
        @if(session('success'))<div class="alert ok">{{ session('success') }}</div>@endif
        @if(isset($errors) && $errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif
        @yield('content')
    </div>

    <div class="hub-toast" id="hubToast"></div>

    <script nonce="{{ csp_nonce() }}">
        // Shared hub JS: JSON cart calls + topbar/bar sync + toast.
        window.hub = {
            csrf: document.querySelector('meta[name=csrf-token]').content,
            async post(url, body) {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    body: JSON.stringify(body),
                });
                return { ok: r.ok, data: await r.json().catch(() => ({})) };
            },
            counts(cart) {
                let items = 0, total = 0;
                (cart || []).forEach(s => s.items.forEach(i => { items += i.quantity; total += i.line_total; }));
                return { items, total };
            },
            sync(cart) {
                const { items, total } = this.counts(cart);
                const c = document.getElementById('hubCartCount');
                if (c) {
                    c.textContent = items; c.style.display = items ? '' : 'none';
                    c.classList.remove('hub-bounce'); void c.offsetWidth; c.classList.add('hub-bounce');
                }
                const bar = document.getElementById('hubBar');
                if (bar) {
                    bar.classList.toggle('show', items > 0);
                    const n = bar.querySelector('[data-bar-count]'); if (n) n.textContent = items;
                    const t = bar.querySelector('[data-bar-total]'); if (t) t.textContent = total.toFixed(2);
                }
            },
            // Guest favourites, device-local (Snoonu heart pattern).
            favs() { try { return JSON.parse(localStorage.getItem('hub-favs') || '[]'); } catch (e) { return []; } },
            toggleFav(id) {
                const f = this.favs(); const i = f.indexOf(id);
                i === -1 ? f.push(id) : f.splice(i, 1);
                localStorage.setItem('hub-favs', JSON.stringify(f));
                return i === -1;
            },
            // Bottom-sheet open/close helpers.
            openSheet(id) { const w = document.getElementById(id); if (w) { w.classList.add('open'); document.body.style.overflow = 'hidden'; } },
            closeSheet(id) { const w = document.getElementById(id); if (w) { w.classList.remove('open'); document.body.style.overflow = ''; } },
            toast(msg) {
                const el = document.getElementById('hubToast');
                el.textContent = msg; el.classList.add('show');
                clearTimeout(this._t); this._t = setTimeout(() => el.classList.remove('show'), 1800);
            },
        };
    </script>
    @stack('scripts')
</body>
</html>
