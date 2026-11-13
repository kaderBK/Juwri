---
title: "Sunmi kiosk: printing + device setup"
audience: owner
group: Integrations
updated: 2026-07-12
---

# Sunmi kiosk: printing + device setup

How to turn a Sunmi device into a self-service kiosk that prints receipts on its inner
printer.

## Enable printing
1. In **Kiosk settings**, turn on **Auto-print receipt on order (Sunmi device)**.
2. Pick the **printer width**: 72mm (48 cols) for T2/D3, 58mm (32 cols) for K2-mini.
3. Optionally enable **Kick cash drawer on print** if a drawer is attached.

The kiosk Capacitor app fetches the receipt as an ESC/POS stream
(`/api/kiosk/orders/{track_token}/escpos`) right after an order is placed and raw-prints
it through the Sunmi printer plugin. A **plain browser kiosk cannot reach the inner
printer** — you must install the Capacitor APK (see below).

## Build the kiosk APK
- See `kiosk/SUNMI.md` in the codebase for the exact build steps, the printer plugin
  config, and the graceful no-printer fallback (non-Sunmi devices skip printing silently).

## Kiosk lock (single-app mode)
- Kiosk lock is **not an API** — use Sunmi OS + the free **MDM/TMS console**
  (partner.sunmi.com): set our app as the default/startup app, enable single-app lock,
  and push silent updates. Zero app-code changes.

## Arabic receipts
- Arabic prints as UTF-8 text on most Sunmi devices. If a specific device mis-shapes
  Arabic, switch that device to the raster-bitmap fallback (documented in `kiosk/SUNMI.md`).
  Verify on a physical device — emulators do not emulate the printer.
