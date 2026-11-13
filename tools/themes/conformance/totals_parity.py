#!/usr/bin/env python3
"""Check 3 (THEME-CONTRACT §11.3, §5.2): totals parity on view-model fixtures.

Usage: python3 totals_parity.py [fixture.json ...]     (default: kit/viewmodel/fixtures/*.json)
       python3 totals_parity.py --self-test
       python3 totals_parity.py --strict-zero ...   (zero amounts may not be words such as "Free")

For every Quote found anywhere in a fixture (an object with lines[], rows[], total):
  - sum(lines.line_total) == the subtotal row
  - rows combine to total by kind:
        total = subtotal + fee + delivery + tip + exclusive tax - |discount|
    * inclusive tax rows are informational and NOT added (tax mode from fixture money.tax.mode;
      without it a tax row is inclusive when muted:true or its key contains "incl")
    * estimate_cap rows and muted discount rows ("You save") are informational
    * deposit / due_later rows are informational splits: deposit + due_later == total
  - the `total` row (if any) equals quote.total
For every Money {minor, currency, formatted} in the fixture (Quote or not):
  - formatted parses with the locale pattern (en "SAR 1.00" / "−SAR 1.00", ar "1.00 ر.س")
  - fraction digits == currency exponent (SAR 2, KWD 3, JPY 0 ...; fixture money.currency.exponent wins)
  - the parsed amount equals minor (sign included); one symbol per currency within a fixture
Python 3 stdlib only. Exit 1 on any error.
"""
import glob, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.environ.get('CONFORMANCE_ROOT') or os.path.join(HERE, 'kit-snapshot')  # juwri: kit repo root (tokens/, kit/, prototypes/, tools/)

STRICT_ZERO = True    # contract §4 (2026-10-02): --strict-zero: a zero Money must be formatted money, not a word like "Free"
EXPONENT = {'JPY': 0, 'KRW': 0, 'VND': 0, 'CLP': 0, 'ISK': 0, 'UGX': 0, 'XOF': 0, 'XAF': 0,
            'KWD': 3, 'BHD': 3, 'OMR': 3, 'JOD': 3, 'TND': 3, 'LYD': 3, 'IQD': 3}  # default 2
BIDI = re.compile('[⁦-⁩‎‏؜‪-‮]')
MINUS = ('−', '-', '–')
NUM = r'(?P<num>\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)'
EN = re.compile(r'^(?P<neg>[−\-–])?(?P<sym>[^\d\s,.\-−][^\d]*?)\s' + NUM + r'$')
AR = re.compile(r'^(?P<neg>[−\-–])?' + NUM + r'\s(?P<sym>[^\d\s,.\-−].*)$')


def exponent(cur, vm_cur=None):
    if vm_cur and vm_cur.get('code') == cur and isinstance(vm_cur.get('exponent'), int): return vm_cur['exponent']
    return EXPONENT.get(cur, 2)


def parse_formatted(s, lang):
    """-> (negative, minor_digits_as_str_with_frac (int, frac_len), symbol, None) or error string."""
    t = BIDI.sub('', s).replace(' ', ' ').replace(' ', ' ').strip()
    cands = [('ar', AR)] if lang == 'ar' else [('en', EN)] if lang == 'en' else [('en', EN), ('ar', AR)]
    for name, rx in cands:
        m = rx.match(t)
        if m:
            num = m.group('num').replace(',', '')
            whole, _, frac = num.partition('.')
            return (bool(m.group('neg')), int(whole + frac), len(frac), m.group('sym').strip()), None
    want = {'ar': '"1.00 ر.س" (number, space, symbol)', 'en': '"SAR 1.00" (symbol, space, number)'}.get(lang, 'locale pattern')
    return None, f'{s!r} does not match {want}'


def walk(node, path='$'):
    yield path, node
    if isinstance(node, dict):
        for k, v in node.items(): yield from walk(v, f'{path}.{k}')
    elif isinstance(node, list):
        for i, v in enumerate(node): yield from walk(v, f'{path}[{i}]')


def is_money(n):
    return isinstance(n, dict) and set(n) == {'minor', 'currency', 'formatted'}


def is_quote(n):
    return isinstance(n, dict) and isinstance(n.get('lines'), list) and isinstance(n.get('rows'), list) and 'total' in n


