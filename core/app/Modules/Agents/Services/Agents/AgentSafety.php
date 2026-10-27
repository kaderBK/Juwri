<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Agents;

use Juwri\Core\Support\CustomerText;

/**
 * AO5 #29/#31 — deterministic pre-model gates on inbound customer text. These run
 * BEFORE any AI/tool call, so abuse never reaches the model (saves spend + blocks
 * it) and an explicit "talk to a human" is escalated instead of hallucinated.
 */
class AgentSafety
{
    /** Obvious abuse: slurs/profanity or spam (rules in {@see CustomerText::isAbusive()}). */
    public static function isAbusive(string $text): bool
    {
        return CustomerText::isAbusive($text);
    }

    /**
     * P6-46: a medical question (dosage, symptoms, diagnosis, side effects, interactions, pregnancy, "can I take")
     * that a pharmacy store's assistant must hand to the pharmacist instead of answering. Phrase list over the
     * 11 storefront locales; deliberately broad (a false positive costs one hand-off, a miss could be advice).
     * Product, stock, price and order questions do not match.
     */
    public static function isMedicalQuestion(string $text): bool
    {
        $t = mb_strtolower($text);
        $phrases = [
            // en
            'dose', 'dosage', 'how many should i take', 'how much should i take', 'how many can i take', 'how much can i take',
            'how often should i take', 'can i take', 'should i take', 'is it safe to take', 'safe for my', 'safe during',
            'side effect', 'interaction', 'interact with', 'together with my', 'i am pregnant', "i'm pregnant", 'while pregnant',
            'during pregnancy', 'while breastfeeding', 'i am breastfeeding', "i'm breastfeeding", 'symptom', 'diagnos',
            'what do i have', 'what should i take for', 'what can i take for', 'good for my', 'treat my', 'cure for',
            'overdose', 'allergic to', 'my doctor', 'medical advice', 'prescri', 'mg per', 'i have a fever', 'i have a headache',
            'i feel sick', 'it hurts', 'pain in my', 'infection', 'my blood pressure', 'i am diabetic', 'my diabetes',
            // ar
            'جرعة', 'الجرعة', 'كم حبة آخذ', 'كم مرة في اليوم', 'هل يمكنني تناول', 'هل أستطيع تناول', 'آثار جانبية',
            'الآثار الجانبية', 'أعراض', 'تشخيص', 'أنا حامل', 'أثناء الحمل', 'أثناء الرضاعة', 'تفاعل', 'وصفة طبية',
            'أعاني من', 'عندي صداع', 'عندي حرارة', 'علاج ل',
            // fr
            'posologie', 'combien dois-je prendre', 'combien de comprimés', 'puis-je prendre', 'effets secondaires',
            'effet secondaire', 'symptôme', 'je suis enceinte', "j'allaite", 'ordonnance', "j'ai mal", "j'ai de la fièvre",
            // es
            'dosis', 'cuántas debo tomar', 'cuánto debo tomar', 'puedo tomar', 'efectos secundarios', 'efecto secundario',
            'síntoma', 'diagnóstico', 'estoy embarazada', 'durante la lactancia', 'receta médica', 'me duele', 'tengo fiebre',
            // pt
            'dosagem', 'quantos devo tomar', 'quanto devo tomar', 'posso tomar', 'efeitos colaterais', 'efeito colateral',
            'sintoma', 'estou grávida', 'amamentando', 'tenho febre', 'estou com dor',
            // de
            'dosierung', 'wie viele soll ich nehmen', 'wie viel soll ich nehmen', 'kann ich einnehmen', 'darf ich einnehmen',
            'nebenwirkung', 'wechselwirkung', 'diagnose', 'ich bin schwanger', 'in der schwangerschaft', 'in der stillzeit',
            'ich habe fieber', 'tut weh',
            // tr
            'dozaj', 'dozu', 'kaç tane içmeliyim', 'günde kaç', 'kullanabilir miyim', 'içebilir miyim', 'yan etki', 'belirti',
            'teşhis', 'hamileyim', 'emziriyorum', 'ateşim var', 'ağrım var',
            // ru
            'дозировк', 'доза', 'сколько принимать', 'сколько таблеток', 'можно ли принимать', 'можно ли мне принимать',
            'побочн', 'симптом', 'диагноз', 'я беременна', 'при беременности', 'кормлю грудью', 'у меня болит',
            'у меня температура',
            // zh
            '剂量', '用量', '吃几片', '吃多少', '能吃吗', '可以吃吗', '副作用', '症状', '诊断', '我怀孕', '哺乳期', '我头痛', '我发烧',
            // hi
            'खुराक', 'कितनी गोली', 'क्या मैं ले सकता', 'क्या मैं ले सकती', 'साइड इफेक्ट', 'दुष्प्रभाव', 'लक्षण',
            'मैं गर्भवती', 'मुझे बुखार', 'दर्द हो',
            // ur
            'خوراک', 'کتنی گولیاں', 'کیا میں لے سکتا', 'کیا میں لے سکتی', 'مضر اثرات', 'علامات', 'میں حاملہ', 'مجھے بخار', 'درد ہو',
        ];

        return array_any($phrases, fn ($p) => str_contains($t, $p));
    }

    /** Customer explicitly wants a person / is out of the agent's ordering scope. */
    public static function wantsHuman(string $text): bool
    {
        $t = mb_strtolower($text);
        $phrases = [
            'human', 'real person', 'speak to someone', 'talk to someone', 'speak to a person',
            'talk to a person', 'customer service', 'representative', 'agent please', 'manager',
            'complaint', 'complain', 'file a complaint',
        ];

        return array_any($phrases, fn ($p) => str_contains($t, $p));
    }
}
