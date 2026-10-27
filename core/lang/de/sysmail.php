<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => 'Hallo :name,',
    'terms_changed.intro' => 'Wir aktualisieren die Bedingungen von :brand und die dazugehörigen Dokumente. Die neue Fassung gilt ab dem :date.',
    'terms_changed.notice' => 'Über wesentliche Änderungen informieren wir mindestens 30 Tage im Voraus. Sie gelten ab Ihrer nächsten Verlängerung, früher nur, wenn das Gesetz es verlangt oder Sie ihnen zustimmen. Wenn Sie einer wesentlichen Änderung widersprechen, können Sie vor ihrem Inkrafttreten kündigen.',
    'terms_changed.cta' => 'Änderungen lesen',
    'app_overdue.failed_subject' => 'Verlängerung von :app fehlgeschlagen',
    'app_overdue.failed_body' => 'Wir konnten :price für die Verlängerung von :app nicht abbuchen (Wallet-Guthaben reicht nicht). Die App läuft weiter. Laden Sie Ihr Wallet auf; wir versuchen es täglich automatisch erneut.',
    'app_overdue.notice_subject' => ':app wird am :date pausiert',
    'app_overdue.notice_body' => 'Die Verlängerung von :app über :price ist mehr als 14 Tage überfällig. Ist sie am :date, also in 7 Tagen, noch offen, pausieren wir die App. Es werden keine Daten gelöscht, und die App läuft wieder, sobald die Zahlung eingeht.',
];
