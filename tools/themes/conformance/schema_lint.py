#!/usr/bin/env python3
"""Check 1 (THEME-CONTRACT §11.1): schema lint for theme.json + preset contrast.

Usage: python3 schema_lint.py [theme_dir | theme.json ...]     (default: kit/*/theme.json)

Checks (stdlib only):
  - SchemaV2 shape: version 2, presets[] / settings[] / sections[] / texts  (kit/README.md)
  - canonical field ids of §3 are present (colors.*, colors_dark.*, scheme.mode, layout.*, fonts.pair,
    menu.show_*, at least one texts.*) and fragments splash/search/share are included
  - select defaults are in their options (and in the canonical option set for core-fragment fields)
  - every preset: id, label, values; each value key is a known field id; select values are valid options;
    colour values are #rrggbb; all 10 role colours set (light + dark); 2..5 presets (§1)
  - required sections (§2.1) present, default on, not toggleable
  - contrast (§8) for every preset x scheme: same pairs and same maths as tools/check_derived.py
    (CHECKS list read from that file, maths imported from tools/build_theme_css.py; nothing duplicated)
  - drift: preset roles equal tokens/<theme>.json when that file exists
"""
import ast, json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.environ.get('CONFORMANCE_ROOT') or os.path.join(HERE, 'kit-snapshot')  # juwri: kit repo root (tokens/, kit/, prototypes/, tools/)
TOOLS = os.path.join(ROOT, 'tools')
sys.path.insert(0, TOOLS)
from build_theme_css import derive, ratio, on_colour  # noqa: E402  (the contrast maths lives there)

ROLES = ['primary', 'background', 'surface', 'text', 'accent']
CANON_COLOR = [f'colors.{r}' for r in ROLES] + [f'colors_dark.{r}' for r in ROLES]
CANON_FIELDS = CANON_COLOR + [
    'layout.radius', 'layout.density', 'fonts.pair', 'scheme.mode',
    'layout.card', 'layout.image_ratio', 'layout.category_nav', 'layout.header', 'layout.desktop_cart',
    'menu.show_calories', 'menu.show_allergens', 'menu.show_descriptions']
# option sets for fields whose definition lives in a core fragment (include) or that are fixed by §3
CANON_OPTIONS = {
    'layout.radius': ['none', 'soft', 'round', 'pill'],
    'layout.density': ['compact', 'cozy', 'comfortable'],
    'scheme.mode': ['light', 'dark', 'auto'],
    'layout.card': ['list', 'grid', 'compact'],
    'layout.image_ratio': ['1:1', '4:3', '16:9', '4:5', '3:4'],
    'layout.category_nav': ['chips', 'tabs'],
    'layout.header': ['cover', 'compact'],
    'layout.desktop_cart': ['panel', 'drawer'],
}
FRAGMENTS = ['tokens', 'splash', 'search', 'share']
REQUIRED_SECTIONS = ['header', 'order_type', 'search', 'category_nav', 'catalog', 'info', 'footer', 'cart_bar']
NOT_TOGGLEABLE = [s for s in REQUIRED_SECTIONS if s != 'search']  # search: required only above 20 items, toggle allowed <= 20
KNOWN_SECTIONS = set(REQUIRED_SECTIONS) | {
    'announcement', 'hero', 'stories', 'offers', 'featured', 'combos', 'loyalty', 'reviews', 'reservations',
    'custom_blocks', 'reorder', 'free_delivery', 'fitment', 'otc_notice', 'age_gate', 'licence'}


def load_checks():
    """CHECKS list from tools/check_derived.py without executing it (that file runs main() on import)."""
    tree = ast.parse(open(os.path.join(TOOLS, 'check_derived.py')).read())
    for n in tree.body:
        if isinstance(n, ast.Assign) and any(getattr(t, 'id', '') == 'CHECKS' for t in n.targets):
            return ast.literal_eval(n.value)
    raise SystemExit('cannot find CHECKS in tools/check_derived.py')


def collect_fields(settings, errs):
    """-> (fields{id: field-dict-or-override}, includes[set]). Handles group lists and include dicts."""
    fields, includes = {}, set()
    for i, g in enumerate(settings):
        if not isinstance(g, dict):
            errs.append(f'settings[{i}]: not an object'); continue
        if 'include' in g:
            includes.add(g['include'])
            f = g.get('fields', {})
            if not isinstance(f, dict):
                errs.append(f'settings[{i}] include {g["include"]}: fields must be an object id -> override')
            else:
                for k, v in f.items(): fields[k] = {'id': k, **(v if isinstance(v, dict) else {}), '_include': g['include']}
        elif 'group' in g:
            f = g.get('fields')
            if not isinstance(f, list):
                errs.append(f'settings[{i}] group {g["group"]}: fields must be a list'); continue
            for fd in f:
                if not isinstance(fd, dict) or 'id' not in fd or 'type' not in fd:
                    errs.append(f'settings[{i}] group {g["group"]}: field without id/type: {fd!r}'); continue
                if fd['id'] in fields: errs.append(f'field {fd["id"]}: defined twice')
                fields[fd['id']] = fd
        else:
            errs.append(f'settings[{i}]: neither include nor group')
    return fields, includes


