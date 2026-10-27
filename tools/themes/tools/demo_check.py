#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Validate kit/demo/*.json (demo store content). Python 3 stdlib only. Exit 1 on any failure."""
import json, os, re, sys

# juwri copy: the demo JSON lives with the seeders (core/database/seeders/data/kit_demo); only the files present
# there are checked (cafe, grocery, services in P3-00; restaurant, retail, pharmacy arrive with their themes).
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEMO = os.environ.get('DEMO_DIR') or os.path.join(os.path.dirname(os.path.dirname(ROOT)), 'core', 'database', 'seeders', 'data', 'kit_demo')
FILES = {  # file: (items min, items max, cats min, cats max)
    'restaurant': (40, 60, 6, 10), 'cafe': (30, 40, 6, 10), 'grocery': (80, 120, 6, 10),
    'retail': (40, 60, 6, 10), 'services': (15, 25, 6, 10), 'pharmacy': (35, 60, 6, 10),
}
BADGES = {'new', 'popular', 'sold_out', 'low_stock'}
PACK_UNITS = {'g', 'kg', 'ml', 'L', 'pc', 'm'}
AR_RE = re.compile(r'[؀-ۿ]')
LATIN_RUN = re.compile(r'[A-Za-z]{3,}')
AR_LATIN_OK = {'Apple Pay', 'mada', 'SAR', 'Tabby', 'Tamara', 'Visa', 'Mastercard', 'LED'}
PRICE_RE = re.compile(r'^\d+\.\d{2}$')
errors = []

def err(f, path, msg):
    errors.append(f'{f}: {path}: {msg}')

def is_pair(d):
    return isinstance(d, dict) and set(d) == {'en', 'ar'}

def check_pair(f, path, d):
    en, ar = d['en'], d['ar']
    if not isinstance(en, str) or not en.strip(): err(f, path, 'en empty'); return
    if not isinstance(ar, str) or not ar.strip(): err(f, path, 'ar empty'); return
    if not AR_RE.search(ar): err(f, path, f'ar has no Arabic script: {ar!r}')
    if ar.strip() == en.strip() and en not in AR_LATIN_OK: err(f, path, f'ar identical to en: {en!r}')
    rest = ar
    for ok in AR_LATIN_OK: rest = rest.replace(ok, '')
    if LATIN_RUN.search(rest): err(f, path, f'ar contains English text: {ar!r}')

def walk(f, path, node):
    if isinstance(node, dict):
        if 'en' in node or 'ar' in node:
            if is_pair(node): check_pair(f, path, node)
            else: err(f, path, 'dict with en/ar must have exactly the keys en and ar')
            return
        for k, v in node.items(): walk(f, f'{path}.{k}', v)
    elif isinstance(node, list):
        for i, v in enumerate(node): walk(f, f'{path}[{i}]', v)

def money(f, path, v, allow_zero=False):
    if not isinstance(v, str) or not PRICE_RE.match(v): err(f, path, f'not a 2-dp price string: {v!r}'); return None
    x = float(v)
    if x < 0 or (x == 0 and not allow_zero): err(f, path, f'price must be {">=" if allow_zero else ">"} 0: {v}')
    return x

def is_int(v): return isinstance(v, int) and not isinstance(v, bool)

def check_group(f, path, g, kind_opts):
    req, mn, mx = g.get('required'), g.get('min_select'), g.get('max_select')
    if not isinstance(req, bool): err(f, path, 'required must be bool')
    if not is_int(mn) or mn < 0: err(f, path, 'min_select must be int >= 0')
    if not is_int(mx) or (is_int(mn) and mx < mn): err(f, path, 'max_select must be int >= min_select')
    if req and (not is_int(mn) or mn < 1): err(f, path, 'required group needs min_select >= 1')
    opts = g.get(kind_opts) or []
    if not opts: err(f, path, 'no options')
    if g.get('kind') not in ('variant', 'addon'): err(f, path, 'kind must be variant or addon')
    if req and sum(1 for o in opts if o.get('default')) != 1: err(f, path, 'required group needs exactly one default option')
    if is_int(mx) and g.get('kind') == 'addon' and mx > len(opts): err(f, path, 'max_select larger than option count')
    for j, o in enumerate(opts):
        money(f, f'{path}.{kind_opts}[{j}].price', o.get('price'), allow_zero=True)
        if 'duration_add_min' in o:
            d = o['duration_add_min']
            if not is_int(d) or d < 0 or d % 5: err(f, f'{path}.{kind_opts}[{j}]', f'duration_add_min must be a multiple of 5 >= 0: {d!r}')

