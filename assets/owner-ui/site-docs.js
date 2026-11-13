// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * juwri owner-ui — Help Center "On this page" (WI-16g). Builds a table of
 * contents from the article's h2/h3 headings (hidden when there are fewer than
 * two) and highlights the heading nearest the top while the reader scrolls.
 */
(function () {
  'use strict';

  function init() {
    var content = document.querySelector('.mk-docs__body');
    var toc = document.getElementById('docToc');
    if (!content || !toc) return;

    var heads = [].slice.call(content.querySelectorAll('h2, h3')).filter(function (h) {
      return (h.textContent || '').trim() && h.offsetParent !== null;
    });
    if (heads.length < 2) return;

    var used = {};
    var map = {};
    var current = null;
    var ul = document.createElement('ul');

    function slug(t) {
      return (t || '').toLowerCase().replace(/[^\w؀-ۿ]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
    }
    function mark(a) {
      if (current) current.classList.remove('is-current');
      current = a || null;
      if (current) current.classList.add('is-current');
    }

    heads.forEach(function (h, i) {
      if (!h.id) {
        var base = slug(h.textContent) || ('sec-' + i);
        var id = base;
        var n = 1;
        while (used[id] || document.getElementById(id)) { id = base + '-' + (++n); }
        h.id = id;
      }
      used[h.id] = 1;
      var a = document.createElement('a');
      a.href = '#' + h.id;
      a.textContent = (h.textContent || '').trim();
      if (h.tagName === 'H3') a.className = 'is-sub';
      a.addEventListener('click', function () { mark(a); });
      var li = document.createElement('li');
      li.appendChild(a);
      ul.appendChild(li);
      map[h.id] = a;
    });

    var hd = document.createElement('p');
    hd.className = 'mk-docs__toc-title';
    hd.textContent = toc.getAttribute('data-label') || '';
    toc.appendChild(hd);
    toc.appendChild(ul);
    toc.hidden = false;

    if ('IntersectionObserver' in window) {
      var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) mark(map[en.target.id]);
        });
      }, { rootMargin: '-90px 0px -70% 0px', threshold: 0 });
      heads.forEach(function (h) { obs.observe(h); });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
