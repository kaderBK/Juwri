// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use client';

import { useEffect, useState } from 'react';
import { getMenu, getRestaurant, placeOrder } from '../lib/storefront';

export default function Home() {
  const [restaurant, setRestaurant] = useState(null);
  const [categories, setCategories] = useState([]);
  const [cart, setCart] = useState([]);
  const [error, setError] = useState(null);
  const [placed, setPlaced] = useState(null);

  useEffect(() => {
    getRestaurant().then(setRestaurant).catch((e) => setError(String(e)));
    getMenu().then((m) => setCategories(m.categories || [])).catch((e) => setError(String(e)));
  }, []);

  const add = (it) =>
    setCart((c) => {
      const e = c.find((l) => l.id === it.id);
      return e ? c.map((l) => (l.id === it.id ? { ...l, qty: l.qty + 1 } : l)) : [...c, { id: it.id, name: it.name, price: it.price, qty: 1 }];
    });

  const total = cart.reduce((s, l) => s + l.price * l.qty, 0);

  const submit = async () => {
    const res = await placeOrder({
      items: cart.map((l) => ({ item_id: l.id, quantity: l.qty })),
      type: 'takeaway',
      clientUuid: `sf-${Date.now()}`,
      // customerToken: '...'  // wire your customer auth here
    });
    if (res.ok) { setPlaced(res.data); setCart([]); }
    else setError(res.data?.message || `order failed (${res.status})`);
  };

  if (error) return <main style={{ padding: 24 }}><p>Setup needed: {error}</p><p>Check NEXT_PUBLIC_API_BASE + NEXT_PUBLIC_STOREFRONT_TOKEN.</p></main>;

  return (
    <main style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 24, padding: 24 }}>
      <section>
        <h1>{restaurant?.title || 'Menu'}</h1>
        {categories.map((c) => (
          <div key={c.id}>
            <h3>{c.name}</h3>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(160px,1fr))', gap: 10 }}>
              {(c.items || []).map((it) => (
                <button key={it.id} onClick={() => add(it)} style={card}>
                  <b>{it.name}</b>
                  <span style={{ color: '#8b93a3' }}>{Number(it.price).toFixed(2)}</span>
                </button>
              ))}
            </div>
          </div>
        ))}
      </section>
      <aside style={{ borderInlineStart: '1px solid #232833', paddingInlineStart: 16 }}>
        <h3>Cart ({cart.length})</h3>
        {cart.map((l) => (<div key={l.id}>{l.qty}× {l.name} — {(l.price * l.qty).toFixed(2)}</div>))}
        <p><b>Total: {total.toFixed(2)}</b></p>
        <button disabled={!cart.length} onClick={submit} style={btn}>Place order</button>
        {placed && <p>Order #{placed.id} placed. Track: {placed.track_token}</p>}
      </aside>
    </main>
  );
}

const card = { background: '#1b1f27', color: '#e7e9ee', border: 0, borderRadius: 12, padding: 12, textAlign: 'start', cursor: 'pointer', display: 'flex', flexDirection: 'column' };
const btn = { background: '#10b981', color: '#06231a', border: 0, borderRadius: 10, padding: '12px 16px', fontWeight: 700, cursor: 'pointer', width: '100%' };
