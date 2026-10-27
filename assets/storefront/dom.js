// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — the delegated hooks themes use (THEME-CONTRACT §5.1). Themes write markup and style;
   they never change cart state outside `window.Juwri.cart`.

   Actions (click, delegated on the document):
     data-juwri-add="<item public id>"   + optional data-juwri-qty, -variant, -options="id id", -note, -name
     data-juwri-inc / -dec / -remove="<line key>"
     data-juwri-apply-code              (reads the nearest [data-juwri-code] input)
     data-juwri-place                   (collects the nearest <form data-juwri-checkout> fields; add data-juwri-confirmed on the
                                        "confirm the new total" button: the first press shows the changed total, the confirmed one places)
     data-juwri-order-type="<key>"
     data-juwri-queue-confirm / -retry / -dismiss="<queue id>"
   Live text (textContent only, never HTML):
     [data-juwri-count]  [data-juwri-total]  [data-juwri-cta]  [data-juwri-coupon-message]  [data-juwri-error]
     [data-juwri-empty] / [data-juwri-filled]  shown (hidden removed) when the cart is empty / not
     [data-juwri-stale]  shown while the quote is an offline copy ("offline, prices may change")
     [data-juwri-rows] with <template data-juwri-row>: [data-juwri-row-label] [data-juwri-row-amount]
     [data-juwri-lines] with <template data-juwri-line>: [data-juwri-line-name] [data-juwri-line-qty]
          [data-juwri-line-total] [data-juwri-line-choices]; buttons carry data-juwri-inc/-dec/-remove
          goods lines also: [data-juwri-line-thumb] (img, hidden without a thumb) [data-juwri-line-brand] [data-juwri-line-was]
          (the compare-at total, hidden without one) [data-juwri-line-link] (the product page) and a quantity
          <select data-juwri-line-select data-juwri-label-template="Quantity of :name"> (0 removes the line)
     [data-juwri-total-label] [data-juwri-tax-note] [data-juwri-substitution]   the quote's total label, its tax note, the order's out-of-stock choice
     goods lines also: [data-juwri-line-unit] (pack size / weight) [data-juwri-line-note] (estimate / weighed / warning)
          [data-juwri-line-substitution] [data-juwri-line-estimate] (shown for an estimated line: "~" before its amount)
     [data-juwri-freeship] with [data-juwri-freeship-label] and <progress data-juwri-freeship-bar>: the quote's free-shipping row
     [data-juwri-queue] with <template data-juwri-queue-item>: [data-juwri-queue-state] [data-juwri-queue-total]
          [data-juwri-queue-detail], buttons data-juwri-queue-confirm / -retry / -dismiss; [data-juwri-queue-badge] */

const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));
const setText = (root, sel, value) => { for (const el of $$(root, sel)) el.textContent = value == null ? '' : String(value); };
const show = (root, sel, on) => { for (const el of $$(root, sel)) el.hidden = !on; };

