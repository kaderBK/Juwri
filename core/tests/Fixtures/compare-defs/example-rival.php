<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Test-only fixture for the W4.0 comparison-hub template. It exercises every
 * defs field (intro, grouped table with bool + string cells, notes, better-fit,
 * verdict, faqs) without shipping a fabricated competitor into the live defs
 * directory — CompareDefs::dir() is pointed here by CompareTemplateTest via the
 * seo.compare_defs_path config override. Not autoloaded, never served in prod.
 */

return [
    'name' => 'Example Rival',
    'eyebrow' => 'Comparison',
    'title' => 'Example Rival Alternative for Restaurants',
    'sub' => 'A side-by-side look at how the two stack up for QR menus and online ordering.',
    'meta_title' => 'Example Rival Alternative — Compared',
    'meta' => 'Compare Example Rival with juwri on price, commission and features.',
    'intro' => [
        'This is a fixture intro paragraph describing the comparison.',
        'A second paragraph so multi-paragraph intros are covered.',
    ],
    'groups' => [
        [
            'group' => 'Pricing',
            'rows' => [
                ['label' => 'Free plan', 'us' => true, 'them' => false],
                ['label' => 'Commission per order', 'us' => 'None', 'them' => 'Up to 30%'],
            ],
        ],
        [
            'group' => 'Features',
            'rows' => [
                ['label' => 'WhatsApp ordering', 'us' => true, 'them' => false],
                ['label' => 'Kitchen display', 'us' => true, 'them' => true],
            ],
        ],
    ],
    'notes_title' => 'How to switch in three steps',
    'notes' => [
        'Export your menu.',
        'Import into juwri.',
        'Publish your QR codes.',
    ],
    'better_fit' => [
        'title' => 'When Example Rival is the better fit',
        'points' => [
            'You are already deeply invested in their ecosystem.',
            'You need a niche feature only they offer.',
        ],
    ],
    'verdict_title' => 'The verdict',
    'verdict' => 'For most restaurants, keeping 100% of every order wins.',
    'faq_title' => 'Example Rival vs juwri FAQ',
    'faqs' => [
        ['q' => 'Is juwri really commission-free?', 'a' => 'Yes — flat subscription, no cut of your orders.'],
        ['q' => 'Can I migrate my existing menu?', 'a' => 'Yes, in a few steps.'],
    ],
];
