{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('order-hub.layout')
@section('title', ___('Checkout'))
@section('content')
    <div class="hub-h"><h2>{{ ___('Your order') }}</h2></div>

    @if(empty($cart))
        <div class="hub-card hub-empty">
            <div class="e">&#128722;</div>
            <p>{{ ___('Your cart is empty.') }}</p>
            <a class="hub-cta secondary" style="max-width:280px;margin:0 auto" href="{{ route('orderhub.index') }}">{{ ___('Browse stores') }}</a>
        </div>
    @else
        @php $grand = collect($cart)->sum('total'); $grandStoreId = (int) (collect($cart)->first()['store_id'] ?? 0); @endphp

        {{-- Per-store groups (Snoonu "Your Order" pattern: thumb · name · xN · price) --}}
        @foreach($cart as $store)
            <div class="hub-card">
                <div class="hub-h" style="margin:0 0 6px"><h2 style="font-size:16px">{{ $store['title'] ?? ___('Store') }}</h2>
                    <span class="muted">{{ store_money_format((float) $store['total'], (int) $store['store_id']) }}</span></div>
                @foreach($store['items'] as $it)
                    <div class="hub-line" data-line data-rid="{{ $store['store_id'] }}" data-item="{{ $it['item_id'] }}" data-qty="{{ $it['quantity'] }}" data-name="{{ $it['name'] }}">
                        @if($it['image'])<img src="{{ $it['image'] }}" alt="" data-csp-error="replace-ph">@else<div class="ph">&#127869;</div>@endif
                        <div style="min-width:0">
                            <div>{{ $it['name'] }}</div>
                            <div class="q">{{ store_money_format((float) $it['unit_price'], (int) $store['store_id']) }} × {{ $it['quantity'] }}</div>
                            {{-- Snoonu "Add / Edit Special Request" per line --}}
                            <span class="hub-noterow @if($it['note']) set @endif" data-note-edit data-note="{{ $it['note'] }}">
                                &#128172; @if($it['note']){{ \Illuminate\Support\Str::limit($it['note'], 40) }}@else{{ ___('Add special request') }}@endif
                            </span>
                        </div>
                        <span class="amt">{{ store_money_format((float) $it['line_total'], (int) $store['store_id']) }}</span>
                        <span class="mini">
                            <button type="button" data-step="-1" aria-label="{{ ___('Remove') }}">&minus;</button>
                            <span class="q">{{ $it['quantity'] }}</span>
                            <button type="button" data-step="1" aria-label="{{ ___('Add') }}">+</button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="hub-card">
            <div class="hub-total"><span>{{ ___('Total') }}</span><span>{{ store_money_format((float) $grand, $grandStoreId) }}</span></div>
            <p class="muted" style="color:var(--hub-ink-2);font-size:13px;margin:6px 0 0">
                {{ ___('You will place a separate order at each store. Pay at each store.') }}
            </p>
        </div>

        {{-- Contact + place --}}
        <form method="post" action="{{ route('orderhub.placeOrder') }}" class="hub-card" id="hubPlaceForm">
            @csrf
            <div class="hub-h" style="margin:0"><h2 style="font-size:16px">{{ ___('Contact details') }}</h2></div>
            <label for="hubName" style="display:block;font-weight:600;margin-top:8px">{{ ___('Name') }}</label>
            <input class="hub-input" id="hubName" name="name" required maxlength="120" autocomplete="name" placeholder="{{ ___('Name') }}" value="{{ old('name') }}">
            <label for="hubPhone" style="display:block;font-weight:600;margin-top:8px">{{ ___('Phone') }}</label>
            <input class="hub-input" id="hubPhone" name="phone" type="tel" required maxlength="40" inputmode="tel" autocomplete="tel" placeholder="{{ ___('Phone') }}" value="{{ old('phone') }}">
            <button class="hub-cta" id="hubPlaceBtn">
                <span class="lbl">{{ ___('Place orders') }} · {{ store_money_format((float) $grand, $grandStoreId) }}</span>
                <span class="spin"></span>
            </button>
        </form>

        {{-- Special-request editor sheet --}}
        <div class="hub-sheetwrap" id="hubNoteSheet">
            <div class="bk" data-close></div>
            <div class="hub-sheet">
                <div class="grab"></div>
                <div class="sc">
                    <h3 id="ntName" style="margin-top:4px"></h3>
                    <label class="ds" for="ntText" style="display:block;font-weight:600">{{ ___('Special request') }}</label>
                    <textarea id="ntText" maxlength="255" placeholder="{{ ___('e.g. no onions, extra spicy') }}"></textarea>
                </div>
                <div class="ft">
                    <button type="button" class="hub-cta" id="ntSave"><span class="lbl">{{ ___('Save') }}</span><span class="spin"></span></button>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    (function () {
        const UPDATE = @json(route('orderhub.update'));
        let ntLine = null;

        // Mini steppers: absolute set; hitting 0 removes. Reload re-renders totals.
        document.querySelectorAll('[data-line] [data-step]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const line = btn.closest('[data-line]');
                const q = Math.max(0, parseInt(line.dataset.qty, 10) + parseInt(btn.dataset.step, 10));
                btn.disabled = true;
                const res = await hub.post(UPDATE, {
                    store_id: parseInt(line.dataset.rid, 10),
                    item_id: parseInt(line.dataset.item, 10),
                    quantity: q,
                });
                if (res.ok) { location.reload(); } else { btn.disabled = false; hub.toast(res.data.error || @json(___('Could not update the cart.'))); }
            });
        });

        // Special-request editor (bottom sheet; saves via the same update endpoint).
        document.querySelectorAll('[data-note-edit]').forEach(el => {
            el.addEventListener('click', () => {
                ntLine = el.closest('[data-line]');
                document.getElementById('ntName').textContent = ntLine.dataset.name;
                document.getElementById('ntText').value = el.dataset.note || '';
                hub.openSheet('hubNoteSheet');
            });
        });
        const noteSheet = document.getElementById('hubNoteSheet');
        noteSheet && noteSheet.querySelector('[data-close]').addEventListener('click', () => hub.closeSheet('hubNoteSheet'));
        const ntSave = document.getElementById('ntSave');
        ntSave && ntSave.addEventListener('click', async () => {
            if (!ntLine) return;
            ntSave.classList.add('busy');
            const res = await hub.post(UPDATE, {
                store_id: parseInt(ntLine.dataset.rid, 10),
                item_id: parseInt(ntLine.dataset.item, 10),
                quantity: parseInt(ntLine.dataset.qty, 10),
                note: document.getElementById('ntText').value.trim(),
            });
            ntSave.classList.remove('busy');
            if (res.ok) { location.reload(); } else { hub.toast(res.data.error || @json(___('Could not update the cart.'))); }
        });

        // Busy spinner on submit (double-submit guard).
        const form = document.getElementById('hubPlaceForm');
        form && form.addEventListener('submit', () => {
            document.getElementById('hubPlaceBtn').classList.add('busy');
        });
    })();
</script>
@endpush
