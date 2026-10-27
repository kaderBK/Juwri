<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* Shared roster for the feature icon grid (home) and the /features page.
   Plain PHP on purpose: consumers `require` it into their own scope —
   variables set inside an @include'd Blade view never reach the parent
   view, so a .blade.php defs file silently defines nothing.

   Icon paths reuse the hero's 24x24 single-path style
   (the home hero) so the two surfaces stay visually consistent.
   'img' names a real capture in
   the old landing features/{n}.webp captures (the same files the timed
   accordion shows) — null means the feature has no live screenshot yet and
   renders as a text card on /features, never stock art. */

return [
    ['icon' => 'M2 3h2l2.6 12.6a2 2 0 0 0 2 1.6h8.7a2 2 0 0 0 2-1.5l1.7-7.2H6M9 21a1 1 0 1 0 0 .01M18 21a1 1 0 1 0 0 .01',
     'id' => 'f-ordering-dashboard',
     'label' => ___('Ordering dashboard'),
     'blurb' => ___('Every order lands on one live board — dine-in, pickup, delivery and WhatsApp — with statuses your whole team can follow.'),
     'img' => null],
    ['icon' => 'M3 3h8v8H3zm2 2v4h4V5zM3 13h8v8H3zm2 2v4h4v-4zM13 3h8v8h-8zm2 2v4h4V5zM13 13h3v3h-3zm5 0h3v3h-3zm-5 5h3v3h-3zm5 0h3v3h-3z',
     'id' => 'f-qr-menu-builder',
     'label' => ___('QR menu builder'),
     'blurb' => ___('Build categories, items, photos and options once — print the QR and your tables are live. Updates publish instantly.'),
     'img' => 1],
    ['icon' => 'M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-4-.9L3 21l1.9-4.9A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 12 3a8.5 8.5 0 0 1 9 8.5z',
     'id' => 'f-whatsapp-orders',
     'label' => ___('WhatsApp Orders'),
     'blurb' => ___('Diners order from the menu and it arrives as a structured WhatsApp message — no app installs, no missed calls.'),
     'img' => 2],
    ['icon' => 'M5 2h14a1 1 0 0 1 1 1v18l-3-2-2 2-2-2-2 2-2-2-3 2V3a1 1 0 0 1 1-1zm2 5h10M7 11h10M7 15h6',
     'id' => 'f-pos-kitchen',
     'label' => ___('POS & kitchen screens'),
     'blurb' => ___('A full register with shifts and drawers, plus kitchen displays that route every ticket to the right station.'),
     'img' => null],
    ['icon' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
     'id' => 'f-sales-analytics',
     'label' => ___('Sales analytics'),
     'blurb' => ___('Peak hours, top dishes and revenue per branch — see what is happening while it is still happening.'),
     'img' => 3],
    ['icon' => 'M3 7l9-4 9 4-9 4zM3 7v10l9 4 9-4V7M12 11v10',
     'id' => 'f-inventory',
     'label' => ___('Inventory & purchasing'),
     'blurb' => ___('Recipes deduct stock as orders land. Purchase orders, suppliers, wastage and low-stock alerts included.'),
     'img' => null],
    ['icon' => 'M12 2l2.9 6.3 6.9.6-5.2 4.6 1.6 6.8L12 17l-6.2 3.3 1.6-6.8L2.2 8.9l6.9-.6z',
     'id' => 'f-loyalty',
     'label' => ___('Loyalty and offers'),
     'blurb' => ___('Points, store credit, gift cards and campaigns that bring regulars back — every order grows your list.'),
     'img' => 4],
    ['icon' => 'M3 5h18v16H3zM3 9h18M8 2v4M16 2v4M8 13h3v3H8z',
     'id' => 'f-reservations',
     'label' => ___('Reservations'),
     'blurb' => ___('Bookings, a waitlist and a live floor map — seat guests faster and never lose a table to a no-show.'),
     'img' => null],
    ['icon' => 'M12 2a10 10 0 0 0 0 20h2a2 2 0 0 0 0-4h-1a2 2 0 0 1 0-4h3a4 4 0 0 0 4-4c0-4.4-3.6-8-8-8zM7.5 10a1 1 0 1 0 0 .01M10.5 6a1 1 0 1 0 0 .01M15.5 6.5a1 1 0 1 0 0 .01',
     'id' => 'f-branding',
     'label' => ___('Restaurant branding'),
     'blurb' => ___('40 themes, your colors, your domain and a link-in-bio page — a storefront that looks like you, without code.'),
     'img' => null],
    ['icon' => 'M4 4h16v12H9l-5 4zM8 9h8M8 12h5',
     'id' => 'f-feedback',
     'label' => ___('Customer feedback'),
     'blurb' => ___('Collect ratings and comments after every order — and turn happy guests into public reviews.'),
     'img' => null],
    ['icon' => 'M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20zM2 12h20M3.5 7h17M3.5 17h17M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
     'id' => 'f-multi-language',
     'label' => ___('Multi-language menus'),
     'blurb' => ___('Serve every guest in their own language — AI translates your menu and keeps RTL layouts right.'),
     'img' => 5],
    ['icon' => 'M6 2h9l5 5v15H6zM15 2v5h5M9 12h6M9 16h6',
     'id' => 'f-e-invoicing',
     'label' => ___('E-Invoicing'),
     'blurb' => ___('Compliant invoices generated and filed automatically — live drivers for ZATCA, JoFotara and ETA.'),
     'img' => null],
];
