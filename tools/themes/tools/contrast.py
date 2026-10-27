#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Check a tokens/<theme>.json: every preset x scheme against contract §8.
Pairs: text/background, text/surface, muted/background, muted/surface (4.5),
on-primary/primary, on-accent/accent (4.5), primary/background + primary/surface
(3.0, non-text: active chip, focus ring), border/surface (3.0 for inputs)."""
import json, sys
def lum(h):
    h=h.lstrip('#'); c=[int(h[i:i+2],16)/255 for i in (0,2,4)]
    c=[x/12.92 if x<=0.03928 else ((x+0.055)/1.055)**2.4 for x in c]
    return 0.2126*c[0]+0.7152*c[1]+0.0722*c[2]
def ratio(a,b):
    la,lb=sorted([lum(a),lum(b)],reverse=True); return (la+0.05)/(lb+0.05)
def on(fill):  # same rule as Tokens: #ffffff or #111111, whichever is higher
    return '#ffffff' if ratio('#ffffff',fill)>=ratio('#111111',fill) else '#111111'
CHECKS=[('text','background',4.5),('text','surface',4.5),('muted','background',4.5),('muted','surface',4.5),
        ('on_primary','primary',4.5),('on_accent','accent',4.5),('primary','background',3.0),('primary','surface',3.0),
        ('border_strong','surface',3.0),('danger','surface',4.5),('success','surface',4.5),
        ('text','surface_2',4.5),('muted','surface_2',4.5),('success','surface_2',4.5),('danger','surface_2',4.5)]
def main(path):
    d=json.load(open(path)); bad=0
    for pid,p in d['presets'].items():
        for scheme in ('light','dark'):
            c=dict(p[scheme]); c['on_primary']=on(c['primary']); c['on_accent']=on(c['accent'])
            out=[]
            for f,b,need in CHECKS:
                r=ratio(c[f],c[b]); ok=r>=need; bad+=not ok
                out.append(f"{f}/{b} {r:.2f}{'' if ok else ' FAIL<'+str(need)}")
            print(f"{pid:10} {scheme:5} on-primary={c['on_primary']} | "+' · '.join(out))
    print('FAILURES:',bad); sys.exit(1 if bad else 0)
main(sys.argv[1])
