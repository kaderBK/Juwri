#!/usr/bin/env python3
"""Check 2 (THEME-CONTRACT §11.2, §3, §6, §7): grep gates over Blade / HTML / CSS / JS.

Usage: python3 grep_gates.py [dir_or_file ...]      (default: prototypes/ and kit/)
Output: path:line: rule-id: message        Exit 1 when anything is reported.

Rules
  raw-blade            `{!!` in a theme file (§7). Allow: `{{-- conformance:allow-raw <reason> --}}` on the same or previous line
  inline-handler       on[a-z]+= attribute (§7)
  javascript-url       javascript: URL (§7)
  inline-script        <script> without src and without type="application/json" or "application/ld+json" (§7)
  style-attr           style="" attribute (§7, D5)
  hex-outside-tokens   hex colour in CSS outside :root / [data-scheme…] blocks; tokens.css is exempt (§3)
  physical-css         left/right in a CSS property name or text-align/float/clear value (§6).
                       Allow: `/* conformance:allow-physical */` on the same or previous line
  external-host        http(s):// or // URL in src/href/srcset/poster/url()/@import, or in JS strings (§7)
  vm-bypass            post_options( / request( / \\App\\Models / DB:: in Blade (§4)
  tc-var-outside-tokens  var(--tc-*) used outside tokens.css: components use --x-* (§3, prototypes/README)
  target-blank         target="_blank" without rel="noopener noreferrer" (§7)
  js-eval              eval( / new Function / string setTimeout|setInterval (§7)
Python 3 stdlib only.
"""
import os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.environ.get('CONFORMANCE_ROOT') or os.path.join(HERE, 'kit-snapshot')  # juwri: kit repo root (tokens/, kit/, prototypes/, tools/)
EXTS = ('.php', '.html', '.htm', '.css', '.js', '.mjs')
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'notifications', 'out'}  # notifications: email needs inline CSS (documented exception); out: generated SEO samples

RULES = ['raw-blade', 'inline-handler', 'javascript-url', 'inline-script', 'style-attr', 'hex-outside-tokens',
         'physical-css', 'external-host', 'vm-bypass', 'tc-var-outside-tokens', 'target-blank', 'js-eval']

ALLOW_RAW = re.compile(r'conformance:allow-raw\s+(?!--\}\})\S')
ALLOW_RAW_ANY = re.compile(r'conformance:allow-raw')
ALLOW_PHYS = re.compile(r'conformance:allow-physical')

def line_of(text, pos):
    return text.count('\n', 0, pos) + 1

def blank(text, rx):
    """Replace matches of rx by spaces, keeping newlines (so line numbers stay valid)."""
    return re.sub(rx, lambda m: re.sub(r'[^\n]', ' ', m.group(0)), text, flags=re.S)

# ---------------------------------------------------------------- CSS
HEX = re.compile(r'(?<![\w&#-])#[0-9a-fA-F]{3,8}(?![\w-])')
PHYS_PROP = re.compile(r'(^|-)(left|right)(-|$)')
URL_EXT = re.compile(r'url\(\s*[\'"]?\s*(?:https?:)?//', re.I)
IMPORT_EXT = re.compile(r'@import\s+(?:url\(\s*)?[\'"]?\s*(?:https?:)?//', re.I)

