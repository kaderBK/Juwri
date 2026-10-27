# Sunmi kiosk build + deployment guide

This wraps the storefront in a Capacitor app that prints receipts on a Sunmi device's
inner printer and locks to a single app. Code-complete; **physical print verification is
DEFERRED until a device is available (BD-ACTIONS #10).**

## 1. Printer plugin

Recommended: `@kduma-autoid/capacitor-sunmi-printer` — it exposes a **raw ESC/POS
passthrough** so receipts stay server-rendered (our `EscPosRenderer` is the single source
of truth; the app never re-lays-out the receipt).

```bash
cd kiosk
npm install @kduma-autoid/capacitor-sunmi-printer
npx cap sync android
```

Alternatives if that plugin is unavailable on a device: `bistroo/capacitor-plugin-sunmi`
or Sunmi's own `@sunmi/device-sdk`. Keep the ESC/POS-passthrough shape either way.

## 2. Print flow (already wired)

`www/sunmi-print.js` (`SunmiPrint.printOrder(trackToken)`):
1. `GET /api/kiosk/orders/{trackToken}/escpos` → `{ escpos: <base64> }` (or 204 when the
   owner disabled auto-print).
2. base64-decode → raw-print via the plugin.
3. **Graceful fallback**: if the plugin is unavailable (non-Sunmi device) it logs and
   returns without error — the web kiosk keeps working.

Call it from the order-success handler with the new order's `track_token`.

## 3. Build the APK

```bash
cd kiosk
npm run build          # if a web build step is present
npx cap sync android
npx cap open android   # build a signed APK in Android Studio
```

The `@capgo/capacitor-android-kiosk` launcher (already configured in
`capacitor.config.json`) provides the full-screen launcher.

## 4. Kiosk lock + fleet management (no app code)

Use Sunmi OS + the free **MDM/TMS** console (partner.sunmi.com):
- Set this app as the **default startup app** and enable **single-app (kiosk) lock**.
- Push **silent install/update** through your private app channel.
- Notes: K2/T2 support full lock; K2-mini uses 58mm paper (set printer width = 32 cols).

> MaxIoT / SUNMAX (low-code IoT IDE) was evaluated and rejected — immature English docs,
> not the standard path. Sunmi AI services (Salesbuddy/Checkout) are hardware-tied with no
> public API — out of scope.

## 5. Physical print-verify protocol (DEFERRED — run when a device arrives)

- [ ] English receipt prints, aligns, and cuts.
- [ ] **Arabic** receipt renders correctly; if not, switch that device to raster-bitmap
      line rendering.
- [ ] Long item names wrap within the paper width (48 / 32 cols).
- [ ] Cash-drawer kick fires when enabled.
- [ ] 204 path (auto-print disabled) prints nothing.
- Emulators do NOT emulate the printer — a physical device is required for this step only.