def options_of(fd):
    if fd and isinstance(fd.get('options'), list): return fd['options']
    return CANON_OPTIONS.get(fd['id']) if fd else None


def lint_file(path):
    errs, warns = [], []
    try: d = json.load(open(path))
    except Exception as e: return [f'invalid JSON: {e}'], [], 0
    slug = os.path.basename(os.path.dirname(os.path.abspath(path)))
    if d.get('version') != 2: errs.append(f'version must be 2 (got {d.get("version")!r})')
    for k in ('presets', 'settings', 'sections'):
        if not isinstance(d.get(k), list): errs.append(f'{k} must be a list')
    if not isinstance(d.get('texts'), dict): errs.append('texts must be an object (texts.localized)')
    elif d['texts'].get('localized') is not True: errs.append('texts.localized must be true (§3 Texts are localized)')
    extra = set(d) - {'version', 'presets', 'settings', 'sections', 'texts'}
    if extra: errs.append(f'unknown top-level keys {sorted(extra)} (SchemaV2::normalize drops them)')
    if errs: return errs, warns, 0

    fields, includes = collect_fields(d['settings'], errs)
    for f in FRAGMENTS:
        if f not in includes: errs.append(f'fragment include "{f}" missing')
    for fid in CANON_FIELDS:
        if fid not in fields: errs.append(f'canonical field {fid} missing (§3)')
    if not any(k.startswith('texts.') for k in fields): errs.append('no texts.* field (§3 Texts)')
    for fid, fd in fields.items():
        if '_include' in fd and fd['_include'] != 'tokens' and fid not in CANON_FIELDS and not fid.startswith(('splash.', 'search.', 'share.')):
            warns.append(f'{fid}: override of unknown fragment field')
        opts, dflt = options_of(fd), fd.get('default')
        if fd.get('type') == 'select':
            if not isinstance(fd.get('options'), list) or not fd['options']: errs.append(f'{fid}: select without options')
            if dflt is None: errs.append(f'{fid}: select without default')
            elif fd.get('options') and dflt not in fd['options']: errs.append(f'{fid}: default {dflt!r} not in options {fd["options"]}')
            if fid in CANON_OPTIONS and fd.get('options') and not set(CANON_OPTIONS[fid]) <= set(fd['options']):
                errs.append(f'{fid}: options {fd["options"]} miss canonical {sorted(set(CANON_OPTIONS[fid]) - set(fd["options"]))}')
            ol = fd.get('option_labels')
            if ol is not None and set(ol) != set(fd.get('options', [])): errs.append(f'{fid}: option_labels keys differ from options')
        elif fid in CANON_OPTIONS and dflt is not None and dflt not in CANON_OPTIONS[fid]:
            errs.append(f'{fid}: default {dflt!r} not in {CANON_OPTIONS[fid]}')
        if fid in CANON_COLOR or fd.get('type') == 'color':
            if fd.get('type') not in (None, 'color') and '_include' not in fd: errs.append(f'{fid}: must be type color')
            if dflt is not None and not _hex(dflt): errs.append(f'{fid}: default {dflt!r} is not #rrggbb')
        if fid == 'fonts.pair' and dflt in (None, '', 'none'): errs.append('fonts.pair default must be a real pair, not none (D2)')
        if fid in ('menu.show_calories', 'menu.show_allergens', 'menu.show_descriptions') and fd.get('type') != 'toggle':
            errs.append(f'{fid}: must be type toggle')
        vi = fd.get('visible_if')
        if vi:
            for k in vi:
                if k not in fields: errs.append(f'{fid}: visible_if references unknown field {k}')

    # sections
    secs = {}
    for s in d['sections']:
        if not isinstance(s, dict) or 'id' not in s: errs.append(f'section without id: {s!r}'); continue
        if s['id'] in secs: errs.append(f'section {s["id"]}: duplicated')
        secs[s['id']] = s
        for k in ('default', 'toggleable', 'orderable'):
            if not isinstance(s.get(k), bool): errs.append(f'section {s["id"]}: {k} must be boolean')
        if s['id'] not in KNOWN_SECTIONS: warns.append(f'section {s["id"]}: not in §2.1 or kit/README.md extras')
    for sid in REQUIRED_SECTIONS:
        s = secs.get(sid)
        if not s: errs.append(f'required section {sid} missing (§2.1)'); continue
        if sid == 'search':  # required above 20 items, optional (toggleable, may default off) at <= 20
            if s.get('default') is not True and s.get('toggleable') is not True: errs.append('section search: default off requires toggleable (§2.1)')
        elif s.get('default') is not True: errs.append(f'required section {sid}: must default on')
        if sid in NOT_TOGGLEABLE and s.get('toggleable') is not False: errs.append(f'required section {sid}: must not be toggleable')
    for sid in ('header', 'order_type', 'search', 'category_nav', 'catalog', 'cart_bar', 'footer', 'announcement'):
        if sid in secs and secs[sid].get('orderable') is True: errs.append(f'section {sid}: §2.1 says no reorder')

    # presets
    presets = d['presets']
    if not 2 <= len(presets) <= 5: errs.append(f'{len(presets)} presets (§1 asks for 2 to 5)')
    seen = set()
    dsch = {}
    for p in presets:
        pid = p.get('id') if isinstance(p, dict) else None
        if not pid or not p.get('label') or not isinstance(p.get('values'), dict):
            errs.append(f'preset {pid!r}: needs id, label, values'); continue
        if pid in seen: errs.append(f'preset {pid}: duplicated id')
        seen.add(pid)
        v = p['values']
        for k, val in v.items():
            if k not in fields: errs.append(f'preset {pid}: value key {k} is not a field id'); continue
            opts = options_of(fields[k])
            if opts and val not in opts: errs.append(f'preset {pid}: {k}={val!r} not in {opts}')
            if (k in CANON_COLOR or fields[k].get('type') == 'color') and not _hex(val): errs.append(f'preset {pid}: {k}={val!r} not #rrggbb')
        for k in CANON_COLOR:
            if k not in v: errs.append(f'preset {pid}: {k} not set (every preset sets light and dark roles)')
        if v.get('fonts.pair') in ('none', ''): errs.append(f'preset {pid}: fonts.pair none')
        if 'scheme.mode' not in v: errs.append(f'preset {pid}: scheme.mode not set')

    # contrast + drift
    checks = load_checks()
    n_checked = 0
    tok_path = os.path.join(ROOT, 'tokens', slug + '.json')
    tok = json.load(open(tok_path)) if os.path.exists(tok_path) else None
    if tok is None: warns.append(f'tokens/{slug}.json not found: status colours (danger/success/warning) not checked, no drift check')
    for p in presets:
        if not isinstance(p, dict) or not isinstance(p.get('values'), dict) or any(k not in p['values'] for k in CANON_COLOR) \
                or not all(_hex(p['values'][k]) for k in CANON_COLOR): continue
        pid, v = p['id'], p['values']
        for scheme, pre in (('light', 'colors.'), ('dark', 'colors_dark.')):
            c = {r: v[pre + r].lower() for r in ROLES}
            u = dict(c); u.update(derive(c, scheme))
            u['on_primary'], u['on_accent'] = on_colour(c['primary']), on_colour(c['accent'])
            tp = tok and tok['presets'].get(pid, {}).get(scheme)
            if tp:
                for r in ROLES:
                    if tp[r].lower() != c[r]: errs.append(f'drift: preset {pid} {scheme} {r} {c[r]} != tokens/{slug}.json {tp[r].lower()}')
                for s in ('danger', 'success', 'warning'): u[s] = tp[s].lower()
            elif tok: warns.append(f'preset {pid}: not in tokens/{slug}.json')
            for f, b, need in checks:
                if f not in u or b not in u: continue  # status colours unavailable
                n_checked += 1
                r = ratio(u[f], u[b])
                if r < need: errs.append(f'contrast {pid}/{scheme}: {f}/{b} {r:.2f} < {need}')
    return errs, warns, n_checked


def _hex(s):
    return isinstance(s, str) and len(s) == 7 and s[0] == '#' and all(c in '0123456789abcdefABCDEF' for c in s[1:])


def expand(args):
    out = []
    for a in args:
        if os.path.isdir(a): out.append(os.path.join(a, 'theme.json'))
        else: out.append(a)
    return out


def main(argv):
    paths = expand(argv) or sorted(os.path.join(ROOT, 'kit', t, 'theme.json') for t in os.listdir(os.path.join(ROOT, 'kit'))
                                   if os.path.exists(os.path.join(ROOT, 'kit', t, 'theme.json')))
    bad = 0
    for p in paths:
        errs, warns, n = lint_file(p)
        rel = os.path.relpath(p, os.getcwd())
        print(f'{rel}: {"OK" if not errs else str(len(errs)) + " error(s)"} ({n} contrast checks)')
        for e in errs: print(f'  ERROR {e}')
        for w in warns: print(f'  warn  {w}')
        bad += len(errs)
    print(f'schema_lint: {len(paths)} theme(s), {bad} error(s)')
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
