# juwri Kiosk (Capacitor 8 — Android launcher mode)

Self-service ordering kiosk. This project is a thin Capacitor **Android** wrapper of
the server-rendered kiosk web mode (`{slug}/kiosk`, built in B9-P1). The web mode is
fully usable in any browser today; this wrapper adds device lockdown for unattended
tablets.

> **Native build is DEFERRED** (needs Android Studio + a physical Android tablet).
> The config here is complete and drivable; nothing native is claimed to build in CI.

## Configure

1. Edit `capacitor.config.json` → `server.url`: point it at the restaurant's kiosk URL,
   e.g. `https://yourhost/acme-diner/kiosk`. (Each tablet targets one restaurant.)
2. `allowNavigation` should list only your host(s).

## Build (on a machine with Android Studio)

```bash
cd kiosk
npm install
npx cap add android
npx cap sync
npx cap open android   # then Run on the connected tablet
```

## Kiosk lockdown — two tiers

### Tier 1 — Launcher mode (no device-owner, recommended)

Uses [`@capgo/capacitor-android-kiosk`](https://github.com/Cap-go/capacitor-android-kiosk)
(MPL-2.0, Capacitor v8, Android 6–15). In app startup JS:

```js
import { Kiosk } from '@capgo/capacitor-android-kiosk';

await Kiosk.enterKioskMode();
await Kiosk.setAsLauncher();          // become HOME → survives reboot (reliable path)
await Kiosk.setAllowedKeys({ keys: [] });
await Kiosk.configure({
  restoreAfterReboot: true,
  relaunchIntervalMinutes: 15,        // 5–60: auto-relaunch if the app is closed
});
```

- Being the HOME launcher is the reliable auto-boot path on Android 10+
  (`RECEIVE_BOOT_COMPLETED` alone is unreliable).
- Exit is handled by the web mode's **Staff exit PIN** (server-verified) → `Kiosk.exitKioskMode()`.

### Tier 2 — True Lock Task Mode (device-owner)

Stronger lockdown, requires provisioning the app as **device owner** via ADB or an MDM:

```bash
adb shell dpm set-device-owner com.juwri.kiosk/.KioskDeviceAdminReceiver
```

Use an MDM (e.g. for a fleet) to push device-owner + Lock Task allowlists at scale.

## iOS

iOS has **no programmatic kiosk**. Use **Guided Access** (Settings → Accessibility →
Guided Access, triple-click to lock a single app) for a single device, or **Single App
Mode** via a supervised MDM for a fleet. There is no code path — Android tablets are the
supported kiosk product.

## Payment

The web mode defaults to counter / pay-later. Card-terminal integration is out of scope
(hardware-gated).
