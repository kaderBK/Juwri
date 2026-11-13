// Minimal client for the juwri Storefront API (GAP-1/GAP-3).
// Menu + restaurant are read with the public storefront token (menu:read).
const API = process.env.NEXT_PUBLIC_API_BASE;
const TOKEN = process.env.NEXT_PUBLIC_STOREFRONT_TOKEN;

function headers() {
  return { Accept: 'application/json', 'X-Storefront-Token': TOKEN };
}

export async function getRestaurant() {
  const r = await fetch(`${API}/api/v1/storefront/restaurant`, { headers: headers() });
  if (!r.ok) throw new Error(`restaurant ${r.status}`);
  return r.json();
}

export async function getMenu() {
  const r = await fetch(`${API}/api/v1/storefront/menu`, { headers: headers() });
  if (!r.ok) throw new Error(`menu ${r.status}`);
  return r.json();
}

// Order submission requires a customer token (register/login on the customer API)
// OR an order:write storefront token + allowed origin. This helper posts the public
// ordering contract; wire your auth of choice. client_uuid makes retries idempotent.
export async function placeOrder({ items, type = 'takeaway', customerToken, clientUuid }) {
  const r = await fetch(`${API}/api/v1/customer/orders`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(customerToken ? { Authorization: `Bearer ${customerToken}` } : {}),
    },
    body: JSON.stringify({ type, client_uuid: clientUuid, items }),
  });
  return { ok: r.ok, status: r.status, data: await r.json().catch(() => ({})) };
}

// Track an order by its unguessable track token.
export async function trackOrder(token) {
  const r = await fetch(`${API}/api/v1/track/${token}`, { headers: { Accept: 'application/json' } });
  return r.ok ? r.json() : null;
}
