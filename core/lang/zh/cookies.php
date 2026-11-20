<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'Cookie 文件',
    'list_link' => '查看我们使用的全部 Cookie',
    'intro' => '本页列出本网站可能设置的所有 Cookie：它们的用途、是否需要您的同意以及保存多长时间。',
    'intro_juwri' => 'juwri.com 和 juwri 控制台由 JUWRI LTD 运营。我们的[隐私政策](/privacy)第 5 节说明了我们如何使用 Cookie。',
    'consent_note' => '必要 Cookie 始终开启。分析和营销 Cookie 只有在您允许后才会设置，您可以随时更改选择。',
    'category.essential' => '必要',
    'category.analytics' => '分析',
    'category.marketing' => '营销',
    'category_note.essential' => '网站正常运行所必需，无需您的同意。',
    'category_note.analytics' => '仅在您允许分析 Cookie 时设置。',
    'category_note.marketing' => '仅在您允许营销 Cookie 时设置。',
    'col.name' => '名称',
    'col.purpose' => '用途',
    'col.lifetime' => '有效期',
    'col.party' => '设置方',
    'party.first' => '本网站',
    'party.third' => ':provider（第三方）',
    'lifetime.session' => '直到您关闭浏览器',
    'stores' => '店铺页面也可能使用店铺自己的分析或营销工具，例如 Google Analytics、Google Tag Manager 或 Meta Pixel。在您允许之前它们会被阻止，并由店铺负责。',
    'purpose.session' => '让您保持登录，并在页面之间记住您的操作。',
    'purpose.csrf' => '保护表单免受跨站请求伪造攻击。',
    'purpose.remember' => '当您选择“记住我”时，让您在此设备上保持登录。',
    'purpose.two_factor' => '在您要求时，于两步登录后记住此设备。',
    'purpose.social_login' => '核对“通过 Apple 登录”的回应是否属于您发起的那次登录。',
    'purpose.consent' => '记住您的 Cookie 选择。',
    'purpose.language' => '记住您选择的语言。',
    'purpose.language_prompt' => '记住您已关闭语言建议。',
    'purpose.theme' => '记住您选择的浅色或深色外观。',
    'purpose.owner_app' => '告知网站它正在已安装的店主应用中打开。',
    'purpose.table' => '将您的订单与您扫描二维码的餐桌关联。',
    'purpose.cart' => '在您浏览时保留您的购物车。',
    'purpose.wishlist' => '在此设备上保留您加入心愿单的商品（仅一个随机令牌；清单由商店保存）。',
    'purpose.studio' => '在您注册前保留您的 Menu Studio 草稿。',
    'purpose.popup' => '在一天内记住您已关闭店铺的弹窗。',
    'purpose.referral' => '记住是谁推荐了您，以便为推荐记功。',
    'purpose.ga' => 'Google Analytics：统计访问量并显示页面的使用情况。',
    'purpose.ga_throttle' => 'Google Analytics：限制数据发送的频率。',
    'purpose.ads' => 'Google Ads：衡量哪些广告带来了注册或订单。',
    'purpose.meta' => 'Meta Pixel：衡量 Facebook 和 Instagram 上的广告和活动效果。',
    'purpose.chat' => 'Tawk.to 在线聊天：保存您的聊天对话并统计聊天访客。',
    'purpose.marketplace' => '记住您正在浏览的市场区域。',
    'purpose.announcement' => '记住您已关闭的公告。',
];
