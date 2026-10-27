<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Per-locale store-ENTITY nouns for store_noun() (VerticalHelper.php).
 *
 * config('verticals.noun') holds the English forms and the Arabic ones (ar_*).
 * Every other locale lives here: [locale => [group => forms]], group being the
 * same vertical group key ('food' | 'goods' | 'services' | 'stays' | '_generic').
 *
 * Slots (what the ":noun" placeholder stands for in the lang string):
 *   title     bare noun, sentence start / label        "Restaurant"
 *   lower     "this :noun" (nominative/accusative)     "dieses Restaurant"
 *   poss      "your :noun" (nominative/accusative)     "dein Restaurant"
 *   indef     "a :noun"                                "ein Restaurant"
 *   plural    bare plural                              "Restaurants"
 *   gen       "of/for this :noun", only where the language has a genitive
 *             (ru); falls back to `lower`
 *   poss_gen  "of your :noun" (de, ru, tr genitive; pt contraction "do seu");
 *             falls back to `poss`
 *
 * Languages that inflect the determiner (de, es, fr, pt, ru, hi, ur) carry the
 * demonstrative / possessive / article INSIDE the form, so the surrounding
 * sentence never has to agree in gender or case. For that to stay true the
 * four nouns of a locale share one grammatical gender (de neuter, fr/es/pt/ru
 * masculine; hi/ur masculine, so the oblique "इस / आपके / اس / آپ کے" forms are
 * used with postpositions only). Turkish and Chinese need no agreement; tr
 * sentences use postpositions (için, ile, göre) so that only the nominative is
 * ever needed.
 *
 * `_generic` (no store in context / unknown store_type) mirrors `services`.
 */
