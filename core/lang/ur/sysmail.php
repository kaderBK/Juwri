<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'ہیلو :name،',
    'terms_changed.intro' => 'ہم :brand کی شرائط اور ان کے ساتھ شامل دستاویزات کو اپ ڈیٹ کر رہے ہیں۔ نیا ورژن :date سے نافذ ہوگا۔',
    'terms_changed.notice' => 'اہم تبدیلیوں کی اطلاع ہم کم از کم 30 دن پہلے دیتے ہیں۔ یہ آپ کی اگلی تجدید سے لاگو ہوتی ہیں، یا اس سے پہلے اگر قانون تقاضا کرے یا آپ انہیں قبول کر لیں۔ اگر آپ کسی اہم تبدیلی سے متفق نہیں ہیں تو اس کے نافذ ہونے سے پہلے منسوخ کر سکتے ہیں۔',
    'terms_changed.cta' => 'تبدیلیاں پڑھیں',
    'app_overdue.failed_subject' => ':app کی تجدید ناکام ہو گئی',
    'app_overdue.failed_body' => 'ہم :app کی تجدید کے لیے :price وصول نہیں کر سکے (والیٹ میں بیلنس ناکافی ہے)۔ ایپ کام کرتی رہے گی۔ اپنا والیٹ ٹاپ اپ کریں، ہم ہر روز خود بخود دوبارہ کوشش کریں گے۔',
    'app_overdue.notice_subject' => ':app کو :date کو روک دیا جائے گا',
    'app_overdue.notice_body' => ':app کی :price کی تجدید 14 دن سے زیادہ تاخیر کا شکار ہے۔ اگر :date تک، یعنی اب سے 7 دن میں، ادائیگی نہ ہوئی تو ہم ایپ کو روک دیں گے۔ کوئی ڈیٹا حذف نہیں ہوگا، اور ادائیگی ہوتے ہی ایپ دوبارہ کام کرنے لگے گی۔',
];
