// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* T2-3: store page module (lane L2) — item sheet, add-to-bag via hub
   endpoints, info sheet, closed gate, deep-link (#store-{rid}-item-{id}). */
(function () {
  'use strict';

  var mod = {
    init: function (Mk) {
      var page = document.querySelector('.mk-store');
      if (!page || !window.MkStorePage) { return; }
      var cfg = window.MkStorePage;

      var sheet = document.getElementById('mk-item-sheet');
      var info = document.getElementById('mk-info-sheet');
      var qtyEl = document.getElementById('mkis-qty');
      var totalEl = document.getElementById('mkis-total');
      var current = null;
      var qty = 1;

      function money(n) { return (Math.round(n * 100) / 100).toString(); }
      function syncTotal() {
        qtyEl.textContent = qty;
        qtyEl.classList.remove('mk-bump'); void qtyEl.offsetWidth; qtyEl.classList.add('mk-bump');
        totalEl.textContent = money((parseFloat(current.dataset.price) || 0) * qty);
      }

      function openItem(btn) {
        if (cfg.closed) { Mk.toast(MkI18n.closed); return; }
        if (btn.dataset.soldout === '1') { return; }
        current = btn;
        qty = 1;
        document.getElementById('mkis-name').textContent = btn.dataset.name;
        document.getElementById('mkis-desc').textContent = btn.dataset.desc || '';
        var img = document.getElementById('mkis-img');
        img.onerror = function () { img.hidden = true; };
        if (btn.dataset.image) { img.hidden = false; img.src = btn.dataset.image; } else { img.hidden = true; }
        document.getElementById('mkis-note').value = '';
        syncTotal();
        sheet.hidden = false;
      }

      page.addEventListener('click', function (e) {
        var btn = e.target.closest('.mk-item');
        if (btn) { openItem(btn); }
      });

      document.getElementById('mkis-minus').addEventListener('click', function () { if (qty > 1) { qty--; syncTotal(); } });
      document.getElementById('mkis-plus').addEventListener('click', function () { if (qty < 50) { qty++; syncTotal(); } });

      var adding = false; // T6-4 busy-guard: no double-add on double-tap
      document.getElementById('mkis-add').addEventListener('click', function () {
        if (!current || adding) { return; }
        adding = true;
        var addBtn = document.getElementById('mkis-add');
        addBtn.disabled = true;
        var note = document.getElementById('mkis-note').value.trim();
        fetch(cfg.addUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': Mk.csrf },
          body: JSON.stringify({ store_id: cfg.id, item_id: parseInt(current.dataset.item, 10), quantity: qty, note: note || null })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j.ok !== false) { sheet.hidden = true; Mk.toast(MkI18n.added); }
            else {
              var msg = (res.j.errors && Object.values(res.j.errors)[0][0]) || res.j.message || MkI18n.addFailed;
              Mk.toast(msg);
            }
          })
          .catch(function () { Mk.toast(MkI18n.addFailed); })
          .then(function () { adding = false; addBtn.disabled = false; });
      });

      // sheets: backdrop + escape close
      [sheet, info].forEach(function (el) {
        if (!el) { return; }
        el.addEventListener('click', function (e) { if (e.target === el) { el.hidden = true; } });
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { sheet.hidden = true; if (info) { info.hidden = true; } }
      });

      document.querySelectorAll('[data-mk-info]').forEach(function (b) {
        b.addEventListener('click', function () {
          info.hidden = false;
          if (cfg.lat && cfg.lng && window.L && !info.dataset.mapDone) {
            info.dataset.mapDone = '1';
            L.Icon.Default.prototype.options.imagePath = '/assets/vendor/leaflet/images/';
            var m = L.map('mkinfo-map', { scrollWheelZoom: false }).setView([cfg.lat, cfg.lng], 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(m);
            L.marker([cfg.lat, cfg.lng]).addTo(m);
            setTimeout(function () { m.invalidateSize(); }, 120);
          }
        });
      });

      // deep-link from search: #store-{rid}-item-{id}
      var m = (location.hash || '').match(/^#store-(\d+)-item-(\d+)$/);
      if (m && parseInt(m[1], 10) === cfg.id) {
        var target = document.getElementById('store-' + m[1] + '-item-' + m[2]);
        if (target) { target.scrollIntoView({ block: 'center' }); openItem(target); }
      }
    }
  };

  /* Snoonu-skin: sticky category tabs — click smooth-scrolls, scrollspy moves
     the red underline while the menu scrolls. */
  function cattabs() {
    var bar = document.getElementById('mk-cattabs');
    if (!bar) { return; }
    var links = [].slice.call(bar.querySelectorAll('a'));
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function activate(link) {
      links.forEach(function (l) { l.classList.toggle('is-active', l === link); });
      if (link && link.scrollIntoView) {
        link.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduced ? 'auto' : 'smooth' });
      }
    }

    bar.addEventListener('click', function (e) {
      var a = e.target.closest('a[href^="#"]');
      if (!a) { return; }
      var target = document.getElementById(a.getAttribute('href').slice(1));
      if (!target) { return; }
      e.preventDefault();
      activate(a);
      var y = target.getBoundingClientRect().top + window.scrollY - 116;
      window.scrollTo({ top: y, behavior: reduced ? 'auto' : 'smooth' });
    });

    if ('IntersectionObserver' in window) {
      var byId = {};
      links.forEach(function (l) { byId[l.getAttribute('href').slice(1)] = l; });
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting && byId[en.target.id]) { activate(byId[en.target.id]); }
        });
      }, { rootMargin: '-25% 0px -65% 0px' });
      Object.keys(byId).forEach(function (id) {
        var sec = document.getElementById(id);
        if (sec) { io.observe(sec); }
      });
    }
  }
  if (document.readyState !== 'loading') { cattabs(); }
  else { document.addEventListener('DOMContentLoaded', cattabs); }

  if (window.Mk && window.Mk.register) { window.Mk.register('discovery', mod); }
  else { document.addEventListener('DOMContentLoaded', function () { window.Mk && window.Mk.register('discovery', mod); }); }
})();