$locales = [

    'de' => [
        'food' => ['title' => 'Restaurant', 'lower' => 'dieses Restaurant', 'poss' => 'dein Restaurant',
            'indef' => 'ein Restaurant', 'plural' => 'Restaurants', 'poss_gen' => 'deines Restaurants'],
        'goods' => ['title' => 'Geschäft', 'lower' => 'dieses Geschäft', 'poss' => 'dein Geschäft',
            'indef' => 'ein Geschäft', 'plural' => 'Geschäfte', 'poss_gen' => 'deines Geschäfts'],
        'services' => ['title' => 'Unternehmen', 'lower' => 'dieses Unternehmen', 'poss' => 'dein Unternehmen',
            'indef' => 'ein Unternehmen', 'plural' => 'Unternehmen', 'poss_gen' => 'deines Unternehmens'],
        'stays' => ['title' => 'Hotel', 'lower' => 'dieses Hotel', 'poss' => 'dein Hotel',
            'indef' => 'ein Hotel', 'plural' => 'Hotels', 'poss_gen' => 'deines Hotels'],
    ],

    'es' => [
        'food' => ['title' => 'Restaurante', 'lower' => 'este restaurante', 'poss' => 'tu restaurante',
            'indef' => 'un restaurante', 'plural' => 'restaurantes'],
        'goods' => ['title' => 'Comercio', 'lower' => 'este comercio', 'poss' => 'tu comercio',
            'indef' => 'un comercio', 'plural' => 'comercios'],
        'services' => ['title' => 'Negocio', 'lower' => 'este negocio', 'poss' => 'tu negocio',
            'indef' => 'un negocio', 'plural' => 'negocios'],
        'stays' => ['title' => 'Hotel', 'lower' => 'este hotel', 'poss' => 'tu hotel',
            'indef' => 'un hotel', 'plural' => 'hoteles'],
    ],

    'fr' => [
        'food' => ['title' => 'Restaurant', 'lower' => 'ce restaurant', 'poss' => 'votre restaurant',
            'indef' => 'un restaurant', 'plural' => 'restaurants'],
        'goods' => ['title' => 'Commerce', 'lower' => 'ce commerce', 'poss' => 'votre commerce',
            'indef' => 'un commerce', 'plural' => 'commerces'],
        'services' => ['title' => 'Établissement', 'lower' => 'cet établissement', 'poss' => 'votre établissement',
            'indef' => 'un établissement', 'plural' => 'établissements'],
        'stays' => ['title' => 'Hôtel', 'lower' => 'cet hôtel', 'poss' => 'votre hôtel',
            'indef' => 'un hôtel', 'plural' => 'hôtels'],
    ],

    'pt' => [
        'food' => ['title' => 'Restaurante', 'lower' => 'este restaurante', 'poss' => 'seu restaurante',
            'indef' => 'um restaurante', 'plural' => 'restaurantes', 'poss_gen' => 'do seu restaurante'],
        'goods' => ['title' => 'Comércio', 'lower' => 'este comércio', 'poss' => 'seu comércio',
            'indef' => 'um comércio', 'plural' => 'comércios', 'poss_gen' => 'do seu comércio'],
        'services' => ['title' => 'Negócio', 'lower' => 'este negócio', 'poss' => 'seu negócio',
            'indef' => 'um negócio', 'plural' => 'negócios', 'poss_gen' => 'do seu negócio'],
        'stays' => ['title' => 'Hotel', 'lower' => 'este hotel', 'poss' => 'seu hotel',
            'indef' => 'um hotel', 'plural' => 'hotéis', 'poss_gen' => 'do seu hotel'],
    ],

    'ru' => [
        'food' => ['title' => 'Ресторан', 'lower' => 'этот ресторан', 'poss' => 'ваш ресторан',
            'indef' => 'ресторан', 'plural' => 'рестораны', 'gen' => 'этого ресторана', 'poss_gen' => 'вашего ресторана'],
        'goods' => ['title' => 'Магазин', 'lower' => 'этот магазин', 'poss' => 'ваш магазин',
            'indef' => 'магазин', 'plural' => 'магазины', 'gen' => 'этого магазина', 'poss_gen' => 'вашего магазина'],
        'services' => ['title' => 'Бизнес', 'lower' => 'этот бизнес', 'poss' => 'ваш бизнес',
            'indef' => 'бизнес', 'plural' => 'бизнесы', 'gen' => 'этого бизнеса', 'poss_gen' => 'вашего бизнеса'],
        'stays' => ['title' => 'Отель', 'lower' => 'этот отель', 'poss' => 'ваш отель',
            'indef' => 'отель', 'plural' => 'отели', 'gen' => 'этого отеля', 'poss_gen' => 'вашего отеля'],
    ],

    'tr' => [
        'food' => ['title' => 'Restoran', 'lower' => 'bu restoran', 'poss' => 'restoranınız',
            'indef' => 'bir restoran', 'plural' => 'restoranlar', 'poss_gen' => 'restoranınızın'],
        'goods' => ['title' => 'Mağaza', 'lower' => 'bu mağaza', 'poss' => 'mağazanız',
            'indef' => 'bir mağaza', 'plural' => 'mağazalar', 'poss_gen' => 'mağazanızın'],
        'services' => ['title' => 'İşletme', 'lower' => 'bu işletme', 'poss' => 'işletmeniz',
            'indef' => 'bir işletme', 'plural' => 'işletmeler', 'poss_gen' => 'işletmenizin'],
        'stays' => ['title' => 'Otel', 'lower' => 'bu otel', 'poss' => 'oteliniz',
            'indef' => 'bir otel', 'plural' => 'oteller', 'poss_gen' => 'otelinizin'],
    ],

    'hi' => [
        'food' => ['title' => 'रेस्टोरेंट', 'lower' => 'इस रेस्टोरेंट', 'poss' => 'आपके रेस्टोरेंट',
            'indef' => 'एक रेस्टोरेंट', 'plural' => 'रेस्टोरेंट'],
        'goods' => ['title' => 'स्टोर', 'lower' => 'इस स्टोर', 'poss' => 'आपके स्टोर',
            'indef' => 'एक स्टोर', 'plural' => 'स्टोर'],
        'services' => ['title' => 'व्यवसाय', 'lower' => 'इस व्यवसाय', 'poss' => 'आपके व्यवसाय',
            'indef' => 'एक व्यवसाय', 'plural' => 'व्यवसाय'],
        'stays' => ['title' => 'होटल', 'lower' => 'इस होटल', 'poss' => 'आपके होटल',
            'indef' => 'एक होटल', 'plural' => 'होटल'],
    ],

    'ur' => [
        'food' => ['title' => 'ریسٹورنٹ', 'lower' => 'اس ریسٹورنٹ', 'poss' => 'آپ کے ریسٹورنٹ',
            'indef' => 'ایک ریسٹورنٹ', 'plural' => 'ریسٹورنٹس'],
        'goods' => ['title' => 'اسٹور', 'lower' => 'اس اسٹور', 'poss' => 'آپ کے اسٹور',
            'indef' => 'ایک اسٹور', 'plural' => 'اسٹورز'],
        'services' => ['title' => 'کاروبار', 'lower' => 'اس کاروبار', 'poss' => 'آپ کے کاروبار',
            'indef' => 'ایک کاروبار', 'plural' => 'کاروبار'],
        'stays' => ['title' => 'ہوٹل', 'lower' => 'اس ہوٹل', 'poss' => 'آپ کے ہوٹل',
            'indef' => 'ایک ہوٹل', 'plural' => 'ہوٹلز'],
    ],

    'zh' => [
        'food' => ['title' => '餐厅', 'lower' => '该餐厅', 'poss' => '您的餐厅',
            'indef' => '一家餐厅', 'plural' => '餐厅'],
        'goods' => ['title' => '店铺', 'lower' => '该店铺', 'poss' => '您的店铺',
            'indef' => '一家店铺', 'plural' => '店铺'],
        'services' => ['title' => '商户', 'lower' => '该商户', 'poss' => '您的商户',
            'indef' => '一家商户', 'plural' => '商户'],
        'stays' => ['title' => '酒店', 'lower' => '该酒店', 'poss' => '您的酒店',
            'indef' => '一家酒店', 'plural' => '酒店'],
    ],

];

// No store in context / unregistered store_type → the neutral set (same as `services`).
foreach ($locales as $code => $groups) {
    $locales[$code]['_generic'] = $groups['services'];
}

return $locales;
