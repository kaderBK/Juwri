<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'नमस्ते :name,',
    'terms_changed.intro' => 'हम :brand की शर्तों और उनसे जुड़े दस्तावेज़ों को अपडेट कर रहे हैं। नया संस्करण :date से लागू होगा।',
    'terms_changed.notice' => 'महत्वपूर्ण बदलावों की सूचना हम कम से कम 30 दिन पहले देते हैं। ये आपके अगले नवीनीकरण से लागू होते हैं, या उससे पहले अगर कानून इसकी माँग करे या आप इन्हें स्वीकार कर लें। अगर आप किसी महत्वपूर्ण बदलाव से सहमत नहीं हैं, तो उसके लागू होने से पहले रद्द कर सकते हैं।',
    'terms_changed.cta' => 'बदलाव पढ़ें',
    'app_overdue.failed_subject' => ':app का नवीनीकरण विफल रहा',
    'app_overdue.failed_body' => 'हम :app के नवीनीकरण के लिए :price नहीं काट सके (वॉलेट में पर्याप्त बैलेंस नहीं)। ऐप काम करता रहेगा। अपना वॉलेट टॉप अप करें, हम हर दिन अपने आप फिर से कोशिश करेंगे।',
    'app_overdue.notice_subject' => ':app को :date को रोक दिया जाएगा',
    'app_overdue.notice_body' => ':app का :price का नवीनीकरण 14 दिनों से अधिक समय से बकाया है। अगर :date तक, यानी अब से 7 दिन में, इसका भुगतान नहीं हुआ, तो हम ऐप को रोक देंगे। कोई डेटा नहीं हटाया जाएगा, और भुगतान होते ही ऐप फिर से काम करने लगेगा।',
];
