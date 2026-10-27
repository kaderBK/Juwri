{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('order-hub.layout')
@section('title', $store->title)
@section('content')
    {{-- Hero cover + floating back button (Snoonu store header pattern) --}}
    <div class="hub-hero">
        @if($store->hub_cover)<img class="cover" src="{{ $store->hub_cover }}" alt="" data-csp-error="remove">@endif
        <a class="back" href="{{ route('orderhub.index') }}" aria-label="{{ ___('All stores') }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="transform: scaleX({{ is_rtl(app()->getLocale()) ? -1 : 1 }})"><path d="m15 18-6-6 6-6"/></svg>
        </a>
    </div>

    <div class="hub-storehead">
        @if($store->hub_logo)<img class="logo" src="{{ $store->hub_logo }}" alt="" data-csp-error="remove">@endif
        <div>
            <h1>{{ $store->title }}</h1>
            <div class="meta">
                @if($store->hub_rating)<span class="hub-star">★ {{ number_format($store->hub_rating, 1) }}</span><span>({{ $store->hub_reviews }})</span>@endif
                @if($store->timing)<span>· {{ $store->timing }}</span>@endif
                @if($store->address)<span>· {{ \Illuminate\Support\Str::limit($store->address, 40) }}</span>@endif
            </div>
        </div>
    </div>

    {{-- Best selling: most-ordered items, horizontal snap carousel (Snoonu pattern) --}}
    @if($bestSelling->count() >= 2)
        <div class="hub-h"><h2>{{ ___('Best selling') }}</h2></div>
        <div class="hub-hscroll">
            @foreach($bestSelling as $i => $item)
                <div class="hub-fcard hub-anim" style="--i: {{ $i }}" data-open-item="{{ $item->id }}">
                    <div class="cv">
                        @if($item->image)<img src="{{ media_url('storage/menu/', $item->image, $store->id) }}" alt="" loading="lazy" data-csp-error="remove">@endif
                    </div>
                    <div class="bd">
                        <div class="nm">{{ $item->name }}</div>
                        <div class="pr">{{ number_format($item->price, 2) }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Category tabs → anchor scroll + scrollspy --}}
    @if($sections->count() > 1)
        <div class="hub-tabs">
            <div class="hub-chips" id="hubSecTabs">
                @foreach($sections as $i => $sec)
                    <button class="hub-chip @if($i===0) on @endif" data-target="sec-{{ $sec['id'] }}">{{ $sec['name'] }}</button>
                @endforeach
            </div>
        </div>
    @endif

    @foreach($sections as $sec)
        <div class="hub-h" id="sec-{{ $sec['id'] }}" data-sec>
            <h2>{{ $sec['name'] }}</h2>
            <span class="muted">{{ $sec['items']->count() }}</span>
        </div>
        <div class="hub-items">
            @foreach($sec['items'] as $i => $item)
                <div class="hub-item hub-anim" style="--i: {{ min($i, 6) }}" data-open-item="{{ $item->id }}"
                     data-name="{{ $item->name }}" data-price="{{ (float) $item->price }}"
                     data-desc="{{ $item->description }}"
                     data-img="{{ $item->image ? media_url('storage/menu/', $item->image, $store->id) : '' }}">
                    <div class="tx">
                        <div class="nm">{{ $item->name }}</div>
                        @if($item->description)<div class="ds">{{ $item->description }}</div>@endif
                        <div class="pr">{{ number_format($item->price, 2) }}</div>
                    </div>
                    <div class="im">
                        @if($item->image)
                            <img src="{{ media_url('storage/menu/', $item->image, $store->id) }}" alt="{{ $item->name }}" loading="lazy" data-csp-error="replace-ph">
                        @else
                            <div class="ph">&#127869;</div>
                        @endif
                        <div class="hub-step" data-item="{{ $item->id }}" data-q="{{ $qty[$item->id] ?? 0 }}">
                            <button type="button" class="dec" aria-label="{{ ___('Remove') }}">&minus;</button>
                            <span class="q">{{ $qty[$item->id] ?? 0 }}</span>
                            <button type="button" class="add1" aria-label="{{ ___('Add') }}">+</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- Item detail bottom sheet: image, description, qty, special request, add CTA --}}
    <div class="hub-sheetwrap" id="hubItemSheet">
        <div class="bk" data-close></div>
        <div class="hub-sheet">
            <div class="grab"></div>
            <div class="sc">
                <img class="big" id="shImg" alt="" style="display:none" data-csp-error="hide">
                <h3 id="shName"></h3>
                <div class="ds" id="shDesc"></div>
                <div class="pr" id="shPrice"></div>
                <label class="ds" style="display:block;margin-top:12px;font-weight:600">{{ ___('Special request') }}</label>
                <textarea id="shNote" maxlength="255" placeholder="{{ ___('e.g. no onions, extra spicy') }}"></textarea>
            </div>
            <div class="ft">
                <span class="qtybox">
                    <button type="button" id="shDec">&minus;</button>
                    <span class="q" id="shQty">1</span>
                    <button type="button" id="shInc">+</button>
                </span>
                <button type="button" class="hub-cta" id="shAdd">
                    <span class="lbl">{{ ___('Add to cart') }} · <span id="shTotal"></span></span>
                    <span class="spin"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- Sticky view-cart bar --}}
    @php $barCount = \Juwri\Core\Support\ViewHelpers::cartQuantity($cart); $barTotal = collect($cart)->sum('total'); @endphp
    <a class="hub-bar @if($barCount) show @endif" id="hubBar" href="{{ route('orderhub.checkout') }}">
        <span>{{ ___('View cart') }} · <span data-bar-count>{{ $barCount }}</span></span>
        <span data-bar-total>{{ number_format($barTotal, 2) }}</span>
    </a>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    (function () {
        const RID = {{ (int) $store->id }};
        const UPDATE = @json(route('orderhub.update'));
        const ADD = @json(route('orderhub.add'));
        let busy = false;

        function bumpStep(step, q) {
            step.dataset.q = q;
            const el = step.querySelector('.q');
            el.textContent = q;
            el.classList.remove('hub-pop'); void el.offsetWidth; el.classList.add('hub-pop');
        }

        // Card steppers: absolute-quantity updates via the JSON endpoint.
        document.querySelectorAll('.hub-step').forEach(step => {
            step.addEventListener('click', async (e) => {
                e.stopPropagation(); // don't open the item sheet
                const inc = e.target.closest('.add1') ? 1 : (e.target.closest('.dec') ? -1 : 0);
                if (!inc || busy) return;
                busy = true;
                const q = Math.max(0, parseInt(step.dataset.q || '0', 10) + inc);
                const res = await hub.post(UPDATE, { store_id: RID, item_id: parseInt(step.dataset.item, 10), quantity: q });
                busy = false;
                if (!res.ok) { hub.toast(res.data.error || @json(___('Could not update the cart.'))); return; }
                bumpStep(step, q);
                hub.sync(res.data.cart);
            });
        });

        // ── Item detail sheet ──
        const sheet = { id: 0, price: 0, qty: 1 };
        const $ = (x) => document.getElementById(x);
        function fmt(v) { return v.toFixed(2); }
        function paintTotal() { $('shQty').textContent = sheet.qty; $('shTotal').textContent = fmt(sheet.price * sheet.qty); }
        function openItem(card) {
            sheet.id = parseInt(card.dataset.openItem, 10);
            sheet.price = parseFloat(card.dataset.price || '0');
            sheet.qty = 1;
            $('shName').textContent = card.dataset.name || '';
            $('shDesc').textContent = card.dataset.desc || '';
            $('shPrice').textContent = fmt(sheet.price);
            $('shNote').value = '';
            const img = $('shImg');
            if (card.dataset.img) { img.src = card.dataset.img; img.style.display = ''; } else { img.style.display = 'none'; }
            paintTotal();
            hub.openSheet('hubItemSheet');
        }
        document.querySelectorAll('[data-open-item]').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.closest('.hub-step')) return;
                // Best-selling mini cards carry only the id — resolve the full card.
                const full = card.dataset.price !== undefined
                    ? card
                    : document.querySelector('.hub-item[data-open-item="' + card.dataset.openItem + '"]');
                full && openItem(full);
            });
        });
        $('shDec').addEventListener('click', () => { sheet.qty = Math.max(1, sheet.qty - 1); paintTotal(); });
        $('shInc').addEventListener('click', () => { sheet.qty = Math.min(99, sheet.qty + 1); paintTotal(); });
        document.querySelector('#hubItemSheet [data-close]').addEventListener('click', () => hub.closeSheet('hubItemSheet'));
        window.addEventListener('keydown', (e) => { if (e.key === 'Escape') hub.closeSheet('hubItemSheet'); });
        $('shAdd').addEventListener('click', async () => {
            const btn = $('shAdd');
            btn.classList.add('busy');
            const res = await hub.post(ADD, {
                store_id: RID, item_id: sheet.id, quantity: sheet.qty,
                note: ($('shNote').value || '').trim() || null,
            });
            btn.classList.remove('busy');
            if (!res.ok) { hub.toast(res.data.error || @json(___('Could not update the cart.'))); return; }
            hub.sync(res.data.cart);
            // Mirror the new absolute qty onto the card stepper.
            const step = document.querySelector('.hub-step[data-item="' + sheet.id + '"]');
            if (step && res.data.cart) {
                const store = res.data.cart.find(s => s.store_id === RID);
                const line = store && store.items.find(i => i.item_id === sheet.id);
                if (line) bumpStep(step, line.quantity);
            }
            hub.closeSheet('hubItemSheet');
            hub.toast(@json(___('Added to cart.')));
        });

        // ── Category tabs: click scroll + scrollspy ──
        const tabs = document.getElementById('hubSecTabs');
        function activate(id) {
            if (!tabs) return;
            tabs.querySelectorAll('.hub-chip').forEach(x => {
                const on = x.dataset.target === id;
                x.classList.toggle('on', on);
                if (on) x.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            });
        }
        tabs && tabs.addEventListener('click', (e) => {
            const b = e.target.closest('.hub-chip'); if (!b) return;
            activate(b.dataset.target);
            const el = document.getElementById(b.dataset.target);
            el && el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        // Scrollspy: highlight the section currently at the top of the viewport.
        if (tabs && 'IntersectionObserver' in window) {
            const io = new IntersectionObserver((es) => {
                es.forEach(en => { if (en.isIntersecting) activate(en.target.id); });
            }, { rootMargin: '-20% 0px -70% 0px' });
            document.querySelectorAll('[data-sec]').forEach(s => io.observe(s));
        }
    })();
</script>
@endpush
