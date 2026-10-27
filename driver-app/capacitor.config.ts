// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Marketplace T4-3 — Capacitor wrapper SKELETON for the fleet driver app.
 *
 * This is a config-only scaffold: no native ios/ or android/ projects are
 * committed (see README.md for the wrap steps). The wrapper loads the SERVED
 * web shell at /driver-app (remote-shell strategy, rev/delivery spec §4) so
 * the web build stays the single source of truth.
 */
const config: CapacitorConfig = {
  appId: 'app.fusion.driver',
  appName: 'Fusion Driver',
  // Remote-shell strategy: point at the deployed Laravel app. For local
  // device testing use your LAN IP / Herd HTTPS domain.
  webDir: 'www', // placeholder — remote shell means this stays an empty stub
  server: {
    url: 'https://juwri.test/driver-app',
    cleartext: false,
  },
  plugins: {
    // rev/delivery D2: true background GPS is the reason Capacitor exists.
    // Configure @capacitor-community/background-geolocation here once the
    // native projects are generated (see README.md — SEAM, not yet wired).
  },
};

export default config;
