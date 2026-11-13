<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W4.1 — GloriaFood comparison page.
 * Slug: gloriafood. English (KEYWORDS §Compare). Time-boxed: the whole angle is
 * Oracle's announced retirement of GloriaFood on 30 April 2027.
 *
 * Cite-check (2026-08-30):
 * - GloriaFood free ordering plan advertises (gloriafood.com/pricing):
 *   "No commission per order", "No monthly fee", "No setup fee",
 *   "Unlimited orders", "Unlimited locations".
 * - Paid add-ons on their pricing page: Online/Credit Card Payment Service
 *   US$29/mo; Restaurant POS System US$49/mo per location; Advanced Promo
 *   Marketing US$19/mo; Table Reservation Deposits US$0.5/guest.
 * - Oracle (acquired GloriaFood 2021) has announced a permanent shutdown on
 *   30 April 2027; new signups are closed and there is no migration tool —
 *   widely reported and consistent across sources. RE-CONFIRM before edits.
 */

return [
    'name' => 'GloriaFood',
    'eyebrow' => 'Comparison',
    'title' => 'GloriaFood Alternative (Free Migration Guide, 2026)',
    'meta_title' => 'GloriaFood Alternative: Free Migration Before It Shuts Down',
    'meta' => 'GloriaFood is shutting down on 30 April 2027. Compare a free, commission-free alternative and see how to migrate your menu, ordering and website before the cutoff.',
    'sub' => 'GloriaFood is being retired on 30 April 2027. Here is a free, commission-free alternative — and how to move across before the deadline.',
    'intro' => [
        'Oracle has announced that GloriaFood will shut down permanently on 30 April 2027. New sign-ups are already closed, there is no official migration tool, and after the cutoff the website ordering widget, the Facebook ordering button and stored data stop working.',
        'The good news: GloriaFood’s core appeal — a free, commission-free online-ordering system — is easy to replace on a platform that is actively maintained. This page compares the two honestly and shows how to migrate.',
    ],
    'groups' => [
        [
            'group' => 'Pricing',
            'rows' => [
                ['label' => 'Free plan', 'us' => true, 'them' => true],
                ['label' => 'Commission per order', 'us' => 'None', 'them' => 'None'],
                ['label' => 'Setup fee', 'us' => 'None', 'them' => 'None'],
                ['label' => 'Future of the platform', 'us' => 'Active & maintained', 'them' => 'Retiring 30 Apr 2027'],
            ],
        ],
        [
            'group' => 'Features',
            'rows' => [
                ['label' => 'QR code menu', 'us' => true, 'them' => true],
                ['label' => 'WhatsApp ordering', 'us' => true, 'them' => false],
                ['label' => 'Own POS & kitchen screens', 'us' => 'Included', 'them' => 'Add-on (US$49/mo per location)'],
                ['label' => 'E-invoicing (ZATCA / JoFotara / ETA)', 'us' => true, 'them' => false],
                ['label' => 'Data export & migration path', 'us' => true, 'them' => 'None after shutdown'],
            ],
        ],
    ],
    'notes_title' => 'How to migrate off GloriaFood',
    'notes' => [
        'Export your GloriaFood menu and data before 30 April 2027 — nothing is retained after the cutoff.',
        'Rebuild your menu in minutes, or photograph the paper menu and let AI import it.',
        'Swap the GloriaFood website widget and Facebook ordering button for your new ordering links.',
        'Reconnect payments, publish your QR codes, and you are live — commission-free.',
    ],
    'better_fit' => [
        'title' => 'When staying on GloriaFood still makes sense',
        'points' => [
            'You only need the existing free widget for the short time left before the 30 April 2027 cutoff.',
            'Even then, plan your migration now — there is no extension and no automatic export once the platform closes.',
        ],
    ],
    'verdict_title' => 'The verdict',
    'verdict' => 'For most restaurants and other businesses, moving now to an actively-maintained, commission-free platform avoids a rushed migration at the 2027 deadline — and adds WhatsApp ordering, a built-in POS and compliant e-invoicing on the way.',
    'faq_title' => 'GloriaFood shutdown & migration FAQ',
    'faqs' => [
        ['q' => 'Is GloriaFood really shutting down?', 'a' => 'Yes — Oracle has announced a permanent shutdown on 30 April 2027. New sign-ups are already closed and there is no official migration tool.'],
        ['q' => 'What happens to my menu and orders after the shutdown?', 'a' => 'The website ordering widget, Facebook ordering button and stored data stop working after the cutoff, so export your data and migrate before then.'],
        ['q' => 'Is the alternative also commission-free?', 'a' => 'Yes — like GloriaFood’s free plan, there is no commission on your orders; you pay a flat subscription instead.'],
        ['q' => 'How long does migration take?', 'a' => 'Usually minutes — rebuild the menu or AI-import it from a photo, swap your ordering links, reconnect payments and republish your QR codes.'],
    ],
];