def check_money(m, path, lang, vm_cur, symbols, errs, warns):
    if not isinstance(m['minor'], int) or isinstance(m['minor'], bool):
        errs.append(f'{path}: minor must be an integer'); return
    res, err = parse_formatted(m['formatted'], lang)
    if err:
        if m['minor'] == 0 and not re.search(r'\d', m['formatted']):
            (errs if STRICT_ZERO else warns).append(f'{path}: zero amount printed as the word {m["formatted"]!r} (Money.formatted should be money; use a label field)')
            return
        errs.append(f'{path}: {err}'); return
    neg, val, flen, sym = res
    exp = exponent(m['currency'], vm_cur)
    if flen != exp: errs.append(f'{path}: {m["formatted"]!r} has {flen} decimals, {m["currency"]} has exponent {exp}')
    parsed = -val if neg else val
    if flen == exp and parsed != m['minor']:
        errs.append(f'{path}: formatted {m["formatted"]!r} = {parsed} minor, but minor is {m["minor"]}')
    if neg and val == 0: errs.append(f'{path}: negative zero {m["formatted"]!r}')
    prev = symbols.setdefault(m['currency'], sym)
    if prev != sym: errs.append(f'{path}: symbol {sym!r} differs from {prev!r} used elsewhere for {m["currency"]}')
    if re.search(r'[٠-٩۰-۹०-९]', m['formatted']):
        warns.append(f'{path}: non-Latin digits (§6: Latin by default)')


def check_quote(q, path, tax_mode, errs):
    cur = q['total'].get('currency') if isinstance(q.get('total'), dict) else None
    mons = [l.get('line_total') for l in q['lines']] + [r.get('amount') for r in q['rows']] + [q['total']]
    for m in mons:
        if not is_money(m): errs.append(f'{path}: non-Money amount {m!r}'); return
        if m['currency'] != cur: errs.append(f'{path}: mixed currencies {m["currency"]} vs {cur}')
    lines_sum = sum(l['line_total']['minor'] for l in q['lines'])
    sub = [r for r in q['rows'] if (r.get('kind') or r.get('key')) == 'subtotal']
    if len(sub) != 1:
        errs.append(f'{path}: expected exactly 1 subtotal row, found {len(sub)}'); return
    if sub[0]['amount']['minor'] != lines_sum:
        errs.append(f'{path}: sum(lines.line_total)={lines_sum} but subtotal row={sub[0]["amount"]["minor"]}')
    comp, dep, due, kinds_seen = 0, None, None, []
    for i, r in enumerate(q['rows']):
        kind = r.get('kind') or (r['key'] if r.get('key') in ('subtotal', 'discount', 'fee', 'delivery', 'tax', 'tip', 'deposit', 'due_later', 'total') else None)
        a = r['amount']['minor']
        info = bool(r.get('informational'))
        if kind is None:
            errs.append(f'{path}.rows[{i}] ({r.get("key")}): no kind; cannot tell how it combines'); continue
        kinds_seen.append(kind)
        # Contract §5.2: a row is summed unless it is flagged informational. Splits
        # (deposit, due_later), caps and inclusive tax MUST be informational.
        if kind in ('deposit', 'due_later', 'estimate_cap') and not info:
            errs.append(f'{path}.rows[{i}] ({kind}): must be informational')
        if kind == 'tax' and tax_mode == 'inclusive' and not info:
            errs.append(f'{path}.rows[{i}]: inclusive tax row must be informational')
        if kind == 'deposit': dep = (dep or 0) + a
        elif kind == 'due_later': due = (due or 0) + a
        if info or kind in ('deposit', 'due_later', 'estimate_cap', 'total'):
            continue
        if kind == 'discount' and a > 0:
            errs.append(f'{path}.rows[{i}]: a summed discount must be negative (got {a})')
        if kind in ('subtotal', 'fee', 'delivery', 'tip', 'discount', 'tax'): comp += a
        else: errs.append(f'{path}.rows[{i}]: unknown kind {kind!r}')
    tot = q['total']['minor']
    if comp != tot:
        errs.append(f'{path}: rows combine to {comp} (sum of non-informational rows) but total is {tot}')
    for i, r in enumerate(q['rows']):
        if r.get('kind') == 'total' and r['amount']['minor'] != tot:
            errs.append(f'{path}.rows[{i}]: total row {r["amount"]["minor"]} != quote.total {tot}')
    if dep is not None and due is not None and dep + due != tot:
        errs.append(f'{path}: deposit {dep} + due_later {due} != total {tot}')
    elif (dep is not None and dep > tot) or (due is not None and due > tot):
        errs.append(f'{path}: deposit/due_later larger than total {tot}')


