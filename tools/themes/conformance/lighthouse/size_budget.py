#!/usr/bin/env python3
"""Check 5 helper (THEME-CONTRACT §9): static gzip size budgets for files Lighthouse can't isolate. Stdlib only.

Usage: python3 size_budget.py [theme_dir ...]        default: kit/<theme>/ for every theme, plus prototypes/<theme>/ + _kit
Per theme dir (a folder holding css/, js/, fonts/, or theme.css next to it), budgets in gzip bytes:
  js/theme.js                    <= 20 KB     (§9 Theme JS)
  CSS of the theme (all css, not preset-*.css; theme.css/tokens.css)   <= 25 KB   (§9 Theme CSS)
  prototypes/_kit/kit.css        <= 15 KB     (stands in for the shared core CSS)
  *.woff2                        <= 4 files and <= 140 KB total        (§9 Fonts)
Lighthouse CI (lighthouserc.json) enforces the whole-page numbers; this script enforces the per-file ones.
"""
import gzip, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.environ.get('CONFORMANCE_ROOT') or os.path.join(os.path.dirname(HERE), 'kit-snapshot')  # juwri
KB = 1024

def gz(path):
    return len(gzip.compress(open(path, 'rb').read(), 9))

def walk(d):
    for dp, dn, fn in os.walk(d):
        dn[:] = [x for x in dn if x not in ('node_modules', '.git')]
        for f in fn: yield os.path.join(dp, f)

def check_dir(d, errs, rows):
    css = [p for p in walk(d) if p.endswith('.css') and not os.path.basename(p).startswith('preset-')]
    js = [p for p in walk(d) if os.path.basename(p) == 'theme.js']
    fonts = [p for p in walk(d) if p.endswith('.woff2')]
    c = sum(gz(p) for p in css)
    if css: rows.append((d, 'theme css', c, 25 * KB))
    if c > 25 * KB: errs.append(f'{d}: theme CSS {c} B gzip > 25 KB')
    for p in js:
        s = gz(p); rows.append((p, 'theme js', s, 20 * KB))
        if s > 20 * KB: errs.append(f'{p}: {s} B gzip > 20 KB')
    if fonts:
        tot = sum(os.path.getsize(p) for p in fonts)
        rows.append((d, 'fonts', tot, 140 * KB))
        if len(fonts) > 4: errs.append(f'{d}: {len(fonts)} font files > 4')
        if tot > 140 * KB: errs.append(f'{d}: fonts {tot} B > 140 KB')

def main(argv):
    errs, rows = [], []
    dirs = argv
    if not dirs:
        for base in ('kit', 'prototypes'):
            b = os.path.join(ROOT, base)
            if os.path.isdir(b):
                dirs += [os.path.join(b, t) for t in sorted(os.listdir(b)) if os.path.isdir(os.path.join(b, t)) and not t.startswith('_') and t not in ('lang', 'demo', 'viewmodel')]
        kitcss = os.path.join(ROOT, 'prototypes', '_kit', 'kit.css')
        if os.path.exists(kitcss):
            s = gz(kitcss); rows.append((kitcss, 'core css', s, 15 * KB))
            if s > 15 * KB: errs.append(f'{kitcss}: {s} B gzip > 15 KB')
    for d in dirs: check_dir(d, errs, rows)
    for p, what, size, lim in rows: print(f'{"ok  " if size <= lim else "FAIL"} {os.path.relpath(p)} {what}: {size / KB:.1f} KB / {lim // KB} KB')
    for e in errs: print('ERROR', e)
    print(f'size_budget: {len(rows)} budget(s), {len(errs)} error(s)')
    return 1 if errs else 0

if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
