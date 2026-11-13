// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Fusion marketplace — mk-account.js (L1 T1-1 + T1-3)
   Shopper OTP login sheet ("account") + address book ("addresses").
   Registers via window.Mk.register — never edits mk-core.js. Markup:
   resources/views/marketplace/partials/auth-sheet.blade.php and
   .../address-sheet.blade.php.
   ============================================================ */
(function () {
  'use strict';

  function boot(Mk) {
    var root = document.getElementById('mk-auth-sheet');
    if (!root) { return null; }

    var steps = {
      phone: root.querySelector('[data-mkas-step="phone"]'),
      code: root.querySelector('[data-mkas-step="code"]'),
      account: root.querySelector('[data-mkas-step="account"]')
    };
    var phoneInput = document.getElementById('mkas-phone');
    var codeInput = document.getElementById('mkas-code');
    var errorEl = root.querySelector('[data-mkas-error]');
    var lastPhone = '';

    function show(step) {
      Object.keys(steps).forEach(function (k) {
        if (steps[k]) { steps[k].hidden = k !== step; }
      });
      error('');
    }

    function error(msg) {
      if (!errorEl) { return; }
      errorEl.textContent = msg || '';
      errorEl.hidden = !msg;
    }

    function busy(btn, on) {
      if (btn) { btn.disabled = !!on; }
    }

    function sendCode() {
      var phone = (phoneInput && phoneInput.value || '').trim();
      if (!phone) { error(root.getAttribute('aria-label')); if (phoneInput) { phoneInput.focus(); } return; }
      var btn = root.querySelector('[data-mkas-send]');
      busy(btn, true);
      Mk.post(root.dataset.otpUrl, { phone: phone }).then(function (json) {
        busy(btn, false);
        if (json && json.ok) {
          lastPhone = phone;
          show('code');
          if (codeInput) { codeInput.value = ''; codeInput.focus(); }
          if (json.message) { Mk.toast(json.message); }
        } else {
          error((json && json.message) || 'Error');
        }
      }).catch(function () { busy(btn, false); error('Network error'); });
    }

    function verifyCode() {
      var code = (codeInput && codeInput.value || '').trim();
      if (!code) { if (codeInput) { codeInput.focus(); } return; }
      var btn = root.querySelector('[data-mkas-verify]');
      busy(btn, true);
      Mk.post(root.dataset.verifyUrl, { phone: lastPhone, code: code }).then(function (json) {
        busy(btn, false);
        if (json && json.ok) {
          if (json.message) { Mk.toast(json.message); }
          // T1-4: fold this device's guest hearts (localStorage mk_hearts)
          // into the account BEFORE the reload wipes the page.
          migrateHearts(Mk, root.dataset.favMigrateUrl).then(function () {
            window.location.reload();
          });
        } else {
          error((json && json.message) || 'Error');
        }
      }).catch(function () { busy(btn, false); error('Network error'); });
    }

    function logout() {
      Mk.post(root.dataset.logoutUrl, {}).then(function () {
        window.location.reload();
      });
    }

    root.addEventListener('click', function (e) {
      if (e.target === root || e.target.closest('[data-mkas-close]')) { api.close(); return; }
      if (e.target.closest('[data-mkas-send]')) { sendCode(); return; }
      if (e.target.closest('[data-mkas-verify]')) { verifyCode(); return; }
      if (e.target.closest('[data-mkas-back]')) { show('phone'); if (phoneInput) { phoneInput.focus(); } return; }
      if (e.target.closest('[data-mkas-logout]')) { logout(); }
    });

    root.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        if (e.target === phoneInput) { e.preventDefault(); sendCode(); }
        if (e.target === codeInput) { e.preventDefault(); verifyCode(); }
      }
      if (e.key === 'Escape') { api.close(); }
    });

    var api = {
      open: function () {
        root.hidden = false;
        var isAuthed = root.dataset.mkAuth === '1';
        show(isAuthed ? 'account' : 'phone');
        var focus = isAuthed ? root.querySelector('[data-mkas-logout]') : phoneInput;
        if (focus) { focus.focus(); }
      },
      close: function () { root.hidden = true; },
      isLoggedIn: function () { return root.dataset.mkAuth === '1'; }
    };

    return api;
  }

  /* T1-4: migrate guest hearts (localStorage 'mk_hearts' — array of store
     ids) to the server, then clear the key. Always resolves. */
  function migrateHearts(Mk, url) {
    var ids;
    try { ids = JSON.parse(window.localStorage.getItem('mk_hearts') || '[]'); } catch (e) { ids = []; }
    if (!url || !Array.isArray(ids) || !ids.length) { return Promise.resolve(); }
    return Mk.post(url, { store_ids: ids }).then(function (json) {
      if (json && json.ok) { try { window.localStorage.removeItem('mk_hearts'); } catch (e) {} }
    }).catch(function () {});
  }

  window.Mk.register('account', {
    init: function (Mk) {
      var api = boot(Mk);
      if (!api) { return; }
      this.open = api.open;
      this.close = api.close;
      this.isLoggedIn = api.isLoggedIn;

      // Wire any [data-mk-login] trigger (e.g. the topbar account button once
      // the lead swaps it to a button with this attribute).
      document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-mk-login]');
        if (t) { e.preventDefault(); api.open(); }
      });
    }
  });

  /* ============================================================
     T1-3 address book. Sheet markup: address-sheet.blade.php.
     Opens via [data-mk-addresses]. Checkout (T3) consumes
     Mk.modules.addresses.getDefault() → the cached default address
     (with zone info) for the current ctx city, or null.
     ============================================================ */
  function bootAddresses(Mk) {
    var root = document.getElementById('mk-addr-sheet');
    if (!root) { return null; }

    var d = root.dataset;
    var views = {
      list: root.querySelector('[data-mkad-view="list"]'),
      form: root.querySelector('[data-mkad-view="form"]')
    };
    var listEl = root.querySelector('[data-mkad-list]');
    var emptyEl = root.querySelector('[data-mkad-empty]');
    var errorEl = root.querySelector('[data-mkad-error]');
    var rangeEl = root.querySelector('[data-mkad-range]');
    var mapEl = root.querySelector('[data-mkad-map]');
    var inputs = {
      label: document.getElementById('mkad-label'),
      address: document.getElementById('mkad-address'),
      notes: document.getElementById('mkad-notes'),
      isDefault: document.getElementById('mkad-default')
    };

    var cache = [];            // last fetched address list (with zone info)
    var loaded = false;
    var editing = null;        // address being edited, or null (adding)
    var pin = { lat: null, lng: null };
    var map = null, marker = null;
    var resolveTimer = null;

    function error(msg) {
      if (!errorEl) { return; }
      errorEl.textContent = msg || '';
      errorEl.hidden = !msg;
    }

    function show(view) {
      Object.keys(views).forEach(function (k) {
        if (views[k]) { views[k].hidden = k !== view; }
      });
      error('');
    }

    function req(method, url, body) {
      return fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': Mk.csrf },
        body: body ? JSON.stringify(body) : undefined
      }).then(function (r) { return r.json(); });
    }

    /* ---------- list ---------- */
    function load() {
      return Mk.get(d.listUrl).then(function (json) {
        if (json && json.ok) {
          cache = json.addresses || [];
          loaded = true;
          render();
        }
        return cache;
      });
    }

    function render() {
      if (!listEl) { return; }
      listEl.textContent = '';
      if (emptyEl) { emptyEl.hidden = cache.length > 0; }
      cache.forEach(function (a) {
        var item = document.createElement('div');
        item.className = 'mkad__item' + (a.out_of_range ? ' mkad__item--range' : '');

        var main = document.createElement('button');
        main.type = 'button';
        main.className = 'mkad__item-main';
        main.setAttribute('aria-label', d.strEdit);

        var label = document.createElement('div');
        label.className = 'mkad__item-label';
        label.appendChild(document.createTextNode(a.label || ''));
        if (a.is_default) {
          var badge = document.createElement('span');
          badge.className = 'mkad__badge';
          badge.textContent = d.strDefaultBadge;
          label.appendChild(badge);
        }
        if (a.out_of_range) {
          var out = document.createElement('span');
          out.className = 'mkad__badge mkad__badge--range';
          out.textContent = d.strOut;
          label.appendChild(out);
        }
        var addr = document.createElement('div');
        addr.className = 'mkad__item-addr';
        addr.textContent = a.address || '';
        main.appendChild(label);
        main.appendChild(addr);
        main.addEventListener('click', function () { openForm(a); });
        item.appendChild(main);

        if (!a.is_default) {
          var def = document.createElement('button');
          def.type = 'button';
          def.className = 'mkad__item-act';
          def.textContent = '★';
          def.setAttribute('aria-label', d.strDefault);
          def.addEventListener('click', function () {
            req('POST', d.storeUrl + '/' + a.id + '/default').then(function (json) {
              if (json && json.ok) { load(); } else { error((json && json.message) || 'Error'); }
            });
          });
          item.appendChild(def);
        }

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'mkad__item-act';
        del.textContent = '🗑';
        del.setAttribute('aria-label', d.strDelete);
        del.addEventListener('click', function () {
          if (!window.confirm(d.strDeleteConfirm)) { return; }
          req('DELETE', d.storeUrl + '/' + a.id).then(function (json) {
            if (json && json.ok) { load(); } else { error((json && json.message) || 'Error'); }
          });
        });
        item.appendChild(del);

        listEl.appendChild(item);
      });
    }

    /* ---------- map ---------- */
    function leaflet() {
      if (window.L) { return Promise.resolve(window.L); }
      return new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = d.leafletJs;
        s.onload = function () { resolve(window.L); };
        s.onerror = reject;
        document.head.appendChild(s);
      });
    }

    function pinMoved() {
      if (resolveTimer) { clearTimeout(resolveTimer); }
      resolveTimer = setTimeout(function () {
        Mk.get(d.resolveUrl + '?lat=' + encodeURIComponent(pin.lat) + '&lng=' + encodeURIComponent(pin.lng))
          .then(function (json) {
            if (rangeEl && json && json.ok) { rangeEl.hidden = !json.out_of_range; }
          }).catch(function () {});
      }, 250);
    }

    function initMap(lat, lng) {
      leaflet().then(function (L) {
        L.Icon.Default.prototype.options.imagePath = d.leafletImages;
        if (!map) {
          map = L.map(mapEl).setView([lat, lng], 15);
          L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
          }).addTo(map);
          marker = L.marker([lat, lng], { draggable: true }).addTo(map);
          marker.on('dragend', function () {
            var p = marker.getLatLng();
            pin.lat = p.lat; pin.lng = p.lng;
            pinMoved();
          });
          map.on('click', function (e) {
            marker.setLatLng(e.latlng);
            pin.lat = e.latlng.lat; pin.lng = e.latlng.lng;
            pinMoved();
          });
        } else {
          map.setView([lat, lng], 15);
          marker.setLatLng([lat, lng]);
        }
        setTimeout(function () { map.invalidateSize(); }, 60);
        pin.lat = lat; pin.lng = lng;
        pinMoved();
      }).catch(function () { if (mapEl) { mapEl.hidden = true; } });
    }

    /* ---------- form ---------- */
    function openForm(address) {
      editing = address || null;
      if (inputs.label) { inputs.label.value = editing ? (editing.label || '') : ''; }
      if (inputs.address) { inputs.address.value = editing ? (editing.address || '') : ''; }
      if (inputs.notes) { inputs.notes.value = editing ? (editing.notes || '') : ''; }
      if (inputs.isDefault) {
        inputs.isDefault.checked = editing ? !!editing.is_default : cache.length === 0;
      }
      if (rangeEl) { rangeEl.hidden = !(editing && editing.out_of_range); }
      show('form');
      var lat = editing && editing.lat != null ? editing.lat : parseFloat(d.cityLat);
      var lng = editing && editing.lng != null ? editing.lng : parseFloat(d.cityLng);
      initMap(lat, lng);
      if (inputs.label) { inputs.label.focus(); }
    }

    function save() {
      var body = {
        label: (inputs.label && inputs.label.value || '').trim(),
        address: (inputs.address && inputs.address.value || '').trim(),
        notes: (inputs.notes && inputs.notes.value || '').trim() || null,
        lat: pin.lat,
        lng: pin.lng,
        is_default: !!(inputs.isDefault && inputs.isDefault.checked)
      };
      if (!body.label) { if (inputs.label) { inputs.label.focus(); } return; }
      if (!body.address) { if (inputs.address) { inputs.address.focus(); } return; }
      var btn = root.querySelector('[data-mkad-save]');
      if (btn) { btn.disabled = true; }
      var p = editing
        ? req('PATCH', d.storeUrl + '/' + editing.id, body)
        : req('POST', d.storeUrl, body);
      p.then(function (json) {
        if (btn) { btn.disabled = false; }
        if (json && (json.ok || json.address)) {
          if (json.address && rangeEl) { rangeEl.hidden = !json.address.out_of_range; }
          load().then(function () { show('list'); });
        } else {
          var msg = (json && json.message) || 'Error';
          if (json && json.errors) {
            var first = Object.keys(json.errors)[0];
            if (first && json.errors[first][0]) { msg = json.errors[first][0]; }
          }
          error(msg);
        }
      }).catch(function () { if (btn) { btn.disabled = false; } error('Network error'); });
    }

    root.addEventListener('click', function (e) {
      if (e.target === root || e.target.closest('[data-mkad-close]')) { api.close(); return; }
      if (e.target.closest('[data-mkad-add]')) { openForm(null); return; }
      if (e.target.closest('[data-mkad-back]')) { show('list'); return; }
      if (e.target.closest('[data-mkad-save]')) { save(); }
    });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { api.close(); }
    });

    var api = {
      open: function () {
        root.hidden = false;
        show('list');
        load();
      },
      close: function () { root.hidden = true; },
      load: load,
      /* T3 checkout contract: cached default address (incl. zone/out_of_range)
         for the current ctx city; null when none saved or list not loaded. */
      getDefault: function () {
        var cityId = parseInt(d.cityId, 10) || (Mk.ctx && Mk.ctx.city_id) || 0;
        for (var i = 0; i < cache.length; i++) {
          if (cache[i].is_default && (!cityId || cache[i].city_id === cityId)) { return cache[i]; }
        }
        return null;
      },
      isLoaded: function () { return loaded; }
    };

    return api;
  }

  window.Mk.register('addresses', {
    init: function (Mk) {
      var api = bootAddresses(Mk);
      if (!api) { return; }
      this.open = api.open;
      this.close = api.close;
      this.load = api.load;
      this.getDefault = api.getDefault;
      this.isLoaded = api.isLoaded;

      document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-mk-addresses]');
        if (t) { e.preventDefault(); api.open(); }
      });

      // Warm the cache once for checkout's getDefault() when signed in. The
      // promise is kept (`warm`) so later consumers — P8.1's account-page
      // address chips — can await THIS fetch instead of firing a second one.
      var auth = document.getElementById('mk-auth-sheet');
      if (auth && auth.dataset.mkAuth === '1') { this.warm = api.load(); }
    }
  });

  /* ============================================================
     T1-4 favorites. Server API client for hearted stores — T2's store-card
     hearts consume window.Mk.modules.favorites:
       list()      → Promise<{ok, ids: [], stores: []}>
       toggle(id)  → Promise<{ok, favorited}>   POST toggles membership
       remove(id)  → Promise<{ok}>              DELETE, idempotent
       isLoggedIn()→ bool (guests should keep hearts in localStorage
                     'mk_hearts'; they migrate automatically on login)
     URLs ride on the #mk-auth-sheet data attrs so the module works on any
     page that includes the auth sheet.
     ============================================================ */
  window.Mk.register('favorites', {
    init: function (Mk) {
      var auth = document.getElementById('mk-auth-sheet');
      if (!auth) { return; }
      var d = auth.dataset;
      var authed = d.mkAuth === '1';

      function req(method, url, body) {
        return fetch(url, {
          method: method,
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': Mk.csrf },
          body: body ? JSON.stringify(body) : undefined
        }).then(function (r) { return r.json(); });
      }

      this.isLoggedIn = function () { return authed; };
      this.list = function () { return Mk.get(d.favListUrl); };
      this.toggle = function (id) { return Mk.post(d.favToggleUrl, { store_id: id }); };
      this.remove = function (id) { return req('DELETE', d.favToggleUrl, { store_id: id }); };

      // Already signed in with leftover guest hearts (e.g. logged in on
      // another tab before hearting here) — fold them in now.
      if (authed) { migrateHearts(Mk, d.favMigrateUrl); }
    }
  });

  /* ============================================================
     T1-5 push. Shopper Web Push opt-in — reuses the staff-PWA VAPID public
     key (data-push-key on #mk-auth-sheet). Two surfaces share ONE state
     machine: the [data-mkas-push] button in the auth sheet's signed-in step
     and (P8.1) the [data-mka-push] switch in the account page's preferences.
     Both stay hidden unless the browser supports push AND a VAPID key is
     configured — a toggle that can never subscribe is a dead control. Click
     toggles subscribe/unsubscribe; the push + notificationclick handlers live
     in mk-push-sw.js (importScripts'd by the shared sw.js — lead-owned).
     ============================================================ */
  window.Mk.register('push', {
    init: function (Mk) {
      var auth = document.getElementById('mk-auth-sheet');
      if (!auth || auth.dataset.mkAuth !== '1') { return; }
      var d = auth.dataset;
      var btn = auth.querySelector('[data-mkas-push]');
      var sw = document.querySelector('[data-mka-push]');
      var swRow = document.querySelector('[data-mka-push-row]');
      var key = d.pushKey || '';
      if ((!btn && !sw) || !key || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return; }
      /* Messages come from whichever control exists — the sheet button carries
         them as data-msg-*, and so does the account switch. */
      var msg = function (name) {
        var el = sw || btn;
        return (el && el.dataset[name]) || '';
      };

      // atob-based base64url → Uint8Array for applicationServerKey.
      function keyBytes(k) {
        var pad = '='.repeat((4 - (k.length % 4)) % 4);
        var raw = atob((k + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
      }

      var subscribed = false;

      function paint() {
        if (btn) {
          btn.textContent = subscribed ? btn.dataset.labelOn : btn.dataset.labelOff;
          btn.hidden = false;
        }
        if (sw) {
          sw.classList.toggle('is-on', subscribed);
          sw.setAttribute('aria-checked', subscribed ? 'true' : 'false');
        }
        if (swRow) { swRow.hidden = false; }
      }

      navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription();
      }).then(function (sub) {
        subscribed = !!sub;
        paint();
      }).catch(function () { /* no SW on this page — keep the button hidden */ });

      function enable() {
        Notification.requestPermission().then(function (perm) {
          if (perm !== 'granted') { Mk.toast(msg('msgDenied')); return; }
          navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
          }).then(function (sub) {
            return Mk.post(d.pushSubUrl, sub.toJSON());
          }).then(function (json) {
            if (json && json.ok) { subscribed = true; paint(); Mk.toast(msg('msgOn')); }
          }).catch(function () { Mk.toast(msg('msgDenied')); });
        });
      }

      function disable() {
        navigator.serviceWorker.ready.then(function (reg) {
          return reg.pushManager.getSubscription();
        }).then(function (sub) {
          if (!sub) { subscribed = false; paint(); return null; }
          var endpoint = sub.endpoint;
          return sub.unsubscribe().then(function () {
            return Mk.post(d.pushUnsubUrl, { endpoint: endpoint });
          }).then(function () { subscribed = false; paint(); Mk.toast(msg('msgOff')); });
        }).catch(function () {});
      }

      function toggle() {
        if (subscribed) { disable(); } else { enable(); }
      }

      if (btn) { btn.addEventListener('click', toggle); }
      if (sw) { sw.addEventListener('click', toggle); }
    }
  });

  /* ============================================================
     P8.1 account page (crave refit). Mounts only on #mk-account-page, so this
     stays inert on every other /fusion page that loads mk-account.js. Owns:
     profile editor toggle + save, theme segmented control, address chips,
     favourite-rail heart state, install row visibility and sign-out.
     Push lives in the module above (shared state machine); the [data-mk-login]
     and [data-mk-addresses] triggers are already delegated by the account /
     addresses modules. All user-facing strings arrive as data-* attributes —
     this file has no access to ___().
     ============================================================ */
  window.Mk.register('accountPage', {
    init: function (Mk) {
      var root = document.getElementById('mk-account-page');
      if (!root) { return; }
      var d = root.dataset;

      /* ---------- profile: disclosure + save ---------- */
      var edit = root.querySelector('[data-mka-edit]');
      var card = document.getElementById('mka-profile-card');
      if (edit && card) {
        edit.addEventListener('click', function () {
          var open = card.hidden;
          card.hidden = !open;
          edit.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (open) {
            var first = card.querySelector('input:not([disabled])');
            if (first) { first.focus(); }
          }
        });
      }

      var form = document.getElementById('mka-profile');
      var nameIn = document.getElementById('mka-name');
      var emailIn = document.getElementById('mka-email');
      if (form && nameIn && emailIn) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var btn = form.querySelector('[type="submit"]');
          var msg = form.querySelector('[data-mka-profile-msg]');
          btn.disabled = true;
          Mk.post(d.profileUrl, {
            name: nameIn.value.trim(),
            email: emailIn.value.trim() || null
          }).then(function (json) {
            btn.disabled = false;
            var text = (json && json.message) || d.strError;
            if (json && json.errors) {
              var k = Object.keys(json.errors)[0];
              if (k && json.errors[k][0]) { text = json.errors[k][0]; }
            }
            if (msg) { msg.textContent = text; msg.hidden = false; }
            if (json && json.ok) {
              if (Mk.toast) { Mk.toast(json.message); }
              // Keep the me-card in step with what was just saved.
              var meName = root.querySelector('.mk-me__txt b');
              if (meName && nameIn.value.trim()) { meName.textContent = nameIn.value.trim(); }
            }
          }).catch(function () {
            btn.disabled = false;
            if (msg) { msg.textContent = d.strError; msg.hidden = false; }
          });
        });
      }

      /* ---------- theme segmented control ----------
         Mk.theme owns apply/toggle + the localStorage key; this only reflects
         and drives it. Painted client-side: the server cannot know which theme
         this device stored, and dark is the default. */
      var seg = root.querySelector('[data-mka-themeseg]');
      if (seg) {
        var opts = seg.querySelectorAll('[data-mka-theme]');
        var paintTheme = function () {
          var cur = document.documentElement.getAttribute('data-mk-theme') || 'dark';
          Array.prototype.forEach.call(opts, function (b) {
            var on = b.getAttribute('data-mka-theme') === cur;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
          });
        };
        seg.addEventListener('click', function (e) {
          var b = e.target.closest('[data-mka-theme]');
          if (!b) { return; }
          Mk.theme.apply(b.getAttribute('data-mka-theme'));
          paintTheme();
        });
        paintTheme();
      }

      /* ---------- install row ----------
         mk-core adds .is-available to every .mk-install on beforeinstallprompt
         and removes it once the prompt is consumed. The row follows that flag
         so a label never sits next to a button that cannot do anything. */
      var installRow = root.querySelector('[data-mka-install-row]');
      if (installRow) {
        var installBtn = installRow.querySelector('.mk-install');
        var paintInstall = function () {
          var ready = !!(Mk.install && Mk.install.deferred)
            || !!(installBtn && installBtn.classList.contains('is-available'));
          installRow.hidden = !ready;
        };
        window.addEventListener('beforeinstallprompt', function () { setTimeout(paintInstall, 0); });
        if (installBtn) { installBtn.addEventListener('click', function () { setTimeout(paintInstall, 0); }); }
        paintInstall();
      }

      /* ---------- address chips ----------
         Rendered from the SAME list the T1-3 sheet fetches (its warm-up promise
         when it exists, so the page does not issue a second GET). Tapping a chip
         opens that sheet — there is no second address editor here. */
      var addrWrap = root.querySelector('[data-mka-addrs]');
      var addrEmpty = root.querySelector('[data-mka-addr-empty]');
      var addresses = Mk.modules && Mk.modules.addresses;
      if (addrWrap && addresses && addresses.load) {
        var renderAddrs = function (list) {
          list = list || [];
          addrWrap.textContent = '';
          addrWrap.hidden = list.length === 0;
          if (addrEmpty) { addrEmpty.hidden = list.length > 0; }
          list.forEach(function (a) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'mk-addrcard' + (a.is_default ? ' mk-addrcard--on' : '');
            chip.textContent = a.label || a.address || '';
            if (a.is_default && d.strDefault) { chip.title = d.strDefault; }
            chip.addEventListener('click', function () { addresses.open(); });
            addrWrap.appendChild(chip);
          });
        };
        var pullAddrs = function () {
          return Promise.resolve(addresses.load()).then(renderAddrs).catch(function () {});
        };
        Promise.resolve(addresses.warm || addresses.load())
          .then(renderAddrs).catch(function () {});
        // The sheet can add/edit/delete; refresh the chips when it closes.
        document.addEventListener('click', function (e) {
          if (e.target.closest && e.target.closest('[data-mkad-close]')) { setTimeout(pullAddrs, 0); }
        });
      }

      /* ---------- favourite rail ----------
         Every card in this rail IS a favourite, so its [data-mk-fav] heart is
         filled without asking the server. mk-core owns the toggle from here on;
         un-hearting empties the heart and the store drops out on next load. */
      var rail = root.querySelector('[data-mka-favrail]');
      if (rail) {
        rail.querySelectorAll('[data-mk-fav]').forEach(function (b) {
          b.classList.add('is-on');
          if (Mk.hearts && Mk.hearts.paint) { Mk.hearts.paint(b, true); }
        });
      }

      /* ---------- sign out ----------
         Same endpoint the auth sheet's Log out uses (its URL rides on the
         sheet's dataset), so there is one logout path, not two. */
      var out = root.querySelector('[data-mka-logout]');
      var authSheet = document.getElementById('mk-auth-sheet');
      if (out && authSheet && authSheet.dataset.logoutUrl) {
        out.addEventListener('click', function () {
          out.disabled = true;
          Mk.post(authSheet.dataset.logoutUrl, {}).then(function () {
            window.location.reload();
          }).catch(function () { out.disabled = false; });
        });
      } else if (out) {
        out.hidden = true;
      }
    }
  });

  /* ============================================================
     P9.2 Fusion wallet card + top-up sheet. Mounts ONLY on
     #mk-wallet-card, which account.blade.php renders only while
     ShopperWalletService::enabled() and a shopper is signed in — so a disabled
     wallet has no markup and this module is inert, not merely quiet.

     PER CURRENCY, NEVER TOTALLED. The hero shows the balance of the ONE wallet
     spendable in the city being browsed; every other pot the shopper holds is
     listed beside it, separately labelled. Nothing here adds two currencies.

     Limits, presets and gateways are read from mk.wallet.balance rather than
     baked into the markup, so the sheet can only ever offer what the server
     will accept. The top-up itself is a real browser NAVIGATION to pay_url: a
     gateway owns the next screen, and following it with fetch would land the
     shopper on a page they cannot see. Nothing is credited by that redirect —
     the balance moves only when a driver confirms the charge server-side.
     ============================================================ */
  window.Mk.register('walletCard', {
    init: function (Mk) {
      var card = document.getElementById('mk-wallet-card');
      if (!card) { return; }
      var d = card.dataset;
      var sheet = document.getElementById('mk-wallet-sheet');

      var balanceEl = card.querySelector('[data-mkw-balance]');
      var othersEl = card.querySelector('[data-mkw-others]');
      var config = null;       // last mk.wallet.balance payload

      /* Currency-aware formatting WITHOUT a hardcoded decimals table: Intl
         already knows each ISO code's minor unit (KWD/BHD/OMR are 3, JPY is 0),
         which is the same fact `currency_decimals()` encodes server-side, so the
         two cannot drift. Falls back to a plain code + 2dp where Intl is absent
         or the code is unknown. The currency is ALWAYS stamped on the figure —
         an unlabelled number in a multi-currency wallet is a lie waiting. */
      function money(currency, amount) {
        var value = Number(amount) || 0;
        try {
          return value.toLocaleString(undefined, { style: 'currency', currency: currency });
        } catch (e) {
          return currency + ' ' + value.toFixed(2);
        }
      }

      /* ---------- balance ---------- */
      function paintBalance(json) {
        config = json || null;
        if (!json) { return; }
        if (balanceEl && json.formatted) { balanceEl.textContent = json.formatted; }

        // Other currencies: re-rendered wholesale so a pot that emptied since
        // the page was served disappears instead of lingering.
        if (othersEl) {
          var others = Object.keys(json.balances || {}).filter(function (c) { return c !== json.currency; });
          othersEl.querySelectorAll('[data-mkw-other]').forEach(function (n) { n.remove(); });
          var label = othersEl.querySelector('.mk-wallet__lbl');
          others.forEach(function (code) {
            var pill = document.createElement('span');
            pill.className = 'mk-pill';
            pill.setAttribute('data-mkw-other', code);
            pill.textContent = money(code, json.balances[code]);
            if (label && label.nextSibling) { othersEl.insertBefore(pill, label.nextSibling); }
            else { othersEl.appendChild(pill); }
          });
          othersEl.hidden = others.length === 0;
        }
      }

      function loadBalance() {
        return Mk.get(d.balanceUrl).then(paintBalance).catch(function () {});
      }
      loadBalance();

      /* ---------- statement ---------- */
      var histToggle = card.querySelector('[data-mkw-history-toggle]');
      var histBox = document.getElementById('mkw-history');
      var histList = card.querySelector('[data-mkw-history]');
      var histMsg = card.querySelector('[data-mkw-history-msg]');
      var histLoaded = false;

      function typeLabel(type) {
        return d['type' + type.charAt(0).toUpperCase() + type.slice(1)] || type;
      }

      function loadHistory() {
        return Mk.get(d.historyUrl).then(function (json) {
          if (!histList) { return; }
          histList.textContent = '';
          var entries = (json && json.entries) || [];
          if (histMsg) {
            histMsg.textContent = entries.length ? '' : d.strEmpty;
            histMsg.hidden = entries.length > 0;
          }
          entries.forEach(function (e) {
            var li = document.createElement('li');
            li.className = 'mk-wallet__row' + (e.amount < 0 ? ' is-out' : ' is-in');

            var why = document.createElement('b');
            // The reason the server recorded, falling back to the movement type.
            why.textContent = e.reason || typeLabel(e.type || '');
            li.appendChild(why);

            var amt = document.createElement('span');
            amt.className = 'mk-wallet__rowamt';
            // Signed and currency-stamped: a statement mixing currencies must
            // never let two rows look like they belong to one running total.
            amt.textContent = (e.amount > 0 ? '+' : '−') + money(e.currency, Math.abs(e.amount));
            li.appendChild(amt);

            if (e.created_at) {
              var when = document.createElement('small');
              when.textContent = new Date(e.created_at).toLocaleDateString();
              li.appendChild(when);
            }
            histList.appendChild(li);
          });
          histLoaded = true;
        }).catch(function () {
          if (histMsg) { histMsg.textContent = d.strError; histMsg.hidden = false; }
        });
      }

      if (histToggle && histBox) {
        histToggle.addEventListener('click', function () {
          var open = histBox.hidden;
          histBox.hidden = !open;
          histToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (open && !histLoaded) { loadHistory(); }
        });
      }

      /* ---------- top-up sheet ---------- */
      if (!sheet) { return; }
      var amountEl = sheet.querySelector('[data-mkw-amount]');
      var presetsEl = sheet.querySelector('[data-mkw-presets]');
      var gatewaysEl = sheet.querySelector('[data-mkw-gateways]');
      var noGateway = sheet.querySelector('[data-mkw-nogateway]');
      var limitsEl = sheet.querySelector('[data-mkw-limits]');
      var payBtn = sheet.querySelector('[data-mkw-pay]');
      var errorEl = sheet.querySelector('[data-mkw-error]');

      function error(msg) {
        if (!errorEl) { return; }
        errorEl.textContent = msg || '';
        errorEl.hidden = !msg;
      }

      /* The sheet is built from the server's OWN limits: min/max become the
         input's bounds, the presets become the quick picks, and the gateways
         are exactly the installed platform ones. Nothing offerable here can be
         refused by mk.wallet.topup for being out of range or unknown. */
      function paintSheet() {
        var topup = (config && config.topup) || null;
        if (!topup) { return; }

        if (amountEl) {
          amountEl.min = topup.min;
          amountEl.max = topup.max;
          if (!amountEl.value) { amountEl.value = topup.presets && topup.presets.length ? topup.presets[0] : topup.min; }
        }
        if (limitsEl) {
          limitsEl.textContent = money(config.currency, topup.min) + ' – ' + money(config.currency, topup.max);
        }

        if (presetsEl) {
          presetsEl.textContent = '';
          (topup.presets || []).forEach(function (value) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mk-seg__btn';
            b.textContent = money(config.currency, value);
            b.addEventListener('click', function () {
              if (amountEl) { amountEl.value = value; }
              presetsEl.querySelectorAll('.mk-seg__btn').forEach(function (n) { n.classList.remove('is-active'); });
              b.classList.add('is-active');
              error('');
            });
            presetsEl.appendChild(b);
          });
        }

        if (gatewaysEl) {
          gatewaysEl.textContent = '';
          var list = topup.gateways || [];
          list.forEach(function (g, i) {
            var row = document.createElement('label');
            row.className = 'mk-copay__row' + (i === 0 ? ' is-on' : '');
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'mkw-gateway';
            input.value = g.id;
            input.checked = i === 0;
            var txt = document.createElement('span');
            txt.className = 'mk-copay__txt';
            var b = document.createElement('b');
            b.textContent = g.title || g.folder;
            txt.appendChild(b);
            row.appendChild(input);
            row.appendChild(txt);
            row.addEventListener('change', function () {
              gatewaysEl.querySelectorAll('.mk-copay__row').forEach(function (n) { n.classList.remove('is-on'); });
              row.classList.add('is-on');
            });
            gatewaysEl.appendChild(row);
          });
          if (noGateway) { noGateway.hidden = list.length > 0; }
          if (payBtn) { payBtn.disabled = list.length === 0; }
        }
      }

      function open() {
        error('');
        sheet.hidden = false;
        // Config may not have landed yet on a slow first paint — build the
        // sheet from whatever the server says, never from a guess.
        if (config) { paintSheet(); } else { loadBalance().then(paintSheet); }
        if (amountEl) { amountEl.focus(); }
      }
      function close() { sheet.hidden = true; }

      var openBtn = card.querySelector('[data-mkw-topup]');
      if (openBtn) { openBtn.addEventListener('click', open); }
      sheet.addEventListener('click', function (e) {
        if (e.target === sheet || e.target.closest('[data-mkw-close]')) { close(); }
      });
      sheet.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); }
      });

      if (payBtn) {
        payBtn.addEventListener('click', function () {
          error('');
          var topup = (config && config.topup) || {};
          var amount = parseFloat(amountEl && amountEl.value);
          if (!(amount > 0) || (topup.min != null && amount < topup.min) || (topup.max != null && amount > topup.max)) {
            error(payBtn.getAttribute('data-err-amount') || '');
            if (amountEl) { amountEl.focus(); }
            return;
          }
          var picked = gatewaysEl && gatewaysEl.querySelector('input[type="radio"]:checked');
          if (!picked) {
            error(payBtn.getAttribute('data-err-gateway') || '');
            return;
          }

          var label = payBtn.textContent;
          payBtn.disabled = true;
          payBtn.textContent = payBtn.getAttribute('data-busy-text') || label;

          Mk.post(d.topupUrl, { amount: amount, payment_method: picked.value }).then(function (json) {
            if (json && json.ok && json.pay_url) {
              // A REAL navigation. The gateway renders the next screen; a fetch
              // would swallow it. The button stays disabled — this page is gone.
              window.location.assign(json.pay_url);
              return;
            }
            payBtn.disabled = false;
            payBtn.textContent = label;
            error((json && json.error) || d.strError);
          }).catch(function () {
            payBtn.disabled = false;
            payBtn.textContent = label;
            error(d.strError);
          });
        });
      }
    }
  });
})();
