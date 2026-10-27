#!/usr/bin/env python3
"""Check 6, static half (THEME-CONTRACT §6, §11.6): cheap RTL structure checks on generated HTML. Stdlib only.

Usage: python3 mirror_check.py [file.html | dir ...] [--css file.css ...]
  default target: prototypes/   (files under an /ar/ folder or named *.ar.html are Arabic pages,
                                 files under /en/ are checked for the LTR counterpart)

For Arabic pages:
  - <html lang="ar…" dir="rtl">
  - no dir="ltr" on <html> or <body>
  - every directional icon (chevron / arrow / caret / back / next / prev / forward in its class, name or href)
    carries a class containing "--dir" (c-icon--dir) so the CSS can flip it; non-directional ones must not
    (play, check, clock, logo, map: §6 "do not mirror")
  - phone links (href="tel:") and one-time-code / tel inputs are dir="ltr" or wrap their text in <bdi>
  - no inline `transform: scaleX(-1)` and no `translateX` carousels in <style> blocks (use scroll-snap)
For English pages: <html lang="en…" dir="ltr">.
With --css (default: prototypes/_kit/kit.css when present): a rule flipping `.c-icon--dir` under [dir=rtl] exists.
"""
import os, re, sys
from html.parser import HTMLParser

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.environ.get('CONFORMANCE_ROOT') or os.path.join(os.path.dirname(HERE), 'kit-snapshot')  # juwri
DIRECTIONAL = re.compile(r'(chevron|arrow|caret|angle)[-_]?(left|right|forward|back|next|prev|start|end)?|(^|[-_])(back|forward|next|prev|previous)([-_]|$)', re.I)
NON_DIRECTIONAL = re.compile(r'(play|pause|check|clock|time|logo|brand|map|phone|whatsapp|star|close|search|plus|minus)', re.I)


class P(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.html = None; self.body = None; self.problems = []; self.bdi = 0; self.pending_tel = None
        self.style = False; self.styles = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs); ln = self.getpos()[0]
        cls = a.get('class') or ''
        if tag == 'html': self.html = (a, ln)
        if tag == 'body': self.body = (a, ln)
        if tag == 'bdi': self.bdi += 1
        if tag == 'style': self.style = True; self.styles.append((ln, ''))
        if tag == 'a' and (a.get('href') or '').startswith('tel:'):
            if (a.get('dir') or '').lower() == 'ltr': pass
            else: self.pending_tel = (ln, 0)
        if tag == 'input' and ((a.get('type') or '').lower() == 'tel' or (a.get('autocomplete') or '') == 'one-time-code'):
            if (a.get('dir') or '').lower() != 'ltr': self.problems.append((ln, 'ltr-code', f'<input {a.get("type") or a.get("autocomplete")}> needs dir="ltr" in RTL'))
        # directional icons: look at class tokens, data-icon, name and the use/href target
        hay = ' '.join([cls, a.get('data-icon') or '', a.get('data-name') or '', a.get('href') or '' if tag == 'use' else ''])
        if ('icon' in cls or tag == 'use' or a.get('data-icon')) and tag != 'a':
            tokens = [t for t in re.split(r'\s+', hay) if t and '--dir' not in t]
            directional = any(DIRECTIONAL.search(t) for t in tokens)
            flagged = '--dir' in cls
            if directional and not flagged and not any(NON_DIRECTIONAL.search(t) and not DIRECTIONAL.search(t) for t in tokens):
                self.problems.append((ln, 'icon-not-dir', f'directional icon without a `--dir` class: {hay.strip()}'))
            elif flagged and not directional and NON_DIRECTIONAL.search(hay):
                self.problems.append((ln, 'icon-should-not-flip', f'non-directional icon marked `--dir` (§6: do not mirror): {hay.strip()}'))

    def handle_endtag(self, tag):
        if tag == 'bdi': self.bdi = max(0, self.bdi - 1)
        if tag == 'style': self.style = False
        if tag == 'a' and self.pending_tel:
            if self.pending_tel[1] == 2:
                self.problems.append((self.pending_tel[0], 'ltr-code', 'tel: link text needs dir="ltr" or <bdi> in RTL'))
            self.pending_tel = None

    def handle_data(self, data):
        if self.style and self.styles: ln, t = self.styles[-1]; self.styles[-1] = (ln, t + data)
        # only digits / '+' outside a <bdi> are a problem; a worded label ("Contact store") is fine
        if self.pending_tel and not self.bdi and re.search(r'[0-9+]', data): self.pending_tel = (self.pending_tel[0], 2)


