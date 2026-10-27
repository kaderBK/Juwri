<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'مرحباً :name،',
    'terms_changed.intro' => 'نقوم بتحديث شروط :brand والمستندات المرتبطة بها. يبدأ سريان النسخة الجديدة في :date.',
    'terms_changed.notice' => 'نمنحك إشعاراً قبل 30 يوماً على الأقل بالتغييرات الجوهرية. وتسري من تجديدك القادم، أو قبل ذلك إذا اقتضى القانون ذلك أو وافقت عليها. وإذا اعترضت على تغيير جوهري، يمكنك الإلغاء قبل أن يبدأ سريانه.',
    'terms_changed.cta' => 'اقرأ التغييرات',
    'app_overdue.failed_subject' => 'فشل تجديد :app',
    'app_overdue.failed_body' => 'تعذّر علينا خصم :price لتجديد :app (رصيد المحفظة غير كافٍ). يستمر التطبيق في العمل. اشحن محفظتك وسنعيد المحاولة تلقائياً كل يوم.',
    'app_overdue.notice_subject' => 'سيتم إيقاف :app مؤقتاً في :date',
    'app_overdue.notice_body' => 'تجديد :app بقيمة :price متأخر أكثر من 14 يوماً. إذا بقي غير مدفوع في :date، أي بعد 7 أيام من الآن، سنوقف التطبيق مؤقتاً. لن تُحذف أي بيانات، ويعود التطبيق للعمل فور إتمام الدفع.',
];
