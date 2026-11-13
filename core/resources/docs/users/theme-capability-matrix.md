---
title: Theme capability matrix
audience: owner
group: Sharing your menu
updated: 2026-09-10
verified_against: e09d26f8
---

# Theme capability matrix

This is the single reference for what each storefront menu template
supports. The platform keeps seventeen modern templates; anything not in
this list is a legacy template that predates these features and is being
retired. If a feature page says "supported themes", this table is what it
means.

The seventeen templates: **crave**, **matcha**, **glow**, **aurora**,
**vivid**, **feast**, **velvet**, **dough**, **plate**, **lime**,
**picnic**, **orbit**, **velora**, **crumb**, **soiree**, **scoop**,
**ember**.

## The matrix

Legend: ✓ supported · — not on this template. Most capabilities are also
opt-in per store — the column notes say where each is switched on.

| Template | Reviews | AI chat | Loyalty | Coupon at checkout | Banners | Reservations | Brand row | Offers strip | Home order-type |
|---|---|---|---|---|---|---|---|---|---|
| crave | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| matcha | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| glow | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| aurora | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| vivid | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| feast | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| velvet | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| dough | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| plate | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| lime | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| picnic | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| orbit | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| velora | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| crumb | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| soiree | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| scoop | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| ember | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |

## What each column means

- **Reviews** — diners see per-item ratings and reviews and can write one
  from the item view (average, star histogram and a write-a-review flow).
  See [Reviews, ratings and feedback](reviews-feedback.md).
- **AI chat** — the AI ordering assistant's chat widget on the storefront.
  Renders when the assistant is enabled for your store; see
  [AI agent settings](ai-agents.md).
- **Loyalty** — the stamp-card / points program showcase and redeeming a
  reward at checkout. Opt-in per store; see [Loyalty stamps](loyalty.md).
- **Coupon at checkout** — a promo-code field in the cart. Shown while
  coupons are allowed for the store (they are by default); see
  [Coupons](coupons.md).
- **Banners** — the rotating promo banners you manage in the dashboard;
  see [Banners and daily specials](banners.md).
- **Reservations** — the "Reserve a table" booking flow on the menu page.
  Opt-in: it appears only when reservations are enabled for the store; see
  [Reservations](reservations.md).
- **Brand row** — the follower/social proof row with your brand links near
  the top of the menu. Only the seven templates marked above ship it.
- **Offers strip** — the horizontal strip of active offers and coupons on
  the menu home. Shared across all seventeen templates.
- **Home order-type selector** — the delivery / pickup / dine-in chooser on
  the menu home. Shared across all seventeen templates.

Not in the table because it is everywhere: a **share** control. Themes with
a native share button keep their own; every other template can show the
shared fallback share button (a per-store theme setting, off by default).

Waitlist joins ride with the reservations column — templates that render
the reserve flow render the waitlist join too, when the waitlist is on.

## Legacy templates

Templates outside the seventeen (for example classic, modern, orange) do
not get the capabilities above. They keep serving basic menus, but new
storefront features are built only for the modern seventeen — if you want
these capabilities, switch templates on the restaurant edit page (see
[Storefront themes and customizer](themes.md)).

## Shelf and Booking (non-restaurant store types)

This matrix covers the seventeen food templates a **Restaurant** picks from.
A Retail, Cosmetics, Clothing, Car parts or Pharmacy store instead renders
through **Shelf**, and a Laundry & services store through **Booking** — see
[Choosing your store type](store-types.md). These commerce-oriented features
(reviews, loyalty, reservations, and the rest of this table) don't apply the
same way to a goods or services storefront, so Shelf and Booking aren't
scored against this matrix; see
[Themes for other kinds of business](themes.md#themes-for-other-kinds-of-business)
for what each of the two offers instead.

## Related pages

- [Storefront themes and customizer](themes.md) — choosing and customising
  a template.
- [Choosing your store type](store-types.md) — the store types that use
  Shelf or Booking instead of this matrix's seventeen templates.
- [Reviews, ratings and feedback](reviews-feedback.md)
- [AI agent settings](ai-agents.md)
- [Loyalty stamps](loyalty.md)
- [Coupons](coupons.md)
- [Banners and daily specials](banners.md)
- [Reservations](reservations.md)

Feature pages link here instead of listing themes inline.
