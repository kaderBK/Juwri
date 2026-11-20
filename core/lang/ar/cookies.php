<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'ملفات تعريف الارتباط',
    'list_link' => 'اطّلع على كل ملفات تعريف الارتباط التي نستخدمها',
    'intro' => 'تعرض هذه الصفحة كل ملفات تعريف الارتباط التي يمكن أن يضعها هذا الموقع: وظيفتها، وهل تحتاج إلى موافقتك، ومدة بقائها.',
    'intro_juwri' => 'يدير juwri.com ولوحة تحكم juwri شركةُ JUWRI LTD. يشرح القسم 5 من [سياسة الخصوصية](/privacy) كيف نستخدم ملفات تعريف الارتباط.',
    'consent_note' => 'ملفات تعريف الارتباط الأساسية مفعّلة دائمًا. لا نضع ملفات التحليلات والتسويق إلا بعد أن تسمح بها، ويمكنك تغيير اختيارك في أي وقت.',
    'category.essential' => 'أساسية',
    'category.analytics' => 'التحليلات',
    'category.marketing' => 'التسويق',
    'category_note.essential' => 'ضرورية لعمل الموقع، ولا تحتاج إلى موافقتك.',
    'category_note.analytics' => 'لا تُوضع إلا إذا سمحت بملفات تعريف الارتباط الخاصة بالتحليلات.',
    'category_note.marketing' => 'لا تُوضع إلا إذا سمحت بملفات تعريف الارتباط الخاصة بالتسويق.',
    'col.name' => 'الاسم',
    'col.purpose' => 'الغرض',
    'col.lifetime' => 'المدة',
    'col.party' => 'الجهة الواضعة',
    'party.first' => 'هذا الموقع',
    'party.third' => ':provider (طرف ثالث)',
    'lifetime.session' => 'حتى تغلق المتصفح',
    'stores' => 'قد تستخدم صفحات المتجر أيضًا أدوات التحليلات أو التسويق الخاصة بالمتجر، مثل Google Analytics أو Google Tag Manager أو Meta Pixel. تبقى محجوبة حتى تسمح بها، والمتجر هو المسؤول عنها.',
    'purpose.session' => 'يُبقيك مسجّل الدخول ويتذكّر ما تفعله من صفحة إلى أخرى.',
    'purpose.csrf' => 'يحمي النماذج من هجمات تزوير الطلبات عبر المواقع.',
    'purpose.remember' => 'يُبقيك مسجّل الدخول على هذا الجهاز عندما تختار «تذكّرني».',
    'purpose.two_factor' => 'يتذكّر هذا الجهاز بعد تسجيل الدخول بخطوتين، عندما تطلب ذلك.',
    'purpose.social_login' => 'يتحقّق من أن ردّ «تسجيل الدخول عبر Apple» يخصّ عملية تسجيل الدخول التي بدأتها.',
    'purpose.consent' => 'يتذكّر اختياراتك بشأن ملفات تعريف الارتباط.',
    'purpose.language' => 'يتذكّر اللغة التي اخترتها.',
    'purpose.language_prompt' => 'يتذكّر أنك أغلقت اقتراح اللغة.',
    'purpose.theme' => 'يتذكّر المظهر الفاتح أو الداكن الذي اخترته.',
    'purpose.owner_app' => 'يُعلم الموقع أنه مفتوح داخل تطبيق المالك المثبّت.',
    'purpose.table' => 'يربط طلبك بالطاولة التي مسحت رمز QR الخاص بها.',
    'purpose.cart' => 'يحفظ السلة أثناء تصفحك.',
    'purpose.wishlist' => 'يحفظ المنتجات التي أضفتها إلى قائمة الأمنيات على هذا الجهاز (رمز عشوائي فقط؛ القائمة محفوظة لدى المتجر).',
    'purpose.studio' => 'يحفظ مسودة Menu Studio حتى تُنشئ حسابك.',
    'purpose.popup' => 'يتذكّر ليوم واحد أنك أغلقت النافذة المنبثقة للمتجر.',
    'purpose.referral' => 'يتذكّر من أحالك، لكي تُحتسب الإحالة.',
    'purpose.ga' => 'Google Analytics: يحصي الزيارات ويوضح كيف تُستخدم الصفحات.',
    'purpose.ga_throttle' => 'Google Analytics: يحدّ من عدد مرات إرسال البيانات.',
    'purpose.ads' => 'Google Ads: يقيس الإعلانات التي تؤدي إلى تسجيلات أو طلبات.',
    'purpose.meta' => 'Meta Pixel: يقيس الإعلانات والحملات على Facebook وInstagram.',
    'purpose.chat' => 'الدردشة المباشرة Tawk.to: تحفظ محادثتك وتحصي زوار الدردشة.',
    'purpose.marketplace' => 'يتذكّر منطقة السوق التي تتصفحها.',
    'purpose.announcement' => 'يتذكّر الإعلانات التي أغلقتها.',
];
