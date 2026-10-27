// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * mb-builder runtime — behavior primitives for html-mode builder pages.
 *
 * WHY THIS EXISTS
 * Builder pages are sanitised by App\Support\Html::cleanPage(): operator HTML
 * keeps layout + inline CSS + scoped <style>, but ALL <script> and on* handlers
 * are stripped (semi-trusted operators). So operators can never author JS. This
 * platform-owned, nonce-loaded runtime restores interactivity WITHOUT operator
 * JS: it scans the sanitised `.mb-doc` for `data-mb-*` hooks (which cleanPage
 * keeps) and wires accessible, reduced-motion-aware behaviour. The operator
 * declares intent via data-attributes; we own the code.
 *
 * PRIMITIVES  (all inside .mb-doc only, so page CSS/JS never touches app chrome)
 *   data-mb-accordion[="single"]  + [data-mb-accordion-item]
 *                                   > [data-mb-accordion-trigger][data-mb-accordion-panel]
 *   data-mb-tabs                  + [data-mb-tab] / [data-mb-tabpanel]  (index- or id-matched)
 *   data-mb-carousel              + [data-mb-carousel-track] / -prev / -next / -dots
 *                                   [data-mb-carousel-autoplay="ms"]
 *   data-mb-reveal[="up|fade|zoom|left|right"] [data-mb-reveal-delay="ms"]
 *   data-mb-count-up              [data-mb-count-to] [data-mb-count-duration]
 *   data-mb-rotate                + [data-mb-rotate-item]  [data-mb-rotate-interval]
 *   data-mb-parallax              [data-mb-parallax-speed]   (desktop, motion-ok only)
 *
 * GUARANTEES
 *   - No dependency, no globals except window.MBBuilder.
 *   - Idempotent: every element is marked once (data-mb-ready) so re-init / HMR
 *     / the editor preview calling MBBuilder.init() again never double-binds.
 *   - Respects prefers-reduced-motion: motion becomes instant, autoplay off,
 *     parallax off. Content is ALWAYS visible without JS (reveal arms only after
 *     JS runs), so a script failure or old browser degrades to a readable page.
 *   - CSP-safe: sets element.style via the DOM (not inline style attributes it
 *     parses), adds no <script>; its transitions live in mb-builder.css.
 */
