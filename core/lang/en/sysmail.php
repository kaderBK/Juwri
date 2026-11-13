<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject), except the app_overdue.* subjects (P1-23 part 7). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Hi :name,',
    'terms_changed.intro' => 'We are updating the :brand Terms and the documents that go with them. The new version takes effect on :date.',
    'terms_changed.notice' => 'We give at least 30 days\' notice of material changes. They apply from your next renewal, or earlier if the law requires it or you accept them. If you object to a material change, you can cancel before it takes effect.',
    'terms_changed.cta' => 'Read the changes',
    'app_overdue.failed_subject' => ':app renewal failed',
    'app_overdue.failed_body' => 'We could not charge :price for your :app renewal (insufficient wallet balance). The app keeps working. Top up your wallet and we will retry automatically every day.',
    'app_overdue.notice_subject' => ':app will be paused on :date',
    'app_overdue.notice_body' => 'The :price renewal of :app is more than 14 days overdue. If it is still unpaid on :date, 7 days from now, we will pause the app. No data is deleted, and the app works again as soon as the payment goes through.',
];
