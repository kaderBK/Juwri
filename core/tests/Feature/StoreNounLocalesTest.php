<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Models\Store;
use Tests\TestCase;

/**
 * store_noun() must localise the store-entity noun for EVERY locale, not just
 * ar: a German owner reads "Restaurant erfolgreich geklont.", never a German
 * sentence with an English "restaurant" in it. Forms: config/store_nouns.php.
 */
class StoreNounLocalesTest extends TestCase
{
    private const LOCALES = ['de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    private const SLOTS = ['title', 'lower', 'poss', 'indef', 'plural', 'gen', 'poss_gen'];

    /** group => a registered store_type of that group (null = no store in context → neutral set). */
    private const GROUPS = ['food' => 'restaurant', 'goods' => 'pharmacy', 'services' => 'laundry', 'stays' => 'hotel', '_generic' => null];

    /**
     * Forms that are legitimately the same word as the English one (case-insensitive):
     * de/fr "Restaurant", de/es/pt "Hotel", fr "restaurants" (plural), de "Hotels"/"Restaurants".
     */
    private const LEGIT_IDENTICAL = [
        'de:food:title', 'de:food:plural', 'de:stays:title', 'de:stays:plural',
        'fr:food:title', 'fr:food:plural',
        'es:stays:title', 'pt:stays:title',
    ];

    private function mkPost(?string $type): Store
    {
        $p = new Store;
        $p->store_type = $type;

        return $p;
    }

    public function test_every_locale_every_vertical_returns_a_non_english_noun(): void
    {
        $leaks = [];
        foreach (self::GROUPS as $group => $type) {
            $store = $this->mkPost($type);
            foreach (self::SLOTS as $slot) {
                app()->setLocale('en');
                $en = store_noun($store, $slot);
                $this->assertNotSame('', $en);
                foreach (self::LOCALES as $locale) {
                    app()->setLocale($locale);
                    $got = store_noun($store, $slot);
                    $this->assertNotSame('', $got, "$locale:$group:$slot is empty");
                    if (mb_strtolower($got) === mb_strtolower($en) && ! in_array("$locale:$group:$slot", self::LEGIT_IDENTICAL, true)) {
                        $leaks[] = "$locale:$group:$slot={$got}";
                    }
                    // Non-Latin locales must not contain a single Latin letter.
                    if (in_array($locale, ['ru', 'hi', 'ur', 'zh'], true)) {
                        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $got, "$locale:$group:$slot has Latin letters");
                    }
                }
            }
        }
        $this->assertSame([], $leaks, 'English noun leaked into a localised locale');
    }

    public function test_unknown_locale_still_falls_back_to_english(): void
    {
        app()->setLocale('xx');
        $this->assertSame('Restaurant', store_noun($this->mkPost('restaurant'), 'title'));
        $this->assertSame('store', store_noun($this->mkPost('pharmacy'), 'gen'));
        $this->assertSame('business', store_noun($this->mkPost(null), 'poss_gen'));
    }

    public function test_gen_and_poss_gen_fall_back_where_a_language_has_no_genitive(): void
    {
        app()->setLocale('fr');
        $this->assertSame('ce restaurant', store_noun($this->mkPost('restaurant'), 'gen'));
        $this->assertSame('votre restaurant', store_noun($this->mkPost('restaurant'), 'poss_gen'));

        app()->setLocale('ru');
        $this->assertSame('этого ресторана', store_noun($this->mkPost('restaurant'), 'gen'));
        $this->assertSame('вашего отеля', store_noun($this->mkPost('hotel'), 'poss_gen'));
    }

    public function test_every_noun_sentence_exists_in_every_locale_with_its_placeholder(): void
    {
        $en = include lang_path('en/lang.php');
        $nounKeys = array_keys(array_filter($en, fn ($v) => is_string($v) && str_contains($v, ':noun')));
        $this->assertGreaterThanOrEqual(17, count($nounKeys));

        foreach (array_merge(self::LOCALES, ['ar']) as $locale) {
            $t = include lang_path("$locale/lang.php");
            foreach ($nounKeys as $key) {
                $this->assertArrayHasKey($key, $t, "$locale is missing $key");
                $this->assertStringContainsString(':noun', $t[$key], "$locale:$key lost :noun");
                $this->assertNotSame($en[$key], $t[$key], "$locale:$key is still English");
            }
        }
    }