def lang_of(path):
    p = path.replace('\\', '/')
    if '/ar/' in p or re.search(r'\.ar\.[a-z]*\.?html?$|\.ar\.html?$', p): return 'ar'
    if '/en/' in p or re.search(r'\.en\.', p): return 'en'
    return None


def check_file(path, force=None):
    lang = force or lang_of(path)
    if not lang: return None
    text = open(path, encoding='utf-8').read()
    p = P(); p.feed(text)
    out = []
    if not p.html: return [(1, 'html', 'no <html> tag')]
    a, ln = p.html
    l, d = (a.get('lang') or '').lower(), (a.get('dir') or '').lower()
    if lang == 'ar':
        if not l.startswith('ar'): out.append((ln, 'lang', f'<html lang="ar"> expected, got {a.get("lang")!r}'))
        if d != 'rtl': out.append((ln, 'dir', f'<html dir="rtl"> expected, got {a.get("dir")!r}'))
        if p.body and (p.body[0].get('dir') or '').lower() == 'ltr': out.append((p.body[1], 'dir', '<body dir="ltr"> inside an Arabic page'))
        out += p.problems
        for ln2, css in p.styles:
            for m in re.finditer(r'scaleX\(\s*-1\s*\)|translateX\(', css):
                out.append((ln2, 'transform', f'`{m.group(0)}` in <style>: flip with [dir=rtl] CSS / use scroll-snap, not translateX math'))
    else:
        if not l.startswith('en'): out.append((ln, 'lang', f'<html lang="en"> expected, got {a.get("lang")!r}'))
        if d not in ('ltr', ''): out.append((ln, 'dir', f'<html dir="ltr"> expected, got {a.get("dir")!r}'))
    return out


def check_css(path):
    t = re.sub(r'/\*.*?\*/', '', open(path, encoding='utf-8').read(), flags=re.S)
    ok = re.search(r'\[dir=["\']?rtl["\']?\][^{]*\.c-icon--dir[^{]*\{[^}]*scaleX\(\s*-1\s*\)', t) or \
         re.search(r'\.c-icon--dir[^{]*\{[^}]*(rotate\(180deg\)|scaleX\(\s*-1\s*\))[^}]*\}', t)
    return [] if ok else [(1, 'flip-rule', '`[dir=rtl] .c-icon--dir { transform: scaleX(-1) }` not found')]


def main(argv):
    css, targets, i = [], [], 0
    while i < len(argv):
        if argv[i] == '--css': css.append(argv[i + 1]); i += 2
        else: targets.append(argv[i]); i += 1
    targets = targets or [os.path.join(ROOT, 'prototypes')]
    if not css:
        kit = os.path.join(ROOT, 'prototypes', '_kit', 'kit.css')
        if os.path.exists(kit): css = [kit]
    files = []
    for t in targets:
        if os.path.isfile(t): files.append(t)
        else:
            for dp, dn, fn in os.walk(t):
                dn[:] = sorted(d for d in dn if d not in ('node_modules', '.git'))
                files += [os.path.join(dp, f) for f in sorted(fn) if f.endswith(('.html', '.htm'))]
    bad = n = 0
    for f in files:
        forced = 'ar' if os.path.isfile(f) and targets == [f] and lang_of(f) is None else None
        r = check_file(f, forced)
        if r is None: continue
        n += 1
        for ln, rule, msg in r:
            print(f'{os.path.relpath(f)}:{ln}: {rule}: {msg}'); bad += 1
    for c in css:
        for ln, rule, msg in check_css(c): print(f'{os.path.relpath(c)}:{ln}: {rule}: {msg}'); bad += 1
    print(f'mirror_check: {n} page(s), {len(css)} css file(s), {bad} problem(s)'
          + ('' if n else '  (no /ar/ or /en/ html found yet: prototypes not generated)'))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