def check_doc(doc, label):
    """-> (errors, warnings, n_quotes, n_money)"""
    errs, warns, symbols = [], [], {}
    vm = doc.get('money') if isinstance(doc, dict) else None
    vm_cur = vm.get('currency') if isinstance(vm, dict) else None
    tax_mode = (vm.get('tax') or {}).get('mode') if isinstance(vm, dict) else None
    lang = (doc.get('store') or {}).get('locale') if isinstance(doc, dict) else None
    lang = (lang or '').split('-')[0].lower() or None
    nq = nm = 0
    for path, n in walk(doc):
        if is_money(n):
            nm += 1; check_money(n, path, lang, vm_cur, symbols, errs, warns)
        elif is_quote(n):
            nq += 1; check_quote(n, path, tax_mode, errs)
    return [f'{label}: {e}' for e in errs], [f'{label}: {w}' for w in warns], nq, nm


# ------------------------------------------------------------------ self-test
SYM = {'SAR': ('SAR', 'ر.س'), 'KWD': ('KWD', 'د.ك'), 'JPY': ('JPY', '¥'), 'AED': ('AED', 'د.إ')}

def _fmt(minor, cur, loc):
    e = EXPONENT.get(cur, 2); a = abs(minor); s = f'{a // 10**e}' + (f'.{a % 10**e:0{e}d}' if e else '')
    sign = '−' if minor < 0 else ''
    return f'{sign}{SYM[cur][0]} {s}' if loc == 'en' else f'{sign}{s} {SYM[cur][1]}'

def _m(minor, cur, loc): return {'minor': minor, 'currency': cur, 'formatted': _fmt(minor, cur, loc)}

def _quote(cur, loc, lines, rows):
    """rows: list of (kind, minor, extra). total computed by caller via 'total' kwarg inside rows."""
    return {'lines': [{'id': f'l{i}', 'name': f'item {i}', 'qty': 1, 'line_total': _m(x, cur, loc)} for i, x in enumerate(lines)],
            'rows': [{'key': k, 'label': k, 'kind': k, 'amount': _m(a, cur, loc), **ex} for k, a, ex in rows[:-1]],
            'total': _m(rows[-1][1], cur, loc)}

def _doc(cur, loc, tax_mode, q):
    return {'store': {'locale': loc}, 'money': {'currency': {'code': cur, 'exponent': EXPONENT.get(cur, 2)}, 'tax': {'mode': tax_mode}},
            'page': {'cart': q}}

