<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * rev/mv-marketing — Hotels & guesthouses industry landing page (industries/hotel). EN + AR.
 * Positions the platform as an online booking & in-room ordering builder for hotels:
 * room types, availability & bookings, QR in-room ordering, charge-to-room folio,
 * and breakfast pre-order. Live demo: hotel-demo.
 */

return [
    '_default' => 'en',

    'en' => [
        'eyebrow' => 'Hotels & guesthouses',
        'title' => 'Booking & in-room ordering for hotels',
        'meta_title' => 'Hotel booking builder — rooms, QR in-room orders & folio',
        'meta' => 'Build a hotel booking store with room types and availability, QR in-room ordering, charge-to-room folio and breakfast pre-order — on a flat subscription, live in minutes.',
        'sub' => 'Room types with live availability and bookings, QR in-room ordering that charges to the room folio, and breakfast pre-order — on a flat subscription, no commission per booking.',
        'intro' => [
            'Run the room and everything in it from one place. Publish room types with live availability, take direct bookings, and give every guest a QR they scan to order to their room — charged straight to their folio.',
            'The same engine that runs thousands of ordering storefronts, tuned for hospitality: room bookings, a QR in-room menu, an order board for the kitchen and front desk, and a breakfast pre-order that lands before service.',
        ],
        'cta_primary' => 'Start free',
        'cta_demo' => 'See a live hotel demo',
        'demo_slug' => 'hotel-demo',
        'features_title' => 'Everything a hotel needs',
        'features' => [
            ['icon' => '🛏️', 'title' => 'Room types', 'body' => 'Publish each room type with photos, rates and amenities so guests pick the right room fast.'],
            ['icon' => '📅', 'title' => 'Availability & bookings', 'body' => 'Live availability takes direct bookings and only accepts a room while it is free for the dates.'],
            ['icon' => '📱', 'title' => 'QR in-room ordering', 'body' => 'Every room gets a QR guests scan to order food, service or amenities straight from their phone.'],
            ['icon' => '🧾', 'title' => 'Charge to room', 'body' => 'In-room orders post to the guest folio and settle at checkout — no card needed at the point of order.'],
            ['icon' => '🥐', 'title' => 'Breakfast pre-order', 'body' => 'Guests pre-order breakfast the night before so the kitchen has every order ready before service.'],
            ['icon' => '🗂️', 'title' => 'Order & front-desk board', 'body' => 'Room bookings and in-room orders land on one board the kitchen and front desk work from together.'],
        ],
        'faqs_title' => 'Hotel questions',
        'faqs' => [
            ['q' => 'Can guests book a room directly?', 'a' => 'Yes — publish room types with live availability and take direct bookings, and a room is only accepted while it is free for the dates.'],
            ['q' => 'How does in-room ordering work?', 'a' => 'Each room gets a QR the guest scans to order from their phone, and the order lands on the board for the kitchen and front desk.'],
            ['q' => 'Can orders be charged to the room?', 'a' => 'Yes. In-room orders post to the guest folio and settle at checkout, so no card is needed at the moment of ordering.'],
        ],
        'cta_title' => 'Open your hotel storefront — live in minutes',
    ],

    'ar' => [
        'eyebrow' => 'فنادق ونُزل',
        'title' => 'حجوزات وطلبات داخل الغرفة للفنادق',
        'meta_title' => 'منشئ حجوزات فندق — غرف وطلبات QR وفاتورة غرفة',
        'meta' => 'أنشئ متجر حجوزات فندق بأنواع غرف وإتاحة مباشرة وطلبات داخل الغرفة عبر QR وقيد على فاتورة الغرفة وحجز إفطار مسبق — باشتراك ثابت، وجاهز خلال دقائق.',
        'sub' => 'أنواع غرف بإتاحة مباشرة وحجوزات، وطلبات داخل الغرفة عبر QR تُقيَّد على فاتورة الغرفة، وحجز إفطار مسبق — باشتراك ثابت وبدون عمولة على الحجز.',
        'intro' => [
            'أدِر الغرفة وكل ما فيها من مكان واحد. انشر أنواع الغرف بإتاحة مباشرة، واستقبل الحجوزات المباشرة، وامنح كل نزيل باركود QR يمسحه ليطلب إلى غرفته — ويُقيَّد الطلب مباشرة على فاتورته.',
            'المحرّك نفسه الذي يشغّل آلاف متاجر الطلبات، مضبوط للضيافة: حجوزات غرف، وقائمة QR داخل الغرفة، ولوحة طلبات للمطبخ والاستقبال، وحجز إفطار مسبق يصل قبل موعد التقديم.',
        ],
        'cta_primary' => 'ابدأ مجاناً',
        'cta_demo' => 'شاهد عرض فندق مباشراً',
        'demo_slug' => 'hotel-demo',
        'features_title' => 'كل ما يحتاجه الفندق',
        'features' => [
            ['icon' => '🛏️', 'title' => 'أنواع الغرف', 'body' => 'انشر كل نوع غرفة بالصور والأسعار والمرافق ليختار النزيل الغرفة المناسبة بسرعة.'],
            ['icon' => '📅', 'title' => 'إتاحة وحجوزات', 'body' => 'إتاحة مباشرة تستقبل الحجوزات المباشرة ولا تقبل الغرفة إلا وهي شاغرة في التواريخ.'],
            ['icon' => '📱', 'title' => 'طلبات QR داخل الغرفة', 'body' => 'لكل غرفة باركود QR يمسحه النزيل ليطلب طعامًا أو خدمة أو مستلزمات من هاتفه مباشرة.'],
            ['icon' => '🧾', 'title' => 'قيد على الغرفة', 'body' => 'تُقيَّد طلبات الغرفة على فاتورة النزيل وتُسوّى عند المغادرة — دون بطاقة لحظة الطلب.'],
            ['icon' => '🥐', 'title' => 'حجز إفطار مسبق', 'body' => 'يحجز النزلاء الإفطار مساء اليوم السابق فيجهّز المطبخ كل طلب قبل موعد التقديم.'],
            ['icon' => '🗂️', 'title' => 'لوحة طلبات واستقبال', 'body' => 'تصل حجوزات الغرف وطلبات الغرفة إلى لوحة واحدة يعمل عليها المطبخ والاستقبال معًا.'],
        ],
        'faqs_title' => 'أسئلة الفندق',
        'faqs' => [
            ['q' => 'هل يستطيع النزلاء حجز غرفة مباشرة؟', 'a' => 'نعم — انشر أنواع الغرف بإتاحة مباشرة واستقبل الحجوزات المباشرة، ولا تُقبل الغرفة إلا وهي شاغرة في التواريخ.'],
            ['q' => 'كيف تعمل الطلبات داخل الغرفة؟', 'a' => 'لكل غرفة باركود QR يمسحه النزيل ليطلب من هاتفه، ويصل الطلب إلى لوحة المطبخ والاستقبال.'],
            ['q' => 'هل يمكن قيد الطلبات على الغرفة؟', 'a' => 'نعم. تُقيَّد طلبات الغرفة على فاتورة النزيل وتُسوّى عند المغادرة، فلا حاجة لبطاقة لحظة الطلب.'],
        ],
        'cta_title' => 'افتح واجهة فندقك — جاهزة خلال دقائق',
    ],
];
