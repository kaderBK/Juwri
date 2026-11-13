# Kit string keys that differ from core's (P3-00 part 2)

Kit themes call `___('English text')`; the key is the slug of the English text. Six kit strings share a slug
with a core key of a DIFFERENT meaning, so core's value would be shown in the theme. They live under their own
`kit_` key (11 locales, the kit's translations, `kit/lang/collisions.json`; `tools/themes/tools/merge_lang.php`
adds them). Themes (table, brew, aisle, basket, book — P3-05 onwards) MUST call the `kit_` key:

| Theme call | Kit meaning (en value) | Core key it must not use | Core meaning |
|---|---|---|---|
| `___('kit_min')` | "Min" (price-range lower bound, aisle) | `min` | minutes |
| `___('kit_per_unit')` | "Per unit" (static unit-price label, carely) | `per_unit` | "per :unit" template |
| `___('kit_your_name')` | "Your name" (book form field) | `your_name` | bare "Name" |
| `___('kit_items')` | "Items" (aisle summary) | `items` | "Item(s)" |
| `___('kit_shop')` | "Shop" (aisle nav, verb) | `shop` | the noun (a store) in ar/hi/others |
| `___('kit_sale')` | "Sale" (aisle nav, discounted goods) | `sale` | see below |

Core `sale` was wrong in ar ("بيع" = sell) and es/hi/pt/tr/ur ("sales/selling"); its only use is the
"Sale only" filter of the maison theme (discounted goods). Fixed to the discount meaning in those six locales.

`KitLangMergeTest` checks the keys exist in all 11 locales and (once a kit theme has views) that the kit
views never call the plain words.
