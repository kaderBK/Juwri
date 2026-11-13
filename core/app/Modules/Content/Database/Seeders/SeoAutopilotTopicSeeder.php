<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Database\Seeders;

use Juwri\Modules\Content\Models\BlogTopic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * SEO Growth W5.1 — Blog Autopilot topic backlog seed.
 *
 * Loads the KEYWORDS.md §Longtails question keywords (from ~140 live SERP reads,
 * 2026-08-30) into `blog_topics` as PLATFORM topics (reseller_id NULL),
 * status = 'queued', source = 'manual'. Title pattern = keyword + " (2026)".
 *
 * This seeder ONLY fills the backlog. It never drafts or publishes: the
 * scheduler (ai:blog-autopilot) is a no-op until the owner flips
 * `blog_autopilot_enabled='1'`, and drafting is further paced by the backlog
 * cap. Enabling runs (+ AI keys, per-plan limits) is the owner's call.
 *
 * Idempotent + non-destructive: each row is matched by
 * (reseller_id NULL, locale, keyword) via firstOrCreate, so re-running adds
 * nothing and never resets a topic the owner has since drafted/published.
 *
 * Run on production:  php artisan db:seed --class=SeoAutopilotTopicSeeder
 * Not wired into DatabaseSeeder — it is an explicit, one-command content import.
 *
 * hi/ur note: those keywords are Roman-script (transliterated) and target
 * English-body posts with native-language H2 headings (Devanagari/Nastaliq
 * landing pages carry no commercial volume — negative-keyword list). They are
 * filed under hi/ur so the owner can route them, with the intent recorded in
 * meta.note.
 */
class SeoAutopilotTopicSeeder extends Seeder
{
    /**
     * KEYWORDS.md §Longtails, verbatim, keyed by locale. Order preserved.
     *
     * @var array<string, list<string>>
     */
    private const LONGTAILS = [
        'en' => [
            'how to create a qr code menu for a restaurant',
            'best free qr menu app',
            'how to convert paper menu to digital',
            'qr code menu without app download',
            'hotel room service qr ordering',
            'how does qr code ordering work',
        ],
        'ar' => [
            'طريقة عمل منيو الكتروني',
            'كيف اسوي منيو الكتروني',
            'افضل برنامج منيو الكتروني للمطاعم',
            'خطوات انشاء منيو الكتروني',
            'عمل باركود للمنيو مجانا',
        ],
        'tr' => [
            'qr menü nasıl yapılır',
            'yemeksepeti komisyon oranı',
            'dijital menü avantajları',
            'qr menü nasıl güncellenir',
            'yapay zeka ile menü oluşturma',
        ],
        'de' => [
            'wie erstelle ich eine digitale speisekarte',
            'lieferando provision wie hoch',
            'lohnt sich ein eigenes bestellsystem',
            'qr code speisekarte dsgvo',
        ],
        'fr' => [
            'comment créer un qr code pour son menu',
            'combien coûte uber eats pour un restaurant',
            'menu qr code sans application',
        ],
        'es' => [
            'cómo hacer un menú qr para mi restaurante',
            'cuánto cuesta una carta digital con qr',
            'cómo vender comida sin pagar comisiones',
            'cómo recibir pedidos por whatsapp',
        ],
        'pt' => [
            'como montar delivery próprio sem depender do ifood',
            'quanto custa um cardápio digital',
            'como criar cardápio digital grátis',
            'ifood ou delivery próprio',
        ],
        'ru' => [
            'как сделать qr меню бесплатно',
            'qr меню с оплатой',
            'электронное меню с интеграцией iiko',
        ],
        'zh' => [
            '扫码点餐系统多少钱',
            '二维码菜单 制作 免费',
        ],
        // Roman-script, English-body posts with native H2s (see class docblock).
        'hi' => [
            'qr menu kaise banaye',
            'whatsapp par order kaise le',
        ],
        'ur' => [
            'restaurant pos price in pakistan',
            'whatsapp order lene ka tarika',
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $existing = 0;
        $perLocale = [];

        foreach (self::LONGTAILS as $locale => $keywords) {
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if ($keyword === '') {
                    continue;
                }

                $meta = ['seo_seed' => 'W5.1'];
                if (in_array($locale, ['hi', 'ur'], true)) {
                    $meta['note'] = 'English-body post with native-language H2 headings';
                }

                $topic = BlogTopic::firstOrCreate(
                    ['reseller_id' => null, 'locale' => $locale, 'keyword' => $keyword],
                    [
                        'title' => Str::ucfirst($keyword).' (2026)',
                        'angle' => null,
                        'status' => 'queued',
                        'source' => 'manual',
                        'meta' => $meta,
                    ]
                );

                if ($topic->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existing++;
                }
                $perLocale[$locale] = ($perLocale[$locale] ?? 0) + 1;
            }
        }

        $lines = [sprintf(
            'SEO Autopilot topics: %d created, %d already present (%d total across %d locales).',
            $created,
            $existing,
            $created + $existing,
            count($perLocale)
        )];
        foreach ($perLocale as $locale => $count) {
            $lines[] = sprintf('  %s: %d', $locale, $count);
        }

        // $command is bound by db:seed / $this->seed() (the only invocation paths).
        foreach ($lines as $line) {
            $this->command->info($line);
        }
    }
}
