<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W5.3 — FBR Integrated POS (Pakistan) guide for restaurants. EN.
 * Slug: fbr-pos. Title per KEYWORDS §Compliance.
 * country=pk: links the CTA to the Pakistan country page (W3.7) once it ships,
 * else falls back to register.
 *
 * Cite-check (2026-08-30, FBR + public summaries):
 * - FBR POS integration connects a POS to FBR for real-time sales reporting;
 *   each sale is sent to FBR and prints an FBR-verified invoice with a QR code
 *   the customer can verify in the FBR Tax Asaan app.
 * - Mandatory for Tier-1 retailers (Income Tax Ordinance 2001). Tier-1 includes
 *   brand outlets/chains/franchises, businesses in air-conditioned malls, and a
 *   business whose electricity bill over the last 12 months exceeded PKR
 *   1,200,000 (12 lac). Confirm current thresholds with FBR.
 */

return [
    'country' => 'pk',

    'en' => [
        'eyebrow' => 'Compliance guide',
        'title' => 'FBR Integrated POS for Restaurants — 2026 Guide',
        'meta_title' => 'FBR Integrated POS for Restaurants — 2026 Guide',
        'meta' => 'How restaurants in Pakistan integrate their POS with FBR for real-time invoicing: who must comply (Tier-1), the QR-coded FBR invoice, and how to get set up.',
        'sub' => 'What FBR POS integration means for a restaurant or business in Pakistan — who must comply, the QR-coded FBR invoice, and how to get set up.',
        'intro' => [
            'FBR POS integration connects your point-of-sale to the Federal Board of Revenue so that every sale is reported in real time. Each transaction prints an FBR-verified invoice with a QR code the customer can check in the FBR Tax Asaan app.',
            'This guide explains what integration is, who must comply, and how a restaurant or business gets set up.',
        ],
        'sections' => [
            [
                'heading' => 'What FBR POS integration is',
                'body' => [
                    'It is a real-time link between your POS and FBR’s central system. When you make a sale, the software sends the invoice details to FBR and prints a QR-coded, FBR-verified receipt — proof to the customer (and the authority) that the sale was reported.',
                ],
            ],
            [
                'heading' => 'Who must integrate (Tier-1 retailers)',
                'body' => [
                    'Under the Income Tax Ordinance 2001, Tier-1 retailers must integrate their POS with FBR for real-time sales-tax reporting. Tier-1 includes:',
                ],
                'list' => [
                    'Outlets, chains and franchises of national or international brands.',
                    'Businesses operating in air-conditioned shopping malls, plazas or centres (excluding kiosks).',
                    'A business whose electricity bill over the last 12 months exceeded PKR 1,200,000 (12 lac).',
                ],
            ],
            [
                'heading' => 'Real-time invoicing and the QR code',
                'body' => [
                    'On every sale the POS generates a QR code printed on the customer’s invoice. The customer can verify the receipt in FBR’s Tax Asaan app — which proves your restaurant or business is compliant and builds trust.',
                ],
            ],
            [
                'heading' => 'How to get compliant',
                'body' => [
                    'Your menu and POS report each sale to FBR automatically and print the QR-coded invoice — no manual filing per sale.',
                ],
                'list' => [
                    'Register your business and POS with FBR for integration.',
                    'Connect your POS so invoices are reported to FBR in real time.',
                    'Confirm your exact scope and timeline with FBR or your tax advisor.',
                ],
            ],
        ],
        'faq_title' => 'FBR POS integration FAQ',
        'faqs' => [
            ['q' => 'Which restaurants and businesses must integrate their POS with FBR?', 'a' => 'Tier-1 retailers — brand outlets/chains/franchises, businesses in air-conditioned malls, or those whose electricity bill exceeded PKR 1,200,000 over the last 12 months.'],
            ['q' => 'What is on an FBR-integrated invoice?', 'a' => 'A QR code the customer can verify in the FBR Tax Asaan app, confirming the sale was reported in real time.'],
            ['q' => 'Does the reporting happen automatically?', 'a' => 'Yes — an integrated POS sends each sale to FBR and prints the QR-coded invoice without manual filing per transaction.'],
        ],
        'cta_title' => 'Launch an FBR-ready menu and POS',
        'cta_sub' => 'QR menu, WhatsApp ordering and FBR-integrated invoicing — with no commission on your orders.',
    ],
];
