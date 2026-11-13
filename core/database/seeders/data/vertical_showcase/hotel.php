<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * The Aurelia Grand — hotel demo catalog. Rooms/stays/folio are seeded
 * separately (VerticalShowcaseSeeder::buildHotelExtras + the hotel engine).
 * These menu items are the in-room DINING, SPA & WELLNESS and EXPERIENCES the
 * aurelia theme sells (ordered via the goods cart / room_service, chargeable to
 * room). Images resolve from database/seeders/data/showcase_images/hotel/.
 */

return [
    'brands' => [],
    'categories' => [
        ['name' => 'Breakfast', 'items' => [
            ['name' => 'Aurelia Continental Breakfast', 'price' => 22, 'popular' => true, 'image' => 'dining-continental-breakfast.jpg',
                'desc' => 'Fresh pastries, seasonal fruit, yoghurt, juice and a hot beverage — served to your room.'],
            ['name' => 'Full Arabic Breakfast', 'price' => 28, 'popular' => true, 'image' => 'dining-arabic-breakfast.jpg',
                'desc' => 'Foul, shakshuka, halloumi, labneh, olives and fresh khubz.'],
            ['name' => 'Eggs Benedict', 'price' => 19, 'image' => 'dining-eggs-benedict.jpg',
                'desc' => 'Poached eggs, hollandaise and smoked turkey on a toasted muffin.'],
            ['name' => 'Acai & Granola Bowl', 'price' => 16, 'image' => 'dining-acai-bowl.jpg',
                'desc' => 'Acai, house granola, banana, berries and honey.'],
        ]],
        ['name' => 'All-Day Dining', 'items' => [
            ['name' => 'Wagyu Beef Burger', 'price' => 32, 'popular' => true, 'image' => 'dining-burger.jpg',
                'desc' => 'Wagyu patty, aged cheddar, truffle aioli and hand-cut fries.'],
            ['name' => 'Grilled Sea Bass', 'price' => 46, 'image' => 'dining-sea-bass.jpg',
                'desc' => 'Line-caught sea bass, saffron beurre blanc and seasonal vegetables.'],
            ['name' => 'Truffle Mushroom Risotto', 'price' => 34, 'image' => 'dining-risotto.jpg',
                'desc' => 'Arborio rice, wild mushrooms, parmesan and black truffle.'],
            ['name' => 'Caesar Salad', 'price' => 18, 'image' => 'dining-caesar-salad.jpg',
                'desc' => 'Cos lettuce, aged parmesan, sourdough croutons and grilled chicken.'],
            ['name' => 'Margherita Pizza', 'price' => 24, 'image' => 'dining-pizza.jpg',
                'desc' => 'San Marzano tomato, fior di latte and fresh basil.'],
        ]],
        ['name' => 'Beverages', 'items' => [
            ['name' => 'Fresh Orange Juice', 'price' => 9, 'image' => 'dining-orange-juice.jpg', 'desc' => 'Cold-pressed, served chilled.'],
            ['name' => 'Signature Arabic Coffee', 'price' => 11, 'popular' => true, 'image' => 'dining-arabic-coffee.jpg', 'desc' => 'Cardamom-spiced gahwa with dates.'],
            ['name' => 'Fresh Mint Lemonade', 'price' => 10, 'image' => 'dining-mint-lemonade.jpg', 'desc' => 'Lemon, fresh mint and a hint of honey.'],
        ]],
        ['name' => 'Desserts', 'items' => [
            ['name' => 'Pistachio Kunafa', 'price' => 15, 'popular' => true, 'image' => 'dining-kunafa.jpg', 'desc' => 'Warm kunafa, clotted cream and crushed pistachio.'],
            ['name' => 'Valrhona Chocolate Fondant', 'price' => 16, 'image' => 'dining-fondant.jpg', 'desc' => 'Molten dark-chocolate fondant with vanilla gelato.'],
        ]],
        ['name' => 'Spa & Wellness', 'items' => [
            ['name' => 'Signature Aromatherapy Massage (60 min)', 'price' => 260, 'popular' => true, 'image' => 'spa-massage.jpg',
                'pricing_unit' => 'each', 'turnaround_hours' => 1, 'desc' => 'A calming full-body massage with bespoke essential oils.'],
            ['name' => 'Hot Stone Therapy (90 min)', 'price' => 340, 'image' => 'spa-hot-stone.jpg',
                'pricing_unit' => 'each', 'turnaround_hours' => 2, 'desc' => 'Deep warmth to release tension and restore balance.'],
            ['name' => 'Radiance Facial (60 min)', 'price' => 220, 'image' => 'spa-facial.jpg',
                'pricing_unit' => 'each', 'turnaround_hours' => 1, 'desc' => 'A brightening facial tailored to your skin.'],
            ['name' => 'Hammam Ritual', 'price' => 300, 'image' => 'spa-hammam.jpg',
                'pricing_unit' => 'each', 'turnaround_hours' => 2, 'desc' => 'Traditional steam, exfoliation and cleanse.'],
        ]],
        ['name' => 'Experiences', 'items' => [
            ['name' => 'Private Desert Safari', 'price' => 480, 'popular' => true, 'image' => 'exp-desert-safari.jpg',
                'pricing_unit' => 'each', 'desc' => 'Dune drive, sunset, and a private Bedouin-style dinner.'],
            ['name' => 'City Heritage Tour', 'price' => 190, 'image' => 'exp-city-tour.jpg',
                'pricing_unit' => 'each', 'desc' => 'A guided half-day tour of the old city and museums.'],
            ['name' => 'Airport Luxury Transfer', 'price' => 120, 'image' => 'exp-transfer.jpg',
                'pricing_unit' => 'each', 'desc' => 'Private chauffeur transfer in an executive sedan.'],
            ['name' => 'Rooftop Pool Day Pass', 'price' => 85, 'image' => 'exp-pool.jpg',
                'pricing_unit' => 'each', 'desc' => 'Full-day access to the infinity pool and lounge.'],
        ]],
    ],
];
