<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth I2 — "Google Wallet loyalty cards" integration page.
 * Slug: google-wallet-loyalty. English (micro-volume, zero-competition term).
 * Describes the platform's real Google Wallet loyalty pass (a "Save to Google
 * Wallet" button mints a save-link for the customer's loyalty card; the pass
 * auto-updates as points change). No country CTA → falls back to register.
 */

return [
    'country' => null,

    'en' => [
        'eyebrow' => 'Google integration',
        'title' => 'Google Wallet Loyalty Cards for Restaurants',
        'meta' => 'Let customers save their loyalty card to Google Wallet in one tap — points update automatically. What it does and how to set it up for your restaurant or business.',
        'sub' => 'Put your loyalty programme in the phone your regulars already carry — a Google Wallet card that updates its points automatically.',
        'intro' => [
            'A Google Wallet loyalty card turns your points programme into a pass a customer saves to their phone. No app to install: they tap “Save to Google Wallet”, and their balance updates on its own as they earn and redeem.',
            'This page explains what the integration does and how to switch it on.',
        ],
        'sections' => [
            [
                'heading' => 'What a Google Wallet loyalty card is',
                'body' => [
                    'It is a digital loyalty pass in Google Wallet. Once a customer saves it, their current points balance lives on their phone and refreshes automatically — no paper stamp card, no re-scanning a QR every visit.',
                ],
            ],
            [
                'heading' => 'How customers add it',
                'body' => [
                    'A signed-in customer sees a “Save to Google Wallet” button on your storefront. One tap adds the card to their phone. Because the save link is generated only for that logged-in customer, each pass is tied to the right account.',
                ],
            ],
            [
                'heading' => 'How to switch it on',
                'body' => [
                    'Setup happens from your dashboard:',
                ],
                'list' => [
                    'Create your loyalty programme (points per order, rewards, tiers).',
                    'Enable Google Wallet for the loyalty card.',
                    'The “Save to Google Wallet” button then appears for signed-in customers on your storefront.',
                    'As customers earn or redeem points, their saved pass updates automatically.',
                ],
            ],
            [
                'heading' => 'Why it beats a stamp card',
                'body' => [
                    'A card in Google Wallet does not get lost, does not need reprinting, and keeps your brand on the customer’s lock screen. Every visit that earns points is a reason for them to come back.',
                ],
            ],
        ],
        'faq_title' => 'Google Wallet loyalty FAQ',
        'faqs' => [
            ['q' => 'What is a Google Wallet loyalty card?', 'a' => 'A digital loyalty pass a customer saves to Google Wallet; it shows their points balance and updates automatically as they earn and redeem.'],
            ['q' => 'Does the customer need to install an app?', 'a' => 'No — Google Wallet is built into most Android phones, and they add the card with a single “Save to Google Wallet” tap.'],
            ['q' => 'How do points stay up to date?', 'a' => 'The saved pass syncs automatically, so the balance on the customer’s phone reflects their real points after each order.'],
            ['q' => 'How do I turn it on?', 'a' => 'Create a loyalty programme and enable Google Wallet in your dashboard; the save button then appears for signed-in customers on your storefront.'],
        ],
        'cta_title' => 'Put your loyalty card in Google Wallet',
        'cta_sub' => 'Loyalty, ordering and a digital menu in one platform — no commission on your orders.',
    ],
];
