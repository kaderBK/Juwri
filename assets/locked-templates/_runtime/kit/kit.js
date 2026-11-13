// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * juwri locked-template runtime for converted SaaS templates.
 *
 * The templates are server-rendered HTML (React stripped at conversion time), so this
 * file re-creates their behaviour from declarative data-ns-* hooks that the build
 * patches emitted: scroll reveals (Web Animations + IntersectionObserver), rolling number counters,
 * divider expand, sticky header, mobile drawer, Swiper sliders (+ prev/next/counter),
 * accordions, pricing plan switch, logo orbit, avatar pop-in, mouse drift, pill-button
 * hover, Lenis smooth scroll, and lead-form submission to the platform endpoint.
 *
 * Content comes from slots (user-edited text), so the runtime always reads CURRENT
 * DOM text (e.g. a counter animates to whatever number the user typed) rather than
 * values baked in at build time.
 *
 * Every page renders complete and readable without this file (reveals only hide an
 * element at the moment they animate it in). Respects prefers-reduced-motion.
 *
 * Motion uses the browser's own Web Animations API and CSS transitions (no animation
 * library): tween() below plays keyframes and then writes the end state inline, and
 * onView() starts a reveal when its element scrolls into view.
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var rtl = (root.getAttribute('dir') || '').toLowerCase() === 'rtl';
  var editor = root.hasAttribute('data-lt-editor');
  var canAnimate = typeof Element !== 'undefined' && typeof Element.prototype.animate === 'function';
  var lenis = null;

  // Easing curves (CSS cubic-bezier equivalents of the original motion).
  var EASE = {
    out: 'cubic-bezier(0.33, 1, 0.68, 1)',
    spring: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
    expo: 'cubic-bezier(0.16, 1, 0.3, 1)',
    sine: 'cubic-bezier(0.37, 0, 0.63, 1)',
    linear: 'linear',
  };

  function num(v, d) {
    var n = parseFloat(v);
    return isNaN(n) ? d : n;
  }

  function each(sel, fn, scope) {
    Array.prototype.forEach.call((scope || doc).querySelectorAll(sel), fn);
  }

  function applyStyle(el, style) {
    Object.keys(style).forEach(function (k) { el.style[k] = style[k]; });
  }

  // Play `from` -> `to` on el, then keep `to` as inline style. Returns a handle with
  // pause / play / kill (kill freezes the element where it is).
  function tween(el, from, to, opts, done) {
    opts = opts || {};
    var duration = Math.max(0, (opts.duration || 0) * 1000);
    var delay = Math.max(0, (opts.delay || 0) * 1000);
    var finished = false;
    var finish = function () {
      if (finished) {
        return;
      }
      finished = true;
      applyStyle(el, to);
      if (anim) {
        anim.cancel();
      }
      if (done) {
        done();
      }
    };
    var anim = null;
    if (from) {
      applyStyle(el, from);
    }
    if (!canAnimate || (duration === 0 && delay === 0)) {
      finish();
      return { pause: function () {}, play: function () {}, kill: function () {}, done: function () { return true; } };
    }
    anim = el.animate([from || {}, to], { duration: duration || 1, delay: delay, easing: opts.ease || EASE.out, fill: 'both' });
    anim.onfinish = finish;
    return {
      pause: function () { if (!finished) { anim.pause(); } },
      play: function () { if (!finished) { anim.play(); } },
      kill: function () {
        if (finished) {
          return;
        }
        finished = true;
        if (typeof anim.commitStyles === 'function') {
          try { anim.commitStyles(); } catch (e) { /* element not rendered */ }
        }
        anim.cancel();
      },
      done: function () { return finished; },
    };
  }

  // A pausable setTimeout.
  function timer(seconds, fn) {
    var left = seconds * 1000;
    var started = Date.now();
    var id = setTimeout(fn, left);
    return {
      pause: function () {
        if (id !== null) {
          clearTimeout(id);
          id = null;
          left -= Date.now() - started;
        }
      },
      play: function () {
        if (id === null && left > 0) {
          started = Date.now();
          id = setTimeout(fn, left);
        }
      },
      kill: function () {
        clearTimeout(id);
        id = null;
        left = 0;
      },
    };
  }

  // Run fn once el enters the viewport. `start` is the original "top NN%" trigger line
  // (the element's top crossing NN% of the viewport height).
  function onView(el, start, fn) {
    if (!('IntersectionObserver' in window)) {
      fn();
      return;
    }
    var m = /top\s+(\d+(?:\.\d+)?)%/.exec(start || '');
    var line = m ? Math.min(100, parseFloat(m[1])) : 90;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          io.disconnect();
          fn();
        }
      });
    }, { rootMargin: '0px 0px -' + (100 - line) + '% 0px', threshold: 0 });
    io.observe(el);
  }

  function transform(x, y, rotation, scale) {
    var t = [];
    if (x || y) {
      t.push('translate(' + (x || 0) + 'px, ' + (y || 0) + 'px)');
    }
    if (rotation) {
      t.push('rotate(' + rotation + 'deg)');
    }
    if (scale !== undefined && scale !== 1) {
      t.push('scale(' + scale + ')');
    }
    return t.length ? t.join(' ') : 'none';
  }

  function classes(el, add, remove) {
    (add || '').split(/\s+/).filter(Boolean).forEach(function (c) { el.classList.add(c); });
    (remove || '').split(/\s+/).filter(Boolean).forEach(function (c) { el.classList.remove(c); });
  }

  /* ------------------------------------------------------------ smooth scroll */
  function initLenis() {
    if (reduced || editor || typeof window.Lenis !== 'function') {
      return;
    }
    lenis = new window.Lenis({ duration: 1.1 });
    var raf = function (t) { lenis.raf(t); requestAnimationFrame(raf); };
    requestAnimationFrame(raf);
  }

  function scrollToHash(hash) {
    var target = hash.length > 1 ? doc.getElementById(decodeURIComponent(hash.slice(1))) : null;
    if (!target) {
      return false;
    }
    if (lenis) {
      lenis.scrollTo(target, { offset: -100 });
    } else {
      window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - 100, behavior: reduced ? 'auto' : 'smooth' });
    }
    return true;
  }

  function initAnchors() {
    doc.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href^="#"]');
      if (!a || editor) {
        return;
      }
      if (scrollToHash(a.getAttribute('href'))) {
        e.preventDefault();
        closeMenu();
      }
    });
  }

  /* ------------------------------------------------------------ reveal */
  function initReveal() {
    if (reduced || editor || !canAnimate) {
      // No animation: drop the "JS will reveal" marker so the CSS shows everything.
      root.classList.remove('lt-js');
      return;
    }
    each('[data-ns-animate]', function (el) {
      if (el.__nsReveal) {
        return;
      }
      el.__nsReveal = true;
      // The template CSS parks targets at opacity 0: the resting state is always
      // visible and sharp; "from" reveals start hidden, blurred and shifted.
      var type = el.getAttribute('data-ns-type') === 'to' ? 'to' : 'from';
      var offset = num(el.getAttribute('data-ns-offset'), 60);
      var dir = el.getAttribute('data-ns-direction') || 'down';
      if (rtl && (dir === 'left' || dir === 'right')) {
        dir = dir === 'left' ? 'right' : 'left';
      }
      var rot = num(el.getAttribute('data-ns-rotation'), 0);
      var horizontal = dir === 'left' || dir === 'right';
      var shift = (dir === 'left' || dir === 'up' ? -1 : 1) * offset;
      var moved = transform(horizontal ? shift : 0, horizontal ? 0 : shift, type === 'from' ? rot : 0);
      var rest = transform(0, 0, type === 'to' ? rot : 0);
      var from = type === 'from'
        ? { opacity: '0', filter: 'blur(16px)', transform: moved }
        : { opacity: '1', filter: 'blur(0)', transform: moved };
      var to = { opacity: '1', filter: 'blur(0)', transform: rest };
      var opts = {
        duration: num(el.getAttribute('data-ns-duration'), 0.6),
        delay: num(el.getAttribute('data-ns-delay'), 0),
        ease: el.hasAttribute('data-ns-spring') ? EASE.spring : EASE.out,
      };
      applyStyle(el, from);
      var play = function () { tween(el, from, to, opts); };
      if (el.hasAttribute('data-ns-instant')) {
        play();
      } else {
        onView(el, el.getAttribute('data-ns-start') || 'top 90%', play);
      }
    });
  }

  /* ------------------------------------------------------------ number counters */
  // Rolling counter: each digit is a vertical strip that rolls to
  // its value. Reads the CURRENT text (a user-edited slot), keeping any prefix /
  // suffix ("$", "+", "k", "%") as static text.
  function rollCounter(el) {
    var text = (el.textContent || '').trim();
    var m = text.match(/^([^0-9]*)([0-9][0-9.,]*)(.*)$/);
    if (!m) {
      return;
    }
    var prefix = m[1];
    var digits = m[2];
    var suffix = m[3];
    var speed = num(el.getAttribute('data-ns-speed'), 800);
    var interval = num(el.getAttribute('data-ns-interval'), 150);
    var rooms = Math.max(num(el.getAttribute('data-ns-rooms'), 2), digits.replace(/[.,]/g, '').length);

    // Pad with leading zeros to `rooms` digits (original behaviour), keep separators.
    var plain = digits.replace(/[.,]/g, '');
    while (plain.length < rooms) {
      digits = '0' + digits;
      plain = '0' + plain;
    }

    el.style.display = 'inline-flex';
    el.style.alignItems = 'center';
    el.style.direction = 'ltr';           // digits always read left to right
    el.style.unicodeBidi = 'isolate';
    el.style.verticalAlign = 'top';       // sit on the text line when inline in a sentence
    var restoreStyle = { display: '', alignItems: '', verticalAlign: '' };
    var probe = doc.createElement('span');
    probe.textContent = '0';
    el.textContent = '';
    el.appendChild(probe);
    var h = probe.offsetHeight || 40;
    var w = num(el.getAttribute('data-ns-space'), 0) || h / num(el.getAttribute('data-ns-hsr'), 2.2);
    el.textContent = '';
    el.setAttribute('aria-label', text);

    function cell(content) {
      var box = doc.createElement('span');
      box.setAttribute('aria-hidden', 'true');
      box.style.cssText = 'display:inline-block;position:relative;overflow:hidden;width:' + w + 'px;height:' + h + 'px;line-height:' + h + 'px;text-align:center;';
      var strip = doc.createElement('span');
      strip.style.cssText = 'position:absolute;left:0;top:0;width:100%;';
      content.forEach(function (c) {
        var s = doc.createElement('span');
        s.style.cssText = 'display:block;height:' + h + 'px;';
        s.textContent = c;
        strip.appendChild(s);
      });
      box.appendChild(strip);
      return { box: box, strip: strip };
    }

    function staticText(t) {
      var s = doc.createElement('span');
      s.setAttribute('aria-hidden', 'true');
      s.textContent = t;
      return s;
    }

    if (prefix) {
      el.appendChild(staticText(prefix));
    }
    var strips = [];
    digits.split('').forEach(function (ch) {
      if (ch === '.' || ch === ',') {
        var sep = cell([ch]);
        sep.box.style.width = (w * 0.45) + 'px';
        el.appendChild(sep.box);
        return;
      }
      var c = cell(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']);
      el.appendChild(c.box);
      strips.push({ strip: c.strip, to: parseInt(ch, 10) });
    });
    if (suffix) {
      el.appendChild(staticText(suffix));
    }

    // When every digit has landed, go back to plain text (exact typography, copyable).
    setTimeout(function () {
      el.textContent = text;
      el.removeAttribute('aria-label');
      Object.keys(restoreStyle).forEach(function (k) { el.style[k] = restoreStyle[k]; });
    }, interval * strips.length + speed + 80);

    strips.forEach(function (s, i) {
      setTimeout(function () {
        var start = performance.now();
        var target = -h * s.to;
        (function frame(now) {
          var p = Math.min((now - start) / speed, 1);
          var eased = 1 - Math.pow(1 - p, 3);
          s.strip.style.top = target * eased + 'px';
          if (p < 1) {
            requestAnimationFrame(frame);
          }
        })(start);
      }, interval * (strips.length - i));
    });
  }

  function initCounters() {
    // In the editor the counter text is live-edited through comment markers that a
    // roll would replace, so counters stay static there.
    if (reduced || editor || !('IntersectionObserver' in window)) {
      return;
    }
    each('[data-ns-number]', function (el) {
      if (el.__nsCounter) {
        return;
      }
      el.__nsCounter = true;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            io.disconnect();
            rollCounter(el);
          }
        });
      }, { threshold: num(el.getAttribute('data-ns-threshold'), 0.5), rootMargin: el.getAttribute('data-ns-rootmargin') || '0px 0px -50px 0px' });
      io.observe(el);
    });
  }

  /* ------------------------------------------------------------ divider */
  function initDividers() {
    each('[data-ns-divider]', function (el) {
      if (reduced) {
        el.style.width = '100%';
        return;
      }
      onView(el, 'top 100%', function () {
        el.style.transition = 'width 1s ' + EASE.out + ' 0.7s';
        el.style.width = '100%';
      });
    });
  }

  /* ------------------------------------------------------------ header */
  function initHeader() {
    each('[data-ns-header]', function (el) {
      var add = el.getAttribute('data-ns-scrolled-add');
      var remove = el.getAttribute('data-ns-scrolled-remove');
      var on = null;
      var update = function () {
        var scrolled = window.scrollY > 150;
        if (scrolled !== on) {
          on = scrolled;
          if (scrolled) {
            classes(el, add, remove);
          } else {
            classes(el, remove, add);
          }
        }
      };
      window.addEventListener('scroll', update, { passive: true });
      update();
    });
  }

  /* ------------------------------------------------------------ mobile menu */
  var menuEl = null;
  var menuOpener = null;

  function openMenu(opener) {
    menuEl = menuEl || doc.querySelector('[data-ns-menu]');
    if (!menuEl) {
      return;
    }
    menuOpener = opener || null;
    classes(menuEl, menuEl.getAttribute('data-ns-open-add'), menuEl.getAttribute('data-ns-open-remove'));
    menuEl.setAttribute('aria-hidden', 'false');
    each('[data-ns-menu-open]', function (b) { b.setAttribute('aria-expanded', 'true'); });
    if (lenis) {
      lenis.stop();
    }
    var first = menuEl.querySelector('a, button');
    if (first) {
      first.focus();
    }
  }

  function closeMenu() {
    if (!menuEl || menuEl.getAttribute('aria-hidden') === 'true') {
      return;
    }
    classes(menuEl, menuEl.getAttribute('data-ns-open-remove'), menuEl.getAttribute('data-ns-open-add'));
    menuEl.setAttribute('aria-hidden', 'true');
    each('[data-ns-menu-open]', function (b) { b.setAttribute('aria-expanded', 'false'); });
    if (lenis) {
      lenis.start();
    }
    if (menuOpener) {
      menuOpener.focus();
    }
  }

  function initMenu() {
    doc.addEventListener('click', function (e) {
      var t = e.target;
      if (!t.closest) {
        return;
      }
      var opener = t.closest('[data-ns-menu-open]');
      if (opener) {
        openMenu(opener);
        return;
      }
      if (t.closest('[data-ns-menu-close]') || t.closest('[data-ns-menu-link]')) {
        closeMenu();
      }
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeMenu();
      }
    });
  }

  /* ------------------------------------------------------------ sliders */
  function initSwipers() {
    if (typeof window.Swiper !== 'function') {
      return;
    }
    each('[data-ns-swiper]', function (el) {
      if (el.swiper) {
        return;
      }
      var cfg = {};
      try {
        cfg = JSON.parse(el.getAttribute('data-ns-swiper') || '{}');
      } catch (e) {
        cfg = {};
      }
      if (reduced) {
        cfg.autoplay = false;
        cfg.speed = 0;
      }
      if (editor) {
        cfg.autoplay = false;
      }
      if (rtl) {
        el.setAttribute('dir', 'rtl');
      }
      var total = el.querySelectorAll('.swiper-slide').length;
      var sw = new window.Swiper(el, cfg);
      // Slider chrome outside the swiper (prev / next buttons, "3 /8" counter).
      var box = el.closest('[data-ns-slider]');
      if (box) {
        each('[data-ns-swiper-prev]', function (b) { b.addEventListener('click', function () { sw.slidePrev(); }); }, box);
        each('[data-ns-swiper-next]', function (b) { b.addEventListener('click', function () { sw.slideNext(); }); }, box);
        each('[data-ns-swiper-total]', function (s) { s.textContent = '/' + total; }, box);
        var update = function () {
          each('[data-ns-swiper-index]', function (s) { s.textContent = String(sw.realIndex + 1); }, box);
        };
        sw.on('slideChange', update);
        update();
      }
    });
  }

  /* ------------------------------------------------------------ accordions */
  // Items are the accordion's children; every data-state inside an item (title,
  // button, icon, panel) follows it, like the React original. In the editor all
  // items stay open so every answer can be edited in place.
  function setAccordion(item, open) {
    var state = open ? 'open' : 'closed';
    var content = item.querySelector('[data-ns-accordion-content]');
    item.setAttribute('data-state', state);
    each('[data-state]', function (el) {
      if (!content || el === content || !content.contains(el)) {
        el.setAttribute('data-state', state);
      }
    }, item);
    each('[aria-controls]', function (b) { b.setAttribute('aria-expanded', String(open)); }, item);
    if (!content) {
      return;
    }
    content.setAttribute('aria-hidden', String(!open));
    content.classList.toggle('opacity-0', !open);
    content.classList.toggle('opacity-100', open);
    content.style.height = content.scrollHeight + 'px';
    if (open) {
      setTimeout(function () {
        if (item.getAttribute('data-state') === 'open') {
          content.style.height = 'auto';
        }
      }, 320);
    } else {
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { content.style.height = '0px'; });
      });
    }
  }

  function initAccordions() {
    each('[data-ns-accordion]', function (acc) {
      var single = acc.getAttribute('data-ns-accordion') !== 'multiple';
      var items = Array.prototype.filter.call(acc.children, function (c) { return c.querySelector('[data-ns-accordion-content]'); });
      items.forEach(function (item) {
        if (editor) {
          setAccordion(item, true);
          return;
        }
        var btn = item.querySelector('button[aria-controls]') || item.querySelector('button');
        if (!btn) {
          return;
        }
        btn.addEventListener('click', function () {
          var open = item.getAttribute('data-state') !== 'open';
          if (open && single) {
            items.forEach(function (o) {
              if (o !== item && o.getAttribute('data-state') === 'open') {
                setAccordion(o, false);
              }
            });
          }
          setAccordion(item, open);
        });
      });
    });
  }

  /* ------------------------------------------------------------ pricing */
  // Plan buttons + monthly/yearly switch; every price / badge / description is in the
  // markup (editable), this only decides which one shows. "Included" rows light up by
  // position, so rows can be added or removed.
  function initPricing() {
    each('[data-ns-pricing]', function (box) {
      var yearly = box.querySelector('[data-ns-pricing-yearly]');
      var plans = Array.prototype.slice.call(box.querySelectorAll('[data-ns-plan]'));
      var apply = function () {
        var plan = box.getAttribute('data-ns-plan-active') || (plans[0] && plans[0].getAttribute('data-ns-plan'));
        var period = yearly && yearly.checked ? 'yearly' : 'monthly';
        var count = 0;
        plans.forEach(function (b) {
          var on = b.getAttribute('data-ns-plan') === plan;
          b.setAttribute('data-active', String(on));
          if (on) {
            count = num(b.getAttribute('data-ns-plan-count'), 0);
          }
        });
        each('[data-ns-price]', function (el) {
          el.classList.toggle('hidden', !(el.getAttribute('data-ns-price') === plan && el.getAttribute('data-ns-period') === period));
        }, box);
        each('[data-ns-period]:not([data-ns-price])', function (el) {
          el.classList.toggle('hidden', el.getAttribute('data-ns-period') !== period);
        }, box);
        each('[data-ns-plan-show]', function (el) {
          var on = el.getAttribute('data-ns-plan-show') === plan;
          el.classList.toggle('opacity-0', !on);
          el.classList.toggle('translate-y-3', !on);
          el.setAttribute('aria-hidden', String(!on));
        }, box);
        each('[data-ns-pricing-included]', function (list) {
          Array.prototype.forEach.call(list.children, function (li, i) { li.setAttribute('data-active', String(i < count)); });
        }, box);
      };
      plans.forEach(function (b) {
        b.addEventListener('click', function () {
          box.setAttribute('data-ns-plan-active', b.getAttribute('data-ns-plan'));
          apply();
        });
      });
      if (yearly) {
        yearly.addEventListener('change', apply);
      }
      apply();
    });
  }

  /* ------------------------------------------------------------ logo orbit */
  // Logos on a ring: pivots fan out around the hub, the hub turns and each logo
  // counter-turns so it stays upright. Laid out from the CURRENT logos, so added or
  // removed ones space themselves evenly.
  function initOrbits() {
    each('[data-ns-orbit]', function (wrap) {
      var ring = wrap.querySelector('[data-ns-orbit-ring]');
      var hub = wrap.querySelector('[data-ns-orbit-hub]');
      if (!ring || !hub) {
        return;
      }
      var speed = Math.min(20, Math.max(0.01, num(wrap.getAttribute('data-ns-orbit'), 0.3)));
      var spins = [];
      var layout = function () {
        var rect = ring.getBoundingClientRect();
        var size = Math.min(1290, Math.max(200, Math.round(Math.max(rect.width, rect.height)) || 400));
        ring.style.setProperty('--orbit-size', size + 'px');
        var off = (size - (hub.offsetWidth || 20)) / 2;
        var pivots = Array.prototype.slice.call(hub.querySelectorAll('[data-ns-orbit-pivot]'));
        var items = pivots.map(function (p) { return p.querySelector('[data-ns-orbit-item]'); }).filter(Boolean);
        var space = 360 / (pivots.length || 1);
        spins.forEach(function (a) { a.cancel(); });
        spins = [];
        var hubRest = 'translate(' + off + 'px, ' + off + 'px) rotate(0deg)';
        hub.style.transform = hubRest;
        pivots.forEach(function (p, i) {
          p.style.transformOrigin = ((p.offsetWidth / 2) || 10) + 'px ' + (size / 2 + 10) + 'px';
          p.style.transform = 'rotate(' + (i * space) + 'deg)';
        });
        items.forEach(function (it, i) {
          it.style.transformOrigin = 'center center';
          it.style.transform = 'rotate(' + (-i * space) + 'deg)';
        });
        if (!reduced && !editor && canAnimate && items.length) {
          // One turn takes 20 s at speed 1: the hub turns, each logo counter-turns.
          var timing = { duration: 20000 / speed, iterations: Infinity, easing: 'linear' };
          spins.push(hub.animate([{ transform: hubRest }, { transform: 'translate(' + off + 'px, ' + off + 'px) rotate(360deg)' }], timing));
          items.forEach(function (it, i) {
            var a = -i * space;
            spins.push(it.animate([{ transform: 'rotate(' + a + 'deg)' }, { transform: 'rotate(' + (a - 360) + 'deg)' }], timing));
          });
        }
      };
      ring.style.visibility = 'visible';
      ring.style.opacity = '1';
      requestAnimationFrame(function () { requestAnimationFrame(layout); });
      if ('ResizeObserver' in window) {
        var t = null;
        new window.ResizeObserver(function () {
          clearTimeout(t);
          t = setTimeout(layout, 80);
        }).observe(ring);
      }
    });
  }

  /* ------------------------------------------------------------ process cards */
  // Stacked step cards with a numbered timeline: the line fills, the card slides to
  // the next step, looping; steps are clickable; hovering pauses it.
  function initProcess() {
    if (editor) {
      return;
    }
    each('[data-ns-process]', function (box) {
      var cards = Array.prototype.slice.call(box.querySelectorAll('[data-ns-process-card]'));
      var n = cards.length;
      if (n < 2) {
        return;
      }
      var steps = Array.prototype.slice.call(box.querySelectorAll('[data-ns-process-step]'));
      var groups = [];
      each('[data-line-axis]', function (l) {
        var owner = l.parentElement && l.parentElement.parentElement;
        var g = groups.filter(function (x) { return x.owner === owner; })[0];
        if (!g) {
          g = { owner: owner, lines: [] };
          groups.push(g);
        }
        g.lines.push(l);
      }, box);
      var horiz = function (l) { return l.getAttribute('data-line-axis') === 'horizontal'; };
      var scaled = function (l, v) { return (horiz(l) ? 'scaleX(' : 'scaleY(') + v + ')'; };
      var active = 0;
      var busy = false;
      var paused = false;
      var lineRun = null;
      var wait = null;

      var parts = function (i) {
        return { card: cards[i], img: cards[i].querySelector('[data-ns-process-img]'), text: cards[i].querySelector('[data-ns-process-text]') };
      };
      var paint = function () {
        steps.forEach(function (s) {
          var i = num(s.getAttribute('data-ns-process-step'), 0);
          s.setAttribute('data-item', i === active ? 'active' : (i < active ? 'completed' : 'inactive'));
        });
        groups.forEach(function (g) {
          g.lines.forEach(function (l, i) {
            l.style.transformOrigin = horiz(l) ? 'left center' : 'top center';
            l.style.transform = scaled(l, i < active ? 1 : 0);
          });
        });
      };
      var slide = function (next, reverse) {
        if (busy || next === active) {
          return;
        }
        busy = true;
        var cur = parts(active);
        var nxt = parts(next);
        nxt.card.classList.remove('invisible');
        nxt.card.style.zIndex = '2';
        cur.card.style.zIndex = '3';
        var d = reverse ? -1 : 1;
        var hidden = function (sign) { return { transform: 'translateY(' + (100 * d * sign) + '%)', opacity: '0', filter: 'blur(3px)' }; };
        var shown = { transform: 'translateY(0%)', opacity: '1', filter: 'blur(0px)' };
        [[nxt.img, 1], [nxt.text, -1]].forEach(function (p) {
          if (p[0]) {
            p[0].classList.remove('opacity-0');
            tween(p[0], hidden(p[1]), shown, { duration: reduced ? 0 : 1, delay: p[1] === -1 && !reduced ? 0.3 : 0, ease: EASE.expo });
          }
        });
        [[cur.img, -1], [cur.text, 1]].forEach(function (p) {
          if (p[0]) {
            tween(p[0], null, hidden(p[1]), { duration: reduced ? 0 : 0.8, ease: EASE.expo });
          }
        });
        var end = function () {
          cur.card.classList.add('invisible');
          cur.card.style.zIndex = '0';
          nxt.card.style.zIndex = '2';
          active = next;
          busy = false;
          paint();
        };
        if (reduced) {
          end();
        } else {
          setTimeout(end, 1300);
        }
      };
      var schedule = function () {
        if (reduced || paused) {
          return;
        }
        if (wait) {
          wait.kill();
        }
        wait = timer(1.2, run);
      };
      var run = function () {
        if (paused || busy) {
          schedule();
          return;
        }
        var next = (active + 1) % n;
        var fills = [];
        groups.forEach(function (g) {
          var l = g.lines[active % Math.max(n - 1, 1)];
          if (l && next !== 0) {
            fills.push(tween(l, null, { transform: scaled(l, 1) }, { duration: 1, ease: EASE.sine }));
          }
        });
        var clock = timer(1, function () {
          lineRun = null;
          slide(next, false);
          schedule();
        });
        lineRun = {
          pause: function () { fills.forEach(function (f) { f.pause(); }); clock.pause(); },
          play: function () { fills.forEach(function (f) { f.play(); }); clock.play(); },
          kill: function () { fills.forEach(function (f) { f.kill(); }); clock.kill(); },
        };
      };
      steps.forEach(function (s) {
        s.addEventListener('click', function () {
          var i = num(s.getAttribute('data-ns-process-step'), 0);
          if (lineRun) {
            lineRun.kill();
            lineRun = null;
          }
          if (wait) {
            wait.kill();
          }
          slide(i, i < active);
          schedule();
        });
      });
      box.addEventListener('mouseenter', function () {
        paused = true;
        if (lineRun) {
          lineRun.pause();
        }
        if (wait) {
          wait.pause();
        }
      });
      box.addEventListener('mouseleave', function () {
        paused = false;
        if (lineRun) {
          lineRun.play();
        } else {
          schedule();
        }
      });
      paint();
      schedule();
    });
  }

  /* ------------------------------------------------------------ avatars */
  function initAvatars() {
    if (reduced || editor || !canAnimate) {
      return;
    }
    var groups = [];
    each('[data-ns-avatar]', function (el) {
      var g = groups.filter(function (x) { return x.parent === el.parentElement; })[0];
      if (!g) {
        g = { parent: el.parentElement, items: [] };
        groups.push(g);
      }
      g.items.push(el);
    });
    groups.forEach(function (g) {
      g.items.forEach(function (el, i) {
        var from = { opacity: '0', filter: 'blur(5px)', transform: transform(rtl ? 50 : -50, 0, 0, 0) };
        applyStyle(el, from);
        onView(el, 'top 90%', function () {
          tween(el, from, { opacity: '1', filter: 'blur(0px)', transform: 'none' }, { duration: 1.5, delay: i * 0.1, ease: EASE.spring });
        });
      });
    });
  }

  /* ------------------------------------------------------------ mouse drift */
  function initMouseMove() {
    if (reduced || editor || !(window.matchMedia && window.matchMedia('(hover: hover)').matches)) {
      return;
    }
    each('[data-ns-mousemove]', function (area) {
      var targets = Array.prototype.slice.call(area.querySelectorAll('[data-ns-shift]'));
      targets.forEach(function (t) { t.style.transition = 'transform 0.6s ' + EASE.out; });
      var last = 0;
      area.addEventListener('mousemove', function (e) {
        var now = Date.now();
        if (now - last < 100) {
          return;
        }
        last = now;
        var r = area.getBoundingClientRect();
        var dx = (e.clientX - r.left) / r.width - 0.5;
        var dy = (e.clientY - r.top) / r.height - 0.5;
        targets.forEach(function (t) {
          var s = num(t.getAttribute('data-ns-shift'), 10) * 2;
          t.style.transform = transform(dx * s, dy * s);
        });
      });
      area.addEventListener('mouseleave', function () {
        targets.forEach(function (t) { t.style.transform = 'none'; });
      });
    });
  }

  /* ------------------------------------------------------------ button hover */
  // Pill buttons: the arrow slides to the far end and the label shifts over.
  function initButtons() {
    if (editor || reduced) {
      return;
    }
    each('.button-inner', function (btn) {
      var icon = btn.querySelector(':scope > [aria-hidden="true"]');
      var text = btn.querySelector(':scope > span');
      if (!icon || !text) {
        return;
      }
      btn.addEventListener('mouseenter', function () {
        var b = btn.getBoundingClientRect();
        var ir = icon.getBoundingClientRect();
        var tr = text.getBoundingClientRect();
        var cs = getComputedStyle(btn);
        var pl = parseFloat(cs.paddingLeft) || 0;
        var pr = parseFloat(cs.paddingRight) || 0;
        var di = rtl ? -((ir.left - b.left) - pl) : b.width - pr - icon.offsetWidth - (ir.left - b.left);
        var dt = rtl ? Math.max(0, (b.right - pr) - tr.right) : -Math.max(0, (tr.left - b.left) - pl);
        icon.style.transform = 'translateX(' + di + 'px)';
        text.style.transform = 'translateX(' + dt + 'px)';
      });
      btn.addEventListener('mouseleave', function () {
        icon.style.transform = 'translateX(0)';
        text.style.transform = 'translateX(0)';
      });
    });
  }

  /* ------------------------------------------------------------ lead forms */
  function initForms() {
    doc.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.matches || !form.matches('form[data-lt-form]')) {
        return;
      }
      e.preventDefault();
      if (editor || form.__nsBusy) {
        return;
      }
      form.__nsBusy = true;
      var btn = form.querySelector('[type=submit]');
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then(function (r) { return r.ok; })
        .catch(function () { return false; })
        .then(function (ok) {
          form.__nsBusy = false;
          var msg = ok ? root.getAttribute('data-lt-thanks') : root.getAttribute('data-lt-failed');
          if (btn && msg) {
            btn.textContent = msg;
            btn.disabled = !!ok;
          }
          if (ok) {
            form.reset();
          }
        });
    });
  }

  function init() {
    initLenis();
    initAnchors();
    initReveal();
    initCounters();
    initDividers();
    initHeader();
    initMenu();
    initSwipers();
    initAccordions();
    initPricing();
    initOrbits();
    initProcess();
    initAvatars();
    initMouseMove();
    initButtons();
    initForms();
    if (location.hash) {
      setTimeout(function () { scrollToHash(location.hash); }, 300);
    }
  }

  window.LtKit = { init: init, closeMenu: closeMenu, rollCounter: rollCounter };

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
