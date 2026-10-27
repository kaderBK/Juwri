<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth I2 — "Reserve with Google" integration page.
 * Slug: reserve-with-google. English (micro-volume, zero-competition term).
 * Describes the platform's real RwG integration (reservations + waitlist that
 * surface on Google Search & Maps; a booking server receives the bookings).
 * No country CTA → falls back to register.
 */

return [
    'country' => null,

    'en' => [
        'eyebrow' => 'Google integration',
        'title' => 'Reserve with Google for Restaurants — Setup Guide',
        'meta' => 'Let diners book a table straight from Google Search and Maps with Reserve with Google. What it is, how reservations and waitlist work, and how to switch it on.',
        'sub' => 'Turn a Google listing into a booking channel — diners reserve a table (or join the waitlist) without leaving Search or Maps.',
        'intro' => [
            'Reserve with Google lets people book a table directly from your Google Business Profile on Search and Maps. The booking flows straight into your dashboard — no third-party reservation fee, no separate login for the diner.',
            'This page explains what the integration does, how reservations and the waitlist appear on Google, and how to switch it on.',
        ],
        'sections' => [
            [
                'heading' => 'What Reserve with Google does',
                'body' => [
                    'When a guest finds your restaurant or business on Google, a “Reserve a table” action appears on your listing. They pick a time, confirm, and the reservation lands in your dashboard alongside your walk-ins and phone bookings — one calendar, no double-booking.',
                ],
            ],
            [
                'heading' => 'Reservations and waitlist, both on Google',
                'body' => [
                    'You can surface two things on your Google listing: table reservations and, when you are full, a waitlist a diner can join from Search. Both stay in sync with your floor in real time, so the availability Google shows is your actual availability.',
                ],
            ],
            [
                'heading' => 'How to switch it on',
                'body' => [
                    'Setup happens from your dashboard — no code:',
                ],
                'list' => [
                    'Turn on reservations (and optionally the waitlist) for the branch.',
                    'Confirm your service hours, table zones and party-size limits so Google shows real availability.',
                    'Connect your Google Business Profile so the “Reserve a table” action appears on your listing.',
                    'Go live — new bookings arrive in your dashboard and update Google automatically.',
                ],
            ],
            [
                'heading' => 'Why it is worth it',
                'body' => [
                    'Most diners decide where to eat on Google. Capturing the booking at that moment — inside Search and Maps — beats sending them to a third-party app that charges per cover and owns the guest relationship.',
                ],
            ],
        ],
        'faq_title' => 'Reserve with Google FAQ',
        'faqs' => [
            ['q' => 'What is Reserve with Google?', 'a' => 'A booking action on your Google listing that lets diners reserve a table (or join your waitlist) directly from Search and Maps, with the booking landing in your dashboard.'],
            ['q' => 'Is there a per-cover commission?', 'a' => 'No — bookings come through your own reservation system on a flat subscription, not a third-party app that charges per guest.'],
            ['q' => 'Can diners join a waitlist when I’m full?', 'a' => 'Yes — you can surface a waitlist on your Google listing, kept in sync with your floor in real time.'],
            ['q' => 'Do I need to write any code?', 'a' => 'No — you enable it from your dashboard and connect your Google Business Profile; availability then updates automatically.'],
        ],
        'cta_title' => 'Take table bookings straight from Google',
        'cta_sub' => 'Reservations, waitlist and online ordering in one dashboard — no per-cover commission.',
    ],
];
