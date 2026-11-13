#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Checks kit/lang/*.json against kit/lang/strings.json. Exit 1 on any failure.

Run from anywhere:  python3 tools/lang_check.py
Python 3 stdlib only.

Checks
  1. Key alignment: every locale file has exactly the keys of strings.json
     (and strings.json keys are unique, equal Str::slug(en, '_') or n_<slug>).
  2. Identical-to-English: non-en value equal to English (allow-list below).
  3. Script / language: ar+ur Arabic script, ur Urdu letters and not a copy of ar,
     hi Devanagari, zh Han, ru Cyrillic, de/es/fr/pt/tr Latin, pt-vs-es sniffing.
  4. Placeholders: same :placeholder set as English in every translation.
  5. Plurals: trans_choice syntax; positional branch count per locale
     (ar 6, ru 3, de/es/fr/hi/pt/ur 2, tr/zh 1).
  6. Hygiene: no empty values, no stray whitespace, no "|" in non-plural values,
     no key that is already fully in core (kit/lang/in-core.json).
"""
import json
import os
import re
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LANG = Path(os.environ.get("LANG_DIR") or ROOT / "kit" / "lang")
LOCALES = ["en", "ar", "de", "es", "fr", "hi", "pt", "ru", "tr", "ur", "zh"]
TRANSLATED = [l for l in LOCALES if l != "en"]

# Values that may equal the English text: pure placeholder/unit strings and loanwords.
# {key: set(locales)}
IDENTICAL_OK = {
    "kcal_kcal": {"de", "es", "fr", "hi", "pt", "tr", "ur"},
    "amount_kg": {"de", "es", "fr", "pt", "tr"},
    "minutes_min": {"es", "fr", "pt"},
    "total_method": {"es", "fr", "pt"},
    "express": {"de", "fr"},
    "express_minutes_min": {"fr"},
    "menu": {"fr"},
}

# Latin tokens allowed inside non-Latin locales (brand names, units, acronyms).
LATIN_OK = {
    "apple", "pay", "tabby", "tamara", "juwri", "qr", "sms", "kcal", "kg", "km", "mada",
    "visa", "mastercard", "whatsapp", "vat", "s",
}

PH = re.compile(r":[a-z_]+")
ARABIC = re.compile(r"[؀-ۿݐ-ݿ]")
DEVA = re.compile(r"[ऀ-ॿ]")
HAN = re.compile(r"[一-鿿]")
CYR = re.compile(r"[Ѐ-ӿ]")
LATIN_LETTER = re.compile(r"[A-Za-zÀ-ɏ]")
URDU_ONLY = set("ےںٹڈڑہی")  # ے ں ٹ ڈ ڑ ہ ی
ARABIC_ONLY = set("يكة")  # ي ك ة (Arabic forms Urdu does not use)
URDU_LETTERS_NOT_IN_AR = set("ےںٹڈڑہکیھ")  # ے ں ٹ ڈ ڑ ہ + Persian/Urdu forms ک ی ھ

PLURAL_BRANCHES = {"ar": 6, "ru": 3, "de": 2, "es": 2, "fr": 2, "hi": 2, "pt": 2, "ur": 2, "tr": 1, "zh": 1}

errors = []
warnings = []


def err(msg):
    errors.append(msg)


def warn(msg):
    warnings.append(msg)


def slug(text):
    """Laravel Str::slug($text, '_') for the characters used in kit strings."""
    t = text.replace("×", "x").replace("−", "-").replace("–", "-").replace("—", "-")
    t = unicodedata.normalize("NFKD", t)
    t = "".join(c for c in t if not unicodedata.combining(c))
    t = re.sub(r"-+", "_", t).replace("@", "_at_").lower()
    t = re.sub(r"[^_\w\s]", "", t)
    return re.sub(r"[\s_]+", "_", t).strip("_")


def load(name):
    with open(LANG / name, encoding="utf-8") as f:
        return json.load(f)


def strip_ph(s):
    return PH.sub(" ", s)


def words_latin(s):
    return re.findall(r"[A-Za-z]+", strip_ph(s))


def main():
    strings = load("strings.json")
    keys = [s["key"] for s in strings]
    key_set = set(keys)
    by_key = {s["key"]: s for s in strings}

    # ---- 1. alignment -------------------------------------------------
    if len(keys) != len(key_set):
        err("strings.json has duplicate keys: " + ", ".join(sorted({k for k in keys if keys.count(k) > 1})))
    data = {}
    for loc in LOCALES:
        p = LANG / f"{loc}.json"
        if not p.exists():
            err(f"missing file {p.name}")
            continue
        data[loc] = load(p.name)
        missing = key_set - set(data[loc])
        extra = set(data[loc]) - key_set
        if missing:
            err(f"{loc}: {len(missing)} missing keys, e.g. {sorted(missing)[:5]}")
        if extra:
            err(f"{loc}: {len(extra)} extra keys, e.g. {sorted(extra)[:5]}")
    for s in strings:
        k, en = s["key"], s["en"]
        if s.get("plural"):
            if not k.startswith("n_"):
                err(f"{k}: plural key must start with n_")
            if not re.match(r"^\{1\} .+\|\[2,\*\] .+$", en):
                err(f"{k}: English plural must be '{{1}} ...|[2,*] ...' got {en!r}")
            if en.count("|") != 1:
                err(f"{k}: English plural must have exactly one '|'")
            if k != "n_" + slug(re.sub(r"^\{1\} :count ", "", en.split("|")[0]).replace("Only :count ", "")) and not k.startswith("n_"):
                err(f"{k}: unexpected plural key")
        else:
            if k != slug(en):
                err(f"{k}: key is not Str::slug(en) -> {slug(en)!r}")
        for f in ("key", "en", "area", "themes"):
            if f not in s:
                err(f"{k}: strings.json entry lacks {f}")
        if data.get("en", {}).get(k) not in (None, en):
            err(f"{k}: en.json differs from strings.json")
    try:
        in_core = {r["key"] for r in load("in-core.json")}
        clash = in_core & key_set
        if clash:
            err(f"keys both in strings.json and in-core.json: {sorted(clash)[:5]}")
    except FileNotFoundError:
        warn("in-core.json not found")

    if errors:
        return finish()

    # ---- 2. identical to English --------------------------------------
    identical_flagged = []
    identical_allowed = []
    for loc in TRANSLATED:
        for k in keys:
            en = by_key[k]["en"]
            v = data[loc][k]
            if v == en:
                if loc in IDENTICAL_OK.get(k, ()):
                    identical_allowed.append((loc, k))
                else:
                    identical_flagged.append((loc, k, v))
    for loc, k, v in identical_flagged:
        err(f"{loc}.{k}: identical to English: {v!r}")

    # ---- 3. script / language -----------------------------------------
    for loc in TRANSLATED:
        for k in keys:
            v = data[loc][k]
            en = by_key[k]["en"]
            allowed_same = loc in IDENTICAL_OK.get(k, ())
            body = strip_ph(v)
            if loc in ("ar", "ur"):
                if not ARABIC.search(body) and not allowed_same:
                    err(f"{loc}.{k}: no Arabic-script letters: {v!r}")
                if CYR.search(body) or DEVA.search(body) or HAN.search(body):
                    err(f"{loc}.{k}: foreign script: {v!r}")
                for w in words_latin(v):
                    if w.lower() not in LATIN_OK and not allowed_same:
                        err(f"{loc}.{k}: stray Latin word {w!r}: {v!r}")
            elif loc == "hi":
                if not DEVA.search(body) and not allowed_same:
                    err(f"hi.{k}: no Devanagari: {v!r}")
                if ARABIC.search(body) or CYR.search(body) or HAN.search(body):
                    err(f"hi.{k}: foreign script: {v!r}")
                for w in words_latin(v):
                    if w.lower() not in LATIN_OK and not allowed_same:
                        err(f"hi.{k}: stray Latin word {w!r}: {v!r}")
            elif loc == "zh":
                if not HAN.search(body) and not allowed_same:
                    err(f"zh.{k}: no Han characters: {v!r}")
                if ARABIC.search(body) or CYR.search(body) or DEVA.search(body):
                    err(f"zh.{k}: foreign script: {v!r}")
                for w in words_latin(v):
                    if w.lower() not in LATIN_OK and not allowed_same:
                        err(f"zh.{k}: stray Latin word {w!r}: {v!r}")
            elif loc == "ru":
                if not CYR.search(body) and not allowed_same:
                    err(f"ru.{k}: no Cyrillic: {v!r}")
                if ARABIC.search(body) or DEVA.search(body) or HAN.search(body):
                    err(f"ru.{k}: foreign script: {v!r}")
                for w in words_latin(v):
                    if w.lower() not in LATIN_OK and not allowed_same:
                        err(f"ru.{k}: stray Latin word {w!r}: {v!r}")
            else:  # de es fr pt tr
                if ARABIC.search(body) or DEVA.search(body) or HAN.search(body) or CYR.search(body):
                    err(f"{loc}.{k}: non-Latin script: {v!r}")
                if not LATIN_LETTER.search(body) and re.search(r"\w", body) and not allowed_same:
                    err(f"{loc}.{k}: no Latin letters: {v!r}")

    # ar / ur letter hygiene
    for k in keys:
        a, u = data["ar"][k], data["ur"][k]
        if set(a) & URDU_LETTERS_NOT_IN_AR:
            err(f"ar.{k}: contains Urdu-only letters: {a!r}")
        if set(u) & ARABIC_ONLY:
            err(f"ur.{k}: contains Arabic-only letters (ي ك ة): {u!r}")
    same = [k for k in keys if data["ar"][k] == data["ur"][k]]
    ratio = len(same) / len(keys)
    if ratio > 0.05:
        err(f"ur identical to ar for {len(same)} keys ({ratio:.1%} > 5%): {same[:8]}")
    # Urdu-specific letters in most sentences (values with 3+ Arabic-script words)
    sentences = [k for k in keys if len([w for w in strip_ph(data["ur"][k]).split() if ARABIC.search(w)]) >= 3]
    with_u = [k for k in sentences if set(data["ur"][k]) & URDU_ONLY]
    if sentences and len(with_u) / len(sentences) < 0.9:
        bad = [k for k in sentences if k not in with_u]
        err(f"ur: only {len(with_u)}/{len(sentences)} sentences use Urdu letters; e.g. {bad[:5]}")
    elif sentences:
        no_u = [k for k in sentences if k not in with_u]
        if no_u:
            warn(f"ur: sentences without Urdu-specific letters: {no_u}")

    # pt looks Spanish / es looks Portuguese
    pt_sus, es_sus = [], []
    for k in keys:
        pv = " " + data["pt"][k].lower() + " "
        ev = " " + data["es"][k].lower() + " "
        if re.search(r"[ñ¿¡]|\s(el|los|las|una?|del|para el)\s|ción\b|\bpor favor\b", pv):
            pt_sus.append((k, data["pt"][k]))
        if re.search(r"[çãõ]|ão\b|\b(você|não|uma|os|às|ções)\b", ev):
            es_sus.append((k, data["es"][k]))
    for k, v in pt_sus:
        err(f"pt.{k}: looks Spanish: {v!r}")
    for k, v in es_sus:
        err(f"es.{k}: looks Portuguese: {v!r}")

    # ---- 4. placeholders ----------------------------------------------
    for k in keys:
        en_ph = set(PH.findall(by_key[k]["en"]))
        for loc in TRANSLATED:
            ph = set(PH.findall(data[loc][k]))
            if ph != en_ph:
                err(f"{loc}.{k}: placeholders {sorted(ph)} != English {sorted(en_ph)}")

    # ---- 5. plurals ---------------------------------------------------
    for s in strings:
        k = s["key"]
        if not s.get("plural"):
            for loc in TRANSLATED:
                if "|" in data[loc][k]:
                    err(f"{loc}.{k}: '|' in a non-plural value")
            continue
        for loc in TRANSLATED:
            segs = data[loc][k].split("|")
            want = PLURAL_BRANCHES[loc]
            if len(segs) != want:
                err(f"{loc}.{k}: {len(segs)} plural branches, expected {want}")
            for i, seg in enumerate(segs):
                if not seg.strip():
                    err(f"{loc}.{k}: empty plural branch {i}")
                # branches must carry :count, except Arabic zero/one/two words
                if ":count" not in seg and not (loc == "ar" and i <= 2):
                    err(f"{loc}.{k}: branch {i} lacks :count: {seg!r}")
            if loc == "ar" and len(segs) == 6:
                for i in (1, 2):
                    if ":count" in segs[i]:
                        warn(f"ar.{k}: branch {i} (one/two) uses :count, normally a word")
            if re.search(r"\{\d|\[\d", data[loc][k]):
                err(f"{loc}.{k}: mixes explicit ranges with positional branches")

    # ---- 6. hygiene ---------------------------------------------------
    for loc in TRANSLATED:
        for k in keys:
            v = data[loc][k]
            if not isinstance(v, str) or not v.strip():
                err(f"{loc}.{k}: empty")
            elif v != v.strip():
                err(f"{loc}.{k}: leading/trailing whitespace: {v!r}")
            elif "  " in v:
                err(f"{loc}.{k}: double space: {v!r}")

    summary = {
        "strings": len(keys),
        "plurals": sum(1 for s in strings if s.get("plural")),
        "identical_allowed": len(identical_allowed),
        "ur_equals_ar": len(same),
    }
    return finish(summary, identical_allowed)


def finish(summary=None, identical_allowed=None):
    if identical_allowed:
        print("identical-to-English (allow-listed):")
        for loc, k in identical_allowed:
            print(f"  {loc}.{k}")
    for w in warnings:
        print("WARN ", w)
    for e in errors:
        print("FAIL ", e)
    if summary:
        print("summary:", json.dumps(summary))
    print("RESULT:", "FAIL" if errors else "PASS", f"({len(errors)} errors, {len(warnings)} warnings)")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
