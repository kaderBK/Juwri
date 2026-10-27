<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23 system e-mail bodies (Community). Flat dotted keys: __('sysmail.terms_changed.intro'). Subjects live in
 * site.php (mail.*.subject). Keep every locale's keys and :placeholders aligned with lang/en/sysmail.php.
 */

return [
    'greeting' => '你好 :name，',
    'terms_changed.intro' => '我们正在更新 :brand 的服务条款及相关文件。新版本将于 :date 生效。',
    'terms_changed.notice' => '对于重大变更，我们会至少提前 30 天通知。变更自你下一次续订起适用；如法律要求或你已接受，则可更早适用。如果你不同意某项重大变更，可以在其生效前取消。',
    'terms_changed.cta' => '查看变更',
    'app_overdue.failed_subject' => ':app 续订失败',
    'app_overdue.failed_body' => '我们无法为 :app 续订扣除 :price（钱包余额不足）。应用会继续运行。请为钱包充值，我们每天会自动重试。',
    'app_overdue.notice_subject' => ':app 将于 :date 暂停',
    'app_overdue.notice_body' => ':app 的 :price 续订费用已逾期超过 14 天。如果到 :date（即 7 天后）仍未支付，我们将暂停该应用。不会删除任何数据，付款成功后应用会立即恢复运行。',
];
