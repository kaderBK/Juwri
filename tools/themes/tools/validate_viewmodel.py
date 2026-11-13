#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Validate view-model fixtures against kit/viewmodel/viewmodel.schema.json.

Stdlib only; supports the JSON Schema subset the schema uses: type (incl. lists),
enum, required, properties, additionalProperties (bool or schema), items, $ref
to #/$defs. Unknown keywords (description, $id, title) are ignored.

Run: python3 tools/validate_viewmodel.py [fixture.json ...]   (default: all fixtures)
"""
import json, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SCHEMA = json.loads((ROOT / "kit/viewmodel/viewmodel.schema.json").read_text())
TYPES = {"object": dict, "array": list, "string": str, "boolean": bool, "null": type(None)}

def type_ok(v, t):
    if t == "integer": return isinstance(v, int) and not isinstance(v, bool)
    if t == "number": return isinstance(v, (int, float)) and not isinstance(v, bool)
    return isinstance(v, TYPES[t])

def check(v, s, path, errs):
    if "$ref" in s:
        s = {**SCHEMA["$defs"][s["$ref"].split("/")[-1]], **{k: x for k, x in s.items() if k != "$ref"}}
    t = s.get("type")
    if t:
        ts = t if isinstance(t, list) else [t]
        if not any(type_ok(v, x) for x in ts):
            errs.append(f"{path}: expected {t}, got {type(v).__name__}"); return
    if "enum" in s and v not in s["enum"]:
        errs.append(f"{path}: {v!r} not in {s['enum']}")
    if isinstance(v, dict):
        for r in s.get("required", []):
            if r not in v: errs.append(f"{path}: missing '{r}'")
        props = s.get("properties", {})
        ap = s.get("additionalProperties", True)
        for k, x in v.items():
            if k in props: check(x, props[k], f"{path}.{k}", errs)
            elif ap is False: errs.append(f"{path}: unexpected key '{k}'")
            elif isinstance(ap, dict): check(x, ap, f"{path}.{k}", errs)
    if isinstance(v, list) and "items" in s:
        for i, x in enumerate(v): check(x, s["items"], f"{path}[{i}]", errs)

def main(paths):
    paths = [pathlib.Path(p) for p in paths] or sorted((ROOT / "kit/viewmodel/fixtures").glob("*.json"))
    bad = 0
    for p in paths:
        errs = []
        check(json.loads(p.read_text()), SCHEMA, "$", errs)
        # cross-checks the schema can't express
        d = json.loads(p.read_text())
        items = d.get("catalog", {}).get("items", {})
        for c in d.get("catalog", {}).get("categories", []):
            for i in c["item_ids"]:
                if i not in items: errs.append(f"category {c['id']}: unknown item {i}")
        for i, it in items.items():
            if it["id"] != i: errs.append(f"items.{i}: id mismatch")
            if "price" not in it and "from_price" not in it: errs.append(f"items.{i}: needs price or from_price")
        print(f"{p.name}: {'OK' if not errs else str(len(errs)) + ' errors'}")
        for e in errs[:30]: print("  ", e)
        bad += len(errs)
    sys.exit(1 if bad else 0)

if __name__ == "__main__":
    main(sys.argv[1:])
