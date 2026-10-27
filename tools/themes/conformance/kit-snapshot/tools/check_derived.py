#!/usr/bin/env python3
"""Contrast proof for the derived colours (muted, surface-2, border, border-strong) that
kit/<theme>/css/tokens.css builds with color-mix(). Every theme x preset x scheme.
Same pairs as tools/contrast.py, but on DERIVED values. Prints deltas vs the designed values."""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build_theme_css import THEMES, ROOT, derive, ratio, on_colour, FORMULAS

CHECKS = [('text','background',4.5),('text','surface',4.5),('muted','background',4.5),('muted','surface',4.5),
          ('on_primary','primary',4.5),('on_accent','accent',4.5),('primary','background',3.0),('primary','surface',3.0),
          ('border_strong','surface',3.0),('danger','surface',4.5),('success','surface',4.5),
          ('text','surface_2',4.5),('muted','surface_2',4.5),('success','surface_2',4.5),('danger','surface_2',4.5),
          ('border_strong','surface_2',3.0),('border_strong','background',3.0),
          ('warning','surface',4.5),('warning','surface_2',4.5)]

def dist(a, b):
    return max(abs(int(a[i:i+2], 16) - int(b[i:i+2], 16)) for i in (1, 3, 5))

def main():
    bad = 0; worst = {}
    print('formulas:', json.dumps(FORMULAS))
    for t in THEMES:
        d = json.load(open(os.path.join(ROOT, 'tokens', t + '.json')))
        for pid, p in d['presets'].items():
            for sc in ('light', 'dark'):
                c = {k: v.lower() for k, v in p[sc].items()}
                dv = derive(c, sc)
                u = dict(c); u.update(dv)
                u['on_primary'] = on_colour(u['primary']); u['on_accent'] = on_colour(u['accent'])
                fails = []; minr = {}
                for f, b, need in CHECKS:
                    r = ratio(u[f], u[b]); minr[(f, b)] = r
                    if r < need: fails.append(f"{f}/{b} {r:.2f}<{need}")
                bad += len(fails)
                deltas = ' '.join(f"{k}:{c[k].upper()}>{dv[k].upper()}(d{dist(c[k], dv[k])})" for k in dv)
                for k in dv:
                    worst[k] = max(worst.get(k, (0,)), (dist(c[k], dv[k]), t, pid, sc))
                print(f"{t:6} {pid:10} {sc:5} muted/bg {minr[('muted','background')]:.2f} muted/s2 {minr[('muted','surface_2')]:.2f} "
                      f"bstrong/s {minr[('border_strong','surface')]:.2f} bstrong/s2 {minr[('border_strong','surface_2')]:.2f} "
                      f"text/s2 {minr[('text','surface_2')]:.2f} | {deltas}" + (' | FAIL ' + '; '.join(fails) if fails else ''))
    print('largest channel delta vs design per token:')
    for k, v in worst.items(): print(f"  {k}: {v[0]} ({v[1]}/{v[2]}/{v[3]})")
    print('FAILURES:', bad); sys.exit(1 if bad else 0)
main()
