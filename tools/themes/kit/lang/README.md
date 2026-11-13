# Kit storefront strings (table, brew, basket, aisle, book + kiosk)

Shopper-facing UI strings for the five kit themes, translated into Juwri's 11 locales (en, ar, de, es, fr, hi, pt, ru, tr, ur, zh). Themes call `___('English text')` / `___n('noun', $count)`, so each key is the Laravel slug of the English text (`Str::slug($text, '_')`), or `n_<slug>` for plurals.

| File | What |
|---|---|
| `strings.json` | Source of truth: `{key, en, area, themes[], placeholders[], plural?, note?}` for every NEW key |
| `<locale>.json` | `{key: translation}`; identical key set in all 11 files (`en.json` holds the English source) |
| `in-core.json` | Strings the themes use that core already translates in all 10 locales (do NOT redefine) |
| `core-fixes.json` | Optional: weak core translations worth correcting (never auto-merged) |

## Counts
- 573 new strings (8 plural), in areas: retail 112, services 87, pharmacy 85, grocery 84, kiosk 34, menu 31, checkout 27, cart 25, common 25, track 21, states 28, item 12, a11y 2. (326 from the theme specs; 229 promoted from the prototype generator on 2026-10-02; 9 for the book reschedule page, item 34; 7 for the hosted-payment states, item 39; 1 for the licence block, item 43; 1 for the store identity block, item 44, which also moved 3 licence labels from pharmacy to common; item 45 swapped "Expires soon" for "Shown in English" (carely Q8, Q23) and reworded the withdrawn-item state without the use-by date, so the totals did not change.)
- 160 strings already in core (`in-core.json`), including the plural `n_item`.
- 3 keys exist in core for `ar` only (`add_name`, `added_name`, `swap`); they are listed in `strings.json` with a `note`, and the merge fills the other locales.

## Merge (into `core/lang/<locale>/lang.php`)
Add only keys that are absent; never overwrite a core value. Same behaviour as `lang_register_missing_key()`:

```php
foreach (['en','ar','de','es','fr','hi','pt','ru','tr','ur','zh'] as $l) {
    $file = base_path("lang/$l/lang.php");
    $lines = include $file;
    $kit = json_decode(file_get_contents("<kit>/lang/$l.json"), true);
    foreach ($kit as $k => $v) { $lines[$k] ??= $v; }   // keep existing core values
    file_put_contents($file, "<?php\n\nreturn ".var_export($lines, true).";\n");
}
```
Merging `en.json` is optional (a missing en key already returns the English source), but it keeps the file complete for translators.

## Conventions
- Placeholders are `:name` style and are identical in every locale. Money always arrives as `:amount` (no currency in strings). Times/dates come pre-formatted by core.
- Plural values use positional trans_choice branches (Laravel picks the CLDR index per locale), as core's existing `n_item` does: ar 6 (zero|one|two|few|many|other), ru 3, de/es/fr/hi/pt/ur 2, tr/zh 1. English keeps the helper default `{1} ...|[2,*] ...`.
- Wording that was store-type specific in the designs ("salon", "kitchen" demo data) is generic where the string is shared by several store types: "At the venue", "Pay at the venue".
- Slugs that collided with a core key of a different meaning were avoided on purpose: `Order no. :number` (not `Order #:number`), `Pickup from :branch`, `Shipping via :method`, `This fits your :vehicle`, `On sale`, `Order these again`, `Fully booked`, `With the courier`, `Optional extras`.

## Check
`python3 tools/lang_check.py` (stdlib only, exit 1 on failure): key alignment, identical-to-English (small allow-list of units/loanwords), script per locale, Urdu-vs-Arabic, pt/es sniffing, placeholders, plural branch counts. `LANG_DIR=<dir>` points it at another copy.
