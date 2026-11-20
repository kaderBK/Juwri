<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-23: the cookie list page (/cookies) — Juwri\Core\Legal\CookieRegistry, resources/views/legal/cookies.blade.php.
 * Flat dotted keys: __('cookies.purpose.session'). `purpose.*` = one key per registry purpose. Inline [text](/path)
 * links are rendered by Juwri\Core\Legal\LegalText::inline(). Keep every locale's keys aligned with lang/en/cookies.php.
 */

return [
    'title' => 'कुकीज़',
    'list_link' => 'हमारे द्वारा इस्तेमाल की जाने वाली सभी कुकीज़ देखें',
    'intro' => 'इस पेज पर वे सभी कुकीज़ दी गई हैं जो यह साइट सेट कर सकती है: वे क्या करती हैं, क्या उन्हें आपकी सहमति चाहिए और वे कितने समय तक रहती हैं।',
    'intro_juwri' => 'juwri.com और juwri डैशबोर्ड JUWRI LTD चलाती है। हमारी [गोपनीयता नीति](/privacy) का खंड 5 बताता है कि हम कुकीज़ का उपयोग कैसे करते हैं।',
    'consent_note' => 'ज़रूरी कुकीज़ हमेशा चालू रहती हैं। एनालिटिक्स और मार्केटिंग कुकीज़ केवल आपकी अनुमति के बाद सेट होती हैं, और आप कभी भी अपनी पसंद बदल सकते हैं।',
    'category.essential' => 'ज़रूरी',
    'category.analytics' => 'एनालिटिक्स',
    'category.marketing' => 'मार्केटिंग',
    'category_note.essential' => 'साइट के काम करने के लिए ज़रूरी। इनके लिए आपकी सहमति की ज़रूरत नहीं है।',
    'category_note.analytics' => 'केवल तभी सेट होती हैं जब आप एनालिटिक्स कुकीज़ की अनुमति देते हैं।',
    'category_note.marketing' => 'केवल तभी सेट होती हैं जब आप मार्केटिंग कुकीज़ की अनुमति देते हैं।',
    'col.name' => 'नाम',
    'col.purpose' => 'उद्देश्य',
    'col.lifetime' => 'अवधि',
    'col.party' => 'किसके द्वारा सेट',
    'party.first' => 'यह साइट',
    'party.third' => ':provider (तृतीय पक्ष)',
    'lifetime.session' => 'ब्राउज़र बंद करने तक',
    'stores' => 'किसी स्टोर के पेज उस स्टोर के अपने एनालिटिक्स या मार्केटिंग टूल भी इस्तेमाल कर सकते हैं, जैसे Google Analytics, Google Tag Manager या Meta Pixel। जब तक आप अनुमति नहीं देते, वे ब्लॉक रहते हैं, और उनकी ज़िम्मेदारी स्टोर की है।',
    'purpose.session' => 'आपको साइन इन रखती है और एक पेज से दूसरे पेज तक आपकी गतिविधियाँ याद रखती है।',
    'purpose.csrf' => 'फ़ॉर्म को क्रॉस-साइट अनुरोध जालसाज़ी से बचाती है।',
    'purpose.remember' => 'जब आप "मुझे याद रखें" चुनते हैं, तो इस डिवाइस पर आपको साइन इन रखती है।',
    'purpose.two_factor' => 'आपके कहने पर, दो-चरणीय साइन-इन के बाद इस डिवाइस को याद रखती है।',
    'purpose.social_login' => 'जाँचती है कि "Apple से साइन इन करें" का जवाब उसी साइन-इन का है जो आपने शुरू किया था।',
    'purpose.consent' => 'कुकीज़ के बारे में आपकी पसंद याद रखती है।',
    'purpose.language' => 'आपकी चुनी हुई भाषा याद रखती है।',
    'purpose.language_prompt' => 'याद रखती है कि आपने भाषा सुझाव बंद कर दिया था।',
    'purpose.theme' => 'आपकी चुनी हुई लाइट या डार्क थीम याद रखती है।',
    'purpose.owner_app' => 'साइट को बताती है कि वह इंस्टॉल किए गए ओनर ऐप में खुली है।',
    'purpose.table' => 'आपके ऑर्डर को उस टेबल से जोड़ती है जिसका QR कोड आपने स्कैन किया।',
    'purpose.cart' => 'ब्राउज़ करते समय आपकी शॉपिंग कार्ट सुरक्षित रखती है।',
    'purpose.wishlist' => 'इस डिवाइस पर आपकी विशलिस्ट में सहेजे गए आइटम याद रखती है (केवल एक यादृच्छिक टोकन; सूची दुकान के पास रहती है)।',
    'purpose.studio' => 'साइन अप करने तक आपका Menu Studio ड्राफ़्ट सुरक्षित रखती है।',
    'purpose.popup' => 'एक दिन के लिए याद रखती है कि आपने स्टोर का पॉप-अप बंद कर दिया था।',
    'purpose.referral' => 'याद रखती है कि आपको किसने रेफ़र किया, ताकि रेफ़रल का श्रेय दिया जा सके।',
    'purpose.ga' => 'Google Analytics: विज़िट गिनती है और दिखाती है कि पेज कैसे इस्तेमाल होते हैं।',
    'purpose.ga_throttle' => 'Google Analytics: डेटा भेजने की आवृत्ति सीमित करती है।',
    'purpose.ads' => 'Google Ads: मापती है कि कौन-से विज्ञापन साइन-अप या ऑर्डर तक ले जाते हैं।',
    'purpose.meta' => 'Meta Pixel: Facebook और Instagram पर विज्ञापनों और अभियानों को मापती है।',
    'purpose.chat' => 'Tawk.to लाइव चैट: आपकी चैट बातचीत सुरक्षित रखती है और चैट विज़िटर गिनती है।',
    'purpose.marketplace' => 'याद रखती है कि आप मार्केटप्लेस के किस हिस्से को देख रहे हैं।',
    'purpose.announcement' => 'वे घोषणाएँ याद रखती है जिन्हें आपने हटा दिया।',
];
