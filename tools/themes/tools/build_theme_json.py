#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

"""Build kit/<theme>/theme.json (Customizer v2, SchemaV2 shape) from tokens/<theme>.json.

Run: python3 tools/build_theme_json.py            (all five themes)
     python3 tools/build_theme_json.py table      (one theme)

The output is meant to be copied as-is to assets/store_templates/<theme>/theme.json
in Juwri. Field ids follow THEME-CONTRACT.md §3; section ids follow §2.1.
New pieces that core does not have yet are listed in kit/README.md.
"""
import json, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
THEMES = ["table", "brew", "basket", "aisle", "book", "carely"]
ROLES = ["primary", "background", "surface", "text", "accent"]

# Contract §2.1: (id, label, required, orderable). Required sections are not toggleable.
SECTION_DEFS = {
    "header":        ("Header", True, False),
    "announcement":  ("Announcement bar", False, False),
    "order_type":    ("Order type", True, False),
    "hero":          ("Hero", False, True),
    "stories":       ("Stories", False, True),
    "offers":        ("Offers", False, True),
    "reorder":       ("Order again", False, True),
    "free_delivery": ("Free delivery progress", False, False),
    "search":        ("Search", True, False),
    "fitment":       ("Find parts for your car", False, True),
    "category_nav":  ("Categories", True, False),
    "featured":      ("Featured", False, True),
    "catalog":       ("Catalog", True, False),
    "combos":        ("Combos", False, True),
    "loyalty":       ("Loyalty", False, True),
    "reviews":       ("Reviews", False, True),
    "reservations":  ("Reservations", False, True),
    "custom_blocks": ("Custom blocks", False, True),
    "info":          ("Store info", True, True),
    "footer":        ("Footer", True, False),
    "cart_bar":      ("Cart bar", True, False),
    # carely (sections/carely.md section 4, Tier A): new ids
    "otc_notice":    ("OTC notice", True, False),
    "age_gate":      ("Entry notice", False, False),
    "licence":       ("Pharmacy licence", True, False),
}
# Per theme: section ids in default order, and which optional ones start ON.
# Matches SHORTLIST.md "Section support matrix". `search` is optional (toggleable) in brew and book.
THEME_SECTIONS = {
    "table":  (["header","announcement","order_type","hero","stories","offers","search","category_nav","featured","catalog","combos","loyalty","reviews","reservations","custom_blocks","info","footer","cart_bar"],
               {"hero","offers","featured"}),
    "brew":   (["header","announcement","order_type","hero","reorder","stories","offers","search","category_nav","featured","catalog","combos","loyalty","reviews","custom_blocks","info","footer","cart_bar"],
               {"hero","reorder","featured"}),
    "basket": (["header","announcement","order_type","free_delivery","search","category_nav","offers","hero","featured","catalog","loyalty","reviews","custom_blocks","info","footer","cart_bar"],
               {"free_delivery","offers"}),
    "aisle":  (["header","announcement","order_type","hero","stories","fitment","offers","search","category_nav","featured","catalog","loyalty","reviews","custom_blocks","info","footer","cart_bar"],
               {"announcement","hero","featured"}),
    "book":   (["header","announcement","order_type","hero","offers","search","category_nav","featured","catalog","loyalty","reviews","custom_blocks","info","footer","cart_bar"],
               {"featured"}),
    # sections/carely.md section 4: otc_notice and licence are required (shown whenever features.pharmacy is present)
    "carely": (["header","announcement","otc_notice","age_gate","order_type","hero","search","category_nav","featured","offers","catalog","loyalty","reviews","custom_blocks","info","licence","footer","cart_bar"],
               {"age_gate","featured"}),
}
OPTIONAL_SEARCH = {"brew", "book"}

TEXTS = {
    "table":  [("texts.hero_title","Hero title",""),("texts.hero_subtitle","Hero subtitle",""),("texts.featured_title","Featured section title","Today's specials"),("texts.footer_note","Footer note","")],
    "brew":   [("texts.hero_title","Hero title","Order ahead, skip the line"),("texts.hero_subtitle","Hero subtitle",""),("texts.featured_title","Featured section title","Popular"),("texts.reorder_title","Order again title","Order again"),("texts.footer_note","Footer note","")],
    "basket": [("texts.hero_title","Deals banner title","Deals this week"),("texts.hero_subtitle","Hero subtitle",""),("texts.featured_title","Featured section title","Deals this week"),("texts.footer_note","Footer note","")],
    "aisle":  [("texts.announcement","Announcement",""),("texts.hero_title","Hero title","The new edit"),("texts.hero_subtitle","Hero subtitle",""),("texts.hero_cta","Hero button","Shop now"),("texts.featured_title","Featured section title","New in"),("texts.footer_note","Footer note","")],
    "carely": [("texts.announcement","Announcement","Free delivery over SAR 100 · Pick up the same day"),("texts.hero_title","Hero title","Everyday health essentials"),("texts.hero_subtitle","Hero subtitle",""),("texts.featured_title","Featured section title","Everyday essentials"),("texts.footer_note","Footer note","")],
    "book":   [("texts.hero_title","Tagline",""),("texts.hero_subtitle","Hero subtitle",""),("texts.featured_title","Featured section title","Popular"),("texts.policy_note","Booking policy note",""),("texts.footer_note","Footer note","")],
}