    public function test_german_sentences_read_naturally(): void
    {
        app()->setLocale('de');
        $this->assertSame('Restaurant erfolgreich geklont.', ___(':noun cloned successfully.', ['noun' => store_noun($this->mkPost('restaurant'), 'title')]));
        $this->assertSame('Geschäft erfolgreich geklont.', ___(':noun cloned successfully.', ['noun' => store_noun($this->mkPost('pharmacy'), 'title')]));
        $this->assertSame(
            'Melde dich an, um auf dein Unternehmen zuzugreifen.',
            ___('Log in to access your :noun.', ['noun' => store_noun($this->mkPost('laundry'), 'poss')])
        );
        $this->assertSame(
            'Bitte erstelle zuerst ein Hotel.',
            ___('Please create a :noun first.', ['noun' => store_noun($this->mkPost('hotel'), 'indef')])
        );
        // genitive: "deines Hotels", not the nominative "dein Hotel"
        $this->assertSame(
            'Die Zeiten gelten nach der Uhr deines Hotels',
            ___('Times are your :noun clock', ['noun' => store_noun($this->mkPost('hotel'), 'poss_gen')])
        );
    }

    public function test_russian_sentences_use_the_right_case(): void
    {
        app()->setLocale('ru');
        $this->assertSame('Магазин успешно клонирован.', ___(':noun cloned successfully.', ['noun' => store_noun($this->mkPost('pharmacy'), 'title')]));
        // genitive after "Для"
        $this->assertSame(
            'Для этого ресторана должен остаться хотя бы один филиал.',
            ___('A :noun must keep at least one branch.', ['noun' => store_noun($this->mkPost('restaurant'), 'gen')])
        );
        // accusative object, inanimate masculine
        $this->assertSame(
            'Подключите ваш ресторан к инструментам, которыми вы уже пользуетесь.',
            ___('Connect your :noun to the tools you already use.', ['noun' => store_noun($this->mkPost('restaurant'), 'poss')])
        );
        $this->assertSame(
            'Время указано по часам вашего бизнеса',
            ___('Times are your :noun clock', ['noun' => store_noun($this->mkPost('laundry'), 'poss_gen')])
        );
    }

    public function test_urdu_sentences_in_urdu_script(): void
    {
        app()->setLocale('ur');
        $this->assertSame('ریسٹورنٹ کامیابی سے کلون ہو گیا۔', ___(':noun cloned successfully.', ['noun' => store_noun($this->mkPost('restaurant'), 'title')]));
        $this->assertSame(
            'آپ کے اسٹور تک رسائی کے لیے لاگ اِن کریں۔',
            ___('Log in to access your :noun.', ['noun' => store_noun($this->mkPost('pharmacy'), 'poss')])
        );
        $this->assertSame(
            'اس ہوٹل کو دوسری قسم میں تبدیل کریں',
            ___('Convert this :noun to a different type', ['noun' => store_noun($this->mkPost('hotel'), 'lower')])
        );
    }

    public function test_other_locales_render_one_sentence_each(): void
    {
        $food = $this->mkPost('restaurant');

        app()->setLocale('fr');
        $this->assertSame('Connectez-vous pour accéder à votre restaurant.', ___('Log in to access your :noun.', ['noun' => store_noun($food, 'poss')]));
        app()->setLocale('es');
        $this->assertSame('Convertir este restaurante a otro tipo', ___('Convert this :noun to a different type', ['noun' => store_noun($food, 'lower')]));
        app()->setLocale('pt');
        $this->assertSame('Os horários seguem o relógio do seu restaurante', ___('Times are your :noun clock', ['noun' => store_noun($food, 'poss_gen')]));
        app()->setLocale('tr');
        $this->assertSame('Giriş yapın ve restoranınız ile devam edin.', ___('Log in to access your :noun.', ['noun' => store_noun($food, 'poss')]));
        app()->setLocale('hi');
        $this->assertSame('आपके रेस्टोरेंट तक पहुँचने के लिए लॉग इन करें।', ___('Log in to access your :noun.', ['noun' => store_noun($food, 'poss')]));
        app()->setLocale('zh');
        $this->assertSame('登录以访问您的餐厅。', ___('Log in to access your :noun.', ['noun' => store_noun($food, 'poss')]));
    }
}
