<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Expanded demo catalog for the pharmacy vertical (Havenwell Pharmacy — Carely theme).
 *
 * OTC ONLY. No prescription-only / Rx items anywhere in this file — every
 * product here is legitimately sold over-the-counter without a prescription.
 *
 * Shape matches VerticalShowcaseSeeder::makeItem() consumption:
 *   name, price, brand, desc, image, popular, stock, attrs, age_restricted,
 *   batch_no, expiry_date.
 *
 * attrs on medicines/supplements uses 'dosage' / 'usage' (rendered as named
 * info rows by the carely theme) plus free-form keys like
 * 'active_ingredient' (rendered generically).
 *
 * Images: 'image' => '<file>.jpg' refers to a file sourced into
 * /tmp/enrich/pharmacy/images/. Omitted where no clean product photo was
 * found (theme falls back to its placeholder).
 */

return [
    'brands' => ['Wellspring', 'Vitaboost', 'Purecare', 'Calmaid'],

    'categories' => [

        [
            'name' => 'Pain Relief',
            'items' => [
                [
                    'name' => 'Pain Relief Tablets 500mg (24ct)', 'brand' => 'Wellspring', 'price' => 5.25,
                    'desc' => 'Fast-acting pain and fever relief tablets, 24 count.',
                    'attrs' => [
                        'dosage' => '1-2 tablets every 4-6h, max 8 in 24h',
                        'usage' => 'For headache, fever & minor aches',
                        'active_ingredient' => 'Paracetamol 500mg',
                    ],
                    'batch_no' => 'WS-2291', 'expiry_date' => '2027-03-31',
                    'popular' => true, 'image' => 'pain-relief-tablets.jpg',
                ],
                [
                    'name' => 'Ibuprofen Caplets 200mg (24ct)', 'brand' => 'Wellspring', 'price' => 6.10,
                    'desc' => 'Anti-inflammatory pain reliever for headache, backache & muscle pain.',
                    'attrs' => [
                        'dosage' => '1-2 caplets every 4-6h, max 6 in 24h',
                        'usage' => 'For headache, backache & fever',
                        'active_ingredient' => 'Ibuprofen 200mg',
                    ],
                    'batch_no' => 'WS-2304', 'expiry_date' => '2027-01-15',
                    'image' => 'ibuprofen-caplets.jpg',
                ],
                [
                    'name' => 'Menthol Muscle Rub 100g', 'brand' => 'Wellspring', 'price' => 6.75,
                    'desc' => 'Topical menthol rub for muscle and joint discomfort.',
                    'attrs' => [
                        'dosage' => 'Apply to affected area up to 3x daily',
                        'usage' => 'For muscle & joint discomfort',
                        'active_ingredient' => 'Menthol 10%, Camphor 5%',
                    ],
                    'batch_no' => 'WS-2187', 'expiry_date' => '2026-11-30',
                    'image' => 'menthol-muscle-rub.jpg',
                ],
                [
                    'name' => 'Aspirin Low-Dose 81mg (100ct)', 'brand' => 'Wellspring', 'price' => 4.99,
                    'desc' => 'Low-dose aspirin tablets, 100 count bottle.',
                    'attrs' => [
                        'dosage' => 'As directed by a healthcare professional',
                        'usage' => 'Pain relief & fever reducer',
                        'active_ingredient' => 'Aspirin 81mg',
                    ],
                    'batch_no' => 'WS-2266', 'expiry_date' => '2027-06-30',
                ],
            ],
        ],

        [
            'name' => 'Vitamins & Supplements',
            'items' => [
                [
                    'name' => 'Vitamin C 1000mg (60ct)', 'brand' => 'Vitaboost', 'price' => 10.99,
                    'desc' => 'High-strength vitamin C supplement for immune support, 60 tablets.',
                    'attrs' => [
                        'dosage' => '1 tablet daily with food',
                        'usage' => 'Immune system support',
                        'active_ingredient' => 'Ascorbic acid 1000mg',
                    ],
                    'batch_no' => 'VB-2214', 'expiry_date' => '2027-09-30',
                    'image' => 'vitamin-c-bottle.jpg',
                ],
                [
                    'name' => 'Daily Multivitamin (30ct)', 'brand' => 'Vitaboost', 'price' => 13.50,
                    'desc' => 'Complete daily multivitamin and mineral formula.',
                    'attrs' => [
                        'dosage' => '1 tablet daily with breakfast',
                        'usage' => 'General daily wellness support',
                    ],
                    'batch_no' => 'VB-2233', 'expiry_date' => '2026-05-31', // expiring soon
                    'popular' => true, 'image' => 'multivitamin-bottle.jpg',
                ],
                [
                    'name' => 'Omega-3 Fish Oil (60ct)', 'brand' => 'Vitaboost', 'price' => 16.00,
                    'desc' => 'Purified fish oil softgels, 1000mg omega-3 per serving.',
                    'attrs' => [
                        'dosage' => '1 softgel twice daily with meals',
                        'usage' => 'Heart & joint health support',
                        'active_ingredient' => 'Fish oil (EPA/DHA) 1000mg',
                    ],
                    'batch_no' => 'VB-2258', 'expiry_date' => '2027-08-31',
                    'image' => 'omega3-fish-oil.jpg',
                ],
                [
                    'name' => 'Vitamin D3 2000IU (90ct)', 'brand' => 'Vitaboost', 'price' => 9.75,
                    'desc' => 'Vitamin D3 softgels supporting bone and immune health.',
                    'attrs' => [
                        'dosage' => '1 softgel daily',
                        'usage' => 'Bone & immune health support',
                        'active_ingredient' => 'Cholecalciferol (Vitamin D3) 2000IU',
                    ],
                    'batch_no' => 'VB-2277', 'expiry_date' => '2027-04-30',
                    'image' => 'vitamin-d3-bottle.jpg',
                ],
                [
                    'name' => 'Probiotic Complex 20 Billion CFU (30ct)', 'brand' => 'Vitaboost', 'price' => 18.90,
                    'desc' => 'Multi-strain probiotic capsules for digestive balance.',
                    'attrs' => [
                        'dosage' => '1 capsule daily, preferably with food',
                        'usage' => 'Digestive & gut health support',
                    ],
                    'batch_no' => 'VB-2296', 'expiry_date' => '2026-06-30', // expiring soon
                    'image' => 'probiotic-capsules.jpg',
                ],
            ],
        ],

        [
            'name' => 'Cold & Flu',
            'items' => [
                [
                    'name' => 'Cough Syrup Adult 100ml', 'brand' => 'Calmaid', 'price' => 7.75,
                    'desc' => 'Adult cough relief syrup, non-drowsy formula.',
                    'attrs' => [
                        'dosage' => '10ml every 4h, max 4 doses in 24h',
                        'usage' => 'For dry & chesty coughs',
                        'active_ingredient' => 'Dextromethorphan HBr 15mg/10ml',
                    ],
                    'age_restricted' => true, 'batch_no' => 'CA-2231', 'expiry_date' => '2027-03-31',
                    'popular' => true, 'image' => 'cough-syrup-bottle.jpg',
                ],
                [
                    'name' => 'Sinus Relief Capsules (16ct)', 'brand' => 'Calmaid', 'price' => 8.50,
                    'desc' => 'Decongestant capsules for sinus pressure and congestion.',
                    'attrs' => [
                        'dosage' => '1 capsule every 12h, max 2 in 24h',
                        'usage' => 'For sinus pressure & nasal congestion',
                        'active_ingredient' => 'Pseudoephedrine 120mg',
                    ],
                    'age_restricted' => true, 'batch_no' => 'CA-2248', 'expiry_date' => '2027-02-28',
                    'image' => 'sinus-relief-capsules.jpg',
                ],
                [
                    'name' => 'Cough Drops Honey Lemon (20ct)', 'brand' => 'Calmaid', 'price' => 3.25,
                    'desc' => 'Soothing honey-lemon throat lozenges.',
                    'attrs' => [
                        'dosage' => 'Dissolve 1 lozenge slowly, repeat as needed',
                        'usage' => 'Soothes sore throat & cough',
                    ],
                    'batch_no' => 'CA-2255', 'expiry_date' => '2027-05-31',
                    'image' => 'cough-drops.jpg',
                ],
                [
                    'name' => 'Multi-Symptom Cold & Flu Tablets (16ct)', 'brand' => 'Calmaid', 'price' => 9.10,
                    'desc' => 'Combination tablets for fever, congestion, and body aches.',
                    'attrs' => [
                        'dosage' => '2 tablets every 6h, max 8 in 24h',
                        'usage' => 'Multi-symptom cold & flu relief',
                        'active_ingredient' => 'Paracetamol 325mg + Phenylephrine 5mg',
                    ],
                    'age_restricted' => true, 'batch_no' => 'CA-2262', 'expiry_date' => '2026-05-31', // expiring soon
                ],
                [
                    'name' => 'Saline Nasal Spray 30ml', 'brand' => 'Purecare', 'price' => 5.99,
                    'desc' => 'Gentle isotonic saline spray to relieve nasal dryness and congestion.',
                    'attrs' => [
                        'dosage' => '1-2 sprays per nostril as needed',
                        'usage' => 'Nasal congestion & dryness relief',
                    ],
                    'batch_no' => 'PC-2189', 'expiry_date' => '2027-06-30',
                    'image' => 'saline-nasal-spray.jpg',
                ],
            ],
        ],

        [
            'name' => 'First Aid',
            'items' => [
                [
                    'name' => 'Adhesive Bandages Assorted (40ct)', 'brand' => 'Purecare', 'price' => 3.50,
                    'desc' => 'Assorted-size adhesive bandages for minor cuts and scrapes.',
                    'popular' => true, 'image' => 'adhesive-bandages.jpg',
                ],
                [
                    'name' => 'Antiseptic Wipes (30ct)', 'brand' => 'Purecare', 'price' => 4.25,
                    'desc' => 'Individually wrapped antiseptic cleansing wipes.',
                    'attrs' => [
                        'usage' => 'Cleanses minor cuts & grazes',
                        'active_ingredient' => 'Benzalkonium chloride 0.13%',
                    ],
                    'batch_no' => 'PC-2098', 'expiry_date' => '2026-04-30', // already-ish near-term
                ],
                [
                    'name' => 'Instant Cold Pack', 'brand' => 'Purecare', 'price' => 5.00,
                    'desc' => 'Single-use instant cold compress for sprains and bruises.',
                    'image' => 'instant-cold-pack.jpg',
                ],
                [
                    'name' => 'Sterile Gauze Roll 5cm x 4m', 'brand' => 'Purecare', 'price' => 2.99,
                    'desc' => 'Absorbent sterile gauze roll for wound dressing.',
                ],
                [
                    'name' => 'Antibiotic Ointment 15g', 'brand' => 'Purecare', 'price' => 4.75,
                    'desc' => 'First-aid antibiotic ointment to prevent infection in minor wounds.',
                    'attrs' => [
                        'dosage' => 'Apply a thin layer 1-3 times daily',
                        'usage' => 'Prevents infection in minor cuts, scrapes & burns',
                        'active_ingredient' => 'Bacitracin / Neomycin / Polymyxin B',
                    ],
                    'batch_no' => 'PC-2205', 'expiry_date' => '2027-01-31',
                    'image' => 'antibiotic-ointment.jpg',
                ],
            ],
        ],

        [
            'name' => 'Personal Care',
            'items' => [
                [
                    'name' => 'Hand Sanitizer Gel 250ml', 'brand' => 'Purecare', 'price' => 4.00,
                    'desc' => '70% alcohol hand sanitizer gel with aloe.',
                    'attrs' => [
                        'usage' => 'Kills 99.9% of germs without water',
                        'active_ingredient' => 'Ethyl alcohol 70%',
                    ],
                    'batch_no' => 'PC-2311', 'expiry_date' => '2027-11-30',
                    'popular' => true, 'image' => 'hand-sanitizer.jpg',
                ],
                [
                    'name' => 'Allergy Relief Tablets (30ct)', 'brand' => 'Wellspring', 'price' => 9.25,
                    'desc' => 'Non-drowsy 24-hour allergy relief tablets.',
                    'attrs' => [
                        'dosage' => '1 tablet daily',
                        'usage' => 'Relieves sneezing, itchy & watery eyes',
                        'active_ingredient' => 'Cetirizine HCl 10mg',
                    ],
                    'batch_no' => 'WS-2318', 'expiry_date' => '2027-08-31',
                    'image' => 'allergy-relief-tablets.jpg',
                ],
                [
                    'name' => 'Sunscreen Lotion SPF 50 100ml', 'brand' => 'Purecare', 'price' => 11.50,
                    'desc' => 'Broad-spectrum water-resistant sunscreen lotion.',
                    'attrs' => [
                        'usage' => 'Apply liberally before sun exposure, reapply every 2h',
                    ],
                    'batch_no' => 'PC-2244', 'expiry_date' => '2027-05-31',
                    'image' => 'sunscreen-lotion.jpg',
                ],
                [
                    'name' => 'Antacid Chewable Tablets (48ct)', 'brand' => 'Wellspring', 'price' => 5.90,
                    'desc' => 'Fast-relief chewable antacid tablets for heartburn and indigestion.',
                    'attrs' => [
                        'dosage' => '2-4 tablets as symptoms occur, max 16 in 24h',
                        'usage' => 'For heartburn, acid indigestion & upset stomach',
                        'active_ingredient' => 'Calcium carbonate 500mg',
                    ],
                    'batch_no' => 'WS-2325', 'expiry_date' => '2027-09-30',
                ],
                [
                    'name' => 'Artificial Tears Eye Drops 15ml', 'brand' => 'Purecare', 'price' => 6.20,
                    'desc' => 'Lubricating eye drops for dry, irritated eyes.',
                    'attrs' => [
                        'dosage' => '1-2 drops in each eye as needed',
                        'usage' => 'Relieves dry & irritated eyes',
                    ],
                    'batch_no' => 'PC-2260', 'expiry_date' => '2027-03-31',
                    'image' => 'eye-drops.jpg',
                ],
            ],
        ],

        [
            'name' => 'Baby & Child',
            'items' => [
                [
                    'name' => "Children's Pain & Fever Relief Syrup 100ml", 'brand' => 'Wellspring', 'price' => 6.99,
                    'desc' => 'Grape-flavored pain and fever relief syrup for children.',
                    'attrs' => [
                        'dosage' => 'Per weight-based dosing chart on label, every 4-6h',
                        'usage' => 'For children\'s fever & minor aches',
                        'active_ingredient' => 'Paracetamol 160mg/5ml',
                    ],
                    'batch_no' => 'WS-2340', 'expiry_date' => '2027-02-28',
                    'image' => 'childrens-syrup.jpg',
                ],
                [
                    'name' => 'Baby Diaper Rash Cream 100g', 'brand' => 'Purecare', 'price' => 5.75,
                    'desc' => 'Zinc oxide barrier cream to soothe and protect against diaper rash.',
                    'attrs' => [
                        'usage' => 'Apply at each diaper change',
                        'active_ingredient' => 'Zinc oxide 15%',
                    ],
                    'batch_no' => 'PC-2278', 'expiry_date' => '2027-07-31',
                    'image' => 'diaper-rash-cream.jpg',
                ],
                [
                    'name' => 'Infant Gripe Water 150ml', 'brand' => 'Calmaid', 'price' => 6.50,
                    'desc' => 'Gentle formula to relieve infant gas and colic discomfort.',
                    'attrs' => [
                        'dosage' => 'Per age-based dosing chart on label',
                        'usage' => 'For gas & colic discomfort',
                    ],
                    'batch_no' => 'CA-2285', 'expiry_date' => '2027-01-31',
                ],
                [
                    'name' => 'Baby Oral Rehydration Salts (10 sachets)', 'brand' => 'Calmaid', 'price' => 5.99,
                    'desc' => 'Pediatric rehydration salts for fluid loss from illness.',
                    'attrs' => [
                        'dosage' => 'Dissolve 1 sachet in 200ml water, per label guidance',
                        'usage' => 'Pediatric rehydration support',
                    ],
                    'batch_no' => 'CA-2292', 'expiry_date' => '2026-06-30', // expiring soon
                ],
            ],
        ],

        [
            'name' => 'Health Devices',
            'items' => [
                [
                    'name' => 'Digital Thermometer', 'brand' => 'Purecare', 'price' => 9.99,
                    'desc' => 'Fast 10-second digital thermometer with fever alert.',
                    'image' => 'digital-thermometer.jpg',
                ],
                [
                    'name' => 'Digital Blood Pressure Monitor', 'brand' => 'Purecare', 'price' => 34.99,
                    'desc' => 'Automatic upper-arm blood pressure monitor with irregular heartbeat detection.',
                    'popular' => true, 'image' => 'blood-pressure-monitor.jpg',
                ],
                [
                    'name' => 'Pulse Oximeter', 'brand' => 'Purecare', 'price' => 19.50,
                    'desc' => 'Fingertip pulse oximeter for blood oxygen and pulse rate readings.',
                    'image' => 'pulse-oximeter.jpg',
                ],
                [
                    'name' => 'Compact Nebulizer Kit', 'brand' => 'Purecare', 'price' => 42.00,
                    'desc' => 'Portable compressor nebulizer for at-home respiratory therapy.',
                    'image' => 'nebulizer-kit.jpg',
                ],
            ],
        ],

    ],
];
