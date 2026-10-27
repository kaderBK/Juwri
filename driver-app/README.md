# Fusion Driver — Capacitor wrapper (T4-3 skeleton)

The fleet driver app is a **PWA served by Laravel at `/driver-app`**
(`core/resources/views/driver/fleet-app.blade.php`, dark mk-token skin). This
folder is the **native-wrapper skeleton only** — config + wrap steps. No
`ios/`/`android/` projects are committed (deliberate: they are generated
artifacts, and the true-native work below is a logged seam).

## What already works in the web build

- OTP login (`POST /api/v1/driver/login/request|verify` → rotated driver token)
- Shift toggle (online / break / offline), dispatch offer cards (10s poll),
  accept/reject via DispatchOrchestrator, order detail, Google Maps / Waze
  nav handoff, status stepper (picked up → en route → delivered), proof of
  delivery (photo upload + customer PIN via the existing PodService seam),
  earnings list, foreground GPS ping every 30s (`PUT /api/v1/driver/location`).

## Wrap steps (when you want store builds)

```bash
cd driver-app
npm init -y && npm i @capacitor/core @capacitor/cli
mkdir -p www && echo '<meta http-equiv="refresh" content="0;url=https://YOUR-HOST/driver-app">' > www/index.html
npx cap add android    # and: npx cap add ios (Mac + Xcode)
# point capacitor.config.ts server.url at the production host, then:
npx cap sync && npx cap run android
```

Plugins to add at wrap time (rev/delivery spec §4):
`@capacitor/geolocation`, `@capacitor-community/background-geolocation`,
`@capacitor/push-notifications`, `@capacitor/camera`, `@capacitor/app`,
`@capacitor/network`, `@capacitor/preferences`.

## SEAMS (deferred, per rev/delivery + rev/marketplace-app ledger)

1. **True background GPS (spec D2)** — web build pings only in the foreground.
   Wire `@capacitor-community/background-geolocation` to `PUT /api/v1/driver/location`
   while an active delivery exists; stop at delivered/failed. Needs
   `NSLocationAlwaysAndWhenInUseUsageDescription` (iOS) + foreground service +
   `ACCESS_BACKGROUND_LOCATION` (Android) and store-listing justification.
2. **Native push (FCM/APNs)** — offers currently arrive by 10s polling; push is
   the battery-friendly replacement (`driver_push_subscriptions` per spec §2.3
   not yet created).
3. **Secure token storage** — web build uses `localStorage`; native build must
   move the driver token to `@capacitor/preferences`.
4. **Offline write-queue** — status updates are online-only today; spec D7
   replay-with-idempotency-key is not built.
5. **Real manifest icons** — `/driver-app/manifest.webmanifest` ships an empty
   `icons` array (same gap as the store-driver shell); add 192/512/maskable
   before store submission (spec §6.7 — the #100 lesson).
