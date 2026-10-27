// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * N8 — Sunmi kiosk receipt printing (Capacitor).
 *
 * Fetches the server-rendered ESC/POS stream for a placed order and raw-prints it on the
 * Sunmi inner printer. The server (EscPosRenderer) is the single source of truth for the
 * receipt layout — this wrapper never re-lays-out anything.
 *
 * Graceful fallback: on a non-Sunmi device (plugin absent) it logs and returns without
 * throwing, so the web kiosk keeps working. A pure browser cannot reach the inner printer.
 */
(function (global) {
  'use strict';

  function base64ToBytes(b64) {
    var bin = atob(b64);
    var len = bin.length;
    var bytes = new Uint8Array(len);
    for (var i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
    return bytes;
  }

  function getPlugin() {
    // Feature-detect the Capacitor Sunmi printer plugin.
    var cap = global.Capacitor;
    if (!cap || !cap.Plugins) return null;
    return cap.Plugins.SunmiPrinter || cap.Plugins.SunmiPrint || null;
  }

  var SunmiPrint = {
    /**
     * @param {string} trackToken  the placed order's track_token
     * @param {string} [apiBase]   e.g. "https://your-host" (defaults to same origin)
     * @returns {Promise<{printed:boolean, reason?:string}>}
     */
    printOrder: function (trackToken, apiBase) {
      var base = apiBase || '';
      var url = base + '/api/kiosk/orders/' + encodeURIComponent(trackToken) + '/escpos';

      return fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (res) {
          if (res.status === 204) return { printed: false, reason: 'auto_print_disabled' };
          if (!res.ok) return { printed: false, reason: 'http_' + res.status };
          return res.json().then(function (data) {
            if (!data || !data.escpos) return { printed: false, reason: 'empty' };

            var plugin = getPlugin();
            if (!plugin) {
              console.warn('[SunmiPrint] printer plugin unavailable — skipping (non-Sunmi device).');
              return { printed: false, reason: 'no_plugin' };
            }

            var bytes = base64ToBytes(data.escpos);
            // Raw ESC/POS passthrough. Method name varies by plugin build; try known ones.
            var call =
              plugin.printRaw ? plugin.printRaw({ data: Array.from(bytes) }) :
              plugin.sendRAWData ? plugin.sendRAWData({ data: data.escpos }) :
              plugin.rawData ? plugin.rawData({ base64: data.escpos }) :
              Promise.reject(new Error('no_raw_method'));

            return Promise.resolve(call)
              .then(function () { return { printed: true }; })
              .catch(function (e) {
                console.error('[SunmiPrint] raw print failed:', e);
                return { printed: false, reason: 'print_error' };
              });
          });
        })
        .catch(function (e) {
          console.error('[SunmiPrint] fetch failed:', e);
          return { printed: false, reason: 'fetch_error' };
        });
    },
  };

  global.SunmiPrint = SunmiPrint;
})(typeof window !== 'undefined' ? window : this);
