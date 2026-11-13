#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Check kit/api/openapi.json: valid JSON, OpenAPI 3.1 basics, and every $ref resolves.

Follows refs into other files (e.g. ../viewmodel/viewmodel.schema.json#/$defs/Money) and
checks the refs inside those files too. Owner decisions (README §8): every operation lists its
plan features in `x-plan-features` (API-5; kiosk operations need both `kiosk_api` and `kiosk`, API-13), legacy aliases are `deprecated` with Deprecation and
Sunset headers (API-4) and a frozen (`x-frozen`) response schema, and no text still says "question for owner".
Decisions API-20…24 (item 40): option group ids are `OptionGroupId` (UUIDv7) wherever a group is referenced, a
late payment is the `received_late` payment state, the substitution choice is an option id (no fixed enum), the
store exposes "Release holds during payment" (off by default) and `releaseHold` documents 200 `HoldReleased` and
409. Decisions API-25…29 (item 41): every substitution option carries one fixed picker action (`PickerAction`
enum, store and item), the release setting names booking-deposit holds, a late payment accepted without a free
slot is `pending_approval` (orders and bookings), late-payment refunds are outside D7, the customer-release
message setting is documented on HoldReleased (core only, not in the store payload). Decisions API-30…34 (item 42):
a `call_customer` option carries a no-answer `fallback` (`offer_replacement` or `remove_item`), a line removed after
capture is refunded outside D7, a late card hold that lapses in `pending_approval` cancels the order with the hold
voided (`authorisation_expires_at`), and info.version is 1.7.0-draft. Python 3 stdlib only.
Exit 1 on any problem.
"""
import json
import sys
from pathlib import Path
from urllib.parse import unquote

HERE = Path(__file__).resolve().parent
SPEC = HERE / "openapi.json"
METHODS = {"get", "put", "post", "delete", "patch", "head", "options", "trace"}

docs: dict[Path, object] = {}
errors: list[str] = []


def load(path: Path):
    if path not in docs:
        try:
            docs[path] = json.loads(path.read_text(encoding="utf-8"))
        except FileNotFoundError:
            docs[path] = None
        except json.JSONDecodeError as e:
            errors.append(f"{path}: invalid JSON: {e}")
            docs[path] = None
    return docs[path]


def pointer(doc, frag: str):
    if frag in ("", "/"):
        return doc
    node = doc
    for part in frag.lstrip("/").split("/"):
        part = unquote(part).replace("~1", "/").replace("~0", "~")
        if isinstance(node, dict) and part in node:
            node = node[part]
        elif isinstance(node, list) and part.isdigit() and int(part) < len(node):
            node = node[int(part)]
        else:
            raise KeyError(part)
    return node


def walk_refs(node, base: Path, seen: set, trail: str):
    if isinstance(node, dict):
        ref = node.get("$ref")
        if isinstance(ref, str):
            file_part, _, frag = ref.partition("#")
            target = (base.parent / file_part).resolve() if file_part else base
            doc = load(target)
            if doc is None:
                errors.append(f"{trail}: $ref {ref!r}: file not found or invalid ({target})")
            else:
                try:
                    pointer(doc, frag)
                except KeyError as k:
                    errors.append(f"{trail}: $ref {ref!r}: no {k} in {target.name}")
                if target != base and target not in seen:
                    seen.add(target)
                    walk_refs(doc, target, seen, target.name)
        for k, v in node.items():
            walk_refs(v, base, seen, f"{trail}/{k}")
    elif isinstance(node, list):
        for i, v in enumerate(node):
            walk_refs(v, base, seen, f"{trail}/{i}")


PLAN_FEATURES = {"storefront_api", "booking_api", "kiosk_api", "kiosk"}
KIOSK_FEATURES = {"kiosk_api", "kiosk"}  # API-13: both, kiosk_api does not imply kiosk


def check_decisions(spec, path: str, method: str, op: dict):
    """Owner decisions API-4 and API-5 (README §8) stay visible in the spec."""
    where = f"{method.upper()} {path}"
    feats = op.get("x-plan-features")
    if not isinstance(feats, list) or not set(feats) <= PLAN_FEATURES:
        errors.append(f"{where}: x-plan-features must list keys from {sorted(PLAN_FEATURES)}")
    elif any("StorefrontToken" in s for s in op.get("security", [])) and "storefront_api" not in feats:
        errors.append(f"{where}: token-authenticated operation must need storefront_api")
    else:
        kiosk_op = any("kiosk" in s.get("StorefrontToken", []) for s in op.get("security", []))
        if kiosk_op and not KIOSK_FEATURES <= set(feats):
            errors.append(f"{where}: kiosk-token operation must list both kiosk_api and kiosk (API-13)")
        if not kiosk_op and set(feats) & KIOSK_FEATURES:
            errors.append(f"{where}: kiosk_api / kiosk only on kiosk-token operations (other calls get them from the scheme, API-13)")
    if "Legacy aliases" in op.get("tags", []):
        if op.get("deprecated") is not True:
            errors.append(f"{where}: legacy alias must be deprecated: true (API-4)")
        ok = [r for c, r in op.get("responses", {}).items() if c.startswith("2")]
        for r in ok:
            if not {"Deprecation", "Sunset"} <= set(r.get("headers", {})):
                errors.append(f"{where}: legacy alias 2xx needs Deprecation and Sunset headers (API-4)")
            sch = r.get("content", {}).get("application/json", {}).get("schema", {})
            if "$ref" in sch:
                try:
                    sch = pointer(spec, sch["$ref"].partition("#")[2])
                except KeyError:
                    sch = {}
            if sch.get("x-frozen") is not True:
                errors.append(f"{where}: legacy alias 2xx needs a response schema marked x-frozen (today's shape)")
    text = json.dumps(op).lower()
    if "question for the owner" in text or "question for owner" in text:
        errors.append(f"{where}: still says 'question for owner' (decided, README §8)")


def check_item40(spec):
    """Owner decisions API-20…24 (README §8, item 40) stay visible in the spec."""
    sc = spec.get("components", {}).get("schemas", {})
    G = "#/components/schemas/OptionGroupId"
    O = "#/components/schemas/OptionId"
    try:
        if sc["OptionGroupId"].get("pattern") != sc["OptionId"].get("pattern"):
            errors.append("OptionGroupId must be a UUIDv7 like OptionId (API-20)")
        if sc["Variant"]["properties"]["options"].get("propertyNames", {}).get("$ref") != G:
            errors.append("Variant.options keys must be OptionGroupId (API-20)")
        if sc["SellRule"]["properties"]["weight_option_group"].get("$ref") != G:
            errors.append("SellRule.weight_option_group must be OptionGroupId (API-20)")
        if sc["ItemResponse"]["properties"]["substitution_group_id"].get("$ref") != G:
            errors.append("ItemResponse.substitution_group_id must be OptionGroupId (API-20, API-22)")
        for name, prop in (("CartLine", "substitution"), ("CartRequest", "substitution_preference")):
            p = sc[name]["properties"][prop]
            if p.get("$ref") != O or "enum" in p:
                errors.append(f"{name}.{prop} must be an OptionId of a store-defined group, not a fixed enum (API-22)")
        if "received_late" not in sc["PaymentState"]["properties"]["status"]["enum"]:
            errors.append("PaymentState.status needs received_late (late payment, API-21)")
        rel = sc["StoreResponse"]["properties"]["holds"]["properties"]["release_during_payment"]
        if rel.get("default") is not False:
            errors.append("StoreResponse.holds.release_during_payment must default to false (API-24a)")
        op = spec["paths"]["/api/v1/storefront/holds/{hold_id}"]["delete"]
        r200 = op["responses"]["200"]["content"]["application/json"]["schema"].get("$ref")
        if r200 != "#/components/schemas/HoldReleased" or "409" not in op["responses"]:
            errors.append("releaseHold must document 200 HoldReleased (setting on) and 409 hold_in_use (setting off), API-24a")
    except KeyError as k:
        errors.append(f"item 40 decisions: missing {k} (API-20…24)")


def check_item41(spec):
    """Owner decisions API-25…29 (README §8, item 41) stay visible in the spec."""
    sc = spec.get("components", {}).get("schemas", {})
    A = "#/components/schemas/SubstitutionPickerAction"
    try:
        if sc["PickerAction"].get("enum") != ["offer_replacement", "call_customer", "remove_item"]:
            errors.append("PickerAction must be exactly offer_replacement, call_customer, remove_item (API-25)")
        spa = sc["SubstitutionPickerAction"]
        if spa["properties"]["action"].get("$ref") != "#/components/schemas/PickerAction" \
                or spa["properties"]["option_id"].get("$ref") != "#/components/schemas/OptionId" \
                or set(spa.get("required", [])) != {"option_id", "action"} or spa.get("additionalProperties") is not False:
            errors.append("SubstitutionPickerAction must be a closed {option_id: OptionId, action: PickerAction} (API-25)")
        sub = sc["StoreResponse"]["properties"]["substitution"]
        if "picker_actions" not in sub.get("required", []) or sub["properties"]["picker_actions"]["items"].get("$ref") != A:
            errors.append("StoreResponse.substitution must require picker_actions (API-25)")
        if sc["ItemResponse"]["properties"]["substitution_picker_actions"]["items"].get("$ref") != A:
            errors.append("ItemResponse.substitution_picker_actions must list SubstitutionPickerAction (API-25)")
        rel = sc["StoreResponse"]["properties"]["holds"]["properties"]["release_during_payment"]["description"]
        if "API-26" not in rel or "booking" not in rel:
            errors.append("release_during_payment must say it covers booking-deposit holds (API-26)")
        if "pending_approval" not in sc["BookingRef"]["properties"]["status"]["enum"] \
                or "pending_approval" not in sc["OrderRef"]["properties"]["status"]["enum"]:
            errors.append("OrderRef and BookingRef status need pending_approval (late payment accepted, no free slot, API-27)")
        if "API-27" not in sc["PaymentState"]["description"] or "API-28" not in sc["PaymentState"]["properties"]["refunded_amount"]["description"]:
            errors.append("PaymentState must document API-27 (accept) and API-28 (late refunds outside D7)")
        if "API-29" not in sc["HoldReleased"].get("description", ""):
            errors.append("HoldReleased must say when the customer-release message is sent (core notification setting, API-29)")
    except KeyError as k:
        errors.append(f"item 41 decisions: missing {k} (API-25…29)")


def check_item42(spec):
    """Owner decisions API-30…34 (README §8, item 42) stay visible in the spec."""
    sc = spec.get("components", {}).get("schemas", {})
    try:
        spa = sc["SubstitutionPickerAction"]
        fb = spa["properties"].get("fallback") or {}
        if fb.get("enum") != ["offer_replacement", "remove_item"] or "remove_item" not in fb.get("description", ""):
            errors.append("SubstitutionPickerAction.fallback must be offer_replacement | remove_item, default remove_item (API-30)")
        if spa.get("if") != {"required": ["action"], "properties": {"action": {"const": "call_customer"}}} \
                or spa.get("then") != {"required": ["fallback"]} or spa.get("else") != {"not": {"required": ["fallback"]}}:
            errors.append("SubstitutionPickerAction: fallback required with call_customer and absent otherwise (API-30)")
        ps = sc["PaymentState"]
        if "API-31" not in ps["properties"]["refunded_amount"]["description"] or "API-31" not in sc["PickerAction"]["description"]:
            errors.append("PaymentState.refunded_amount and PickerAction must say a line removed after capture is refunded outside D7 (API-31)")
        exp = ps["properties"].get("authorisation_expires_at") or {}
        if exp.get("format") != "date-time" or "API-32" not in exp.get("description", "") \
                or "API-32" not in ps["description"] or "API-32" not in sc["OrderRef"]["properties"]["status"]["description"]:
            errors.append("PaymentState.authorisation_expires_at and the pending_approval -> cancelled / voided rule must be documented (API-32)")
        if spec["info"].get("version") != "1.7.0-draft":
            errors.append("info.version must be 1.7.0-draft (item 42)")
    except KeyError as k:
        errors.append(f"item 42 decisions: missing {k} (API-30…34)")


def main() -> int:
    spec = load(SPEC)
    if spec is None:
        print(f"FAIL: cannot read {SPEC}")
        return 1
    if not str(spec.get("openapi", "")).startswith("3.1"):
        errors.append("openapi must be 3.1.x")
    for key in ("info", "paths", "components"):
        if key not in spec:
            errors.append(f"missing top-level {key}")
    op_ids, ops = set(), 0
    for path, item in spec.get("paths", {}).items():
        if not path.startswith("/"):
            errors.append(f"path {path!r} must start with /")
        for method, op in item.items():
            if method not in METHODS:
                continue
            ops += 1
            oid = op.get("operationId")
            if not oid or oid in op_ids:
                errors.append(f"{method.upper()} {path}: missing or duplicate operationId {oid!r}")
            op_ids.add(oid)
            if not op.get("responses"):
                errors.append(f"{method.upper()} {path}: no responses")
            # every {param} in the path needs a path parameter
            names = {seg[1:-1] for seg in path.split("/") if seg.startswith("{") and seg.endswith("}")}
            declared = set()
            for prm in op.get("parameters", []):
                if "$ref" in prm:
                    try:
                        prm = pointer(spec, prm["$ref"].partition("#")[2])
                    except KeyError:
                        continue
                if prm.get("in") == "path":
                    declared.add(prm.get("name"))
            for n in names - declared:
                errors.append(f"{method.upper()} {path}: path parameter {{{n}}} not declared")
            check_decisions(spec, path, method, op)
    walk_refs(spec, SPEC, {SPEC}, "openapi.json")
    check_item40(spec)
    check_item41(spec)
    check_item42(spec)
    sft = spec.get("components", {}).get("securitySchemes", {}).get("StorefrontToken", {})
    if set(sft.get("x-kiosk-plan-features", [])) != KIOSK_FEATURES:
        errors.append("StorefrontToken: x-kiosk-plan-features must be kiosk_api + kiosk (every kiosk-token call, API-13)")
    if "question for" in json.dumps(spec.get("components", {})).lower():
        errors.append("components: still say 'question for owner' (decided, README §8)")
    if errors:
        for e in errors:
            print("FAIL:", e)
        return 1
    print(f"OK: {len(spec['paths'])} paths, {ops} operations, all $refs resolve")
    return 0


if __name__ == "__main__":
    sys.exit(main())
