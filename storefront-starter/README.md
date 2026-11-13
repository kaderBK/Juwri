# juwri Storefront Starter

An open-source **headless storefront** for juwri — a Next.js app that renders a restaurant's
menu from the public **Storefront API** and places orders. Host it anywhere (Vercel, Netlify, your own
box); your code runs on **your** infra, not the platform's. This is the "Hydrogen equivalent."

## Quick start

```bash
cp .env.example .env.local
# set NEXT_PUBLIC_API_BASE + NEXT_PUBLIC_STOREFRONT_TOKEN
npm install
npm run dev            # http://localhost:3000
```

- **API base**: your juwri host, e.g. `https://juwri.test`.
- **Storefront token**: owner dashboard → API → **Storefront tokens** (scope `menu:read`). It's a
  public, per-restaurant token — safe to embed in a client app.

## What it does

- `lib/storefront.js` — client for `GET /api/v1/storefront/menu` + `/restaurant` (variants, extras,
  groups, gallery), `POST /api/v1/customer/orders` (idempotent via `client_uuid`), and order tracking.
- `app/page.js` — menu grid + cart + place-order.

## Ordering

Menu browsing works with the public storefront token. **Placing an order** needs either a customer
token (register/login on the customer API) or an `order:write` storefront token with your domain in
its allowed-origins list. Wire your choice in `placeOrder()` — the request body already follows the
public ordering contract.

## Custom domain

Point your domain at your host (Vercel/Netlify). To make QR codes open your storefront, set the
restaurant's QR target to your URL in the owner dashboard (per-table param preserved).

## Deploy to Vercel

Push this folder to a repo and import it in Vercel, or:

```bash
npx vercel
```

Set the two `NEXT_PUBLIC_*` env vars in the Vercel project. Done.

---
Generated as part of the platform-extensibility suite. i18n/RTL: `app/layout.js` sets `dir` — read the
restaurant locale from `/restaurant` and switch to `rtl` for Arabic/Urdu/etc.