export function bindDom({ doc = globalThis.document, cart, queue, text = (k, d) => d, announce = () => {}, onPlaced, home = '' }) {
    function fill(container, tplSel, rows, map) {
        for (const host of $$(doc, container)) {
            const tpl = host.querySelector(tplSel);
            if (!tpl) continue;
            for (const old of Array.from(host.children)) if (old !== tpl && !old.hasAttribute('data-juwri-keep')) old.remove();
            for (const row of rows) {
                const node = tpl.content.firstElementChild.cloneNode(true);
                map(node, row);
                host.appendChild(node);
            }
        }
    }

    function render() {
        const s = cart.snapshot();
        const q = s.quote;
        setText(doc, '[data-juwri-count]', s.count);
        show(doc, '[data-juwri-empty]', s.count === 0);
        show(doc, '[data-juwri-filled]', s.count > 0);
        show(doc, '[data-juwri-stale]', s.stale);
        setText(doc, '[data-juwri-total]', q ? q.total.formatted : '');
        setText(doc, '[data-juwri-cta]', q ? (q.cta_label || '') : '');
        for (const el of $$(doc, '[data-juwri-total-label]')) if (q && q.total_label) el.textContent = q.total_label;
        setText(doc, '[data-juwri-tax-note]', q ? (q.tax_note || '') : '');
        show(doc, '[data-juwri-tax-note]', !!(q && q.tax_note));
        setText(doc, '[data-juwri-substitution]', q ? (q.substitution_preference || '') : '');
        show(doc, '[data-juwri-substitution-row]', !!(q && q.substitution_preference));
        setText(doc, '[data-juwri-coupon-message]', q && q.coupon ? (q.coupon.message || '') : '');
        setText(doc, '[data-juwri-error]', s.status === 'error' ? cart.state.error || text('couldnt_update_total_retry', "Couldn't update total — Retry") : '');
        fill('[data-juwri-rows]', 'template[data-juwri-row]', q ? q.rows : [], (n, r) => {
            setText(n, '[data-juwri-row-label]', r.label);
            setText(n, '[data-juwri-row-amount]', r.amount_label || r.amount.formatted);
            if (r.muted) n.setAttribute('data-muted', '');
            if (r.informational) n.setAttribute('data-informational', '');
            n.setAttribute('data-kind', r.kind || '');
        });
        const fd = q && q.free_delivery;
        show(doc, '[data-juwri-freeship]', !!fd);
        if (fd) {
            setText(doc, '[data-juwri-freeship-label]', fd.label || '');
            for (const bar of $$(doc, '[data-juwri-freeship-bar]')) bar.value = Math.round(Math.max(0, Math.min(1, Number(fd.progress) || 0)) * 100);
            for (const box of $$(doc, '[data-juwri-freeship]')) box.classList.toggle('is-done', !!fd.remaining && fd.remaining.minor === 0);
        }
        const lines = cart.state.lines;
        fill('[data-juwri-lines]', 'template[data-juwri-line]', lines.map((l, i) => ({ l, q: q && q.lines ? q.lines[i] : null })), (n, { l, q: ql }) => {
            setText(n, '[data-juwri-line-name]', (ql && ql.name) || l.name || '');
            setText(n, '[data-juwri-line-qty]', l.qty);
            setText(n, '[data-juwri-line-total]', ql ? ql.line_total.formatted : '');
            setText(n, '[data-juwri-line-choices]', ql ? ql.choices_label || '' : '');
            setText(n, '[data-juwri-line-brand]', ql ? ql.brand || '' : '');
            for (const [attr, key] of [['unit', 'unit_label'], ['note', 'note'], ['substitution', 'substitution']]) {
                for (const el of $$(n, '[data-juwri-line-' + attr + ']')) {
                    const v = ql ? (ql[key] || (attr === 'note' ? ql.warning : '') || '') : '';
                    el.textContent = v;
                    el.hidden = v === '';
                }
            }
            for (const el of $$(n, '[data-juwri-line-estimate]')) el.hidden = !(ql && ql.estimate);
            for (const img of $$(n, '[data-juwri-line-thumb]')) {
                const t = ql && ql.thumb;
                img.hidden = !t;
                if (t) { img.setAttribute('src', t.src); img.setAttribute('alt', ''); }
            }
            for (const w of $$(n, '[data-juwri-line-was]')) {
                const was = ql && ql.compare_at;
                w.hidden = !was;
                w.textContent = was ? was.formatted : '';
            }
            for (const a of $$(n, '[data-juwri-line-link]')) {
                if (ql && ql.item_id && home) a.setAttribute('href', home + '?item=' + encodeURIComponent(ql.item_id));
                a.textContent = (ql && ql.name) || l.name || '';
            }
            for (const sel of $$(n, 'select[data-juwri-line-select]')) {
                if (!Array.from(sel.options).some((o) => Number(o.value) === l.qty)) sel.appendChild(new Option(String(l.qty), String(l.qty)));
                sel.value = String(l.qty);
                sel.setAttribute('data-juwri-qty', l.key);
                const tpl = sel.getAttribute('data-juwri-label-template');
                if (tpl) sel.setAttribute('aria-label', tpl.replace(':name', (ql && ql.name) || l.name || ''));
            }
            for (const a of ['inc', 'dec', 'remove']) for (const b of $$(n, '[data-juwri-' + a + ']')) b.setAttribute('data-juwri-' + a, l.key);
        });
        if (refocus) {
            const key = refocus;
            const back = $$(doc, 'select[data-juwri-qty]').find((el) => el.getAttribute('data-juwri-qty') === key && el.offsetParent !== null);
            if (back) { refocus = null; back.focus(); }
        }
    }

    async function renderQueue() {
        if (!queue) return;
        const items = await queue.list();
        setText(doc, '[data-juwri-queue-badge]', items.length || '');
        show(doc, '[data-juwri-queue-badge]', items.length > 0);
        const label = {
            waiting: text('waiting_for_connection', 'Waiting for connection'),
            sending: text('sending_your_order', 'Sending your order'),
            confirm: text('needs_your_confirmation', 'Needs your confirmation'),
            placed: text('order_placed', 'Order placed'),
            expired: text('order_expired', 'Order expired'),
            failed: text('could_not_place_the_order', 'Could not place the order'),
        };
        fill('[data-juwri-queue]', 'template[data-juwri-queue-item]', items, (n, it) => {
            n.setAttribute('data-state', it.state);
            setText(n, '[data-juwri-queue-state]', label[it.state] || it.state);
            setText(n, '[data-juwri-queue-total]', it.new_total ? it.new_total.formatted : (it.total_label || ''));
            setText(n, '[data-juwri-queue-detail]', it.state === 'confirm' && it.old && it.new_total ? it.old + ' → ' + it.new_total.formatted : (it.message || ''));
            for (const a of ['confirm', 'retry', 'dismiss']) for (const b of $$(n, '[data-juwri-queue-' + a + ']')) {
                b.setAttribute('data-juwri-queue-' + a, it.id);
                b.hidden = (a === 'confirm' && it.state !== 'confirm') || (a === 'retry' && it.state !== 'failed') || (a === 'dismiss' && it.state === 'sending');
            }
        });
    }

    function formFields(btn) {
        const form = btn.closest('[data-juwri-checkout]') || doc.querySelector('[data-juwri-checkout]');
        const out = {};
        if (!form) return out;
        for (const el of $$(form, 'input[name],select[name],textarea[name]')) {
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
            out[el.name] = el.value;
        }
        return out;
    }

    async function onClick(e) {
        const t = e.target.closest && e.target.closest('[data-juwri-add],[data-juwri-inc],[data-juwri-dec],[data-juwri-remove],[data-juwri-apply-code],[data-juwri-place],[data-juwri-order-type],[data-juwri-queue-confirm],[data-juwri-queue-retry],[data-juwri-queue-dismiss]');
        if (!t) return;
        const get = (n) => t.getAttribute('data-juwri-' + n);
        if (t.hasAttribute('data-juwri-add')) {
            e.preventDefault();
            cart.add(get('add'), {
                qty: get('qty') ? Number(get('qty')) : 1, variant: get('variant') || null,
                options: (get('options') || '').split(/\s+/).filter(Boolean), note: get('note') || '', name: get('name') || '',
            });
        } else if (t.hasAttribute('data-juwri-inc') || t.hasAttribute('data-juwri-dec')) {
            e.preventDefault();
            const inc = t.hasAttribute('data-juwri-inc');
            const key = get(inc ? 'inc' : 'dec');
            const line = cart.state.lines.find((l) => l.key === key);
            if (line) cart.update(key, line.qty + (inc ? 1 : -1));
        } else if (t.hasAttribute('data-juwri-remove')) {
            e.preventDefault();
            cart.remove(get('remove'));
        } else if (t.hasAttribute('data-juwri-apply-code')) {
            e.preventDefault();
            const input = (t.closest('form') || doc).querySelector('[data-juwri-code]');
            cart.setCoupon(input ? input.value : '');
        } else if (t.hasAttribute('data-juwri-order-type')) {
            e.preventDefault();
            cart.setOrderType(get('order-type'));
        } else if (t.hasAttribute('data-juwri-place')) {
            e.preventDefault();
            t.disabled = true;
            try {
                const result = await cart.place(formFields(t), { confirm: t.hasAttribute('data-juwri-confirmed') });
                if (onPlaced) onPlaced(result);
            } finally { t.disabled = false; }
        } else if (queue && t.hasAttribute('data-juwri-queue-confirm')) {
            e.preventDefault();
            await queue.confirm(get('queue-confirm'));
        } else if (queue && t.hasAttribute('data-juwri-queue-retry')) {
            e.preventDefault();
            await queue.retry(get('queue-retry'));
        } else if (queue && t.hasAttribute('data-juwri-queue-dismiss')) {
            e.preventDefault();
            await queue.dismiss(get('queue-dismiss'));
        }
    }

    let refocus = null;
    function onChange(e) {
        const sel = e.target.closest && e.target.closest('select[data-juwri-qty]');
        if (!sel) return;
        const key = sel.getAttribute('data-juwri-qty');
        refocus = Number(sel.value) > 0 ? key : null; // the rows are rebuilt on every quote: keep the shopper's place in the list
        cart.update(key, Number(sel.value));
    }

    doc.addEventListener('click', onClick);
    doc.addEventListener('change', onChange);
    for (const ev of ['cart:changed', 'quote:updated', 'quote:loading', 'quote:error']) doc.addEventListener('juwri:' + ev, render);
    for (const ev of ['queue:changed', 'queue:dropped']) doc.addEventListener('juwri:' + ev, renderQueue);
    render();
    renderQueue();
    return { render, renderQueue };
}
