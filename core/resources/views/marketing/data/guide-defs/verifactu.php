<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * SEO Growth W5.3 — Verifactu (Spain) guide for restaurants. ES (single-locale).
 * Slug: verifactu. Title per KEYWORDS §Compliance.
 * country=null: no ES country page (W3.x), so the CTA falls back to register.
 *
 * Cite-check (2026-08-30, AEAT / Reglamento Veri*factu + public summaries):
 * - Timeline: 1 Jan 2026 for companies with turnover > €6M; 1 Jan 2027 for other
 *   companies (most hospitality); 1 Jul 2027 for self-employed / entities without
 *   legal personality.
 * - Invoicing software must be manufacturer-declared compliant (declaración
 *   responsable). Records are unalterable and cryptographically chained; every
 *   invoice carries a verifiable QR; in VERI*FACTU mode records are sent to the
 *   AEAT in real time and the invoice shows "VERI*FACTU" / "Factura verificable
 *   en la sede electrónica de la AEAT". Confirm current rules with the AEAT.
 */

return [
    'country' => null,

    'es' => [
        'eyebrow' => 'Guía de cumplimiento',
        'title' => 'Verifactu para restaurantes: guía 2026',
        'meta_title' => 'Verifactu para restaurantes: guía 2026',
        'meta' => 'Qué es Verifactu, plazos (2026-2027), requisitos del software de facturación, el QR en cada ticket y cómo cumplir en tu bar o restaurante.',
        'sub' => 'Qué es Verifactu, qué plazos te aplican y qué debe hacer tu software de facturación — explicado para bares y restaurantes.',
        'intro' => [
            'Verifactu es la normativa antifraude que obliga a que el software de facturación genere registros inalterables y verificables. Para un bar o restaurante, significa emitir tickets con un software conforme y un código QR verificable por el cliente.',
            'Esta guía explica los plazos, los requisitos técnicos y cómo el sistema se encarga de ello por ti.',
        ],
        'sections' => [
            [
                'heading' => '¿Qué es Verifactu?',
                'body' => [
                    'Es un sistema que exige que cada factura o ticket se genere con software conforme, quede registrada de forma inalterable y encadenada, y pueda verificarse. En modo VERI*FACTU los registros se envían a la AEAT en tiempo real.',
                ],
            ],
            [
                'heading' => 'Plazos: ¿cuándo me aplica?',
                'body' => [
                    'Las fechas de obligatoriedad son:',
                ],
                'list' => [
                    '1 de enero de 2026: empresas con facturación superior a 6 millones de euros.',
                    '1 de enero de 2027: el resto de sociedades (la mayoría de la hostelería).',
                    '1 de julio de 2027: autónomos y entidades sin personalidad jurídica.',
                ],
            ],
            [
                'heading' => 'Requisitos del software y el QR',
                'body' => [
                    'El software debe ser conforme mediante una declaración responsable del fabricante. Cada registro es inalterable y se encadena criptográficamente con el anterior, y cada ticket incluye un QR para que el cliente lo verifique. En modo VERI*FACTU la factura muestra «VERI*FACTU» o «Factura verificable en la sede electrónica de la AEAT».',
                ],
            ],
            [
                'heading' => 'Alcance para restaurantes',
                'body' => [
                    'Cualquier bar, restaurante, cafetería o negocio de restauración debe emitir sus tickets con software certificado según el calendario. Adaptarte a tiempo evita sanciones y sorpresas en una inspección.',
                ],
            ],
            [
                'heading' => 'Cómo lo resuelve el sistema por ti',
                'body' => [
                    'Tu carta y TPV emiten los tickets con registros conformes y el QR verificable, y (en modo VERI*FACTU) los envían a la AEAT automáticamente — sin trabajo manual por cada venta.',
                ],
            ],
        ],
        'faq_title' => 'Preguntas frecuentes sobre Verifactu',
        'faqs' => [
            ['q' => '¿Cuándo es obligatorio Verifactu para mi restaurante?', 'a' => 'El 1 de enero de 2026 para empresas con más de 6 M€ de facturación, el 1 de enero de 2027 para el resto de sociedades y el 1 de julio de 2027 para autónomos.'],
            ['q' => '¿Qué debe cumplir el software de facturación?', 'a' => 'Debe ser conforme (declaración responsable del fabricante), generar registros inalterables y encadenados, e incluir un QR verificable en cada ticket.'],
            ['q' => '¿Qué es el modo VERI*FACTU?', 'a' => 'Un modo en el que los registros se envían a la AEAT en tiempo real y la factura muestra «VERI*FACTU» o «Factura verificable en la sede electrónica de la AEAT».'],
        ],
        'cta_eyebrow' => 'Empieza',
        'cta_title' => 'Lanza una carta y un TPV listos para Verifactu',
        'cta_sub' => 'Carta digital QR, pedidos por WhatsApp y facturación conforme — sin comisiones por pedido.',
        'cta_label' => 'Empieza gratis',
    ],
];
