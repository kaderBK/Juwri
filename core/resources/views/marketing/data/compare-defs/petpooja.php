<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W4.4 — Petpooja comparison page. English (India).
 * Slug: petpooja. KEYWORDS §Compare note: only directories rank the term.
 *
 * Cite-check (2026-08-30, petpooja.com):
 * - All-in-one business suite: POS (restaurant management), Invoice (GST
 *   billing), Attendo (payroll/attendance), Tasks. It is a POS/back-office
 *   suite, not a QR-menu-first product.
 * - No free plan and no published pricing — the site says "Book a Free Demo"
 *   and "Industry-low, transparent pricing models" without figures.
 * - GST billing / e-invoicing (India) is a core strength (Invoice product).
 * - QR code menu ordering and WhatsApp ordering are NOT advertised on
 *   petpooja.com (checked 2026-08-30) — hence the "—" cells. Honest framing:
 *   the two are different categories.
 */

return [
    'name' => 'Petpooja',
    'eyebrow' => 'Comparison',
    'title' => 'Petpooja Alternative for Restaurants (2026)',
    'meta_title' => 'Free Petpooja Alternative: QR Menu & WhatsApp Ordering',
    'meta' => 'Looking for a Petpooja alternative? Start free with a QR menu and WhatsApp ordering, with POS on paid plans and 0% commission on orders. An honest side-by-side of features and pricing.',
    'sub' => 'Petpooja is a full restaurant POS and GST-billing suite; this is a QR-menu and WhatsApp-ordering platform with a free plan. Here is where each one fits.',
    'intro' => [
        'Petpooja is a mature all-in-one Indian restaurant suite — POS, GST billing, inventory, payroll and marketplace order management. Pricing is on request and there is no free plan.',
        'If what you actually want is a free, self-serve QR menu with WhatsApp ordering and your own direct-ordering channel, that is a different category. This page compares the two honestly.',
    ],
    'groups' => [
        [
            'group' => 'Pricing',
            'rows' => [
                ['label' => 'Free plan', 'us' => true, 'them' => 'No — pricing on request'],
                ['label' => 'Published pricing', 'us' => true, 'them' => 'Book a demo'],
            ],
        ],
        [
            'group' => 'Features',
            'rows' => [
                ['label' => 'QR code menu', 'us' => true, 'them' => false],
                ['label' => 'WhatsApp ordering', 'us' => true, 'them' => false],
                ['label' => 'Full restaurant POS', 'us' => true, 'them' => true],
                ['label' => 'Inventory & staff management', 'us' => true, 'them' => true],
                ['label' => 'GST billing (India)', 'us' => false, 'them' => true],
            ],
        ],
    ],
    'better_fit' => [
        'title' => 'When Petpooja is the better fit',
        'points' => [
            'You run a larger Indian operation and need deep back-office: GST billing, inventory, payroll and attendance in one suite.',
            'You rely heavily on Zomato/Swiggy and want their marketplace order management and integrations.',
        ],
    ],
    'verdict_title' => 'The verdict',
    'verdict' => 'Petpooja is a strong choice for a full POS and back-office suite with India GST billing. If you want a free, self-serve QR menu with WhatsApp ordering and your own commission-free ordering channel, the alternative is the better starting point — and you can add POS features on one flat subscription.',
    'faq_title' => 'Petpooja comparison FAQ',
    'faqs' => [
        ['q' => 'Does Petpooja have a free plan?', 'a' => 'No — petpooja.com does not list a free plan or public pricing; it invites you to book a demo. The alternative offers a free plan.'],
        ['q' => 'Does Petpooja offer a QR menu and WhatsApp ordering?', 'a' => 'Those are not advertised on petpooja.com, which centres on POS, GST billing and back-office. The alternative provides a QR menu and WhatsApp ordering.'],
        ['q' => 'Which one should an Indian restaurant or shop choose?', 'a' => 'For deep POS, GST billing and marketplace management, Petpooja fits; for a free direct-ordering channel with QR and WhatsApp, the alternative fits — and covers core POS on paid plans.'],
    ],
];