def scan_css(text, path, rep, base_line=0, is_tokens=False):
    raw_lines = text.split('\n')
    clean = blank(text, r'/\*.*?\*/')
    stack = []          # [selector, is_token_block]
    buf, start, line = '', None, 1
    def flush(end_line):
        nonlocal buf, start
        stmt = buf.strip()
        if stmt:
            s_line = start or end_line
            def allowed(rx):
                for ln in {s_line - 1, s_line, end_line}:
                    if 1 <= ln <= len(raw_lines) and rx.search(raw_lines[ln - 1]): return True
                return False
            if URL_EXT.search(stmt) or IMPORT_EXT.search(stmt):
                rep(path, base_line + s_line, 'external-host', 'external URL in CSS (no CDN fonts, remote CSS or images)')
            if not is_tokens and 'var(--tc-' in stmt:
                rep(path, base_line + s_line, 'tc-var-outside-tokens', 'var(--tc-*) outside tokens.css; use --x-* tokens')
            if stack and ':' in stmt:
                prop, _, val = stmt.partition(':')
                prop, val = prop.strip().lower(), val.strip()
                exempt = any(t for _, t in stack)
                if not exempt and not is_tokens:
                    v2 = re.sub(r'url\([^)]*\)', 'url()', val)   # data URIs may contain %23 / # fragments
                    if HEX.search(v2):
                        rep(path, base_line + s_line, 'hex-outside-tokens', f'hex colour in `{prop}` outside :root/[data-scheme] blocks')
                phys = None
                if PHYS_PROP.search(prop) and not prop.startswith('--'): phys = prop
                elif prop in ('text-align', 'text-align-last', 'float', 'clear') and re.search(r'\b(left|right)\b', val): phys = f'{prop}: {val}'
                elif prop in ('background-position', 'background-position-x', 'object-position', 'transform-origin') \
                        and re.search(r'\b(left|right)\b', val): phys = f'{prop}: {val}'
                if phys and not allowed(ALLOW_PHYS):
                    rep(path, base_line + s_line, 'physical-css', f'physical property `{phys}`; use logical properties or /* conformance:allow-physical */')
        buf, start = '', None
    for ch in clean:
        if ch == '\n': line += 1
        if ch == '{':
            sel = buf.strip()
            stack.append((sel, (':root' in sel) or ('[data-scheme' in sel)))
            if sel and not sel.startswith('@') and not is_tokens and 'var(--tc-' in sel:
                rep(path, base_line + (start or line), 'tc-var-outside-tokens', 'var(--tc-*) in selector')
            buf, start = '', None
        elif ch == '}':
            flush(line)
            if stack: stack.pop()
        elif ch == ';':
            flush(line)
        else:
            if start is None and not ch.isspace(): start = line
            buf += ch
    flush(line)

# ---------------------------------------------------------------- Blade / HTML
SCRIPT_TAG = re.compile(r'<script\b([^>]*)>', re.I | re.S)
ATTR_EXT = re.compile(r'(?<![\w:.-])(?:src|href|poster|action|formaction)\s*=\s*["\']\s*(?:https?:)?//(?!www\.w3\.org/)', re.I)
SRCSET_EXT = re.compile(r'srcset\s*=\s*["\'][^"\']*(?:https?:)?//', re.I)
HANDLER = re.compile(r'(?<![\w:.@-])on[a-z]{2,}\s*=\s*["\']', re.I)
STYLE_ATTR = re.compile(r'(?<![\w:.@-])style\s*=\s*["\']', re.I)
JS_URL = re.compile(r'(?:href|src|action|formaction)\s*=\s*["\']\s*javascript:', re.I)
BYPASS = [(re.compile(r'\bpost_options\s*\('), 'post_options('),
          (re.compile(r'(?<![\w$>:\\])request\s*\('), 'request('),
          (re.compile(r'\\?App\\Models\b'), '\\App\\Models'),
          (re.compile(r'\bDB::'), 'DB::')]
A_TAG = re.compile(r'<a\b[^>]*>', re.I | re.S)

def scan_markup(text, path, rep, is_blade):
    raw_lines = text.split('\n')
    # {!! -- check on raw text (the allow marker is itself a Blade comment)
    for m in re.finditer(r'\{!!', text):
        ln = line_of(text, m.start())
        near = '\n'.join(raw_lines[max(0, ln - 2):ln])
        if ALLOW_RAW.search(near): continue
        why = ' (allow-raw marker needs a reason)' if ALLOW_RAW_ANY.search(near) else ''
        rep(path, ln, 'raw-blade', '`{!!` unescaped output; owner HTML comes sanitised through one core component' + why)
    body = blank(text, r'\{\{--.*?--\}\}') if is_blade else text
    body = blank(body, r'<!--.*?-->')
    # <style> blocks are CSS
    for m in re.finditer(r'<style\b[^>]*>(.*?)</style>', body, re.I | re.S):
        scan_css(m.group(1), path, rep, base_line=line_of(body, m.start(1)) - 1)
    code = blank(body, r'<style\b[^>]*>.*?</style>')
    for m in SCRIPT_TAG.finditer(code):
        a = m.group(1)
        if re.search(r'\bsrc\s*=', a, re.I): continue
        if re.search(r'type\s*=\s*["\']application/(ld\+)?json["\']', a, re.I): continue
        rep(path, line_of(code, m.start()), 'inline-script', '<script> without src and without type="application/json"')
    code_nos = blank(code, r'<script\b[^>]*>.*?</script>')  # script bodies are not attributes
    for m in HANDLER.finditer(code_nos):
        # only inside a tag: previous '<' after last '>'
        pre = code_nos[:m.start()]
        if pre.rfind('<') > pre.rfind('>'):
            rep(path, line_of(code, m.start()), 'inline-handler', f'inline event handler `{m.group(0).split("=")[0].strip()}=`')
    for m in STYLE_ATTR.finditer(code_nos):
        pre = code_nos[:m.start()]
        if pre.rfind('<') > pre.rfind('>'):
            rep(path, line_of(code, m.start()), 'style-attr', 'style="" attribute; use classes or el.style.setProperty from JS')
    for m in JS_URL.finditer(code):
        rep(path, line_of(code, m.start()), 'javascript-url', 'javascript: URL')
    for rx in (ATTR_EXT, SRCSET_EXT):
        for m in rx.finditer(code):
            rep(path, line_of(code, m.start()), 'external-host', 'external URL; only relative paths and view-model values')
    for m in A_TAG.finditer(code):
        t = m.group(0)
        if re.search(r'target\s*=\s*["\']_blank["\']', t, re.I):
            rm = re.search(r'rel\s*=\s*["\']([^"\']*)["\']', t, re.I)
            rel = (rm.group(1).lower().split() if rm else [])
            if not ('noopener' in rel and 'noreferrer' in rel):
                rep(path, line_of(code, m.start()), 'target-blank', 'target="_blank" needs rel="noopener noreferrer"')
    if is_blade:
        for rx, label in BYPASS:
            for m in rx.finditer(code):
                rep(path, line_of(code, m.start()), 'vm-bypass', f'`{label}` in Blade; themes read only the view model (§4)')
    # inline <script> bodies of data type are not scanned for JS rules.

