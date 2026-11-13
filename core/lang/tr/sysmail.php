<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Merhaba :name,',
    'terms_changed.intro' => ':brand Şartları ve bunlara bağlı belgeleri güncelliyoruz. Yeni sürüm :date tarihinde yürürlüğe giriyor.',
    'terms_changed.notice' => 'Önemli değişiklikleri en az 30 gün önceden bildiririz. Değişiklikler bir sonraki yenilemenizden itibaren geçerli olur; yasa gerektiriyorsa veya siz kabul ederseniz daha erken geçerli olabilir. Önemli bir değişikliğe itiraz ederseniz, yürürlüğe girmeden önce aboneliğinizi iptal edebilirsiniz.',
    'terms_changed.cta' => 'Değişiklikleri oku',
    'app_overdue.failed_subject' => ':app yenilemesi başarısız oldu',
    'app_overdue.failed_body' => ':app yenilemesi için :price tahsil edemedik (cüzdan bakiyesi yetersiz). Uygulama çalışmaya devam ediyor. Cüzdanınıza bakiye yükleyin; her gün otomatik olarak yeniden deneyeceğiz.',
    'app_overdue.notice_subject' => ':app :date tarihinde duraklatılacak',
    'app_overdue.notice_body' => ':app için :price tutarındaki yenileme 14 günden fazla gecikti. :date tarihinde, yani 7 gün sonra hâlâ ödenmemişse uygulamayı duraklatacağız. Hiçbir veri silinmez ve ödeme geçtiği anda uygulama yeniden çalışır.',
];