def select(fid, label, options, labels, default, help_=None):
    f = {"id": fid, "type": "select", "label": label, "options": options,
         "option_labels": dict(zip(options, labels)), "default": default}
    if help_: f["help"] = help_
    return f

def build(theme):
    tok = json.loads((ROOT / f"tokens/{theme}.json").read_text())
    pid = tok["default_preset"]
    base = tok["presets"][pid]
    lay = tok["layout"]
    font = tok["typography"]["font_pair"]

    design_overrides = {f"colors.{r}": {"default": base["light"][r]} for r in ROLES}
    design_overrides["layout.radius"] = {"default": tok["radius"]["default"]}
    design_overrides["layout.density"] = {"default": tok["spacing"].get("customizer_default", tok["spacing"]["default"])}
    design_overrides["fonts.pair"] = {"default": font}

    dark_fields = [{"id": f"colors_dark.{r}", "type": "color",
                    "label": {"primary":"Brand colour (dark)","background":"Background (dark)","surface":"Card surface (dark)",
                              "text":"Text colour (dark)","accent":"Accent colour (dark)"}[r],
                    "default": base["dark"][r], "visible_if": {"scheme.mode": ["dark", "auto"]}} for r in ROLES]

    image_opts = ["1:1", "4:3", "16:9", "4:5", "3:4"]
    settings = [
        {"include": "tokens", "fields": design_overrides},
        {"group": "Appearance", "icon": "contrast", "fields": [
            select("scheme.mode", "Colour scheme", ["light","dark","auto"], ["Light","Dark","Follow the device"],
                   tok.get("scheme_default", "auto"),
                   "Auto follows the visitor's phone or computer setting."),
        ] + dark_fields},
        {"group": "Layout", "icon": "layout", "fields": [
            select("layout.card", "Item card", ["list","grid","compact"], ["List","Grid","Compact (no image)"], lay["card_default"]),
            select("layout.image_ratio", "Image shape", image_opts, ["Square","4:3","16:9","Portrait 4:5","Portrait 3:4"], lay["image_ratio_default"]),
            select("layout.category_nav", "Categories", ["chips","tabs"], ["Chips","Tabs"], lay["category_nav_default"]),
            select("layout.header", "Header", ["cover","compact"], ["Cover image","Compact"], lay["header_default"]),
            select("layout.desktop_cart", "Cart on desktop", ["panel","drawer"], ["Side panel","Drawer"], lay["desktop_cart_default"]),
        ]},
        {"group": "Content", "icon": "list", "fields": [
            {"id": "menu.show_descriptions", "type": "toggle", "label": "Show item descriptions", "default": True},
            {"id": "menu.show_calories", "type": "toggle", "label": "Show calories", "default": theme in ("table","brew")},
            {"id": "menu.show_allergens", "type": "toggle", "label": "Show allergens", "default": theme in ("table","brew","basket")},
        ]},
        {"group": "Texts", "icon": "type", "fields": [
            {"id": i, "type": "text", "label": l, "default": d} for i, l, d in TEXTS[theme]]},
        {"include": "splash", "fields": {"splash.enabled": {"default": False}}},
        ({"include": "search", "fields": {"texts.search_placeholder": {"default": ""}}} if theme in ("aisle", "basket") else {"include": "search"}),
        {"include": "share"},
    ]

    presets = []
    for p_id, p in tok["presets"].items():
        values = {f"colors.{r}": p["light"][r] for r in ROLES}
        values.update({f"colors_dark.{r}": p["dark"][r] for r in ROLES})
        values["scheme.mode"] = p["default_scheme"]
        values.update(p.get("layout_overrides", {}))
        presets.append({"id": p_id, "label": p["label"]["en"],
                        "thumb": f"assets/store_templates/{theme}/presets/{p_id}.png", "values": values})

    order, on = THEME_SECTIONS[theme]
    sections = []
    for sid in order:
        label, required, orderable = SECTION_DEFS[sid]
        if sid == "search" and theme in OPTIONAL_SEARCH:
            required = False
        sections.append({"id": sid, "label": label, "default": required or sid in on,
                         "toggleable": not required, "orderable": orderable})

    return {"version": 2, "texts": {"localized": True}, "presets": presets,
            "settings": settings, "sections": sections}

def main(args):
    for t in (args or THEMES):
        out = ROOT / f"kit/{t}/theme.json"
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(build(t), ensure_ascii=False, indent=2) + "\n")
        print("wrote", out.relative_to(ROOT))

if __name__ == "__main__":
    main(sys.argv[1:])
