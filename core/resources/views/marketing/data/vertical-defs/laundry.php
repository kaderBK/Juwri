<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — Laundry & services industry landing page (industries/laundry). EN + AR.
 * Positions the platform as an online booking builder for laundry & services:
 * per-item or per-kg service pricing, pickup & drop-off, time-slot booking,
 * and a service board. This is a SERVICES vertical — checkout is booking/pickup,
 * not shipping. Live demo: laundry-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'Laundry & services',
        'title' => 'Online booking builder for laundry & services',
        'meta_title' => 'Laundry booking builder — per-item, per-kg & pickup slots',
        'meta' => 'Build a laundry booking store with a QR service menu, per-item or per-kg pricing, pickup and drop-off, time-slot booking and a service board — no commission, live in minutes.',
        'sub' => 'A service menu priced per item or per kilo, pickup and drop-off with time-slot booking, and a service board that tracks every load — on a flat subscription, no commission per order.',
        'intro' => [
            'Take laundry orders without the phone. Price each service per item or per kilo, let customers pick a pickup or drop-off slot, and watch every load move across a service board from received to ready.',
            'The same engine that runs thousands of ordering storefronts, tuned for services: a QR service menu, booking and time slots, a service board, and per-item or per-kg pricing your customers understand at a glance.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live laundry demo',
        'demo_slug' => 'laundry-demo',
        'features_title' => 'Everything a laundry service needs',
        'features' => [
            ['icon' => '👔', 'title' => 'Per-item pricing', 'body' => 'Price each garment or service — shirt, suit, duvet — as its own line so customers see the cost before they book.'],
            ['icon' => '⚖️', 'title' => 'Per-kg pricing', 'body' => 'Charge wash-and-fold by the kilo with a clear rate so bulk loads price themselves automatically.'],
            ['icon' => '🚐', 'title' => 'Pickup & drop-off', 'body' => 'Offer doorstep pickup and drop-off or store hand-in — the booking follows the option the customer picks.'],
            ['icon' => '🗓️', 'title' => 'Time-slot booking', 'body' => 'Customers reserve a pickup or collection slot, and each slot fills only to the capacity you set.'],
            ['icon' => '📋', 'title' => 'Service board', 'body' => 'Every load moves across a board from received to washing to ready, so nothing is lost or forgotten.'],
            ['icon' => '🧾', 'title' => '0% commission', 'body' => 'Keep every booking on a flat subscription instead of losing a cut of each order to a marketplace.'],
        ],
        'faqs_title' => 'Laundry service questions',
        'faqs' => [
            ['q' => 'Can I price by item and by kilo?', 'a' => 'Yes — price each garment or service as its own line, and charge wash-and-fold by the kilo, in the same service menu.'],
            ['q' => 'Do customers book a pickup time?', 'a' => 'Yes. Customers reserve a pickup or drop-off slot, and each slot fills only to the capacity you set so your day stays manageable.'],
            ['q' => 'How do I track each load?', 'a' => 'Every booking moves across a service board from received to washing to ready, so staff always know what is where.'],
        ],
        'cta_title' => 'Open your laundry service — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'مغسلة وخدمات',
        'title' => 'منشئ حجوزات إلكتروني للمغاسل والخدمات',
        'meta_title' => 'منشئ حجوزات مغسلة — بالقطعة وبالكيلو ومواعيد استلام',
        'meta' => 'أنشئ متجر حجوزات مغسلة بقائمة خدمات QR وتسعير بالقطعة أو بالكيلو واستلام وتسليم وحجز بمواعيد ولوحة خدمات — بدون عمولة، وجاهز خلال دقائق.',
        'sub' => 'قائمة خدمات مسعّرة بالقطعة أو بالكيلو، واستلام وتسليم مع حجز بمواعيد، ولوحة خدمات تتابع كل شحنة — باشتراك ثابت وبدون عمولة على الطلبات.',
        'intro' => [
            'استقبل طلبات الغسيل دون هاتف. سعّر كل خدمة بالقطعة أو بالكيلو، ودع الزبائن يختارون موعد استلام أو تسليم، وتابع كل شحنة على لوحة خدمات من الاستلام إلى الجاهزية.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط للخدمات: قائمة خدمات QR وحجز بمواعيد، ولوحة خدمات، وتسعير بالقطعة أو بالكيلو يفهمه الزبون من نظرة.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض مغسلة مباشراً',
        'demo_slug' => 'laundry-demo',
        'features_title' => 'كل ما تحتاجه خدمة الغسيل',
        'features' => [
            ['icon' => '👔', 'title' => 'تسعير بالقطعة', 'body' => 'سعّر كل قطعة أو خدمة — قميص، بدلة، لحاف — كبند مستقل ليرى الزبون التكلفة قبل الحجز.'],
            ['icon' => '⚖️', 'title' => 'تسعير بالكيلو', 'body' => 'احتسب الغسيل والكيّ بالكيلو بسعر واضح فتُحسب الشحنات الكبيرة تلقائيًا.'],
            ['icon' => '🚐', 'title' => 'استلام وتسليم', 'body' => 'قدّم استلامًا وتسليمًا من الباب أو تسليمًا في المحل — يتبع الحجز الخيار الذي يختاره الزبون.'],
            ['icon' => '🗓️', 'title' => 'حجز بمواعيد', 'body' => 'يحجز الزبائن موعد استلام أو تسليم، ويمتلئ كل موعد حتى الطاقة التي تحددها فقط.'],
            ['icon' => '📋', 'title' => 'لوحة خدمات', 'body' => 'تنتقل كل شحنة على اللوحة من الاستلام إلى الغسيل إلى الجاهزية فلا يضيع شيء.'],
            ['icon' => '🧾', 'title' => 'بدون عمولة', 'body' => 'احتفظ بكامل قيمة كل حجز باشتراك ثابت بدلاً من خسارة حصة لكل طلب.'],
        ],
        'faqs_title' => 'أسئلة خدمة الغسيل',
        'faqs' => [
            ['q' => 'هل يمكنني التسعير بالقطعة وبالكيلو؟', 'a' => 'نعم — سعّر كل قطعة أو خدمة كبند مستقل، واحتسب الغسيل والكيّ بالكيلو، في قائمة الخدمات نفسها.'],
            ['q' => 'هل يحجز الزبائن موعد استلام؟', 'a' => 'نعم. يحجز الزبون موعد استلام أو تسليم، ويمتلئ كل موعد حتى الطاقة التي تحددها فقط ليبقى يومك منظّمًا.'],
            ['q' => 'كيف أتابع كل شحنة؟', 'a' => 'ينتقل كل حجز على لوحة الخدمات من الاستلام إلى الغسيل إلى الجاهزية فيعرف الفريق موقع كل شحنة دائمًا.'],
        ],
        'cta_title' => 'افتح خدمة الغسيل — جاهزة خلال دقائق',
    ],
];
