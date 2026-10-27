---
title: "Selling retail products"
audience: owner
group: Users
updated: 2026-09-10
---

# Selling retail products

This guide covers the product catalog for a **Retail store**, **Cosmetics**,
or **Clothing store** (see [Choosing your store type](store-types.md)). These
three store types share the same product form, brands, and variant grid — the
differences between them are cosmetic (Cosmetics additionally tracks batch
and expiry, the same fields covered in [Pharmacy](pharmacy.md)). Car parts
also uses this catalog plus its own fitment tool — see
[Car parts](car-parts.md).

## Products, not dishes

Open **Stores** in the sidebar and choose your store to reach its
**Products** page — the same slot a restaurant's "Menu items" page occupies,
relabeled for goods. Products are grouped into categories the same way a
restaurant's menu is, with the same add/rename/delete controls in the
sidebar panel.

The product form: **Name**, **Category**, **Brand**, **Description**,
**Price**, **Cost** (for your own margin tracking, never shown to shoppers),
**Barcode / SKU**, **Weight**, **Dimensions** (length × width × height, with
a cm/in/m unit picker), up to six free-form **Attributes** (label/value
pairs — use these for anything that doesn't have its own field, like fabric,
origin or ingredients), an **image**, and **Active** (visible on the
storefront). None of the food-only fields (veg/non-veg, allergens, spice
level, availability schedule, nutrition) appear here — they don't exist for
this store type.

## Brands

Click **Brands** from the Products page to manage your brand list: add one
by name, rename or deactivate any brand inline, and see how many products
each one carries. Brands are per-store — if you sell more than one brand
across several stores, each store keeps its own list. Deactivating a brand
does not touch the products already assigned to it.

## Stock: simple items vs. the variant grid

Every product has a **Track inventory** checkbox and a **Stock quantity**
field, exactly like a restaurant's simple per-item stock — this is what you
use for a product with no size or colour choices.

If a product comes in more than one size, colour, or other combination, use
the **Variants** section on the product's edit page instead:

1. Name up to two axes (for example "Size" and "Color") and list their
   values comma-separated ("S, M, L" / "Red, Blue, Green").
2. Click **Generate** — every combination of the two axes becomes its own
   row: a Small Red, a Small Blue, a Medium Red, and so on.
3. Each row gets its own **price**, **SKU**, **stock**, **barcode**, and
   **active** toggle, edited inline and saved together with **Save
   variants**. Delete a single combination with its row's **Delete** button.

Each variant row carries its own stock count, separate from the parent
product's **Stock quantity** field (which only applies when the product has
no variants at all — the hint under it says so). This variant grid is a
different feature from a restaurant's **Extras & variants** page (see
[Modifiers and options](modifiers.md)): that page attaches optional add-ons
and choice groups to a dish; this grid generates real, individually
stock-tracked SKUs for a size×colour matrix.

## Bulk tools

The Products page offers **Export CSV** (download your current catalog) and
**Import CSV** (upload a spreadsheet to add or bulk-edit products), in the
same spirit as a restaurant's [menu import/export](menu-import-export.md).

## Related pages

- [Choosing your store type](store-types.md) — the store types that use this
  catalog.
- [Car parts: fitment and OEM search](car-parts.md) — the additional fitment
  tool for a Car parts store.
- [Pharmacy (OTC only)](pharmacy.md) — the extra batch/expiry and age-gate
  fields available on this same product form for a Pharmacy store.
- [Fulfillment methods](fulfillment-methods.md) — ship, pickup and in-store
  checkout for a goods storefront.
- [Storefront themes and customizer](themes.md) — the **Shelf** grid theme
  these store types render with.
