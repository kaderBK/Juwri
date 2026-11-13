<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Expanded demo catalog — carparts vertical (Ironclad Auto Parts — Torque Garage).
 *
 * Authored to match VerticalShowcaseSeeder::makeItem()/makeFitments():
 *   - oem/xref  -> Menu.attributes['oem_numbers']/['cross_refs'] (OEM search)
 *   - fits      -> list of [make, model, submodel, engine, yearFrom, yearTo],
 *                  expanded into one VehicleFitment row per model year
 *   - attrs     -> Menu.attributes, read defensively by torque/index.blade.php's
 *                  spec sheet. NOTE: 'difficulty' MUST be numeric 1-5 (the view
 *                  does `(int) $attrs['difficulty']` and feeds a 5-dot gauge via
 *                  diffDots(n) — a string like 'Moderate' would cast to 0 and the
 *                  row would silently not render).
 *   - variants  -> ['Axis' => [values]], cartesian product -> MenuVariant rows.
 *
 * Images: real sourced photos live in images/<file>.jpg; items without a
 * sourced photo simply omit 'image' and fall back to the seeder's placeholder.
 */

return [
    'brands' => [
        'Ironclad Auto Parts',
        'TorqueLine',
        'BrightBeam',
        'VoltCore',
        'Summit Motorworks',
        'RideRate Suspension',
        'ChemShield Fluids',
    ],

    'categories' => [

        // ------------------------------------------------------------ Brakes
        [
            'name' => 'Brakes',
            'items' => [
                [
                    'name' => 'Ceramic Brake Pad Set - Front', 'brand' => 'Ironclad Auto Parts', 'price' => 46.99,
                    'desc' => 'Low-dust ceramic brake pads with wear sensor, front axle set.',
                    'oem' => ['04465-06121', '04465-33471'], 'xref' => ['D1367', 'MD-1234'],
                    'fits' => [
                        ['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023],
                        ['Toyota', 'Corolla', 'LE', null, 2014, 2019],
                    ],
                    'attrs' => [
                        'part_number' => 'IC-BP-1042', 'position' => 'Front', 'torque' => '110 Nm',
                        'install_min' => 45, 'difficulty' => 2,
                        'tools' => 'Socket set, Torque wrench, C-clamp',
                        'warranty_yr' => 2, 'core_charge' => 0,
                    ],
                    'popular' => true, 'weight' => 2.1,
                ],
                [
                    'name' => 'Ceramic Brake Pad Set - Rear', 'brand' => 'Ironclad Auto Parts', 'price' => 39.99,
                    'desc' => 'Low-dust ceramic brake pads, rear axle set.',
                    'oem' => ['43022-TVA-A00'], 'xref' => ['D1543'],
                    'fits' => [
                        ['Honda', 'Civic', 'EX', '2.0L L4', 2016, 2021],
                        ['Honda', 'Accord', 'Sport', null, 2015, 2020],
                    ],
                    'attrs' => [
                        'part_number' => 'IC-BP-2210', 'position' => 'Rear', 'torque' => '95 Nm',
                        'install_min' => 40, 'difficulty' => 2,
                        'tools' => 'Socket set, C-clamp',
                        'warranty_yr' => 2, 'core_charge' => 0,
                    ],
                    'weight' => 1.8,
                ],
                [
                    'name' => 'Brake Rotor (Vented, Front)', 'brand' => 'TorqueLine', 'price' => 58.00,
                    'desc' => 'Vented front brake rotor, OE replacement dimensions.',
                    'oem' => ['FL3Z-1125-D'], 'xref' => ['600-54163'],
                    'fits' => [['Ford', 'F-150', 'XLT', '3.5L V6', 2015, 2020]],
                    'attrs' => [
                        'part_number' => 'TL-RT-3305', 'position' => 'Front', 'torque' => '150 Nm',
                        'install_min' => 60, 'difficulty' => 3,
                        'tools' => 'Socket set, Torque wrench, Rotor puller',
                        'warranty_yr' => 1, 'core_charge' => 0,
                    ],
                    'variants' => ['Size' => ['300mm', '320mm', '350mm']],
                    'weight' => 8.4,
                ],
                [
                    'name' => 'Brake Rotor (Solid, Rear)', 'brand' => 'TorqueLine', 'price' => 46.00,
                    'desc' => 'Solid rear brake rotor, OE replacement dimensions.',
                    'oem' => ['42431-06160'], 'xref' => ['600-63012'],
                    'fits' => [
                        ['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023],
                        ['Toyota', 'RAV4', 'XLE', null, 2019, 2023],
                    ],
                    'attrs' => [
                        'part_number' => 'TL-RT-3410', 'position' => 'Rear', 'torque' => '130 Nm',
                        'install_min' => 50, 'difficulty' => 3,
                        'tools' => 'Socket set, Torque wrench',
                        'warranty_yr' => 1, 'core_charge' => 0,
                    ],
                    'weight' => 6.0,
                ],
                [
                    'name' => 'Brake Caliper (Front, Loaded)', 'brand' => 'Ironclad Auto Parts', 'price' => 89.99,
                    'desc' => 'Loaded front brake caliper with pads pre-installed, core exchange.',
                    'oem' => ['19B2725'], 'xref' => ['141.65090'],
                    'fits' => [['Chevrolet', 'Silverado 1500', 'LT', '5.3L V8', 2015, 2019]],
                    'attrs' => [
                        'part_number' => 'IC-CAL-5091', 'position' => 'Front', 'torque' => '175 Nm',
                        'install_min' => 75, 'difficulty' => 4,
                        'tools' => 'Socket set, Torque wrench, Brake bleeder kit',
                        'warranty_yr' => 2, 'core_charge' => 35.00,
                    ],
                    'weight' => 4.3,
                ],
            ],
        ],

        // ----------------------------------------------------------- Filters
        [
            'name' => 'Filters',
            'items' => [
                [
                    'name' => 'Engine Air Filter', 'brand' => 'TorqueLine', 'price' => 17.50,
                    'desc' => 'High-flow panel air filter, direct OE fit.',
                    'oem' => ['17801-0P050'], 'xref' => ['CA10467'],
                    'fits' => [
                        ['Toyota', 'Camry', 'SE', null, 2015, 2019],
                        ['Nissan', 'Altima', 'SV', null, 2016, 2020],
                    ],
                    'attrs' => [
                        'part_number' => 'TL-AF-1104', 'install_min' => 10, 'difficulty' => 1,
                        'tools' => 'None — hand tools only', 'warranty_yr' => 1,
                    ],
                    'image' => 'engine-air-filter.jpg',
                ],
                [
                    'name' => 'Oil Filter', 'brand' => 'TorqueLine', 'price' => 9.49,
                    'desc' => 'Full-flow spin-on oil filter.',
                    'oem' => ['15400-PLM-A02'], 'xref' => ['51516'],
                    'fits' => [
                        ['Honda', 'Civic', 'EX', null, 2016, 2021],
                        ['Honda', 'CR-V', 'EX-L', null, 2017, 2021],
                    ],
                    'attrs' => [
                        'part_number' => 'TL-OF-2201', 'install_min' => 10, 'difficulty' => 1,
                        'tools' => 'Oil filter wrench', 'warranty_yr' => 1,
                    ],
                    'popular' => true, 'image' => 'oil-filter.jpg',
                ],
                [
                    'name' => 'Cabin Air Filter', 'brand' => 'TorqueLine', 'price' => 14.99,
                    'desc' => 'Particulate + odor cabin filter.',
                    'oem' => ['FL3Z-19N619-A'], 'xref' => ['CF11182'],
                    'fits' => [
                        ['Ford', 'F-150', 'XLT', null, 2015, 2020],
                        ['Chevrolet', 'Silverado 1500', 'LT', null, 2015, 2019],
                    ],
                    'attrs' => [
                        'part_number' => 'TL-CF-3312', 'install_min' => 15, 'difficulty' => 1,
                        'tools' => 'None — hand tools only', 'warranty_yr' => 1,
                    ],
                ],
                [
                    'name' => 'Fuel Filter', 'brand' => 'Summit Motorworks', 'price' => 19.99,
                    'desc' => 'In-line fuel filter, OE-spec flow and micron rating.',
                    'oem' => ['23300-75100'], 'xref' => ['G3410'],
                    'fits' => [['Toyota', 'Corolla', 'LE', null, 2014, 2019]],
                    'attrs' => [
                        'part_number' => 'SM-FF-4410', 'install_min' => 30, 'difficulty' => 3,
                        'tools' => 'Line disconnect tool, Fuel pressure gauge', 'warranty_yr' => 1,
                    ],
                ],
                [
                    'name' => 'Transmission Filter Kit', 'brand' => 'Summit Motorworks', 'price' => 28.00,
                    'desc' => 'Transmission filter with pan gasket, OE-fit kit.',
                    'oem' => ['35330-06010'], 'xref' => ['FT1256'],
                    'fits' => [['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023]],
                    'attrs' => [
                        'part_number' => 'SM-TF-5510', 'install_min' => 60, 'difficulty' => 3,
                        'tools' => 'Drain pan, Socket set, New gasket', 'warranty_yr' => 1,
                    ],
                ],
            ],
        ],

        // ---------------------------------------------------------- Lighting
        [
            'name' => 'Lighting',
            'items' => [
                [
                    'name' => 'LED Headlight Bulb Kit (H11)', 'brand' => 'BrightBeam', 'price' => 37.99,
                    'desc' => 'Plug-and-play LED conversion kit, 6500K white, pair.',
                    'oem' => ['H11-LED'], 'xref' => ['9145-LED'],
                    'fits' => [
                        ['Toyota', 'Corolla', 'LE', null, 2014, 2018],
                        ['Nissan', 'Altima', 'SV', null, 2016, 2020],
                    ],
                    'attrs' => [
                        'part_number' => 'BB-LED-H11', 'install_min' => 20, 'difficulty' => 2,
                        'tools' => 'Trim removal tool', 'warranty_yr' => 2,
                    ],
                    'image' => 'led-headlight-kit.jpg',
                ],
                [
                    'name' => 'Fog Light Assembly', 'brand' => 'BrightBeam', 'price' => 29.50,
                    'desc' => 'Direct-fit fog light housing with bulb, driver or passenger side.',
                    'oem' => ['33900-TBA-A01'], 'xref' => ['FL7080'],
                    'fits' => [['Honda', 'Civic', 'EX', null, 2016, 2021]],
                    'attrs' => [
                        'part_number' => 'BB-FOG-2201', 'position' => 'Front', 'install_min' => 25, 'difficulty' => 2,
                        'tools' => 'Screwdriver set', 'warranty_yr' => 1,
                    ],
                ],
                [
                    'name' => 'LED Tail Light Assembly', 'brand' => 'BrightBeam', 'price' => 64.99,
                    'desc' => 'Direct-fit LED tail light assembly with sequential turn signal.',
                    'oem' => ['81551-06590'], 'xref' => ['TL7734'],
                    'fits' => [['Toyota', 'Camry', 'SE', null, 2018, 2023]],
                    'attrs' => [
                        'part_number' => 'BB-TL-3391', 'position' => 'Rear', 'install_min' => 20, 'difficulty' => 1,
                        'tools' => 'None — hand tools only', 'warranty_yr' => 2,
                    ],
                    'popular' => true, 'image' => 'tail-light-assembly.jpg',
                ],
                [
                    'name' => 'Turn Signal Bulb Kit', 'brand' => 'BrightBeam', 'price' => 12.99,
                    'desc' => 'Amber LED turn signal bulb pair, universal wedge base.',
                    'attrs' => [
                        'part_number' => 'BB-TSB-1001', 'install_min' => 5, 'difficulty' => 1,
                        'tools' => 'None — hand tools only', 'warranty_yr' => 1,
                    ],
                    'stock' => 60,
                ],
                [
                    'name' => 'LED Interior Dome Light Kit', 'brand' => 'BrightBeam', 'price' => 15.99,
                    'desc' => 'Bright white LED dome + map light conversion kit, universal fit.',
                    'attrs' => [
                        'part_number' => 'BB-DOME-1102', 'install_min' => 10, 'difficulty' => 1,
                        'tools' => 'Trim removal tool', 'warranty_yr' => 1,
                    ],
                    'stock' => 50,
                ],
            ],
        ],

        // --------------------------------------------------------- Electrical
        [
            'name' => 'Electrical',
            'items' => [
                [
                    'name' => 'AGM Car Battery 12V 60Ah', 'brand' => 'VoltCore', 'price' => 134.99,
                    'desc' => 'Maintenance-free AGM battery, 3-year warranty.',
                    'oem' => ['H6-AGM'], 'xref' => ['48-AGM'],
                    'fits' => [
                        ['Toyota', 'Camry', 'SE', null, 2015, 2019],
                        ['Ford', 'F-150', 'XLT', null, 2015, 2020],
                    ],
                    'attrs' => [
                        'part_number' => 'VC-BAT-60AGM', 'torque' => '7 Nm', 'install_min' => 15, 'difficulty' => 1,
                        'tools' => 'Wrench set (terminal)', 'warranty_yr' => 3,
                    ],
                    'weight' => 17.5, 'popular' => true, 'image' => 'agm-battery.jpg',
                ],
                [
                    'name' => 'Alternator (Remanufactured)', 'brand' => 'VoltCore', 'price' => 152.00,
                    'desc' => 'OE-spec remanufactured alternator, 150A output, core exchange.',
                    'oem' => ['23-3401'], 'xref' => ['11265'],
                    'fits' => [['Chevrolet', 'Silverado 1500', 'LT', '5.3L V8', 2015, 2019]],
                    'attrs' => [
                        'part_number' => 'VC-ALT-3401', 'torque' => '48 Nm', 'install_min' => 90, 'difficulty' => 4,
                        'tools' => 'Socket set, Belt tensioner tool', 'warranty_yr' => 2, 'core_charge' => 45.00,
                    ],
                    'weight' => 6.2,
                ],
                [
                    'name' => 'Starter Motor (Remanufactured)', 'brand' => 'VoltCore', 'price' => 118.00,
                    'desc' => 'OE-spec remanufactured starter motor, core exchange.',
                    'oem' => ['17872'], 'xref' => ['SR-2601'],
                    'fits' => [['Honda', 'Accord', 'Sport', '1.5L Turbo', 2018, 2022]],
                    'attrs' => [
                        'part_number' => 'VC-STR-2601', 'torque' => '35 Nm', 'install_min' => 70, 'difficulty' => 4,
                        'tools' => 'Socket set, Jack stands', 'warranty_yr' => 2, 'core_charge' => 30.00,
                    ],
                    'weight' => 4.1, 'image' => 'starter-motor.jpg',
                ],
                [
                    'name' => 'Serpentine Belt', 'brand' => 'TorqueLine', 'price' => 23.50,
                    'desc' => 'Multi-rib serpentine belt, OE length and rib count.',
                    'oem' => ['6PK2110'], 'xref' => ['K060845'],
                    'fits' => [['Honda', 'Accord', 'Sport', null, 2015, 2020]],
                    'attrs' => [
                        'part_number' => 'TL-BELT-2110', 'install_min' => 30, 'difficulty' => 2,
                        'tools' => 'Belt tensioner tool', 'warranty_yr' => 1,
                    ],
                ],
                [
                    'name' => 'Ignition Coil Pack', 'brand' => 'VoltCore', 'price' => 34.99,
                    'desc' => 'Direct-fit ignition coil pack, improves misfire-prone starts.',
                    'oem' => ['90919-02252'], 'xref' => ['UF400'],
                    'fits' => [['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023]],
                    'attrs' => [
                        'part_number' => 'VC-COIL-4002', 'install_min' => 15, 'difficulty' => 2,
                        'tools' => 'Socket set', 'warranty_yr' => 2,
                    ],
                ],
            ],
        ],

        // ---------------------------------------------------- Fluids & Chemicals
        [
            'name' => 'Fluids & Chemicals',
            'items' => [
                [
                    'name' => 'Full Synthetic Motor Oil 5W-30 (5L)', 'brand' => 'Ironclad Auto Parts', 'price' => 34.99,
                    'desc' => 'API SP / dexos-approved full synthetic engine oil, universal fit.',
                    'attrs' => [
                        'part_number' => 'IC-OIL-5W30-5L', 'install_min' => 20, 'difficulty' => 1,
                        'tools' => 'Oil filter wrench, Drain pan',
                    ],
                    'stock' => 90, 'weight' => 4.7, 'popular' => true, 'image' => 'motor-oil.jpg',
                ],
                [
                    'name' => 'Long-Life Coolant (1 Gal)', 'brand' => 'Ironclad Auto Parts', 'price' => 15.50,
                    'desc' => 'Pre-mixed 50/50 extended-life coolant, universal fit.',
                    'attrs' => [
                        'part_number' => 'IC-COOL-1GAL', 'install_min' => 15, 'difficulty' => 1, 'tools' => 'Funnel',
                    ],
                    'stock' => 70, 'weight' => 3.8,
                ],
                [
                    'name' => 'Brake Fluid DOT 4 (500ml)', 'brand' => 'ChemShield Fluids', 'price' => 8.99,
                    'desc' => 'High-temperature DOT 4 brake fluid, universal fit.',
                    'attrs' => [
                        'part_number' => 'CS-BF4-500', 'install_min' => 10, 'difficulty' => 1,
                        'tools' => 'Turkey baster, Funnel',
                    ],
                    'stock' => 100,
                ],
                [
                    'name' => 'Power Steering Fluid (1L)', 'brand' => 'ChemShield Fluids', 'price' => 10.50,
                    'desc' => 'Universal power steering fluid, prevents pump wear and whine.',
                    'attrs' => [
                        'part_number' => 'CS-PSF-1L', 'install_min' => 10, 'difficulty' => 1, 'tools' => 'Funnel',
                    ],
                    'stock' => 85,
                ],
            ],
        ],

        // -------------------------------------------------------------- Engine
        [
            'name' => 'Engine',
            'items' => [
                [
                    'name' => 'Spark Plug Set (4pc)', 'brand' => 'Summit Motorworks', 'price' => 27.99,
                    'desc' => 'Iridium-tipped spark plugs, factory-gapped, set of 4.',
                    'oem' => ['90919-01247'], 'xref' => ['SK20HR11'],
                    'fits' => [
                        ['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023],
                        ['Toyota', 'Corolla', 'LE', null, 2014, 2019],
                    ],
                    'attrs' => [
                        'part_number' => 'SM-SP-2001', 'torque' => '25 Nm', 'install_min' => 45, 'difficulty' => 3,
                        'tools' => 'Spark plug socket, Torque wrench', 'warranty_yr' => 1,
                    ],
                ],
                [
                    'name' => 'Timing Belt Kit with Water Pump', 'brand' => 'Summit Motorworks', 'price' => 145.00,
                    'desc' => 'Complete timing belt kit with water pump, tensioner and idler.',
                    'oem' => ['TCKWP280'], 'xref' => ['TB280K1'],
                    'fits' => [['Honda', 'Accord', 'Sport', '1.5L Turbo', 2018, 2022]],
                    'attrs' => [
                        'part_number' => 'SM-TBK-280', 'torque' => '20 Nm', 'install_min' => 240, 'difficulty' => 5,
                        'tools' => 'Timing tool kit, Torque wrench, Socket set', 'warranty_yr' => 2,
                    ],
                ],
                [
                    'name' => 'Radiator', 'brand' => 'Summit Motorworks', 'price' => 98.00,
                    'desc' => 'Direct-fit aluminum-core radiator, OE capacity and mounting.',
                    'oem' => ['16400-0V420'], 'xref' => ['13029'],
                    'fits' => [['Toyota', 'Camry', 'SE', null, 2018, 2023]],
                    'attrs' => [
                        'part_number' => 'SM-RAD-1300', 'install_min' => 90, 'difficulty' => 4,
                        'tools' => 'Coolant drain pan, Pliers, Socket set', 'warranty_yr' => 2,
                    ],
                    'weight' => 5.6,
                ],
                [
                    'name' => 'Engine Mount (Front)', 'brand' => 'Summit Motorworks', 'price' => 42.00,
                    'desc' => 'Hydraulic front engine mount, reduces vibration and NVH.',
                    'oem' => ['12305-0H150'], 'xref' => ['A6633'],
                    'fits' => [['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023]],
                    'attrs' => [
                        'part_number' => 'SM-EM-6633', 'position' => 'Front', 'torque' => '55 Nm',
                        'install_min' => 60, 'difficulty' => 3,
                        'tools' => 'Jack, Socket set', 'warranty_yr' => 2,
                    ],
                    'weight' => 3.4,
                ],
            ],
        ],

        // --------------------------------------------------------- Suspension
        [
            'name' => 'Suspension',
            'items' => [
                [
                    'name' => 'Front Strut Assembly', 'brand' => 'RideRate Suspension', 'price' => 112.00,
                    'desc' => 'Complete quick-strut assembly with coil spring, ready to bolt on.',
                    'oem' => ['339216'], 'xref' => ['GS20105'],
                    'fits' => [['Toyota', 'Camry', 'SE', '2.5L L4', 2018, 2023]],
                    'attrs' => [
                        'part_number' => 'RR-STR-2011', 'position' => 'Front', 'torque' => '75 Nm',
                        'install_min' => 80, 'difficulty' => 4,
                        'tools' => 'Spring compressor, Socket set', 'warranty_yr' => 2,
                    ],
                    'weight' => 5.4, 'popular' => true, 'image' => 'front-strut.jpg',
                ],
                [
                    'name' => 'Control Arm with Ball Joint (Front)', 'brand' => 'RideRate Suspension', 'price' => 68.00,
                    'desc' => 'Front lower control arm, ball joint and bushings pre-installed.',
                    'oem' => ['RK620463'], 'xref' => ['CMS101173'],
                    'fits' => [['Honda', 'Accord', 'Sport', null, 2015, 2020]],
                    'attrs' => [
                        'part_number' => 'RR-CA-4630', 'position' => 'Front', 'torque' => '90 Nm',
                        'install_min' => 70, 'difficulty' => 4,
                        'tools' => 'Ball joint separator, Socket set', 'warranty_yr' => 2,
                    ],
                    'weight' => 4.8, 'image' => 'control-arm.jpg',
                ],
                [
                    'name' => 'Sway Bar Link Kit (Front)', 'brand' => 'RideRate Suspension', 'price' => 24.99,
                    'desc' => 'Front sway bar end link pair, OE-length and thread.',
                    'oem' => ['K80392'], 'xref' => ['SL620'],
                    'fits' => [['Ford', 'F-150', 'XLT', null, 2015, 2020]],
                    'attrs' => [
                        'part_number' => 'RR-SBL-6200', 'position' => 'Front', 'torque' => '65 Nm',
                        'install_min' => 30, 'difficulty' => 2,
                        'tools' => 'Wrench set', 'warranty_yr' => 1,
                    ],
                ],
                [
                    'name' => 'Shock Absorber (Rear)', 'brand' => 'RideRate Suspension', 'price' => 54.00,
                    'desc' => 'Gas-charged rear shock absorber, OE ride height and travel.',
                    'oem' => ['G7431'], 'xref' => ['37820'],
                    'fits' => [['Chevrolet', 'Silverado 1500', 'LT', '5.3L V8', 2015, 2019]],
                    'attrs' => [
                        'part_number' => 'RR-SA-3782', 'position' => 'Rear', 'torque' => '85 Nm',
                        'install_min' => 45, 'difficulty' => 3,
                        'tools' => 'Socket set, Jack stands', 'warranty_yr' => 2,
                    ],
                    'weight' => 3.9, 'image' => 'shock-absorber.jpg',
                ],
            ],
        ],

    ],
];
