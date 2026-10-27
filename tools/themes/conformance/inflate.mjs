// Catalogue sizing for the Lighthouse step: the prototypes carry one fixed catalogue, the budgets are defined at 30 and
// 300 items (THEME-CONTRACT 9), so the job's server resizes the item list on request (?items=N).
export const ITEM_CLASSES = new Set(['c-card', 'c-gcard', 'c-pcard', 'c-svc']); // the catalogue item <li> of the five kit themes + carely
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

/** Resize the catalogue to exactly n items: drop the surplus or append clones (ids stripped) after the last item. */
export function inflate(html, n) {
  const masked = html.replace(/<!--[\s\S]*?-->|<(script|style)\b[\s\S]*?<\/\1>/gi, (s) => ' '.repeat(s.length));
  const re = /<(\/?)([a-zA-Z][\w:-]*)([^>]*?)(\/?)>/g;
  const stack = [], items = [];
  let m;
  while ((m = re.exec(masked))) {
    const [, close, tag, attrs, self] = m, t = tag.toLowerCase();
    if (close) {
      let i = stack.length - 1;
      while (i >= 0 && stack[i].tag !== t) i--;
      if (i < 0) continue;
      const el = stack[i]; stack.length = i;
      if (el.item) items.push({ start: el.start, end: re.lastIndex });
    } else if (!VOID.has(t) && !self) {
      const cls = /class="([^"]*)"/.exec(attrs)?.[1] ?? '';
      const parent = stack[stack.length - 1];
      const item = t === 'li' && ITEM_CLASSES.has(cls.trim().split(/\s+/)[0]) && !/rail/.test(cls) && parent && (parent.tag === 'ul' || parent.tag === 'ol');
      stack.push({ tag: t, start: m.index, item });
    }
  }
  if (!items.length) return { html, had: 0 };
  items.sort((a, b) => a.start - b.start);
  let out = html;
  if (n < items.length) {
    for (let i = items.length - 1; i >= n; i--) out = out.slice(0, items[i].start) + out.slice(items[i].end);
  } else if (n > items.length) {
    const last = items[items.length - 1];
    let extra = '';
    for (let i = items.length; i < n; i++) {
      const src = items[i % items.length];
      extra += html.slice(src.start, src.end).replace(/\sid="[^"]*"/g, '');
    }
    out = out.slice(0, last.end) + extra + out.slice(last.end);
  }
  return { html: out, had: items.length };
}