(function () {
  'use strict';

  var REDUCE = false;
  try {
    REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch (e) { /* no matchMedia → treat as full motion */ }

  var uid = 0;
  function nextId(prefix) { uid += 1; return (prefix || 'mb') + '-' + uid; }

  function once(el, flag) {
    if (!el || el.getAttribute('data-mb-ready') === flag) return false;
    el.setAttribute('data-mb-ready', flag);
    return true;
  }

  function on(el, type, fn, opts) { el.addEventListener(type, fn, opts || false); }

  function attrInt(el, name, dflt) {
    var v = parseInt(el.getAttribute(name) || '', 10);
    return isNaN(v) ? dflt : v;
  }

  /* ---------------------------------------------------------------- accordion */
  function initAccordion(root) {
    if (!once(root, 'accordion')) return;
    var single = root.getAttribute('data-mb-accordion') === 'single';
    var items = root.querySelectorAll('[data-mb-accordion-item]');
    var triggers = [];

    Array.prototype.forEach.call(items, function (item) {
      var trig = item.querySelector('[data-mb-accordion-trigger]');
      var panel = item.querySelector('[data-mb-accordion-panel]');
      if (!trig || !panel) return;
      var pid = panel.id || (panel.id = nextId('mbacc-panel'));
      var tid = trig.id || (trig.id = nextId('mbacc-trig'));
      var open = item.hasAttribute('data-mb-open');

      // Make the trigger behave as a real button for AT + keyboard.
      if (trig.tagName !== 'BUTTON') { trig.setAttribute('role', 'button'); trig.tabIndex = 0; }
      trig.setAttribute('aria-controls', pid);
      trig.setAttribute('aria-expanded', open ? 'true' : 'false');
      panel.setAttribute('role', 'region');
      panel.setAttribute('aria-labelledby', tid);
      panel.hidden = !open;
      triggers.push(trig);

      function setOpen(state) {
        trig.setAttribute('aria-expanded', state ? 'true' : 'false');
        panel.hidden = !state;
        item.toggleAttribute('data-mb-open', state);
        if (state && single) {
          Array.prototype.forEach.call(items, function (other) {
            if (other === item) return;
            var ot = other.querySelector('[data-mb-accordion-trigger]');
            var op = other.querySelector('[data-mb-accordion-panel]');
            if (ot && op) { ot.setAttribute('aria-expanded', 'false'); op.hidden = true; other.removeAttribute('data-mb-open'); }
          });
        }
      }
      on(trig, 'click', function (e) { e.preventDefault(); setOpen(panel.hidden); });
      on(trig, 'keydown', function (e) {
        if ((e.key === ' ' || e.key === 'Enter') && trig.tagName !== 'BUTTON') { e.preventDefault(); setOpen(panel.hidden); }
      });
    });

    // Roving arrow-key navigation across headers (APG accordion pattern).
    on(root, 'keydown', function (e) {
      var i = triggers.indexOf(document.activeElement);
      if (i < 0) return;
      var next = null;
      if (e.key === 'ArrowDown') next = triggers[(i + 1) % triggers.length];
      else if (e.key === 'ArrowUp') next = triggers[(i - 1 + triggers.length) % triggers.length];
      else if (e.key === 'Home') next = triggers[0];
      else if (e.key === 'End') next = triggers[triggers.length - 1];
      if (next) { e.preventDefault(); next.focus(); }
    });
  }

  /* --------------------------------------------------------------------- tabs */
  function initTabs(root) {
    if (!once(root, 'tabs')) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-mb-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-mb-tabpanel]'));
    if (!tabs.length || !panels.length) return;

    var list = tabs[0].parentNode;
    if (list) list.setAttribute('role', 'tablist');

    function panelFor(tab, i) {
      var ref = tab.getAttribute('data-mb-tab');
      if (ref) { for (var j = 0; j < panels.length; j++) { if (panels[j].getAttribute('data-mb-tabpanel') === ref) return panels[j]; } }
      return panels[i] || null;
    }

    function select(idx) {
      tabs.forEach(function (tab, i) {
        var sel = i === idx;
        tab.setAttribute('aria-selected', sel ? 'true' : 'false');
        tab.tabIndex = sel ? 0 : -1;
        var panel = panelFor(tab, i);
        if (panel) panel.hidden = !sel;
      });
    }

    tabs.forEach(function (tab, i) {
      if (tab.tagName !== 'BUTTON') tab.setAttribute('role', 'tab');
      var panel = panelFor(tab, i);
      if (panel) {
        var pid = panel.id || (panel.id = nextId('mbtab-panel'));
        var tid = tab.id || (tab.id = nextId('mbtab'));
        tab.setAttribute('aria-controls', pid);
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tid);
        panel.tabIndex = 0;
      }
      on(tab, 'click', function (e) { e.preventDefault(); select(i); tab.focus(); });
      on(tab, 'keydown', function (e) {
        var n = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % tabs.length;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + tabs.length) % tabs.length;
        else if (e.key === 'Home') n = 0;
        else if (e.key === 'End') n = tabs.length - 1;
        if (n !== null) { e.preventDefault(); select(n); tabs[n].focus(); }
      });
    });

    var initial = tabs.findIndex ? tabs.findIndex(function (t) { return t.hasAttribute('data-mb-selected'); }) : -1;
    select(initial >= 0 ? initial : 0);
  }

  /* ----------------------------------------------------------------- carousel */
  function initCarousel(root) {
    if (!once(root, 'carousel')) return;
    var track = root.querySelector('[data-mb-carousel-track]') || root;
    var slides = Array.prototype.slice.call(track.children);
    if (!slides.length) return;
    var prev = root.querySelector('[data-mb-carousel-prev]');
    var next = root.querySelector('[data-mb-carousel-next]');
    var dotsBox = root.querySelector('[data-mb-carousel-dots]');
    var current = 0;

    track.setAttribute('role', 'group');
    track.setAttribute('aria-roledescription', 'carousel');

    function go(i, smooth) {
      current = Math.max(0, Math.min(slides.length - 1, i));
      var target = slides[current];
      if (track.scrollTo) track.scrollTo({ left: target.offsetLeft - track.offsetLeft, behavior: (smooth && !REDUCE) ? 'smooth' : 'auto' });
      else track.scrollLeft = target.offsetLeft - track.offsetLeft;
      syncDots();
    }
    function syncDots() {
      if (!dots) return;
      dots.forEach(function (d, i) { d.setAttribute('aria-current', i === current ? 'true' : 'false'); d.tabIndex = i === current ? 0 : -1; });
    }

    var dots = null;
    if (dotsBox) {
      dots = slides.map(function (_, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mb-carousel-dot';
        b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        on(b, 'click', function () { go(i, true); });
        dotsBox.appendChild(b);
        return b;
      });
    }
    if (prev) on(prev, 'click', function () { go(current - 1, true); });
    if (next) on(next, 'click', function () { go(current + 1, true); });

    // Keep `current`/dots synced when the user scroll-snaps manually.
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { var idx = slides.indexOf(en.target); if (idx >= 0) { current = idx; syncDots(); } }
        });
      }, { root: track, threshold: 0.6 });
      slides.forEach(function (s) { io.observe(s); });
    }

    var interval = attrInt(root, 'data-mb-carousel-autoplay', 0);
    if (interval > 0 && !REDUCE) {
      var timer = null;
      var play = function () { timer = setInterval(function () { go((current + 1) % slides.length, true); }, interval); };
      var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
      play();
      on(root, 'mouseenter', stop); on(root, 'mouseleave', play);
      on(root, 'focusin', stop); on(root, 'focusout', play);
      on(document, 'visibilitychange', function () { if (document.hidden) stop(); else play(); });
    }
    syncDots();
  }

  /* ------------------------------------------------------------------- reveal */
  var revealIO = null;
  function initReveal(el) {
    if (!once(el, 'reveal')) return;
    // Arm ONLY now (after JS) so no-JS / old browsers keep the element visible.
    el.classList.add('mb-reveal');
    var mode = el.getAttribute('data-mb-reveal') || 'up';
    el.setAttribute('data-mb-reveal', mode);
    var delay = attrInt(el, 'data-mb-reveal-delay', 0);
    if (delay) el.style.transitionDelay = delay + 'ms';
    if (REDUCE || !('IntersectionObserver' in window)) { el.classList.add('mb-in'); return; }
    if (!revealIO) {
      revealIO = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('mb-in'); revealIO.unobserve(en.target); } });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    }
    revealIO.observe(el);
  }

  /* ------------------------------------------------------------------ countUp */
  function parseNum(txt) {
    var m = (txt || '').match(/-?[\d.,٠-٩۰-۹\s]*\d/);
    if (!m) return null;
    var raw = m[0];
    var normalized = raw
      .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 0x0660; })   // Arabic-Indic
      .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 0x06F0; })   // Extended Arabic-Indic
      .replace(/[\s,]/g, '');
    var val = parseFloat(normalized);
    return isNaN(val) ? null : { value: val, start: txt.indexOf(raw), raw: raw };
  }
  function initCountUp(el) {
    if (!once(el, 'countup')) return;
    var toAttr = el.getAttribute('data-mb-count-to');
    var target = toAttr != null ? parseFloat(toAttr) : (parseNum(el.textContent) || {}).value;
    if (target == null || isNaN(target)) return;
    var found = parseNum(el.textContent) || { value: target, start: 0, raw: String(target) };
    var prefix = el.textContent.slice(0, found.start);
    var suffix = el.textContent.slice(found.start + found.raw.length);
    var decimals = (String(target).split('.')[1] || '').length;
    var lang = (document.documentElement.getAttribute('lang') || 'en').split('-')[0];
    var fmt;
    try { fmt = new Intl.NumberFormat(lang, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); }
    catch (e) { fmt = { format: function (n) { return n.toFixed(decimals); } }; }
    var render = function (n) { el.textContent = prefix + fmt.format(n) + suffix; };

    if (REDUCE || !('IntersectionObserver' in window)) { render(target); return; }
    render(0);
    var dur = attrInt(el, 'data-mb-count-duration', 1600);
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(el);
        var t0 = null;
        (function step(ts) {
          if (t0 === null) t0 = ts;
          var p = Math.min(1, (ts - t0) / dur);
          render(target * (1 - Math.pow(1 - p, 3)));           // easeOutCubic
          if (p < 1) requestAnimationFrame(step); else render(target);
        })(performance.now ? performance.now() : Date.now());
      });
    }, { threshold: 0.4 });
    io.observe(el);
  }

  /* ------------------------------------------------------------------- rotate */
  function initRotate(root) {
    if (!once(root, 'rotate')) return;
    var items = Array.prototype.slice.call(root.querySelectorAll('[data-mb-rotate-item]'));
    if (items.length < 2) return;
    var i = 0;
    items.forEach(function (it, k) { it.classList.toggle('mb-rotate-on', k === 0); it.setAttribute('aria-hidden', k === 0 ? 'false' : 'true'); });
    if (REDUCE) return;                                     // show first word only
    var interval = attrInt(root, 'data-mb-rotate-interval', 2600);
    setInterval(function () {
      items[i].classList.remove('mb-rotate-on'); items[i].setAttribute('aria-hidden', 'true');
      i = (i + 1) % items.length;
      items[i].classList.add('mb-rotate-on'); items[i].setAttribute('aria-hidden', 'false');
    }, Math.max(1200, interval));
  }

  /* ----------------------------------------------------------------- parallax */
  var parallaxEls = [];
  var parallaxBound = false;
  function initParallax(el) {
    if (!once(el, 'parallax')) return;
    if (REDUCE) return;
    if (window.matchMedia && window.matchMedia('(hover: none)').matches) return;  // skip touch (jank)
    el.setAttribute('data-mb-parallax-speed', String(parseFloat(el.getAttribute('data-mb-parallax-speed')) || 0.2));
    parallaxEls.push(el);
    if (!parallaxBound) {
      parallaxBound = true;
      var ticking = false;
      var apply = function () {
        var vh = window.innerHeight || document.documentElement.clientHeight;
        parallaxEls.forEach(function (p) {
          var r = p.getBoundingClientRect();
          var speed = parseFloat(p.getAttribute('data-mb-parallax-speed')) || 0.2;
          var offset = (r.top + r.height / 2 - vh / 2) * -speed;
          p.style.transform = 'translate3d(0,' + offset.toFixed(1) + 'px,0)';
        });
        ticking = false;
      };
      on(window, 'scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(apply); } }, { passive: true });
      on(window, 'resize', function () { if (!ticking) { ticking = true; requestAnimationFrame(apply); } });
      apply();
    }
  }

  /* --------------------------------------------------------------------- forms */
  function showFormSuccess(form) {
    var msg = form.querySelector('[data-mb-form-success]');
    if (msg) {
      msg.hidden = false;
      Array.prototype.forEach.call(form.querySelectorAll('input,textarea,select,button'), function (el) { el.disabled = true; });
      msg.setAttribute('role', 'status');
    } else {
      var d = document.createElement('div');
      d.className = 'mb-form-thanks';
      d.setAttribute('role', 'status');
      d.textContent = form.getAttribute('data-mb-form-thanks') || 'Thanks — your message has been sent.';
      if (form.parentNode) form.parentNode.replaceChild(d, form);
    }
  }
  function initForm(form) {
    if (!once(form, 'form')) return;
    on(form, 'submit', function (e) {
      // No fetch → let the browser POST normally (server redirects back). Progressive.
      if (!window.fetch || !window.FormData) return;
      e.preventDefault();
      var btn = form.querySelector('[type="submit"]') || form.querySelector('button');
      if (btn) btn.disabled = true;
      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function () { showFormSuccess(form); })
        .catch(function () { if (btn) btn.disabled = false; form.setAttribute('data-mb-form-error', '1'); });
    });
  }

  /* --------------------------------------------------------------------- init */
  function init(scope) {
    var roots = [];
    var docs = scope ? [scope] : document.querySelectorAll('.mb-doc');
    Array.prototype.forEach.call(docs, function (d) { roots.push(d); });
    roots.forEach(function (root) {
      var q = function (sel) { return root.querySelectorAll(sel); };
      Array.prototype.forEach.call(q('[data-mb-accordion]'), initAccordion);
      Array.prototype.forEach.call(q('[data-mb-tabs]'), initTabs);
      Array.prototype.forEach.call(q('[data-mb-carousel]'), initCarousel);
      Array.prototype.forEach.call(q('[data-mb-rotate]'), initRotate);
      Array.prototype.forEach.call(q('[data-mb-count-up]'), initCountUp);
      Array.prototype.forEach.call(q('[data-mb-parallax]'), initParallax);
      Array.prototype.forEach.call(q('[data-mb-reveal]'), initReveal);
      Array.prototype.forEach.call(q('[data-mb-form]'), initForm);
    });
  }

  window.MBBuilder = { init: init, reduce: REDUCE };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
