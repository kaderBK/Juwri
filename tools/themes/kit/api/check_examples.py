#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Check the example-payload pack in kit/api/examples against kit/api/openapi.json.

Every example file is {"operationId", "request": {method, path, headers, query?, body?},
"response": {status, headers?, body}}. For each one this resolves the operation by operationId and checks:

  * method + path match the operation's path template (and no other, more specific, operation);
  * required header, query and path parameters are present and valid; query names are declared;
  * the request body validates against the operation's requestBody schema;
  * the response status is documented, declared response headers are present (Deprecation / Sunset /
    Link on the deprecated aliases, Retry-After on 429, ETag / Cache-Control where the spec declares
    them), and the response body validates against the response schema;
  * README rules: Cache-Control per endpoint family, error code <-> HTTP status table (README §6),
    `details.feature` on the *_api_not_enabled 403s, `details.next_free_slot` on the slot 409s,
    UUIDv7 public ids (option ids too, API-18), 10-minute holds for every slot type (API-11),
    `reschedule_deadline_at` = `cancel_deadline_at` (API-9), hosted `next_action.expires_at` = Date + 15 min (API-10),
    no `phone_verification_required` / `verified_phone` for a kiosk token (API-15), UUIDv7 option group ids
    (API-20), a late payment (`received_late`) only on a cancelled order or booking without next_action (API-21),
    store-defined substitution options (API-22), `releaseHold` answering 200 `HoldReleased` with a cancelled
    order or booking (API-24a), exactly one valid picker action per substitution option, in the store's group
    and on the item (API-25), a no-answer `fallback` (`offer_replacement` or `remove_item`) on every `call_customer`
    option and on no other (API-30), and `authorisation_expires_at` only on an `authorised` or `voided` payment (API-32).

Then, per flow (a directory, files in name order):

  * every Quote has the design total of its theme (table 140.27 since P6-271, brew 39.10, basket 75.85, aisle 368.00,
    book 180.00) and passes the totals-parity rules of tools/conformance/totals_parity.py (lines sum to the
    subtotal, rows combine to the total, deposit + due_later = total, Money.formatted agrees with minor);
  * order and booking submits echo the last quote_hash, hold id and X-Hold-Token; hold slots come from the
    listed slots; track / manage tokens come from the create response; hosted vs pay_later payment state
    is consistent; the same Idempotency-Key with the same body replays, with another body is a 409;
    `CartLine.substitution` is an option of the item's substitution group and `substitution_preference` an option
    of the store's group (API-22); `releaseHold` answers 200 only when the flow's store has "Release holds during
    payment" on and 409 `hold_in_use` only when it is off (API-24a), for order and booking-deposit holds alike, and a
    200 cancels the record the hold was bound to (API-26); the order a release cancelled can be polled; after a late
    payment the same record either goes ahead (`placed` / `confirmed`, payment `paid` or `authorised`) or waits as
    `pending_approval` with the money accepted (API-27); `pending_approval` on a booking only after a late payment;
    an accepted card hold still `pending_approval` when its `authorisation_expires_at` has passed is cancelled
    automatically with the hold `voided` (API-32). The whole pack must show a booking release with the setting on
    (200) and off (409), both API-27 outcomes, and a late card hold that lapsed in `pending_approval` (API-32).

