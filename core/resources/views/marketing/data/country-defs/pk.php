<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W3.7 — Pakistan country landing page (cc: pk). EN-first with
 * Roman-Urdu FAQ (Nastaliq landing pages have no commercial volume — see
 * KEYWORDS negatives). Hook: FBR-integrated POS + city coverage
 * (Karachi / Lahore / Islamabad). Confirm FBR scope with a tax advisor.
 */

return [
    '_default' => 'en',

    'en' => [
        'region_name' => 'Pakistan',
        'name' => 'Pakistan',
        'eyebrow' => 'QR menu & POS',
        'title' => 'QR Menu & Restaurant POS Pakistan — FBR Integrated',
        'meta' => 'QR code menu and restaurant POS for Pakistan: WhatsApp ordering, JazzCash & Easypaisa, and FBR-integrated invoicing — for Karachi, Lahore and Islamabad. Start free (POS and invoicing on paid plans), no commission.',
        'sub' => 'A QR digital menu, a full restaurant POS, WhatsApp ordering and FBR-integrated invoicing — for restaurants and shops in Karachi, Lahore and Islamabad, with no commission.',
        'intro' => [
            'Turn your restaurant, shop or café in Karachi, Lahore or Islamabad into a digital menu and POS that runs from the customer’s phone: scan a QR, order, and it lands on WhatsApp.',
            'As FBR requires integrated invoicing from restaurants and other businesses, your POS is built to issue FBR-compliant invoices — no separate cashier software to bolt on.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live menu',
        'demo_slug' => 'crave-demo',
        'gateways_title' => 'Payments',
        'gateways' => ['JazzCash', 'Easypaisa', 'Card', 'Bank transfer', 'Cash on delivery'],
        'compliance' => [
            'title' => 'FBR-integrated POS',
            'body' => 'The Federal Board of Revenue (FBR) requires integrated invoicing from restaurants and other businesses in scope. Your POS issues FBR-compliant invoices with the required details on each receipt.',
            'points' => [
                'FBR-integrated invoicing built into the POS.',
                'One system for QR menu, ordering and cashier.',
                'Confirm your scope and timeline with the FBR or a tax advisor.',
            ],
        ],
        'pain_title' => 'No commission on your orders',
        'pain' => 'foodpanda and other aggregators take up to ~30% of each order. On your own channel you keep 100% for a flat subscription.',
        'prices' => [
            'title' => 'Start today',
            'items' => ['Free plan, no card', 'Restaurant POS pricing on the pricing page (PKR)'],
        ],
        'faq_title' => 'Pakistan restaurant QR menu & POS FAQ',
        'faqs' => [
            ['q' => 'QR code menu banane ka tarika kya hai? (How do I make a QR code menu?)', 'a' => 'Free account banayein, apne items aur prices add karein, aur aap ko ek QR code aur link milta hai jo customer ke phone par aap ka menu minutes mein khol deta hai.'],
            ['q' => 'WhatsApp par order kaise le? (How do I take orders on WhatsApp?)', 'a' => 'Customer aap ka live menu browse karta hai, items add karta hai, aur order aap ke number par ek structured WhatsApp message ke taur par aata hai — koi app install nahi.'],
            ['q' => 'Restaurant POS price in Pakistan?', 'a' => 'You can start free and see restaurant POS pricing in PKR on the pricing page. There is no commission on your orders — just a flat subscription.'],
        ],
    ],
];