# ---------------------------------------------------------------- JS
def scan_js(text, path, rep):
    code = blank(text, r'/\*.*?\*/')
    code = re.sub(r'(?m)(^|[^:\'"\\])//[^\n]*', lambda m: m.group(1), code)
    for m in re.finditer(r'https?://(?!www\.w3\.org/)', code):
        rep(path, line_of(code, m.start()), 'external-host', 'external URL in JS')
    for m in re.finditer(r'(?<![\w$.])eval\s*\(|\bnew\s+Function\b|\bset(?:Timeout|Interval)\s*\(\s*[\'"`]', code):
        rep(path, line_of(code, m.start()), 'js-eval', 'eval / new Function / string timer is banned by the CSP')
    for m in re.finditer(r'setAttribute\(\s*[\'"]style[\'"]|\.cssText\s*=', code):
        rep(path, line_of(code, m.start()), 'style-attr', 'inline style from JS; use style.setProperty("--x", v)')
    for m in re.finditer(r'(?<![\w-])on(?:click|change|submit|load|error|input)\s*=\s*[\'"]', code):
        rep(path, line_of(code, m.start()), 'inline-handler', 'inline handler string in JS')

# ---------------------------------------------------------------- driver
def scan_file(path, rep):
    try: text = open(path, encoding='utf-8').read()
    except (UnicodeDecodeError, OSError) as e:
        rep(path, 0, 'read-error', str(e)); return
    base = os.path.basename(path)
    if base.endswith('.css'): scan_css(text, path, rep, is_tokens=(base == 'tokens.css'))
    elif base.endswith('.php'): scan_markup(text, path, rep, is_blade=True)
    elif base.endswith(('.html', '.htm')): scan_markup(text, path, rep, is_blade=False)
    elif base.endswith(('.js', '.mjs')): scan_js(text, path, rep)

def walk(targets):
    for t in targets:
        if os.path.isfile(t): yield t; continue
        for dp, dn, fn in os.walk(t):
            dn[:] = sorted(d for d in dn if d not in SKIP_DIRS)
            for f in sorted(fn):
                if f.endswith(EXTS): yield os.path.join(dp, f)

def run(targets):
    found = []
    def rep(path, line, rule, msg): found.append((path, line, rule, msg))
    n = 0
    for f in walk(targets):
        n += 1; scan_file(f, rep)
    return n, found

def main(argv):
    targets = argv or [os.path.join(ROOT, 'prototypes'), os.path.join(ROOT, 'kit')]
    n, found = run([t for t in targets if os.path.exists(t)])
    cwd = os.getcwd()
    for path, line, rule, msg in sorted(set(found)):
        print(f'{os.path.relpath(path, cwd)}:{line}: {rule}: {msg}')
    print(f'grep_gates: {n} file(s), {len(found)} violation(s)')
    return 1 if found else 0

if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