Schema keywords supported (the ones openapi.json and the View Model use): $ref (also into other files,
relative to the file holding the ref), type (lists too), enum, const, required, properties,
additionalProperties, propertyNames, items, minItems, maxItems, minimum, maximum, minLength, maxLength, pattern, oneOf,
anyOf, allOf, not, if/then/else, and a light `format` check (date-time with offset, date, uri, uuid, email).
When several oneOf branches match, that is an ERROR (the spec's branches overlap); --lenient-oneof turns it
into a warning (--strict-oneof is accepted and is the default since spec 1.3.0-draft).

Spec rules OpenAPI cannot express, enforced here: createOrder needs Idempotency-Key with the new body, while
the deprecated legacy body may send `client_uuid` instead (or nothing, as today); a quote lists `return_url`
in `requires` exactly when the request's `payment_mode` is `hosted`; `weighed.estimate` is the sum of the
weighed (`estimate: true`) lines and `weighed.cap` = estimate x (1 + cap_percent / 100); a refunded payment
carries `refunded_amount` = `amount_now`; a cancelled booking with a paid deposit reports it `refunded` or `kept`;
a reschedule uses a hold tied to that booking (`X-Manage-Token`, `Hold.booking_id`) and moves no money (API-8); a booking
whose quote required `verified_phone` is accepted only with the customer token from `verifyCustomerOtp` (API-14).
Kiosk tokens are recognised by `_kiosk_` in the example token value.

Run:  python3 kit/api/check_examples.py [--self-test] [--lenient-oneof] [--quiet] [example.json ...]
Python 3 stdlib only. Exit 1 on any failure.
"""
import copy
import importlib.util
import json
import re
import sys
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from urllib.parse import unquote

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
SPEC_PATH = HERE / "openapi.json"
EXAMPLES = HERE / "examples"
METHODS = {"get", "put", "post", "delete", "patch", "head", "options", "trace"}

# Design totals (minor units) of the demo stores: kit/viewmodel/fixtures/*.{checkout,cart,track}.json.
# flow -> (theme, total minor, tax mode)
FLOW_THEMES = {
    "food": ("table", 14027, "exclusive"),  # P6-271: tax after the WELCOME10 discount (was 14210)
    "food-pickup": ("brew", 3910, "exclusive"),
    "grocery": ("basket", 7585, "inclusive"),
    "retail": ("aisle", 36800, "inclusive"),
    "services": ("book", 18000, "inclusive"),
    "services-otp": ("book", 18000, "inclusive"),
    "kiosk-services": ("book", 18000, "inclusive"),
}
CURRENCY = {"code": "SAR", "exponent": 2}

UUID7 = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
DATETIME = re.compile(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:\d\d)$")
DATE = re.compile(r"^\d{4}-\d\d-\d\d$")
ID_KEYS = {"public_id", "item_id", "variant_id", "hold_id", "pickup_branch_id", "booking_id", "reschedule_of"}
PAYMENT_WINDOW_S = 900  # API-10: hosted payment window, 15 minutes

# README §6: error code -> HTTP status
ERROR_STATUS = {
    "unauthenticated": 401, "forbidden": 403,
    "storefront_api_not_enabled": 403, "booking_api_not_enabled": 403, "kiosk_api_not_enabled": 403,
    "feature_disabled": 403, "kiosk_not_enabled": 403, "phone_verification_required": 403,
    "not_found": 404, "quote_changed": 409, "idempotency_conflict": 409,
    "slot_taken": 409, "slot_full": 409, "hold_expired": 409, "hold_in_use": 409, "price_changed": 409,
    "past_deadline": 409, "otp_invalid": 422,
    "booking_not_active": 409, "store_closed": 409, "bookings_not_open": 409,
    "validation_failed": 422, "out_of_zone": 422, "below_minimum": 422, "out_of_stock": 422,
    "invalid_order_type": 422, "payment_method_unavailable": 422, "payment_mode_unavailable": 422,
    "return_url_not_allowed": 422, "rate_limited": 429,
}
FEATURE_OF_CODE = {"storefront_api_not_enabled": "storefront_api", "booking_api_not_enabled": "booking_api",
                   "kiosk_api_not_enabled": "kiosk_api"}
NEXT_SLOT_CODES = {"slot_taken", "slot_full", "hold_expired"}
# README §2.10 caching, for 2xx responses
CACHE_PUBLIC120 = {"getStore", "getCatalog", "getItem"}
CACHE_PUBLIC30 = {"getItemVariants"}
CACHE_NOSTORE = {"getDeliverySlots", "getBookingDays", "getBookingSlots", "createHold", "quoteCart", "quoteShipping",
                 "createOrder", "getOrder", "createBooking", "getBooking", "cancelBooking", "rescheduleBooking",
                 "requestCustomerOtp", "verifyCustomerOtp", "releaseHold"}
DEPRECATION_HEADERS = ("Deprecation", "Sunset", "Link")

_docs = {}


def load(path):
    path = Path(path).resolve()
    if path not in _docs:
        _docs[path] = json.loads(path.read_text(encoding="utf-8"))
    return _docs[path]


SPEC = load(SPEC_PATH)


def pointer(doc, frag):
    node = doc
    if frag in ("", "/"):
        return node
    for part in frag.lstrip("/").split("/"):
        part = unquote(part).replace("~1", "/").replace("~0", "~")
        node = node[int(part)] if isinstance(node, list) else node[part]
    return node


def resolve(ref, base):
    """-> (schema node, file that holds it). Refs are relative to the file holding them."""
    file_part, _, frag = ref.partition("#")
    target = (base.parent / file_part).resolve() if file_part else base
    return pointer(load(target), frag), target


def deref(node, base):
    while isinstance(node, dict) and "$ref" in node and len(node) == 1:
        node, base = resolve(node["$ref"], base)
    return node, base


# ------------------------------------------------------------------ JSON Schema subset
def type_ok(v, t):
    if t == "integer":
        return isinstance(v, int) and not isinstance(v, bool)
    if t == "number":
        return isinstance(v, (int, float)) and not isinstance(v, bool)
    return {"object": isinstance(v, dict), "array": isinstance(v, list), "string": isinstance(v, str),
            "boolean": isinstance(v, bool), "null": v is None}[t]


def format_ok(v, fmt):
    if not isinstance(v, str):
        return True
    if fmt == "date-time":
        if not DATETIME.match(v):
            return False
        try:
            datetime.fromisoformat(v.replace("Z", "+00:00"))
        except ValueError:
            return False
        return True
    if fmt == "date":
        if not DATE.match(v):
            return False
        try:
            datetime.strptime(v, "%Y-%m-%d")
        except ValueError:
            return False
        return True
    if fmt == "uri":
        return bool(re.match(r"^https?://[^\s/]+\S*$", v))
    if fmt == "uuid":
        return bool(re.match(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", v))
    if fmt == "email":
        return bool(re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", v))
    return True


def validate(v, s, base, path, errs, warns, strict_oneof=False):
    if s is True or s == {}:
        return
    if s is False:
        errs.append(f"{path}: not allowed here")
        return
    if "$ref" in s:
        target, nb = resolve(s["$ref"], base)
        validate(v, target, nb, path, errs, warns, strict_oneof)
        s = {k: x for k, x in s.items() if k != "$ref"}
        if not s:
            return
    t = s.get("type")
    if t is not None:
        ts = t if isinstance(t, list) else [t]
        if not any(type_ok(v, x) for x in ts):
            errs.append(f"{path}: expected {t}, got {type(v).__name__}")
            return
    if "enum" in s and v not in s["enum"]:
        errs.append(f"{path}: {v!r} not in {s['enum']}")
    if "const" in s and v != s["const"]:
        errs.append(f"{path}: expected {s['const']!r}, got {v!r}")
    if "format" in s and not format_ok(v, s["format"]):
        errs.append(f"{path}: {v!r} is not a valid {s['format']}")
    if isinstance(v, str):
        if "pattern" in s and not re.search(s["pattern"], v):
            errs.append(f"{path}: {v!r} does not match {s['pattern']}")
        if "maxLength" in s and len(v) > s["maxLength"]:
            errs.append(f"{path}: longer than {s['maxLength']}")
        if "minLength" in s and len(v) < s["minLength"]:
            errs.append(f"{path}: shorter than {s['minLength']}")
    if isinstance(v, (int, float)) and not isinstance(v, bool):
        if "minimum" in s and v < s["minimum"]:
            errs.append(f"{path}: {v} < minimum {s['minimum']}")
        if "maximum" in s and v > s["maximum"]:
            errs.append(f"{path}: {v} > maximum {s['maximum']}")
    if isinstance(v, dict):
        for r in s.get("required", []):
            if r not in v:
                errs.append(f"{path}: missing required '{r}'")
        props = s.get("properties", {})
        ap = s.get("additionalProperties", True)
        if "propertyNames" in s:
            for k in v:
                validate(k, s["propertyNames"], base, f"{path}[key {k!r}]", errs, warns, strict_oneof)
        for k, x in v.items():
            if k in props:
                validate(x, props[k], base, f"{path}.{k}", errs, warns, strict_oneof)
            elif ap is False:
                errs.append(f"{path}: unexpected key '{k}'")
            elif isinstance(ap, dict):
                validate(x, ap, base, f"{path}.{k}", errs, warns, strict_oneof)
    if isinstance(v, list):
        if "minItems" in s and len(v) < s["minItems"]:
            errs.append(f"{path}: fewer than {s['minItems']} items")
        if "maxItems" in s and len(v) > s["maxItems"]:
            errs.append(f"{path}: more than {s['maxItems']} items")
        if "items" in s:
            for i, x in enumerate(v):
                validate(x, s["items"], base, f"{path}[{i}]", errs, warns, strict_oneof)
    for b in s.get("allOf", []):
        validate(v, b, base, path, errs, warns, strict_oneof)
    if "not" in s:
        e = []
        validate(v, s["not"], base, path, e, [], strict_oneof)
        if not e:
            errs.append(f"{path}: matches a schema it must not match (`not`)")
    for kw in ("oneOf", "anyOf"):
        if kw in s:
            results = []
            for b in s[kw]:
                e, w = [], []
                validate(v, b, base, path, e, w, strict_oneof)
                results.append((e, w))
            ok = [i for i, (e, _) in enumerate(results) if not e]
            if not ok:
                best = min(results, key=lambda r: len(r[0]))[0]
                errs.append(f"{path}: matches none of {kw}; closest branch: " + "; ".join(best[:3]))
            else:
                if kw == "oneOf" and len(ok) > 1:
                    msg = f"{path}: oneOf is ambiguous, {len(ok)} branches match (branches overlap in the spec)"
                    (errs if strict_oneof else warns).append(msg)
                for i in ok:
                    warns.extend(results[i][1])
    if "if" in s:
        e, w = [], []
        validate(v, s["if"], base, path, e, w, strict_oneof)
        if not e and "then" in s:
            validate(v, s["then"], base, path, errs, warns, strict_oneof)
        elif e and "else" in s:
            validate(v, s["else"], base, path, errs, warns, strict_oneof)


# ------------------------------------------------------------------ operations
def build_ops():
    ops = []
    for p, item in SPEC["paths"].items():
        rx = re.compile("^" + re.sub(r"\{[^}/]+\}", "([^/]+)", p) + "$")
        names = re.findall(r"\{([^}/]+)\}", p)
        for m, op in item.items():
            if m in METHODS:
                ops.append({"method": m.upper(), "template": p, "rx": rx, "names": names, "op": op,
                            "id": op["operationId"],
                            "specificity": len(re.sub(r"\{[^}/]+\}", "", p))})
    return ops


OPS = build_ops()
BY_ID = {o["id"]: o for o in OPS}


def lower(d):
    return {k.lower(): v for k, v in (d or {}).items()}


def coerce(v, schema):
    """Query and header values arrive as text; accept native JSON values too."""
    t = schema.get("type")
    if isinstance(v, str):
        if t == "integer" and re.fullmatch(r"-?\d+", v):
            return int(v)
        if t == "boolean" and v in ("true", "false"):
            return v == "true"
        if t == "array":
            return v.split(",")
    return v


def check_param(p, value, path_vals, query, headers, errs):
    schema, base = deref(p.get("schema", {}), SPEC_PATH)
    where, name = p["in"], p["name"]
    if where == "header":
        present, val = name.lower() in headers, headers.get(name.lower())
    elif where == "query":
        present, val = name in query, query.get(name)
    else:
        present, val = name in path_vals, path_vals.get(name)
    if not present:
        if p.get("required"):
            errs.append(f"missing required {where} parameter '{name}'")
        return
    val = coerce(val, schema)
    e = []
    validate(val, p.get("schema", {}), SPEC_PATH, f"{where} {name}", e, [])
    errs.extend(e)


def is_kiosk(headers):
    return "_kiosk_" in str(lower(headers).get("x-storefront-token", ""))


def option_id_errors(doc, side):
    """API-18 / API-20 / API-22: option, modifier and option group ids are UUIDv7 public ids, the substitution
    group included (its options are store-defined, not fixed values)."""
    out = []
    for path, n in walk(doc, side):
        if isinstance(n, dict):
            for s in n.get("option_ids") or []:
                if not (isinstance(s, str) and UUID7.match(s)):
                    out.append(f"{path}.option_ids: {s!r} is not a UUIDv7 option public_id (API-18)")
            groups = [g for g in n.get("option_groups") or [] if isinstance(g, dict)]
            for g in groups:
                if not UUID7.match(str(g.get("id"))):
                    out.append(f"{path}.option_groups: group id {g.get('id')!r} is not a UUIDv7 option group public_id (API-20)")
                for o in g.get("options") or []:
                    if not UUID7.match(str(o.get("id"))):
                        out.append(f"{path}.option_groups[{g.get('id')}]: option id {o.get('id')!r} is not a UUIDv7 (API-18)")
            if "sku" in n and isinstance(n.get("options"), dict):
                for gk, s in n["options"].items():
                    if not UUID7.match(str(gk)):
                        out.append(f"{path}.options: key {gk!r} is not a UUIDv7 option group public_id (API-20)")
                    if not UUID7.match(str(s)):
                        out.append(f"{path}.options.{gk}: {s!r} is not a UUIDv7 option public_id (API-18)")
            for k in ("substitution", "substitution_preference"):
                # request side only: the View Model Quote / QuoteLine carry display strings under the same names
                if side == "request" and isinstance(n.get(k), str) and ("item_id" in n or "lines" in n) and not UUID7.match(n[k]):
                    out.append(f"{path}.{k}: {n[k]!r} is not a UUIDv7 option public_id (store-defined options, API-22)")
            item = n.get("item") if isinstance(n.get("item"), dict) else None
            if item is not None:
                gids = {g.get("id") for g in item.get("option_groups") or [] if isinstance(g, dict)}
                wg = (n.get("sell") or {}).get("weight_option_group")
                if wg is not None and wg not in gids:
                    out.append(f"{path}.sell.weight_option_group: {wg!r} is not one of item.option_groups[].id (API-20)")
                sg = n.get("substitution_group_id")
                if sg is not None and sg not in gids:
                    out.append(f"{path}.substitution_group_id: {sg!r} is not one of item.option_groups[].id (API-22)")
            sub = n.get("substitution") if isinstance(n.get("substitution"), dict) else None
            if sub and isinstance(sub.get("group"), dict):
                g = sub["group"]
                if not UUID7.match(str(g.get("id"))):
                    out.append(f"{path}.substitution.group.id: {g.get('id')!r} is not a UUIDv7 option group public_id (API-20)")
                if sub.get("default_option_id") not in {o.get("id") for o in g.get("options") or []}:
                    out.append(f"{path}.substitution.default_option_id is not one of the group's options (API-22)")
                out.extend(picker_action_errors(g, sub.get("picker_actions"), f"{path}.substitution.picker_actions"))
            if item is not None:
                sg = n.get("substitution_group_id")
                acts = n.get("substitution_picker_actions")
                if (sg is None) != (acts is None):
                    out.append(f"{path}: substitution_picker_actions is present exactly when substitution_group_id is (API-25)")
                for g in item.get("option_groups") or []:
                    if sg is not None and isinstance(g, dict) and g.get("id") == sg:
                        out.extend(picker_action_errors(g, acts, f"{path}.substitution_picker_actions"))
    return out


PICKER_ACTIONS = ("offer_replacement", "call_customer", "remove_item")
PICKER_FALLBACKS = ("offer_replacement", "remove_item")


def picker_action_errors(group, actions, where):
    """API-25: every option of a substitution group maps to exactly one fixed picker action."""
    opts = [o.get("id") for o in group.get("options") or [] if isinstance(o, dict)]
    if not isinstance(actions, list):
        return [f"{where}: missing; every substitution option needs one picker action (API-25)"]
    out, seen = [], {}
    for a in actions:
        oid = a.get("option_id") if isinstance(a, dict) else None
        if oid in seen:
            out.append(f"{where}: option {oid!r} has more than one picker action (API-25)")
        seen[oid] = a.get("action") if isinstance(a, dict) else None
        if oid not in opts:
            out.append(f"{where}: {oid!r} is not an option of the group (API-25)")
        if seen[oid] not in PICKER_ACTIONS:
            out.append(f"{where}: option {oid!r} action {seen[oid]!r} is not one of {', '.join(PICKER_ACTIONS)} (API-25)")
        fb = a.get("fallback") if isinstance(a, dict) else None
        if seen[oid] == "call_customer" and fb not in PICKER_FALLBACKS:
            out.append(f"{where}: call_customer option {oid!r} needs fallback {' or '.join(PICKER_FALLBACKS)} (API-30)")
        if seen[oid] != "call_customer" and isinstance(a, dict) and "fallback" in a:
            out.append(f"{where}: option {oid!r}: fallback only goes with call_customer (API-30)")
    for oid in opts:
        if oid not in seen:
            out.append(f"{where}: option {oid!r} has no picker action (API-25)")
    return out


def substitution_options(item_body):
    """Item response -> option ids of its substitution group (API-22), or None."""
    sg = item_body.get("substitution_group_id")
    for g in (item_body.get("item") or {}).get("option_groups") or []:
        if g.get("id") == sg:
            return {o["id"] for o in g.get("options") or []}
    return None


def is_legacy_order(op_id, body):
    return op_id == "createOrder" and isinstance(body, dict) and "items" in body and "lines" not in body


def check_example(ex, label, strict_oneof=True):
    """-> (errors, warnings, info) for one example. Pure: no file access."""
    errs, warns, info = [], [], {}

    def E(m):
        errs.append(m)

    if not isinstance(ex, dict) or set(ex) != {"operationId", "request", "response"}:
        return [f"top level must have exactly operationId, request, response (got {sorted(ex) if isinstance(ex, dict) else type(ex).__name__})"], warns, info
    op_id, req, resp = ex["operationId"], ex["request"], ex["response"]
    if op_id not in BY_ID:
        return [f"operationId {op_id!r} is not in openapi.json"], warns, info
    o = BY_ID[op_id]
    op = o["op"]
    if set(req) - {"method", "path", "headers", "query", "body"} or not {"method", "path", "headers"} <= set(req):
        E(f"request keys must be method, path, headers (+ query, body); got {sorted(req)}")
        return errs, warns, info
    if set(resp) - {"status", "headers", "body"} or not {"status", "body"} <= set(resp):
        E(f"response keys must be status, body (+ headers); got {sorted(resp)}")
        return errs, warns, info

    # method + path
    if req["method"] != o["method"]:
        E(f"method {req['method']} but {op_id} is {o['method']}")
    m = o["rx"].match(req["path"]) if isinstance(req["path"], str) else None
    if not m:
        E(f"path {req['path']!r} does not match template {o['template']}")
        path_vals = {}
    else:
        path_vals = dict(zip(o["names"], m.groups()))
        better = [x for x in OPS if x["method"] == o["method"] and x["rx"].match(req["path"])
                  and x["specificity"] > o["specificity"]]
        if better:
            E(f"path {req['path']} is a better match for {better[0]['id']}")

    headers, query = lower(req["headers"]), req.get("query") or {}
    body = req.get("body")
    legacy = is_legacy_order(op_id, body)

    # parameters
    for p in op.get("parameters", []):
        p, _ = deref(p, SPEC_PATH)
        check_param(p, None, path_vals, query, headers, errs)
    # createOrder: Idempotency-Key is `required: false` in the spec because OpenAPI cannot tie a header to the
    # body; the rule (README §2.7, §5) is: required with the new body, optional with the legacy body, which
    # may carry `client_uuid` instead (the body value wins when both are sent).
    if op_id == "createOrder" and not legacy and "idempotency-key" not in headers:
        E("missing Idempotency-Key header (required with the new order body; only the legacy body may use client_uuid)")
    declared_q = {deref(p, SPEC_PATH)[0]["name"] for p in op.get("parameters", []) if deref(p, SPEC_PATH)[0]["in"] == "query"}
    for q in query:
        if q not in declared_q:
            E(f"query parameter '{q}' is not declared on {op_id}")
    if op.get("security"):
        if "x-storefront-token" not in headers:
            E("missing X-Storefront-Token header")
    if body is not None and headers.get("content-type", "").split(";")[0] != "application/json":
        E("request has a body but no Content-Type: application/json")

    # request body
    rb = op.get("requestBody")
    if rb:
        schema = rb["content"]["application/json"]["schema"]
        if body is None:
            if rb.get("required"):
                E("request body is required")
        else:
            e = []
            validate(body, schema, SPEC_PATH, "request.body", e, warns, strict_oneof)
            errs.extend(e)
    elif body is not None:
        E(f"{op_id} takes no request body")

    # response
    status = resp["status"]
    rheaders = lower(resp.get("headers"))
    spec_resp = op["responses"].get(str(status))
    if spec_resp is None:
        E(f"status {status} is not documented for {op_id} (documented: {sorted(op['responses'])})")
        return errs, warns, info
    spec_resp, rbase = deref(spec_resp, SPEC_PATH)
    for hname, hdef in spec_resp.get("headers", {}).items():
        hdef, _ = deref(hdef, SPEC_PATH)
        conditional = hname in DEPRECATION_HEADERS and not op.get("deprecated")
        if hname.lower() not in rheaders:
            if conditional and not legacy:
                continue
            E(f"response header {hname} is declared for {status} but missing")
        else:
            val = coerce(rheaders[hname.lower()], hdef.get("schema", {}))
            e = []
            validate(val, hdef.get("schema", {}), SPEC_PATH, f"response header {hname}", e, [])
            errs.extend(e)
    content = spec_resp.get("content", {}).get("application/json")
    rbody = resp["body"]
    if content:
        e = []
        validate(rbody, content["schema"], SPEC_PATH, "response.body", e, warns, strict_oneof)
        errs.extend(e)
    elif status in (204, 304):
        if rbody not in (None, ""):
            E(f"status {status} must have an empty body")
    else:
        if not isinstance(rbody, (dict, list)):
            E("response body must be a JSON object or array")
        warns.append(f"SPEC GAP: {op_id} {status} has no response schema in openapi.json; body not validated")

    # deprecated aliases and legacy bodies
    if op.get("deprecated") or legacy:
        h = lower(resp.get("headers"))
        if h.get("deprecation") != "true":
            E("legacy response needs Deprecation: true")
        try:
            sunset = parsedate_to_datetime(h.get("sunset", ""))
            date = parsedate_to_datetime(h["date"]) if "date" in h else datetime.now(timezone.utc)
            if sunset <= date:
                E("Sunset is not in the future of the response Date")
        except (TypeError, ValueError):
            E("Sunset must be an HTTP-date")
        link = h.get("link", "")
        for rel in ("deprecation", "successor-version"):
            if f'rel="{rel}"' not in link:
                E(f'Link header needs rel="{rel}"')

    # README rules
    ctl = rheaders.get("cache-control", "")
    if 200 <= status < 300:
        if op_id in CACHE_PUBLIC120 and ctl != "public, max-age=120":
            E(f"{op_id}: Cache-Control should be 'public, max-age=120' (README §2.10), got {ctl!r}")
        if op_id in CACHE_PUBLIC30 and ctl != "public, max-age=30":
            E(f"{op_id}: Cache-Control should be 'public, max-age=30', got {ctl!r}")
        if op_id in CACHE_NOSTORE and ctl != "no-store":
            E(f"{op_id}: Cache-Control should be 'no-store', got {ctl!r}")
        if op_id in CACHE_PUBLIC120 | CACHE_PUBLIC30 and "etag" not in rheaders and op_id != "getItemVariants":
            E(f"{op_id}: 200 needs an ETag")
    if status == 429:
        ra = rheaders.get("retry-after", "")
        if not re.fullmatch(r"[1-9]\d*", str(ra)):
            E("429 needs a positive integer Retry-After")
    if status >= 400 and isinstance(rbody, dict):
        code = rbody.get("code")
        if code in ERROR_STATUS and ERROR_STATUS[code] != status:
            E(f"error code {code} is HTTP {ERROR_STATUS[code]} in README §6, example uses {status}")
        elif code not in ERROR_STATUS:
            E(f"error code {code!r} is not in the README §6 table")
        d = rbody.get("details") or {}
        if code in FEATURE_OF_CODE and d.get("feature") != FEATURE_OF_CODE[code]:
            E(f"{code} needs details.feature = {FEATURE_OF_CODE[code]!r}")
        if code in NEXT_SLOT_CODES and not d.get("next_free_slot"):
            E(f"{code} needs details.next_free_slot (README §2.8)")
        if code == "quote_changed" and not d.get("quote"):
            E("quote_changed needs details.quote")
        if code == "kiosk_not_enabled" and d.get("feature") not in (None, "kiosk"):
            E("kiosk_not_enabled: details.feature is 'kiosk' (plan) or absent (store toggle), API-13")
        if code == "phone_verification_required" and is_kiosk(req["headers"]):
            E("a kiosk token is exempt from Verify guest phone: never phone_verification_required (API-15)")
    if status == 201 and op_id in ("createOrder", "createBooking") and isinstance(rbody, dict) and not legacy:
        pay = rbody.get("payment") or {}
        ref = rbody.get("order") or rbody.get("booking") or {}
        if pay.get("mode") == "hosted":
            na = pay.get("next_action") or {}
            if pay.get("status") != "pending" or na.get("type") != "redirect" or not na.get("url"):
                E("hosted payment: status pending and next_action {type: redirect, url} expected")
            if ref.get("status") != "pending_payment":
                E("hosted payment: order/booking status must be pending_payment")
            try:
                left = (datetime.fromisoformat(na["expires_at"]) - parsedate_to_datetime(rheaders["date"])).total_seconds()
                if abs(left - PAYMENT_WINDOW_S) > 2:
                    E("hosted payment: next_action.expires_at must be Date + 15 minutes (API-10)")
            except (KeyError, TypeError, ValueError):
                E("hosted payment: next_action.expires_at and a Date response header are needed (API-10)")
        elif pay.get("mode") == "pay_later":
            if pay.get("status") != "pay_later" or pay.get("next_action"):
                E("pay_later: payment.status pay_later and no next_action expected")
        if isinstance(body, dict) and body.get("payment", {}).get("mode") != pay.get("mode"):
            E("payment.mode in the response differs from the request")
        if bool(rbody.get("replayed")) != ("idempotent-replayed" in rheaders):
            E("replayed:true goes with the Idempotent-Replayed header, and only then")
    if op_id == "quoteCart" and status == 200 and isinstance(rbody, dict) and isinstance(body, dict):
        mode = body.get("payment_mode")
        if ("return_url" in rbody.get("requires", [])) != (mode == "hosted"):
            E("requires[] lists return_url exactly when the request's payment_mode is hosted")
        warned = any(w.get("code") == "payment_mode_unavailable" for w in rbody.get("warnings", []))
        if mode and (mode not in rbody.get("payment_modes", [])) != warned:
            E("payment_mode not in payment_modes goes with the warning payment_mode_unavailable, and only then")
        if is_kiosk(req["headers"]) and "verified_phone" in rbody.get("requires", []):
            E("a kiosk token never needs verified_phone (API-15)")
        w = rbody.get("weighed")
        lines = [ln for ln in (rbody.get("quote") or {}).get("lines", []) if ln.get("estimate")]
        if w:
            est = sum(ln["line_total"]["minor"] for ln in lines)
            if w["estimate"]["minor"] != est:
                E(f"weighed.estimate must be the sum of the estimate:true lines ({est})")
            exact = w["estimate"]["minor"] * (100 + w["cap_percent"]) / 100
            if abs(w["cap"]["minor"] - exact) >= 1:
                E(f"weighed.cap must be estimate x (1 + cap_percent/100) rounded to the minor unit (~{exact:g})")
        elif lines:
            E("estimate:true lines need a weighed block")
    if isinstance(rbody, dict) and isinstance(rbody.get("payment"), dict):
        pay = rbody["payment"]
        if pay.get("status") == "refunded" and \
                (pay.get("refunded_amount") or {}).get("minor") != (pay.get("amount_now") or {}).get("minor"):
            E("payment.status refunded needs refunded_amount equal to amount_now")
        if pay.get("status") in ("partially_refunded", "refunded") and not pay.get("refunded_amount"):
            E("a refunded payment needs refunded_amount")
        if pay.get("status") == "received_late":
            ref = rbody.get("order") or rbody.get("booking") or rbody.get("cancelled") or {}
            if ref.get("status") != "cancelled" or pay.get("next_action"):
                E("payment.status received_late: the order or booking stays cancelled and has no next_action (API-21)")
            if not pay.get("amount_now"):
                E("payment.status received_late needs amount_now (the amount received, API-21)")
        if pay.get("authorisation_expires_at") and pay.get("status") not in ("authorised", "voided"):
            E("authorisation_expires_at goes only with an authorised card hold, or one voided after it lapsed (API-32)")
    if op_id == "releaseHold" and status == 200 and isinstance(rbody, dict):
        pay = rbody.get("payment") or {}
        if pay and (pay.get("status") != "failed" or pay.get("next_action")):
            E("a release that cancels a pending order leaves payment failed with no next_action (API-10, API-24a)")
        if path_vals.get("hold_id") and rbody.get("hold_id") != path_vals["hold_id"]:
            E("HoldReleased.hold_id differs from the path")
        c = rbody.get("cancelled") or {}
        if (c.get("kind") == "order") != bool(c.get("track_token")) or (c.get("kind") == "booking") != bool(c.get("manage_token")):
            E("HoldReleased.cancelled: an order carries track_token, a booking manage_token (API-24a)")
    if op_id == "cancelBooking" and status == 200 and isinstance(rbody, dict):
        pay = rbody.get("payment")
        if not pay:
            E("cancel response needs payment (what happened to the deposit, D7/D8)")
        elif (pay.get("amount_now") or {}).get("minor") and pay.get("status") not in ("refunded", "kept", "pending", "failed", "voided"):
            E("a cancelled booking's paid deposit is refunded or kept (D7/D8), not " + repr(pay.get("status")))
    if op_id == "createHold" and status == 201 and isinstance(rbody, dict) and isinstance(body, dict):
        if rbody.get("ttl_seconds") != 600:
            E("holds last 10 minutes: ttl_seconds must be 600 (README §2.8)")
        if rbody.get("kind") != body.get("kind") or rbody.get("slot_id") != body.get("slot_id") \
                or rbody.get("return_slot_id") != body.get("return_slot_id"):
            E("hold kind / slot_id / return_slot_id differ from the request")
        if ("x-manage-token" in headers) != bool(rbody.get("booking_id")):
            E("a hold has booking_id exactly when it was made with X-Manage-Token (reschedule, API-8)")
        try:
            exp = datetime.fromisoformat(rbody["expires_at"])
            date = parsedate_to_datetime(rheaders["date"])
            if abs((exp - date).total_seconds() - 600) > 2:
                E("hold expires_at is not Date + 600 s")
        except (KeyError, ValueError, TypeError):
            E("hold needs expires_at and a Date response header")
    if op_id == "getBookingSlots" and status == 200 and isinstance(rbody, dict):
        if ("x-manage-token" in headers) != bool(rbody.get("reschedule_of")):
            E("booking slots carry reschedule_of exactly when listed with X-Manage-Token (API-8)")
    if isinstance(rbody, dict) and isinstance(rbody.get("booking"), dict):
        b = rbody["booking"]
        if b.get("reschedule_deadline_at") and b["reschedule_deadline_at"] != b.get("cancel_deadline_at"):
            E("reschedule_deadline_at must equal cancel_deadline_at (one store setting, API-9)")

    # ids, Money, quotes
    for side, doc in (("request", req.get("body")), ("response", rbody)):
        walk_ids(doc, side, errs, skip=("legacy" in label.split("/")[0]))
        if not legacy and "legacy" not in label.split("/")[0]:
            errs.extend(option_id_errors(doc, side))
    errs.extend(option_id_errors(query, "query"))
    if not legacy and op_id not in ("legacyMenu", "legacyRestaurant", "legacyTrack"):
        for pname in ("item_id", "hold_id"):
            if pname in path_vals and not UUID7.match(path_vals[pname]):
                E(f"path {pname} must be a UUIDv7")
    return errs, warns, info


def walk(node, path="$"):
    yield path, node
    if isinstance(node, dict):
        for k, v in node.items():
            yield from walk(v, f"{path}.{k}")
    elif isinstance(node, list):
        for i, v in enumerate(node):
            yield from walk(v, f"{path}[{i}]")


def walk_ids(doc, side, errs, skip=False):
    if skip or doc is None:
        return
    for path, n in walk(doc, side):
        if isinstance(n, dict):
            for k in ID_KEYS:
                if k in n and isinstance(n[k], str) and not UUID7.match(n[k]):
                    errs.append(f"{path}.{k}: {n[k]!r} is not a UUIDv7")


# ------------------------------------------------------------------ totals parity (tools/conformance)
def load_parity():
    # In the kit repo the tools live at tools/conformance; imported into juwri they live at tools/themes/conformance.
    path = ROOT / "tools/conformance/totals_parity.py"
    if not path.exists():
        path = ROOT / "conformance/totals_parity.py"
    spec = importlib.util.spec_from_file_location("totals_parity", path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


PARITY = load_parity()


def check_money_and_quotes(flow, label, ex, errs, symbols):
    """Money consistency and quote parity for every Money / Quote anywhere in the example."""
    theme = FLOW_THEMES.get(flow)
    tax = theme[2] if theme else None
    for side, doc in (("request", ex["request"].get("body")), ("response", ex["response"]["body"])):
        if doc is None:
            continue
        for path, n in walk(doc, side):
            if PARITY.is_money(n):
                e, w = [], []
                PARITY.check_money(n, path, "en", CURRENCY, symbols, e, w)
                errs.extend(e)
            if PARITY.is_quote(n):
                e = []
                PARITY.check_quote(n, path, tax, e)
                errs.extend(e)
                if theme and n["total"]["minor"] != theme[1]:
                    errs.append(f"{path}: quote total {n['total']['minor']} != design total {theme[1]} "
                                f"of theme {theme[0]}")


# ------------------------------------------------------------------ flows
def quotes_in(doc):
    return [n for _, n in walk(doc) if isinstance(n, dict) and "lines" in n and "rows" in n and "total" in n]


def check_flow(flow, items, global_keys, strict_oneof=True):
    """items: [(label, example)] in name order. Cross-example rules. -> (errors, warnings)."""
    errs, warns = [], []
    st = {"hash": None, "quote_total": None, "hold": None, "slots": {}, "tokens": {}, "bookings": {}, "date": None,
          "requires": [], "customer_tokens": {}, "verify_on": False, "resched_list": None, "payments": {},
          "release_on": None, "store_subst": None, "item_subst": {}, "bound": {}, "late": set(), "hold_exp": {}}

    def E(label, m):
        errs.append(f"{label}: {m}")

    for label, ex in items:
        op, req, resp = ex["operationId"], ex["request"], ex["response"]
        body, rbody, status = req.get("body"), resp["body"], resp["status"]
        h, rh = lower(req["headers"]), lower(resp.get("headers"))
        ok = status < 300
        # clock moves forward inside a flow (not for the errors pack)
        if flow != "errors" and "date" in rh:
            d = parsedate_to_datetime(rh["date"])
            if st["date"] and d < st["date"]:
                E(label, "Date goes backwards inside the flow")
            st["date"] = d
        # idempotency keys across the whole pack
        key = h.get("idempotency-key")
        if key and op in ("createOrder", "createBooking", "createHold", "rescheduleBooking", "cancelBooking"):
            prev = global_keys.get(key)
            if prev is None:
                if ok:
                    global_keys[key] = (label, copy.deepcopy(body), copy.deepcopy(rbody))
                elif flow != "errors":
                    pass
            else:
                plabel, pbody, prbody = prev
                if body == pbody:
                    exp = copy.deepcopy(prbody)
                    if isinstance(exp, dict) and "replayed" in exp:
                        exp["replayed"] = True
                    if rbody != exp or "idempotent-replayed" not in rh:
                        E(label, f"same Idempotency-Key and body as {plabel}: must replay that response with "
                                 "replayed:true and Idempotent-Replayed")
                elif not (status == 409 and rbody.get("code") == "idempotency_conflict"):
                    E(label, f"Idempotency-Key reused from {plabel} with a different body: expected 409 idempotency_conflict")
        elif key and ok and flow != "errors":
            global_keys.setdefault(key, (label, copy.deepcopy(body), copy.deepcopy(rbody)))
        if flow == "errors":
            # error examples are standalone; still remember slot lists etc. from other flows? no
            continue

        if op in ("getDeliverySlots",) and ok:
            for d in rbody["days"]:
                for s in d["slots"]:
                    st["slots"][s["id"]] = s
        if op == "getBookingSlots" and ok:
            for s in rbody["slots"]:
                st["slots"][s["id"]] = s
            st["duration"] = rbody.get("total_duration_min")
        if op == "getStore" and ok:
            st["verify_on"] = bool((rbody.get("booking") or {}).get("verify_guest_phone"))
            if isinstance(rbody.get("holds"), dict):
                st["release_on"] = bool(rbody["holds"].get("release_during_payment"))
            sub = rbody.get("substitution")
            if isinstance(sub, dict):
                st["store_subst"] = {o["id"] for o in sub["group"].get("options") or []}
        if op == "getItem" and ok:
            opts = substitution_options(rbody)
            if opts is not None:
                st["item_subst"][rbody["item"]["id"]] = opts
        if op in ("quoteCart", "createOrder") and isinstance(body, dict) and not is_legacy_order(op, body):
            for ln in body.get("lines") or []:
                sub = ln.get("substitution")
                opts = st["item_subst"].get(ln.get("item_id"))
                if sub is not None and opts is not None and sub not in opts:
                    E(label, f"line {ln.get('line_id')}: substitution is not an option of the item's substitution group (API-22)")
            pref = body.get("substitution_preference")
            if pref is not None and st["store_subst"] is not None and pref not in st["store_subst"]:
                E(label, "substitution_preference is not an option of the store's substitution group (API-22)")
        if op == "releaseHold":
            hid = req["path"].rsplit("/", 1)[-1]
            bound = st["bound"].get(hid)
            if status == 200:
                c = (rbody or {}).get("cancelled") or {}
                if bound and (c.get("kind"), c.get("public_id")) != bound:
                    E(label, f"the release cancelled {c.get('kind')} {c.get('public_id')}, but the hold was bound to {bound[0]} {bound[1]} (API-26)")
                COVERAGE.add(f"release-200-{bound[0] if bound else c.get('kind')}")
                if c.get("manage_token") in st["bookings"]:
                    st["bookings"][c["manage_token"]] = dict(st["bookings"][c["manage_token"]], status="cancelled")
            if status == 409 and rbody.get("code") == "hold_in_use":
                if st["release_on"] is not False:
                    E(label, "409 hold_in_use needs the flow's store to show holds.release_during_payment = false (API-24a, API-26)")
                if not bound:
                    E(label, "409 hold_in_use: the hold must be bound to a pending hosted order or booking created earlier in the flow")
                else:
                    COVERAGE.add(f"release-409-{bound[0]}")
            if status == 200 and st["release_on"] is False:
                E(label, "the store has Release holds during payment off: a bound hold answers 409 hold_in_use (API-24a)")
            if status == 200 and st["release_on"] is None:
                E(label, "a 200 release needs the store's holds.release_during_payment = true earlier in the flow (API-24a)")
            if status == 409 and rbody.get("code") == "hold_in_use" and st["release_on"]:
                E(label, "the store has Release holds during payment on: the release cancels the order instead of hold_in_use (API-24a)")
            c = (rbody or {}).get("cancelled") if ok else None
            if c and c.get("track_token"):
                st["tokens"][c["track_token"]] = {"public_id": c["public_id"], "number": c["number"], "status": "cancelled"}
        if op == "verifyCustomerOtp" and ok:
            st["customer_tokens"][rbody["token"]] = rbody["customer"]
        if op == "getBookingSlots" and ok and rbody.get("reschedule_of"):
            st["resched_list"] = rbody["reschedule_of"]
        if op in ("createBooking", "cancelBooking", "rescheduleBooking") and ok and not is_kiosk(req["headers"]) \
                and (st["verify_on"] or (op == "createBooking" and "verified_phone" in st["requires"])):
            auth = h.get("authorization", "")
            if not auth.startswith("Bearer "):
                E(label, "Verify guest phone is on: this call needs a verified customer token (API-7)")
            elif st["customer_tokens"]:
                cust = st["customer_tokens"].get(auth[7:])
                if cust is None:
                    E(label, "customer token was not returned by verifyCustomerOtp earlier in the flow (API-14)")
                elif op == "createBooking" and (body.get("customer") or {}).get("phone") not in (None, cust["phone"]):
                    E(label, "the verified token's phone wins: body customer.phone must match it (API-7)")
        if op == "quoteCart" and ok:
            st["hash"], st["quote_total"] = rbody["quote_hash"], rbody["quote"]["total"]["minor"]
            st["requires"] = rbody.get("requires", [])
            st["weighed"] = rbody.get("weighed")
            if isinstance(body, dict) and body.get("hold_id") and st["hold"] and body["hold_id"] != st["hold"]["id"]:
                E(label, "quote hold_id is not the last hold")
            if isinstance(body, dict) and body.get("slot_id") and body.get("hold_id") and st["hold"] \
                    and body["slot_id"] != st["hold"]["slot_id"]:
                E(label, "quote slot_id differs from the held slot")
        if op == "createHold" and ok:
            slot = st["slots"].get(rbody["slot_id"])
            if st["slots"] and slot is None:
                E(label, "held slot_id was not in a slots listing earlier in the flow")
            st["hold"] = rbody
            st["hold_slot"] = slot
        if op in ("createOrder", "createBooking") and not is_legacy_order(op, body):
            if body.get("quote_hash") != st["hash"]:
                E(label, "quote_hash differs from the last quote's quote_hash")
            if ok:
                if rbody["quote"]["total"]["minor"] != st["quote_total"]:
                    E(label, "created quote total differs from the quoted total")
            if body.get("hold_id"):
                hold = st["hold"]
                if not hold or body["hold_id"] != hold["id"]:
                    E(label, "hold_id is not the last hold")
                elif h.get("x-hold-token") != hold["token"]:
                    E(label, "X-Hold-Token is not the hold's token")
                if hold and body.get("slot_id") and body["slot_id"] != hold["slot_id"]:
                    E(label, "slot_id differs from the held slot")
            if op == "createBooking" and ok:
                b = rbody["booking"]
                slot = st.get("hold_slot")
                if slot and (b["starts_at"] != slot["starts_at"] or b["ends_at"] != slot["ends_at"]
                             or b["staff_id"] != slot["staff_id"]):
                    E(label, "booking time / staff differ from the held slot")
                st["bookings"][b["manage_token"]] = b
                st["payments"][b["manage_token"]] = rbody.get("payment")
            if op == "createOrder" and ok:
                st["tokens"][rbody["order"]["track_token"]] = rbody["order"]
            if ok and body.get("hold_id") and (rbody.get("payment") or {}).get("mode") == "hosted":
                ref = rbody.get("order") or rbody.get("booking")
                st["bound"][body["hold_id"]] = ("order" if op == "createOrder" else "booking", ref["public_id"])
            pay = (rbody.get("payment") or {}) if ok else {}
            if ok and pay.get("mode") == "hosted":
                w = st.get("weighed")
                total = rbody["quote"]["total"]["minor"]
                if w:
                    want = total + w["cap"]["minor"] - w["estimate"]["minor"]
                    if pay.get("hold_amount", {}).get("minor") != want:
                        E(label, f"weighed hold_amount should be total + (cap - estimate) = {want}")
                dep = [r for r in rbody["quote"]["rows"] if r.get("kind") == "deposit"]
                if dep and pay.get("amount_now", {}).get("minor") != dep[0]["amount"]["minor"]:
                    E(label, "amount_now should equal the deposit row")
                if pay.get("amount_now", {}).get("minor", 0) > total + (w["cap"]["minor"] - w["estimate"]["minor"] if w else 0):
                    E(label, "amount_now is larger than the total")
        if op in ("getOrder", "getBooking") and ok:
            ref = rbody.get("order") or rbody.get("booking") or {}
            pay = rbody.get("payment") or {}
            rid, rst, pst = ref.get("public_id"), ref.get("status"), pay.get("status")
            if pst == "received_late":
                st["late"].add(rid)
            elif rid in st["late"]:
                if rst in ("placed", "confirmed"):
                    COVERAGE.add("late-accepted-rereserved")
                    if pst not in ("paid", "authorised"):
                        E(label, "a late payment accepted with its slot re-reserved is paid or authorised (API-27)")
                elif rst == "pending_approval":
                    COVERAGE.add("late-accepted-needs-slot")
                    if pst not in ("paid", "authorised") or pay.get("next_action"):
                        E(label, "accepted late payment waiting for a new slot: payment paid or authorised, no next_action (API-27)")
                elif rst == "cancelled" and pst not in ("refunded", "voided"):
                    E(label, "after a late payment a cancelled record is refunded or voided (API-28), or still received_late")
            exp = st["hold_exp"].get(rid)
            if exp and st["date"] and st["date"] >= exp:
                COVERAGE.add("late-hold-expired")
                if rst != "cancelled" or pst != "voided" or pay.get("next_action"):
                    E(label, "an accepted card hold that lapsed while pending_approval: order cancelled, payment voided, "
                             "no next_action (API-32)")
                st["hold_exp"].pop(rid)
            if rid in st["late"] and rst == "pending_approval" and pst == "authorised":
                try:
                    st["hold_exp"][rid] = datetime.fromisoformat(pay["authorisation_expires_at"])
                except (KeyError, TypeError, ValueError):
                    pass
            if op == "getBooking" and rst == "pending_approval" and rid not in st["late"]:
                E(label, "a booking is pending_approval only after a late deposit the owner accepted (API-27)")
        if op == "getOrder" and ok:
            tk = req["path"].rsplit("/", 1)[-1]
            if tk not in st["tokens"]:
                E(label, "track_token was not returned by a createOrder earlier in the flow")
            elif rbody["order"]["track_token"] != tk or rbody["order"]["public_id"] != st["tokens"][tk]["public_id"]:
                E(label, "order ref differs from the created order")
            if st["quote_total"] is not None and rbody["view"]["quote"]["total"]["minor"] != st["quote_total"]:
                E(label, "view quote total differs from the quoted total")
            public = deref({"$ref": "#/components/schemas/OrderRef"}, SPEC_PATH)[0]["properties"]["status"]["enum"]
            if rbody["view"]["number"] != rbody["order"]["number"] or \
                    (rbody["view"]["status"] in public and rbody["view"]["status"] != rbody["order"]["status"]):
                E(label, "view number/status differ from the order ref")
        if op in ("getBooking", "rescheduleBooking", "cancelBooking") and ok:
            mt = req["path"].split("/bookings/")[1].split("/")[0]
            b = rbody["booking"]
            if mt not in st["bookings"]:
                E(label, "manage_token was not returned by createBooking earlier in the flow")
            elif b["manage_token"] != mt or b["public_id"] != st["bookings"][mt]["public_id"]:
                E(label, "booking ref differs from the created booking")
            if op == "rescheduleBooking":
                hold = st["hold"]
                if not hold or body["hold_id"] != hold["id"] or h.get("x-hold-token") != hold["token"]:
                    E(label, "reschedule must use the latest hold id and X-Hold-Token")
                slot = st["slots"].get(hold["slot_id"]) if hold else None
                if slot and (b["starts_at"] != slot["starts_at"] or b["ends_at"] != slot["ends_at"]):
                    E(label, "rescheduled time differs from the new hold's slot")
                if hold and hold.get("booking_id") != b["public_id"]:
                    E(label, "reschedule needs a hold tied to this booking (X-Manage-Token on POST /holds, API-8)")
                if st["slots"] and st["resched_list"] != b["public_id"]:
                    E(label, "the new slot must come from a same-price listing (GET /booking/slots with X-Manage-Token, API-8)")
                if st["payments"].get(mt) is not None and rbody.get("payment") != st["payments"][mt]:
                    E(label, "no money moves on reschedule: payment must be unchanged (API-8)")
            if op != "getBooking" or True:
                st["bookings"][mt] = b
                if rbody.get("payment") is not None and op != "rescheduleBooking":
                    st["payments"][mt] = rbody["payment"]
            if "view" in rbody and rbody["view"]["number"] != b["number"]:
                E(label, "view number differs from the booking number")
            if b["status"] == "confirmed" and not b.get("reschedule_deadline_at"):
                E(label, "a confirmed booking needs reschedule_deadline_at")
            if b["status"] != "confirmed" and b.get("reschedule_deadline_at"):
                E(label, "only a confirmed booking has reschedule_deadline_at (null otherwise)")
            if "quote" in rbody and rbody["quote"]["total"]["minor"] != st["quote_total"]:
                E(label, "booking quote total differs from the quoted total")
    return errs, warns


# ------------------------------------------------------------------ driver
COVERAGE = set()
REQUIRED_COVERAGE = {
    "release-200-booking": "a releaseHold 200 that cancels a pending booking (setting on, API-26)",
    "release-409-booking": "a releaseHold 409 hold_in_use on a booking-deposit hold (setting off, API-26)",
    "release-200-order": "a releaseHold 200 that cancels a pending hosted order (API-24a)",
    "late-accepted-rereserved": "a status poll after an accepted late payment whose slot was re-reserved (API-27)",
    "late-accepted-needs-slot": "a status poll after an accepted late payment waiting for a new slot (API-27)",
    "late-hold-expired": "a status poll after an accepted late card hold lapsed while pending_approval (API-32)",
}


def example_files(args):
    if args:
        return [Path(a).resolve() for a in args]
    return sorted(EXAMPLES.glob("*/*.json"))


def run(files, strict_oneof=True, quiet=False, args_given=False):
    flows, load_errs = {}, []
    COVERAGE.clear()
    for f in files:
        try:
            ex = json.loads(f.read_text(encoding="utf-8"))
        except json.JSONDecodeError as e:
            load_errs.append(f"{f}: invalid JSON: {e}")
            continue
        try:
            flow = f.parent.name
            label = f"{flow}/{f.name}"
        except Exception:
            flow, label = "?", str(f)
        if not re.match(r"^\d\d-[a-z0-9-]+\.json$", f.name):
            load_errs.append(f"{label}: file name must look like NN-step.json")
        flows.setdefault(flow, []).append((label, ex))
    total = bad = nwarn = 0
    seen_ops = set()
    gaps = {}
    global_keys = {}
    # non-error flows first, so the errors pack can refer to their idempotency keys
    order = sorted(flows, key=lambda x: (x == "errors", x))
    out = []
    for e in load_errs:
        out.append(f"FAIL {e}")
        bad += 1
    for flow in order:
        items = flows[flow]
        symbols = {}
        flow_bad = 0
        for label, ex in items:
            total += 1
            errs, warns, _ = check_example(ex, label, strict_oneof)
            if isinstance(ex, dict) and "operationId" in ex:
                seen_ops.add(ex["operationId"])
                if not errs:
                    check_money_and_quotes(flow, label, ex, errs, symbols)
            for w in warns:
                gaps.setdefault(w if w.startswith("SPEC GAP") else re.sub(r"^[^:]*: ", "", w), []).append(label)
                nwarn += 1
            if errs:
                flow_bad += 1
                out.append(f"FAIL {label}")
                out.extend(f"     {e}" for e in errs[:15])
            elif not quiet:
                out.append(f"ok   {label}")
        ferrs, _ = check_flow(flow, items, global_keys, strict_oneof) if not flow_bad else ([], [])
        for e in ferrs:
            flow_bad += 1
            out.append(f"FAIL flow {flow}: {e}")
        bad += flow_bad
    if not args_given:
        for k, what in REQUIRED_COVERAGE.items():
            if k not in COVERAGE:
                out.append(f"FAIL pack: no example shows {what}")
                bad += 1
    print("\n".join(out))
    uncovered = sorted({o["id"] for o in OPS} - seen_ops)
    print(f"\n{total} examples in {len(flows)} flows, {total - bad if bad <= total else 0} without errors, "
          f"{bad} failing item(s); covers {len(seen_ops)}/{len(OPS)} operations")
    if uncovered:
        print("operations without an example: " + ", ".join(uncovered))
    if gaps:
        print(f"\n{len(gaps)} kind(s) of warning ({nwarn} total):")
        for g, labels in sorted(gaps.items()):
            print(f"  WARN {g}  [{len(labels)}x, e.g. {labels[0]}]")
    return bad


# ------------------------------------------------------------------ self-test
def self_test():
    ok = True

    def expect(cond, msg):
        nonlocal ok
        if not cond:
            ok = False
            print("  SELF-TEST FAIL:", msg)

    def first(flow, name):
        return json.loads((EXAMPLES / flow / name).read_text())

    def broken(base, mutate):
        ex = copy.deepcopy(base)
        mutate(ex)
        return ex

    quote = first("food", "01-quote.json")
    order = first("food", "02-order-pay-later.json")
    legacy = first("legacy", "01-menu-alias.json")
    limited = first("errors", "08-rate-limited.json")
    hold = first("grocery", "03-hold-delivery-slot.json")
    errs, _, _ = check_example(quote, "food/01")
    expect(not errs, f"the good example must pass: {errs[:2]}")

    def drop(d, *path):
        for p in path[:-1]:
            d = d[p]
        del d[path[-1]]

    cases = {
        "required response field removed": (quote, lambda e: drop(e, "response", "body", "quote_hash")),
        "unexpected key in a closed schema": (quote, lambda e: e["response"]["body"]["quote"].__setitem__("surprise", 1)),
        "wrong type (price as a float)": (quote, lambda e: e["response"]["body"]["quote"]["lines"][0]["line_total"].__setitem__("minor", 80.5)),
        "wrong HTTP method": (quote, lambda e: e["request"].__setitem__("method", "GET")),
        "path does not match the template": (order, lambda e: e["request"].__setitem__("path", "/api/v1/storefront/order")),
        "Idempotency-Key removed": (order, lambda e: drop(e, "request", "headers", "Idempotency-Key")),
        "Idempotency-Key not a UUIDv7": (order, lambda e: e["request"]["headers"].__setitem__("Idempotency-Key", "not-a-uuid")),
        "X-Storefront-Token removed": (quote, lambda e: drop(e, "request", "headers", "X-Storefront-Token")),
        "request body breaks the schema": (order, lambda e: drop(e, "request", "body", "quote_hash")),
        "undocumented response status": (quote, lambda e: e["response"].__setitem__("status", 418)),
        "Deprecation header removed from an alias": (legacy, lambda e: drop(e, "response", "headers", "Deprecation")),
        "Retry-After removed from a 429": (limited, lambda e: drop(e, "response", "headers", "Retry-After")),
        "error code with the wrong HTTP status": (limited, lambda e: e["response"].__setitem__("status", 403)),
        "hold TTL not 600": (hold, lambda e: e["response"]["body"].__setitem__("ttl_seconds", 300)),
        "hosted order without next_action": (first("grocery", "05-order-hosted.json"),
                                             lambda e: e["response"]["body"]["payment"].__setitem__("next_action", None)),
        "legacy order body that also has lines (`not`)": (first("legacy", "04-order-legacy-created.json"),
                                                          lambda e: e["request"]["body"].__setitem__("lines", [])),
        "legacy order result mixed with the new shape": (first("legacy", "04-order-legacy-created.json"),
                                                         lambda e: e["response"]["body"].__setitem__("order", {})),
        "legacy menu item breaks the frozen shape": (legacy, lambda e: e["response"]["body"]["categories"][0]["items"][0].__setitem__("price", "18.00")),
        "order status outside the public enum": (order, lambda e: e["response"]["body"]["order"].__setitem__("status", "new")),
        "payment status refunded without refunded_amount": (first("services", "13-cancel.json"),
                                                            lambda e: drop(e, "response", "body", "payment", "refunded_amount")),
        "hosted quote without return_url in requires": (first("grocery", "04-quote-weighed.json"),
                                                        lambda e: e["response"]["body"].__setitem__("requires", [])),
        "weighed cap not estimate x (1 + cap_percent)": (first("grocery", "04-quote-weighed.json"),
                                                        lambda e: e["response"]["body"]["weighed"]["cap"].update({"minor": 700, "formatted": "SAR 7.00"})),
        "option id as a slug (API-18)": (quote, lambda e: e["request"]["body"]["lines"][0]["option_ids"].__setitem__(0, "for-one")),
        "weight step id as a slug (API-18)": (first("grocery", "01-item-weighed.json"),
                                              lambda e: e["response"]["body"]["item"]["option_groups"][0]["options"][0].__setitem__("id", "w_250")),
        "reschedule deadline differs from cancel deadline (API-9)": (first("services", "09-booking-confirmed.json"),
                                              lambda e: e["response"]["body"]["booking"].__setitem__("reschedule_deadline_at", "2026-10-03T12:00:00+03:00")),
        "hosted payment window not 15 minutes (API-10)": (first("grocery", "05-order-hosted.json"),
                                              lambda e: e["response"]["body"]["payment"]["next_action"].__setitem__("expires_at", "2026-10-02T11:30:00+03:00")),
        "OTP request reveals an account (API-14)": (first("services-otp", "04-otp-request.json"),
                                                   lambda e: e["response"]["body"].__setitem__("has_account", True)),
        "kiosk quote asks for verified_phone (API-15)": (first("kiosk-services", "04-quote.json"),
                                                        lambda e: e["response"]["body"]["requires"].append("verified_phone")),
        "kiosk_not_enabled with another feature (API-13)": (first("errors", "10-kiosk-feature-missing.json"),
                                                           lambda e: e["response"]["body"]["details"].__setitem__("feature", "booking_api")),
        "reschedule hold without booking_id (API-8)": (first("services", "11-hold-reschedule.json"),
                                                      lambda e: drop(e, "response", "body", "booking_id")),
        "option group id as a slug (API-20)": (first("grocery", "01-item-weighed.json"),
                                               lambda e: e["response"]["body"]["item"]["option_groups"][0].__setitem__("id", "weight")),
        "weight_option_group not an item group (API-20)": (first("grocery", "01-item-weighed.json"),
                                                          lambda e: e["response"]["body"]["sell"].__setitem__("weight_option_group", "019f4455-1a00-7c3e-9b1d-000000000000")),
        "variant options keyed by a group slug (API-20)": (first("retail", "01-variants.json"),
                                                          lambda e: e["response"]["body"]["variants"][0]["options"].__setitem__("colour", "019f23cd-4a14-7b7b-a7c0-91d71e6a5348")),
        "substitution as a fixed value (API-22)": (first("grocery", "04-quote-weighed.json"),
                                                   lambda e: e["request"]["body"]["lines"][1].__setitem__("substitution", "replace_similar")),
        "substitution option ids as slugs (API-22)": (first("grocery", "01-item-weighed.json"),
                                                      lambda e: e["response"]["body"]["item"]["option_groups"][1]["options"][0].__setitem__("id", "replace_similar")),
        "store substitution default not in its group (API-22)": (first("grocery", "00-store.json"),
                                                                lambda e: e["response"]["body"]["substitution"].__setitem__("default_option_id", "019f4455-1a00-7c3e-9b1d-000000000000")),
        "late payment on a live order (API-21)": (first("grocery-release", "03-order-late-payment.json"),
                                                  lambda e: e["response"]["body"]["order"].__setitem__("status", "placed")),
        "late payment with a retry link (API-21)": (first("grocery-release", "03-order-late-payment.json"),
                                                    lambda e: e["response"]["body"]["payment"].__setitem__("next_action", {"type": "redirect", "url": "https://fresh-mart.example/pay/x", "expires_at": "2026-10-02T11:20:00+03:00"})),
        "substitution option without a picker action (API-25)": (first("grocery", "00-store.json"),
                                                                 lambda e: e["response"]["body"]["substitution"]["picker_actions"].pop()),
        "substitution option with two picker actions (API-25)": (first("grocery", "00-store.json"),
                                                                 lambda e: e["response"]["body"]["substitution"]["picker_actions"].append({"option_id": "019f4455-1a00-7e15-a4c7-6d8e2b0f3a31", "action": "remove_item"})),
        "picker action outside the fixed set (API-25)": (first("grocery-release", "01-store.json"),
                                                         lambda e: e["response"]["body"]["substitution"]["picker_actions"][1].__setitem__("action", "ask_customer")),
        "item substitution group without picker actions (API-25)": (first("grocery", "01-item-weighed.json"),
                                                                    lambda e: drop(e, "response", "body", "substitution_picker_actions")),
        "call_customer option without a fallback (API-30)": (first("grocery", "00-store.json"),
                                                             lambda e: e["response"]["body"]["substitution"]["picker_actions"][1].pop("fallback")),
        "fallback outside offer_replacement / remove_item (API-30)": (first("grocery", "01-item-weighed.json"),
                                                                      lambda e: e["response"]["body"]["substitution_picker_actions"][1].__setitem__("fallback", "call_customer")),
        "fallback on a remove_item option (API-30)": (first("grocery-release", "01-store.json"),
                                                      lambda e: e["response"]["body"]["substitution"]["picker_actions"][2].__setitem__("fallback", "remove_item")),
        "authorisation_expires_at on a paid payment (API-32)": (first("grocery-late-hold", "05-order-late-hold-expired.json"),
                                                                lambda e: e["response"]["body"]["payment"].__setitem__("status", "paid")),
        "release cancels an order but leaves the payment open (API-24a)": (first("grocery-release", "02-release-hold.json"),
                                                                          lambda e: e["response"]["body"]["payment"].__setitem__("status", "pending")),
    }
    for name, (base, mut) in cases.items():
        errs, _, _ = check_example(broken(base, mut), "x/y")
        expect(errs, f"broken example not caught: {name}")
    # overlapping oneOf branches fail by default
    e = []
    validate({}, {"oneOf": [{}, {"type": "object"}]}, SPEC_PATH, "x", e, [], True)
    expect(e, "overlapping oneOf branches not caught")
    # the legacy body may skip Idempotency-Key (client_uuid or nothing, as today); the new body may not
    leg = first("legacy", "04-order-legacy-created.json")
    errs, _, _ = check_example(broken(leg, lambda e: drop(e, "request", "body", "client_uuid")), "legacy/x")
    expect(not errs, f"legacy body without any idempotency key must pass: {errs[:2]}")

    # totals and Money rules (these run outside check_example)
    def money_errs(ex, flow):
        e = []
        check_money_and_quotes(flow, "x/y", ex, e, {})
        return e
    expect(not money_errs(quote, "food"), "good quote must pass the totals check")
    bad_total = broken(quote, lambda e: e["response"]["body"]["quote"]["total"].update({"minor": 14200, "formatted": "SAR 142.00"}))
    expect(money_errs(bad_total, "food"), "wrong quote total not caught")
    bad_fmt = broken(quote, lambda e: e["response"]["body"]["quote"]["total"].update({"formatted": "SAR 142.11"}))
    expect(money_errs(bad_fmt, "food"), "Money.formatted disagreeing with minor not caught")
    bad_line = broken(quote, lambda e: e["response"]["body"]["quote"]["lines"][0]["line_total"].update({"minor": 7900, "formatted": "SAR 79.00"}))
    expect(money_errs(bad_line, "food"), "lines not summing to the subtotal not caught")
    wrong_theme = money_errs(quote, "retail")
    expect(wrong_theme, "design total of another theme not caught")

    # flow rules
    flow = [(f"food/{n}", first("food", n)) for n in
            ("01-quote.json", "02-order-pay-later.json", "03-order-replay.json", "04-track.json")]
    ferrs, _ = check_flow("food", flow, {})
    expect(not ferrs, f"good flow must pass: {ferrs[:2]}")
    flow2 = copy.deepcopy(flow)
    flow2[1][1]["request"]["body"]["quote_hash"] = "qh_stale"
    expect(check_flow("food", flow2, {})[0], "stale quote_hash not caught")
    flow3 = copy.deepcopy(flow)
    flow3[3][1]["request"]["path"] = "/api/v1/storefront/orders/trk_unknown"
    expect(check_flow("food", flow3, {})[0], "unknown track token not caught")
    flow4 = copy.deepcopy(flow)
    flow4[2][1]["response"]["body"]["replayed"] = False
    expect(check_flow("food", flow4, {})[0], "replay without replayed:true not caught")
    names = sorted(p.name for p in (EXAMPLES / "services-otp").glob("*.json"))
    otp = [(f"services-otp/{n}", first("services-otp", n)) for n in names]
    expect(not check_flow("services-otp", otp, {})[0], "good OTP flow must pass")
    otp2 = copy.deepcopy(otp)
    del otp2[-1][1]["request"]["headers"]["Authorization"]
    expect(check_flow("services-otp", otp2, {})[0], "booking without the verified customer token not caught (API-14)")
    names = sorted(p.name for p in (EXAMPLES / "services").glob("*.json"))
    svc = [(f"services/{n}", first("services", n)) for n in names]
    svc2 = copy.deepcopy(svc)
    for lab, ex in svc2:
        if ex["operationId"] == "rescheduleBooking":
            ex["response"]["body"]["payment"]["amount_now"] = {"minor": 6000, "currency": "SAR", "formatted": "SAR 60.00"}
    expect(check_flow("services", svc2, {})[0], "money moving on reschedule not caught (API-8)")
    names = sorted(p.name for p in (EXAMPLES / "grocery").glob("*.json"))
    gro = [(f"grocery/{n}", first("grocery", n)) for n in names]
    expect(not check_flow("grocery", gro, {})[0], "good grocery flow must pass")
    gro2 = copy.deepcopy(gro)
    for lab, ex in gro2:
        if ex["operationId"] == "quoteCart":
            ex["request"]["body"]["lines"][1]["substitution"] = "019f4455-1a00-7c3e-9b1d-000000000000"
    expect(check_flow("grocery", gro2, {})[0], "substitution outside the item's group not caught (API-22)")
    gro3 = copy.deepcopy(gro)
    for lab, ex in gro3:
        if ex["operationId"] == "createOrder":
            ex["request"]["body"]["substitution_preference"] = "019f4455-1a00-7c3e-9b1d-000000000000"
    expect(check_flow("grocery", gro3, {})[0], "substitution_preference outside the store's group not caught (API-22)")
    names = sorted(p.name for p in (EXAMPLES / "grocery-release").glob("*.json"))
    rel = [(f"grocery-release/{n}", first("grocery-release", n)) for n in names]
    expect(not check_flow("grocery-release", rel, {})[0], "good release flow must pass")
    rel2 = copy.deepcopy(rel)
    rel2[0][1]["response"]["body"]["holds"]["release_during_payment"] = False
    expect(check_flow("grocery-release", rel2, {})[0], "200 release with the store setting off not caught (API-24a)")
    names = sorted(p.name for p in (EXAMPLES / "services-release").glob("*.json"))
    srel = [(f"services-release/{n}", first("services-release", n)) for n in names]
    expect(not check_flow("services-release", srel, {})[0], "good booking release flow must pass")
    srel2 = copy.deepcopy(srel)
    for lab, ex in srel2:
        if ex["operationId"] == "releaseHold":
            ex["response"]["body"]["cancelled"]["public_id"] = "01a0fd22-0b40-7a51-8c3d-000000000000"
    expect(check_flow("services-release", srel2, {})[0], "release cancelling another record than the bound booking not caught (API-26)")
    srel3 = copy.deepcopy(srel)
    srel3[0][1]["response"]["body"]["holds"]["release_during_payment"] = False
    expect(check_flow("services-release", srel3, {})[0], "booking release 200 with the setting off not caught (API-26)")
    srel4 = [x for x in copy.deepcopy(srel) if not x[0].endswith("06-booking-late-deposit.json")]
    expect(check_flow("services-release", srel4, {})[0], "pending_approval booking without a late deposit not caught (API-27)")
    srel5 = copy.deepcopy(srel)
    srel5[-1][1]["response"]["body"]["payment"]["status"] = "pending"
    expect(check_flow("services-release", srel5, {})[0], "accepted late deposit that is not paid not caught (API-27)")
    svc3 = copy.deepcopy(svc)
    svc3[0][1]["response"]["body"]["holds"]["release_during_payment"] = True
    expect(check_flow("services", svc3, {})[0], "booking hold_in_use with the setting on not caught (API-26)")
    rel3 = copy.deepcopy(rel)
    rel3[-1][1]["response"]["body"]["payment"]["status"] = "failed"
    expect(check_flow("grocery-release", rel3, {})[0], "re-reserved late order without an accepted payment not caught (API-27)")
    names = sorted(p.name for p in (EXAMPLES / "grocery-late-hold").glob("*.json"))
    lh = [(f"grocery-late-hold/{n}", first("grocery-late-hold", n)) for n in names]
    expect(not check_flow("grocery-late-hold", lh, {})[0], "good late-hold flow must pass")
    lh2 = copy.deepcopy(lh)
    lh2[-1][1]["response"]["body"]["order"]["status"] = "pending_approval"
    lh2[-1][1]["response"]["body"]["view"]["status"] = "pending_approval"
    lh2[-1][1]["response"]["body"]["payment"]["status"] = "authorised"
    expect(check_flow("grocery-late-hold", lh2, {})[0], "late card hold still pending_approval after it lapsed not caught (API-32)")
    lh3 = copy.deepcopy(lh)
    lh3[-1][1]["response"]["body"]["payment"]["status"] = "refunded"
    lh3[-1][1]["response"]["body"]["payment"]["refunded_amount"] = lh3[-1][1]["response"]["body"]["payment"]["amount_now"]
    lh3[-1][1]["response"]["body"]["payment"].pop("authorisation_expires_at")
    expect(check_flow("grocery-late-hold", lh3, {})[0], "lapsed late card hold captured instead of voided not caught (API-32)")
    print("self-test: " + ("PASS" if ok else "FAIL") + f" ({len(cases)} broken examples + oneOf overlap + 1 legacy pass + 4 money + 19 flow mutations)")
    return ok


def main(argv):
    args = [a for a in argv if not a.startswith("--")]
    flags = {a for a in argv if a.startswith("--")}
    if "--self-test" in flags:
        sys.exit(0 if self_test() else 1)
    bad = run(example_files(args), strict_oneof="--lenient-oneof" not in flags, quiet="--quiet" in flags, args_given=bool(args))
    st_ok = True
    if not args:
        st_ok = self_test()
    print("RESULT: " + ("PASS" if not bad and st_ok else "FAIL"))
    sys.exit(1 if bad or not st_ok else 0)


if __name__ == "__main__":
    main(sys.argv[1:])
