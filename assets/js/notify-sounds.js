// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   NotifySounds — one sound engine for every surface (dashboard bell, POS
   register, KDS…). Plays owner-configured chimes per event, with optional
   repeat-until-acknowledged for incoming orders.

   Boot:   NotifySounds.init({ volume: 0-100, events: {ev: {sound, repeat,
           interval}}, base: '<asset base for audio dir>' })
   Play:   NotifySounds.play('order_new')           // one shot per config
           NotifySounds.startRepeat('order_new')    // nag until stopRepeat()
           NotifySounds.stopRepeat('order_new')
   Extra:  NotifySounds.preview(fileId, volume?)    // settings-page ▶ button
           NotifySounds.setMuted(bool) / isMuted()  // per-DEVICE (localStorage)

   Uses howler.js when present (autoplay-unlock, cross-browser), falls back to
   HTMLAudio, and finally to a tiny WebAudio two-tone so a missing file never
   means silence. No external hosts — audio ships under assets/global/audio.
   ========================================================================== */
(function () {
    'use strict';

    var MUTE_KEY = 'notify-sounds-muted';

    var NS = {
        cfg: { volume: 70, events: {}, quiet: { enabled: false, from: '23:00', to: '07:00' }, base: '/assets/global/audio/' },
        _howls: {},   // fileId → Howl (lazy)
        _timers: {},  // event → interval id

        init: function (cfg) {
            if (cfg && typeof cfg === 'object') {
                this.cfg.volume = (cfg.volume == null) ? this.cfg.volume : Math.max(0, Math.min(100, +cfg.volume));
                this.cfg.events = cfg.events || {};
                if (cfg.quiet && typeof cfg.quiet === 'object') this.cfg.quiet = cfg.quiet;
                if (cfg.base) this.cfg.base = cfg.base.replace(/\/?$/, '/');
            }
            return this;
        },

        // Quiet hours: true when the local wall-clock is within [from, to) (wraps
        // midnight). All sounds are suppressed while quiet — like device mute.
        _quietNow: function () {
            var q = this.cfg.quiet;
            if (!q || !q.enabled) return false;
            var d = new Date();
            var now = d.getHours() * 60 + d.getMinutes();
            var p = function (s) { var m = /^(\d\d):(\d\d)$/.exec(s || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; };
            var f = p(q.from), t = p(q.to);
            if (f == null || t == null || f === t) return false;
            return f < t ? (now >= f && now < t) : (now >= f || now < t); // wrap past midnight
        },

        isMuted: function () {
            try { return localStorage.getItem(MUTE_KEY) === '1'; } catch (e) { return false; }
        },
        setMuted: function (on) {
            try { on ? localStorage.setItem(MUTE_KEY, '1') : localStorage.removeItem(MUTE_KEY); } catch (e) {}
            if (on) this.stopAllRepeats();
        },

        _vol: function (override) {
            var v = (override == null) ? this.cfg.volume : override;
            return Math.max(0, Math.min(1, v / 100));
        },
        _url: function (fileId) {
            // fileId is a bare filename from the library; tolerate a full URL too.
            return /^https?:|^\//.test(fileId) ? fileId : this.cfg.base + encodeURIComponent(fileId);
        },

        _playFile: function (fileId, volume) {
            var vol = this._vol(volume);
            if (vol <= 0) return;
            var url = this._url(fileId);
            try {
                if (window.Howl) {
                    var h = this._howls[fileId];
                    if (!h) { h = this._howls[fileId] = new Howl({ src: [url], preload: true }); }
                    h.volume(vol);
                    h.play();
                    return;
                }
            } catch (e) { /* fall through */ }
            try {
                var a = new Audio(url);
                a.volume = vol;
                a.play().catch(function () {});
                return;
            } catch (e) { /* fall through */ }
            this._beep(vol);
        },

        // Zero-asset fallback: short two-tone ding via WebAudio.
        _ctx: null,
        _beep: function (vol) {
            try {
                this._ctx = this._ctx || new (window.AudioContext || window.webkitAudioContext)();
                var ctx = this._ctx;
                if (ctx.state === 'suspended') ctx.resume();
                var now = ctx.currentTime;
                [[880, 0], [1175, 0.14]].forEach(function (p) {
                    var o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = 'sine'; o.frequency.value = p[0];
                    g.gain.setValueAtTime(0.0001, now + p[1]);
                    g.gain.exponentialRampToValueAtTime(Math.max(0.02, vol * 0.2), now + p[1] + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, now + p[1] + 0.25);
                    o.connect(g); g.connect(ctx.destination);
                    o.start(now + p[1]); o.stop(now + p[1] + 0.26);
                });
            } catch (e) {}
        },

        play: function (event) {
            if (this.isMuted() || this._quietNow()) return;
            var ev = this.cfg.events[event];
            if (!ev) return;
            if (!ev.sound) return;              // event muted by the owner
            this._playFile(ev.sound);
        },

        // Repeat-until-acknowledged (incoming orders): play now, then every
        // `interval`s until stopRepeat. No-op unless the event has repeat on.
        startRepeat: function (event) {
            var ev = this.cfg.events[event];
            if (!ev || !ev.sound || this.isMuted() || this._quietNow()) return;
            this.play(event);
            if (!ev.repeat) return;
            var self = this;
            this.stopRepeat(event);
            this._timers[event] = setInterval(function () {
                if (self.isMuted()) { self.stopRepeat(event); return; }
                if (self._quietNow()) return; // stay armed but silent during quiet hours
                self._playFile(ev.sound);
            }, Math.max(5, ev.interval || 20) * 1000);
        },
        stopRepeat: function (event) {
            if (this._timers[event]) { clearInterval(this._timers[event]); delete this._timers[event]; }
        },
        stopAllRepeats: function () {
            for (var k in this._timers) this.stopRepeat(k);
        },

        // Settings-page preview: play a library file directly at a given volume.
        preview: function (fileId, volume) {
            if (!fileId) return;
            this._playFile(fileId, volume == null ? this.cfg.volume : volume);
        },
    };

    window.NotifySounds = NS;
})();
