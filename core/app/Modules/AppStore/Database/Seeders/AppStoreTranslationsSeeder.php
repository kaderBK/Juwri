<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;

/**
 * Arabic display copy for the App Store catalog. The English name/tagline stay
 * the stored source; this fills `apps.translations->ar` so an Arabic viewer sees
 * localized cards + detail (App model accessors resolve app()->getLocale()).
 * Idempotent: matches by slug, merges the `ar` locale, preserves other locales.
 * description == tagline for every current app, so both carry the same string.
 */
class AppStoreTranslationsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->translations() as $slug => $ar) {
            $app = App::where('slug', $slug)->first();
            if (! $app) {
                continue; // app not present in this environment — skip
            }

            $current = (array) ($app->getRawOriginal('translations')
                ? json_decode((string) $app->getRawOriginal('translations'), true)
                : []);
            $current['ar'] = [
                'name' => $ar['name'],
                'tagline' => $ar['tagline'],
                'description' => $ar['description'] ?? $ar['tagline'],
            ];

            $app->translations = $current;
            $app->save();
        }
    }

    /** @return array<string, array{name:string, tagline:string, description?:string}> */
    private function translations(): array
    {
        return [
            'accounting' => [
                'name' => 'المحاسبة',
                'tagline' => 'دفتر أستاذ عام بالقيد المزدوج، والمصروفات، والتقارير المالية.',
            ],
            'crm' => [
                'name' => 'إدارة علاقات العملاء',
                'tagline' => 'ملفات موحّدة للزبائن، وشرائح RFM، ومنشئ الجمهور.',
            ],
            'delivery-hub' => [
                'name' => 'مركز التوصيل',
                'tagline' => 'صندوق وارد للمنصّات وإرسال مندوبي أسطولك الخاص.',
            ],
            'demand-forecast' => [
                'name' => 'توقّع الطلب',
                'tagline' => 'خرائط كثافة التوصيل مع توقّعات الطلب والتوظيف.',
            ],
            'digital-signage' => [
                'name' => 'اللافتات الرقمية',
                'tagline' => 'لوحات عرض للقائمة وحالة الطلبات.',
            ],
            'driver-app' => [
                'name' => 'تطبيق السائق',
                'tagline' => 'تطبيق ويب للسائق، وخريطة الإرسال، وإثبات التسليم.',
            ],
            'fiscalization' => [
                'name' => 'الفوترة الضريبية',
                'tagline' => 'الفوترة الإلكترونية لهيئات الضرائب في الشرق الأوسط وشمال إفريقيا والإيصالات الضريبية (ZATCA وJoFotara وETA وملفات الدول).',
            ],
            'hotel-suite' => [
                'name' => 'جناح الفنادق',
                'tagline' => 'الغرف والإقامات وفواتير الغرف والتحميل على الغرفة وطلبات الضيوف.',
            ],
            'hr-payroll' => [
                'name' => 'الموارد البشرية والرواتب',
                'tagline' => 'ملفات الموظفين، والإجازات، وفترات الدفع، وقسائم الرواتب، وتجميع الإكراميات.',
            ],
            'marketing-automation' => [
                'name' => 'أتمتة التسويق',
                'tagline' => 'استعادة السلة وحملات استرجاع العملاء.',
            ],
            'multi-vendor' => [
                'name' => 'سلة متعددة البائعين',
                'tagline' => 'دفع بسلة واحدة عبر عدة متاجر.',
            ],
            'kiosk' => [
                'name' => 'كشك الطلب الذاتي',
                'tagline' => 'وضع طلب ذاتي عبر كشك داخل المتجر للكاونترات والبهو.',
            ],
            'supplier-purchasing' => [
                'name' => 'الموردون والمشتريات',
                'tagline' => 'الاستلام، وفروق تكلفة البضاعة المباعة، والتقاط فواتير الموردين.',
            ],
            'support-desk' => [
                'name' => 'مكتب الدعم',
                'tagline' => 'صندوق وارد للملاحظات، وصندوق WhatsApp مشترك، ونظام تذاكر.',
            ],
            'team-chat' => [
                'name' => 'دردشة الفريق',
                'tagline' => 'مراسلة الفريق، وملاحظات الورديات، وقوائم المهام.',
            ],
        ];
    }
}
