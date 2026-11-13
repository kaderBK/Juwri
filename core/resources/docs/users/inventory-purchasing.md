---
title: Suppliers, transfers and stock alerts
audience: owner
group: Operations
updated: 2026-08-20
---

# Suppliers, transfers and stock alerts

This guide covers the purchasing and multi-branch stock tools that sit on top
of the core stock counts in [Inventory and stock](inventory.md): suppliers and
their running balances, transferring stock between branches, the low-stock
alert digest, and automatically marking a dish sold out when it runs out of an
ingredient (recipe auto-86).

## Suppliers and balances

A **supplier** is a vendor you buy ingredients from. Record supplier invoices
as you receive stock and supplier payments as you pay them; the difference is
the supplier's **running balance** — what you still owe (or have prepaid). The
supplier's page shows its invoices, its payments and the outstanding balance,
so you always know where each vendor account stands.

## Transferring stock between branches

If you run more than one branch, you can move an ingredient's stock from one
branch to another. A transfer writes a paired movement — the quantity leaves
the source branch and the same quantity arrives at the destination — so the two
branch stock levels stay consistent and the movement is auditable in each
branch's history. Use it to balance stock when one branch is short and another
has a surplus.

## Low-stock alert digest

Rather than one alert per item as it dips, the platform batches low-stock
warnings into a periodic **digest** so you get a single, scannable summary of
everything that has fallen to or below its reorder threshold. Set a reorder
level on the ingredients you care about, and the digest tells you what to
re-order without flooding your inbox.

## Recipe auto-86 (auto sold-out)

When a menu item has a recipe (the ingredients and quantities it consumes),
selling it draws down stock automatically. If an ingredient runs out, any dish
that depends on it is marked **sold out** ("86'd") so customers can no longer
order something you cannot make. When you restock, clear the sold-out flag to
put it back on the menu. This keeps the live menu honest during a busy service
without anyone watching stock by hand.

## Related pages

- [Inventory and stock](inventory.md) — stock counts, recipes and movements
- [Menu items](menu-items.md) — sold-out toggles and recipes
- [Multi-branch](branches.md) — running more than one location