def dup(f, label, values):
    seen = set()
    for v in values:
        if v in seen: err(f, label, f'duplicate {v!r}')
        seen.add(v)

def check_file(name, lim):
    path = os.path.join(DEMO, name + '.json')
    if not os.path.isfile(path): err(name, '-', 'file missing'); return
    try:
        with open(path, encoding='utf-8') as fh: d = json.load(fh)
    except Exception as e:
        err(name, '-', f'invalid JSON: {e}'); return
    f = name
    walk(f, '$', d)
    s = d.get('store') or {}
    for k in ('slug', 'name', 'store_type', 'theme', 'preset', 'currency', 'tax', 'order_types', 'hours', 'address', 'phone', 'description'):
        if k not in s: err(f, 'store', f'missing {k}')
    if s.get('currency') != 'SAR': err(f, 'store.currency', 'must be SAR')
    idn = s.get('identity')
    if idn is not None:   # store identity block (contract §4.1): demo values must be flagged and clearly sample
        if s.get('identity_is_demo') is not True: err(f, 'store.identity_is_demo', 'must be true when identity is present')
        if 'legal_name' not in idn: err(f, 'store.identity', 'missing legal_name')
        for k in ('commercial_registration', 'tax_registration'):
            n = (idn.get(k) or {}).get('number')
            if n is not None and not str(n).startswith('SAMPLE-'): err(f, f'store.identity.{k}.number', 'demo numbers must start with SAMPLE-')
    t = s.get('tax') or {}
    if t.get('mode') not in ('inclusive', 'exclusive') or t.get('rate') != 15: err(f, 'store.tax', 'need mode inclusive|exclusive and rate 15')
    if not re.match(r'^\+966 5\d \d{3} \d{4}$', str(s.get('phone', ''))) or s.get('phone_is_demo') is not True: err(f, 'store.phone', 'must match +966 5x xxx xxxx and phone_is_demo true')
    if not s.get('order_types'): err(f, 'store.order_types', 'empty')
    wk = (s.get('hours') or {}).get('weekly') or {}
    if set(wk) != {'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'}: err(f, 'store.hours', 'weekly must have 7 days')

    if name == 'pharmacy':
        ph = s.get('pharmacy') or {}
        for k in ('disclaimer', 'entry_gate', 'min_age', 'show_expiry', 'licence'):
            if k not in ph: err(f, 'store.pharmacy', f'missing {k}')
        lic = ph.get('licence') or {}
        for k in ('legal_name', 'number', 'authority', 'pharmacist_in_charge', 'address'):
            if k not in lic: err(f, 'store.pharmacy.licence', f'missing {k}')
        if ph.get('licence_is_demo') is not True: err(f, 'store.pharmacy', 'licence_is_demo must be true (sample licence, never a real number)')
        if len(ph.get('disclaimer', {}).get('en', '')) > 280: err(f, 'store.pharmacy.disclaimer', 'over 280 chars (spec 5.2)')
    cats = d.get('categories') or []
    items = d.get('items') or []
    if not lim[2] <= len(cats) <= lim[3]: err(f, 'categories', f'count {len(cats)} outside {lim[2]}-{lim[3]}')
    if not lim[0] <= len(items) <= lim[1]: err(f, 'items', f'count {len(items)} outside {lim[0]}-{lim[1]}')
    catset = {c['slug'] for c in cats}
    dup(f, 'categories', [c['slug'] for c in cats])
    dup(f, 'items.slug', [i['slug'] for i in items])
    library = {g['key']: g for g in (d.get('modifier_library') or d.get('option_library') or [])}
    dup(f, 'library keys', [g['key'] for g in (d.get('modifier_library') or d.get('option_library') or [])])
    lib_opts = 'modifiers' if 'modifier_library' in d else 'options'
    for k, g in library.items(): check_group(f, f'library.{k}', g, lib_opts)
    allergens = {a['key'] for a in d.get('allergen_catalog', [])}
    skus, barcodes = [], []

    staff = d.get('staff') or []
    if name == 'services':
        if not 3 <= len(staff) <= 5: err(f, 'staff', f'count {len(staff)} outside 3-5')
        dup(f, 'staff', [x['slug'] for x in staff])
    staffset = {x['slug'] for x in staff}

    for n, it in enumerate(items):
        p = f"items[{n}]({it.get('slug')})"
        if it.get('category') not in catset: err(f, p, f"category {it.get('category')!r} not found")
        price = money(f, p + '.price', it.get('price'))
        if 'compare_at_price' in it:
            c = money(f, p + '.compare_at_price', it['compare_at_price'])
            if c is not None and price is not None and not c > price: err(f, p, 'compare_at_price must be greater than price')
        if not isinstance(it.get('image_prompt'), str) or len(it['image_prompt']) < 15 or not it['image_prompt'].isascii(): err(f, p, 'image_prompt missing or not English text')
        if 'http' in str(it.get('image_prompt')): err(f, p, 'image_prompt must not be a URL')
        for b in it.get('badges', []):
            if b not in BADGES: err(f, p, f'unknown badge {b!r}')
        for k in ('modifier_groups', 'option_groups'):
            for g in it.get(k, []):
                if g not in library: err(f, p, f'group {g!r} not in library')
        for a in it.get('allergens', []):
            if a not in allergens: err(f, p, f'allergen {a!r} not in allergen_catalog')
        if it.get('sku'): skus.append(it['sku'])
        if it.get('barcode'): barcodes.append(it['barcode'])
        if 'stock_qty' in it and (not is_int(it['stock_qty']) or it['stock_qty'] < 0): err(f, p, 'stock_qty must be int >= 0')
        if name in ('restaurant', 'cafe'):
            if not is_int(it.get('calories')) or it['calories'] < 0: err(f, p, 'calories must be int >= 0')
            if 'allergens' not in it: err(f, p, 'allergens missing')
            if it.get('type') not in ('veg', 'nonveg'): err(f, p, 'type must be veg|nonveg')
        if name == 'grocery':
            u = it.get('pack_unit')
            if u not in PACK_UNITS: err(f, p, f'pack_unit {u!r} invalid')
            if not isinstance(it.get('sold_by_weight'), bool): err(f, p, 'sold_by_weight must be bool')
            if it.get('sold_by_weight'):
                if u != 'kg' or it.get('pricing_unit') != 'kg': err(f, p, 'weighed items need pack_unit kg and pricing_unit kg')
                ws = it.get('weight_steps_g')
                if not ws or any(not is_int(x) or x <= 0 for x in ws): err(f, p, 'weight_steps_g must be positive ints')
            else:
                ps = it.get('pack_size')
                if not isinstance(ps, (int, float)) or isinstance(ps, bool) or ps <= 0: err(f, p, 'pack_size must be > 0')
            if not isinstance(it.get('stock_qty'), int): err(f, p, 'stock_qty missing')
            if 'nutrition_per_100g' in it and not is_int(it['nutrition_per_100g'].get('calories')): err(f, p, 'nutrition calories must be int')
        if name == 'retail':
            cols = {c['key']: c for c in it.get('colours', [])}
            if not cols: err(f, p, 'colours missing')
            for c in cols.values():
                if not re.match(r'^#[0-9A-Fa-f]{6}$', c.get('hex', '')): err(f, p, f"bad swatch hex {c.get('hex')!r}")
            sizes = it.get('sizes') or []
            combos = set()
            for v in it.get('variants', []):
                vp = f"{p}.variants[{v.get('sku')}]"
                if v.get('colour') not in cols: err(f, vp, 'unknown colour')
                if v.get('size') not in sizes: err(f, vp, 'unknown size')
                if not is_int(v.get('stock_qty')) or v['stock_qty'] < 0: err(f, vp, 'stock_qty must be int >= 0')
                if not v.get('sku'): err(f, vp, 'sku missing')
                skus.append(v.get('sku')); barcodes.append(v.get('barcode'))
                if v.get('price') is not None: money(f, vp + '.price', v['price'])
                combos.add((v.get('colour'), v.get('size')))
            if len(combos) != len(cols) * len(sizes): err(f, p, 'variants must cover every colour x size exactly once')
            for k in ('material', 'care', 'fit'):
                if k not in it: err(f, p, f'{k} missing')
        if name == 'pharmacy':
            strengths, packs = it.get('strengths') or [], {pk.get('key'): pk for pk in it.get('packs') or []}
            if not strengths or not packs: err(f, p, 'strengths and packs must be non-empty')
            if not isinstance(it.get('age_restricted'), bool) or not isinstance(it.get('promo_allowed'), bool): err(f, p, 'age_restricted and promo_allowed must be bool')
            if it.get('age_restricted') and not is_int(it.get('age_min')): err(f, p, 'age_restricted item needs age_min')
            if not it.get('promo_allowed') and ('compare_at_price' in it): err(f, p, 'promo_allowed=false items must not carry compare_at_price (G4)')
            if not re.match(r'^\d{4}-\d{2}-\d{2}$', str(it.get('expiry_date', ''))): err(f, p, 'expiry_date must be YYYY-MM-DD')
            for k in ('brand', 'form', 'storage'):
                if k not in it: err(f, p, f'{k} missing')
            if it.get('active_ingredients') is not None:
                for k in ('dosage', 'usage'):
                    if k not in it: err(f, p, f'{k} missing (item has active_ingredients)')
            if it.get('active_ingredients') and not it.get('promo_allowed') and not it.get('leaflet'): err(f, p, 'medicines (promo_allowed=false) need a leaflet')
            combos = set()
            for v in it.get('variants', []):
                vp = f"{p}.variants[{v.get('sku')}]"
                if v.get('strength') not in strengths: err(f, vp, 'unknown strength')
                if v.get('pack') not in packs: err(f, vp, 'unknown pack')
                if not is_int(v.get('stock_qty')) or v['stock_qty'] < 0: err(f, vp, 'stock_qty must be int >= 0')
                if not v.get('sku'): err(f, vp, 'sku missing')
                money(f, vp + '.price', v.get('price'))
                skus.append(v.get('sku')); barcodes.append(v.get('barcode'))
                combos.add((v.get('strength'), v.get('pack')))
            if len(combos) != len(strengths) * len(packs): err(f, p, 'variants must cover every strength x pack exactly once')
            if it.get('limit') and not (is_int(it['limit'].get('max_per_order')) and it['limit']['max_per_order'] >= 1): err(f, p, 'limit.max_per_order must be int >= 1')
            if it.get('unit_en') and not it.get('unit_ar'): err(f, p, 'unit_en needs unit_ar')
        if name == 'services':
            dm = it.get('duration_min')
            if not is_int(dm) or dm <= 0 or dm % 5: err(f, p, f'duration_min must be a positive multiple of 5: {dm!r}')
            bm = it.get('buffer_min')
            if not is_int(bm) or bm < 0 or bm % 5: err(f, p, f'buffer_min must be a multiple of 5 >= 0: {bm!r}')
            dep = it.get('deposit') or {}
            if dep.get('type') == 'fixed':
                v = money(f, p + '.deposit.value', dep.get('value'))
                if v is not None and price is not None and v >= price: err(f, p, 'deposit must be below the price')
            elif dep.get('type') == 'percent':
                if not str(dep.get('value', '')).isdigit() or not 0 < int(dep['value']) <= 100: err(f, p, 'percent deposit must be 1-100')
            elif dep.get('type') != 'none': err(f, p, 'deposit.type must be fixed|percent|none')
            if not it.get('staff'): err(f, p, 'staff empty')
            for sid in it.get('staff', []):
                if sid not in staffset: err(f, p, f'unknown staff {sid!r}')
            for x in it.get('includes', []):
                if x not in {i['slug'] for i in items}: err(f, p, f'unknown included service {x!r}')
    if skus: dup(f, 'sku', skus)
    if barcodes: dup(f, 'barcode', barcodes)
    return len(cats), len(items)

def main():
    summary = []
    for name, lim in FILES.items():
        if not os.path.isfile(os.path.join(DEMO, name + '.json')):
            summary.append(f'{name}: not imported yet')
            continue
        n = len(errors)
        r = check_file(name, lim)
        summary.append(f"{name}: {'OK' if len(errors) == n else 'FAIL'}" + (f' ({r[0]} categories, {r[1]} items)' if r else ''))
    print('\n'.join(summary))
    if errors:
        print(f'\n{len(errors)} problem(s):')
        print('\n'.join(errors[:80]))
        if len(errors) > 80: print(f'... and {len(errors) - 80} more')
        return 1
    print('\nAll demo checks passed.')
    return 0

if __name__ == '__main__':
    sys.exit(main())
