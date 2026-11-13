#!/usr/bin/env python3
"""Run the static conformance checks and print a summary. Exit 1 on any failure. Python 3 stdlib only.

  python3 tools/conformance/run_all.py              checks 1, 2, 3, 6 (static) and the per-file size budgets
  python3 tools/conformance/run_all.py --self-test  proves every gate fires on the bad samples in tests/ (and stays quiet on tests/good)
  python3 tools/conformance/run_all.py --quiet      only failing checks print their output
  python3 tools/conformance/run_all.py <dir>...     point checks 1, 2, 6 at other theme folders (Juwri CI: the store_templates dirs)

Checks 4 (axe), 5 (Lighthouse CI) and the RTL screenshots need a browser and npm packages: see README.md.
"""
import importlib.util, io, contextlib, os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
PY = sys.executable

def load(name, rel):
    spec = importlib.util.spec_from_file_location(name, os.path.join(HERE, rel))
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m

def run(label, cmd, quiet):
    p = subprocess.run([PY] + cmd, capture_output=True, text=True)
    out = (p.stdout + p.stderr).rstrip()
    if p.returncode or not quiet: print(f'--- {label}\n{out}')
    return p.returncode == 0, out.splitlines()[-1] if out else ''

def self_test():
    ok = True
    def expect(cond, msg):
        nonlocal ok
        if not cond: ok = False; print('  SELF-TEST FAIL:', msg)
    gg = load('grep_gates', 'grep_gates.py')
    _, good = gg.run([os.path.join(HERE, 'tests', 'good')])
    expect(not good, f'tests/good must be clean, got {good[:3]}')
    _, bad = gg.run([os.path.join(HERE, 'tests', 'bad')])
    hit = {r for _, _, r, _ in bad}
    for rule in gg.RULES: expect(rule in hit, f'grep gate "{rule}" did not fire on tests/bad')
    # allow-lists really silence: raw.blade.php line 1 flagged (no marker), the reasonless marker still flagged
    raw_lines = sorted(l for p, l, r, _ in bad if r == 'raw-blade')
    expect(raw_lines == [1, 3], f'raw-blade should fire on lines 1 and 3 of raw.blade.php, got {raw_lines}')
    print(f'grep_gates self-test: {len(hit)}/{len(gg.RULES)} rules fire on bad samples, good samples clean')

    sl = load('schema_lint', 'schema_lint.py')
    errs, _, _ = sl.lint_file(os.path.join(HERE, 'tests', 'bad_theme', 'aisle', 'theme.json'))
    text = '\n'.join(errs)
    for needle in ('required section catalog missing', "layout.radius='blob'", 'contrast clean/light', 'drift'):
        expect(needle in text, f'schema_lint did not report "{needle}"')
    print(f'schema_lint self-test: bad theme gives {len(errs)} error(s)')

    tp = subprocess.run([PY, os.path.join(HERE, 'totals_parity.py'), '--self-test'], capture_output=True, text=True)
    print(tp.stdout.rstrip()); expect(tp.returncode == 0, 'totals_parity self-test failed')

    mc = load('mirror_check', os.path.join('rtl', 'mirror_check.py'))
    g = mc.check_file(os.path.join(HERE, 'tests', 'rtl', 'ar', 'good.html'))
    expect(g == [], f'mirror_check good page should pass, got {g}')
    b = mc.check_file(os.path.join(HERE, 'tests', 'rtl', 'bad', 'ar', 'bad.html'))
    rules = {r for _, r, _ in b}
    for r in ('lang', 'dir', 'icon-not-dir', 'icon-should-not-flip', 'ltr-code', 'transform'):
        expect(r in rules, f'mirror_check rule "{r}" did not fire')
    print(f'mirror_check self-test: bad page gives {len(b)} problem(s)')
    print('SELF-TEST', 'OK' if ok else 'FAILED')
    return 0 if ok else 1

def main(argv):
    if '--self-test' in argv: return self_test()
    quiet = '--quiet' in argv
    # juwri: --theme <slug> checks one theme of the tree under CONFORMANCE_ROOT (default: kit-snapshot/) and --root <dir>
    # sets that tree; schema_lint wants the theme.json folder, the other gates also take the prototype/view folder.
    argv = list(argv)
    theme = None
    for flag in ('--theme', '--root'):
        if flag in argv:
            i = argv.index(flag); val = argv[i + 1]; del argv[i:i + 2]
            if flag == '--root': os.environ['CONFORMANCE_ROOT'] = os.path.abspath(val)
            else: theme = val
    dirs = [a for a in argv if not a.startswith('--')]
    schema_dirs = floor_dirs = dirs
    if theme:
        root = os.environ.get('CONFORMANCE_ROOT') or os.path.join(HERE, 'kit-snapshot')
        schema_dirs = [os.path.join(root, 'kit', theme)]
        floor_dirs = [os.path.join(root, 'kit', theme), os.path.join(root, 'prototypes', theme), os.path.join(root, 'prototypes', '_kit')]
    steps = [('1 schema lint + contrast (§11.1)', ['schema_lint.py'] + schema_dirs),
             ('2 grep gates (§11.2)', ['grep_gates.py'] + floor_dirs),
             ('3 totals parity (§11.3)', ['totals_parity.py']),
             ('6 RTL static mirror check (§11.6)', [os.path.join('rtl', 'mirror_check.py')] + floor_dirs),
             ('5 per-file size budgets (§9)', [os.path.join('lighthouse', 'size_budget.py')] + floor_dirs)]
    results = []
    for label, cmd in steps:
        ok, last = run(label, [os.path.join(HERE, cmd[0])] + cmd[1:], quiet)
        results.append((label, ok, last))
    print('\n=== conformance summary ===')
    for label, ok, last in results: print(f'{"PASS" if ok else "FAIL"}  {label:38} {last}')
    print('not run here: 4 axe, 5 Lighthouse CI, 6 RTL screenshots (need a browser; see README.md)')
    bad = [r for r in results if not r[1]]
    print('RESULT:', 'FAIL' if bad else 'PASS')
    return 1 if bad else 0

if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
