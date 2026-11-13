import assert from 'node:assert/strict';
import { test } from 'node:test';
import { inflate } from './inflate.mjs';

const page = (n) => `<html><body><ul class="c-cards">${Array.from({ length: n }, (_, i) => `<li class="c-card c-card--list" id="i${i}"><a href="#">Item ${i}</a></li>`).join('')}</ul><ul><li class="c-fcard">x</li></ul><script>var a = '<li class="c-card">';</script></body></html>`;
const count = (h) => (h.match(/<li class="c-card /g) || []).length;

test('grows a catalogue by cloning items without duplicate ids', () => {
  const { html, had } = inflate(page(5), 30);
  assert.equal(had, 5); assert.equal(count(html), 30);
  assert.equal((html.match(/id="i0"/g) || []).length, 1);
  assert.ok(html.includes("var a = '<li class=\"c-card\">'"), 'script text untouched');
});
test('shrinks a catalogue', () => { assert.equal(count(inflate(page(52), 30).html), 30); });
test('300 items', () => { assert.equal(count(inflate(page(12), 300).html), 300); });
test('leaves other lists and pages without items alone', () => {
  assert.ok(inflate(page(5), 3).html.includes('c-fcard'));
  assert.equal(inflate('<p>hi</p>', 30).html, '<p>hi</p>');
});