def self_test():
    good, bad = [], []
    for loc in ('en', 'ar'):
        # SAR, exclusive 15% VAT on (sub+fee+delivery) rounded by server; tip, coupon discount
        sub = 3500; tax = 525
        good.append((f'SAR excl tax {loc}', _doc('SAR', loc, 'exclusive', _quote('SAR', loc, [1000, 2500], [
            ('subtotal', sub, {}), ('fee', 200, {}), ('delivery', 1000, {}), ('tip', 300, {}), ('discount', -500, {}), ('tax', tax, {}),
            ('total', sub + 200 + 1000 + 300 - 500 + tax, {})]))))
        # KWD 3 decimals + fixed-amount tax (flat 0.250), exclusive
        good.append((f'KWD 3dp fixed tax {loc}', _doc('KWD', loc, 'exclusive', _quote('KWD', loc, [12500, 3250], [
            ('subtotal', 15750, {}), ('delivery', 500, {}), ('tax', 250, {'key': 'tax'}), ('total', 16500, {})]))))
        # inclusive tax is informational
        good.append((f'SAR incl tax {loc}', _doc('SAR', loc, 'inclusive', _quote('SAR', loc, [11500], [
            ('subtotal', 11500, {}), ('tax', 1500, {'informational': True}), ('total', 11500, {})]))))
        # JPY 0 decimals
        good.append((f'JPY 0dp {loc}', _doc('JPY', loc, 'exclusive', _quote('JPY', loc, [1200, 800], [('subtotal', 2000, {}), ('total', 2000, {})]))))
        # deposit + due later split
        good.append((f'deposit split {loc}', _doc('SAR', loc, 'exclusive', _quote('SAR', loc, [20000], [
            ('subtotal', 20000, {}), ('deposit', 5000, {'informational': True}), ('due_later', 15000, {'informational': True}), ('total', 20000, {})]))))
    q = lambda **kw: _doc('SAR', 'en', 'exclusive', _quote('SAR', 'en', [1000, 2500], [('subtotal', 3500, {}), ('tax', 525, {}), ('total', 4025, {})]))
    d = q(); d['page']['cart']['lines'][0]['line_total'] = _m(900, 'SAR', 'en'); bad.append(('lines != subtotal', d))
    d = q(); d['page']['cart']['total'] = _m(4000, 'SAR', 'en'); bad.append(('total != rows', d))
    d = _doc('SAR', 'en', 'inclusive', _quote('SAR', 'en', [11500], [('subtotal', 11500, {}), ('tax', 1500, {}), ('total', 13000, {})]))
    bad.append(('inclusive tax wrongly added', d))
    d = q(); d['page']['cart']['total'] = {'minor': 4025, 'currency': 'SAR', 'formatted': 'SAR 40.20'}; bad.append(('minor != formatted', d))
    d = _doc('KWD', 'en', 'exclusive', _quote('KWD', 'en', [12500], [('subtotal', 12500, {}), ('total', 12500, {})]))
    d['page']['cart']['total']['formatted'] = 'KWD 12.50'; bad.append(('KWD printed with 2 decimals', d))
    d = q(); d['page']['cart']['total'] = {'minor': 4025, 'currency': 'SAR', 'formatted': '40.25 ر.س'}; bad.append(('ar pattern in en locale', d))
    d = _doc('SAR', 'ar', 'exclusive', _quote('SAR', 'ar', [1000], [('subtotal', 1000, {}), ('total', 1000, {})]))
    d['page']['cart']['total']['formatted'] = 'SAR 10.00'; bad.append(('en pattern in ar locale', d))
    d = _doc('SAR', 'en', 'exclusive', _quote('SAR', 'en', [20000], [('subtotal', 20000, {}), ('deposit', 5000, {'informational': True}), ('due_later', 14000, {'informational': True}), ('total', 20000, {})]))
    bad.append(('deposit + due_later != total', d))
    d = _doc('SAR', 'en', 'exclusive', _quote('SAR', 'en', [1000], [('subtotal', 1000, {}), ('discount', -100, {}), ('total', 1000, {})]))
    bad.append(('discount ignored', d))
    d = _doc('SAR', 'en', 'exclusive', _quote('SAR', 'en', [1000], [('subtotal', 1000, {}), ('total', 1000, {})]))
    d['page']['cart']['rows'][0]['kind'] = 'surprise'; bad.append(('unknown row kind', d))
    ok = True
    for name, doc in good:
        e, _, nq, _ = check_doc(doc, name)
        if e or nq != 1: ok = False; print(f'  SELF-TEST FAIL (expected pass): {name}', *e[:3], sep='\n    ')
    for name, doc in bad:
        e, _, _, _ = check_doc(doc, name)
        if not e: ok = False; print(f'  SELF-TEST FAIL (expected failure not raised): {name}')
    print(f'totals_parity self-test: {len(good)} good + {len(bad)} bad cases: {"OK" if ok else "FAILED"}')
    return 0 if ok else 1


def main(argv):
    global STRICT_ZERO
    if '--strict-zero' in argv: STRICT_ZERO = True; argv = [a for a in argv if a != '--strict-zero']
    if argv[:1] == ['--self-test']: return self_test()
    paths = argv or sorted(glob.glob(os.path.join(ROOT, 'kit', 'viewmodel', 'fixtures', '*.json')))
    errs, warns, tq, tm, nquote_files = [], [], 0, 0, 0
    for p in paths:
        try: doc = json.load(open(p))
        except Exception as e: errs.append(f'{p}: invalid JSON: {e}'); continue
        e, w, nq, nm = check_doc(doc, os.path.basename(p))
        errs += e; warns += w; tq += nq; tm += nm; nquote_files += bool(nq)
    for w in warns[:12]: print('warn ', w)
    if len(warns) > 12: print(f'warn  ... {len(warns) - 12} more')
    for e in errs: print('ERROR', e)
    print(f'totals_parity: {len(paths)} fixture(s), {nquote_files} with a Quote ({tq} quotes), {tm} Money values checked, {len(errs)} error(s)')
    if paths and tq == 0 and not argv:
        print('note: no fixture contains a Quote yet (page fixtures: PREBUILD-PLAN item 10b); Money formatting was still checked.')
    return 1 if errs else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
