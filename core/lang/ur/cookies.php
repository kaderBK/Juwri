<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'کوکیز',
    'list_link' => 'ہمارے استعمال کردہ تمام کوکیز دیکھیں',
    'intro' => 'اس صفحے پر وہ تمام کوکیز درج ہیں جو یہ سائٹ لگا سکتی ہے: وہ کیا کرتی ہیں، کیا انہیں آپ کی رضامندی درکار ہے اور وہ کتنی دیر رہتی ہیں۔',
    'intro_juwri' => 'juwri.com اور juwri ڈیش بورڈ JUWRI LTD چلاتی ہے۔ ہماری [رازداری کی پالیسی](/privacy) کا سیکشن 5 بتاتا ہے کہ ہم کوکیز کیسے استعمال کرتے ہیں۔',
    'consent_note' => 'ضروری کوکیز ہمیشہ آن رہتی ہیں۔ تجزیاتی اور مارکیٹنگ کوکیز صرف آپ کی اجازت کے بعد لگائی جاتی ہیں، اور آپ کسی بھی وقت اپنا انتخاب بدل سکتے ہیں۔',
    'category.essential' => 'ضروری',
    'category.analytics' => 'تجزیات',
    'category.marketing' => 'مارکیٹنگ',
    'category_note.essential' => 'سائٹ کے کام کرنے کے لیے ضروری۔ انہیں آپ کی رضامندی درکار نہیں۔',
    'category_note.analytics' => 'صرف اس صورت میں لگائی جاتی ہیں جب آپ تجزیاتی کوکیز کی اجازت دیں۔',
    'category_note.marketing' => 'صرف اس صورت میں لگائی جاتی ہیں جب آپ مارکیٹنگ کوکیز کی اجازت دیں۔',
    'col.name' => 'نام',
    'col.purpose' => 'مقصد',
    'col.lifetime' => 'مدت',
    'col.party' => 'لگانے والا',
    'party.first' => 'یہ سائٹ',
    'party.third' => ':provider (تیسرا فریق)',
    'lifetime.session' => 'براؤزر بند کرنے تک',
    'stores' => 'کسی اسٹور کے صفحات اسٹور کے اپنے تجزیاتی یا مارکیٹنگ ٹولز بھی استعمال کر سکتے ہیں، جیسے Google Analytics، Google Tag Manager یا Meta Pixel۔ جب تک آپ اجازت نہ دیں وہ بلاک رہتے ہیں، اور ان کی ذمہ داری اسٹور پر ہے۔',
    'purpose.session' => 'آپ کو سائن ان رکھتی ہے اور ایک صفحے سے دوسرے صفحے تک آپ کے اقدامات یاد رکھتی ہے۔',
    'purpose.csrf' => 'فارموں کو کراس سائٹ درخواست جعل سازی سے محفوظ رکھتی ہے۔',
    'purpose.remember' => 'جب آپ "مجھے یاد رکھیں" منتخب کریں تو اس ڈیوائس پر آپ کو سائن ان رکھتی ہے۔',
    'purpose.two_factor' => 'آپ کے کہنے پر، دو مرحلوں والے سائن ان کے بعد اس ڈیوائس کو یاد رکھتی ہے۔',
    'purpose.social_login' => 'جانچتی ہے کہ "Apple کے ساتھ سائن ان" کا جواب اسی سائن ان کا ہے جو آپ نے شروع کیا تھا۔',
    'purpose.consent' => 'کوکیز کے بارے میں آپ کے انتخاب یاد رکھتی ہے۔',
    'purpose.language' => 'آپ کی منتخب کردہ زبان یاد رکھتی ہے۔',
    'purpose.language_prompt' => 'یاد رکھتی ہے کہ آپ نے زبان کی تجویز بند کر دی تھی۔',
    'purpose.theme' => 'آپ کی منتخب کردہ ہلکی یا گہری شکل یاد رکھتی ہے۔',
    'purpose.owner_app' => 'سائٹ کو بتاتی ہے کہ وہ انسٹال شدہ مالک ایپ میں کھلی ہے۔',
    'purpose.table' => 'آپ کے آرڈر کو اس میز سے جوڑتی ہے جس کا QR کوڈ آپ نے اسکین کیا۔',
    'purpose.cart' => 'براؤز کرتے وقت آپ کی شاپنگ کارٹ محفوظ رکھتی ہے۔',
    'purpose.wishlist' => 'اس ڈیوائس پر آپ کی خواہشات کی فہرست میں محفوظ اشیاء یاد رکھتی ہے (صرف ایک بے ترتیب ٹوکن؛ فہرست دکان کے پاس محفوظ رہتی ہے)۔',
    'purpose.studio' => 'سائن اپ کرنے تک آپ کا Menu Studio مسودہ محفوظ رکھتی ہے۔',
    'purpose.popup' => 'ایک دن کے لیے یاد رکھتی ہے کہ آپ نے اسٹور کا پاپ اپ بند کر دیا تھا۔',
    'purpose.referral' => 'یاد رکھتی ہے کہ آپ کو کس نے ریفر کیا، تاکہ ریفرل کا کریڈٹ دیا جا سکے۔',
    'purpose.ga' => 'Google Analytics: وزٹس گنتی ہے اور دکھاتی ہے کہ صفحات کیسے استعمال ہوتے ہیں۔',
    'purpose.ga_throttle' => 'Google Analytics: ڈیٹا بھیجنے کی رفتار محدود کرتی ہے۔',
    'purpose.ads' => 'Google Ads: ناپتی ہے کہ کون سے اشتہارات سائن اپ یا آرڈرز تک لے جاتے ہیں۔',
    'purpose.meta' => 'Meta Pixel: Facebook اور Instagram پر اشتہارات اور مہمات کو ناپتی ہے۔',
    'purpose.chat' => 'Tawk.to لائیو چیٹ: آپ کی چیٹ گفتگو محفوظ رکھتی ہے اور چیٹ وزیٹرز گنتی ہے۔',
    'purpose.marketplace' => 'یاد رکھتی ہے کہ آپ مارکیٹ پلیس کا کون سا حصہ دیکھ رہے ہیں۔',
    'purpose.announcement' => 'وہ اعلانات یاد رکھتی ہے جو آپ نے ہٹا دیے۔',
];
