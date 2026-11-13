<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Theme;
use App\Services\StorefrontThemeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;

/**
 * Blade showcase: surface the first-party BLADE store_templates in the Theme
 * Store as display-only listings, next to the installable Liquid themes.
 *
 * Source of truth = the live demo stores (ThemeShowcaseSeeder). Each demo store
 * whose `store_template` is a plain Blade slug becomes a Theme row with
 * engine=blade, a published "1.0" version (so index/detail render through the
 * existing gates), a branded SVG hero, and `demo_slug` pointing at that store —
 * which is what the detail page's "View live demo" and preview() redirect use.
 *
 * Skips: Liquid twins (a `crave` Blade card would collide with the Liquid crave
 * listing), `mp:` templates, and any slug whose view no longer exists on disk.
 * Idempotent by slug — safe to re-run.
 *
 *   php artisan db:seed --class=BladeShowcaseSeeder
 */
class BladeShowcaseSeeder extends Seeder
{
    /** Shared Arabic demo disclaimer, merged into every translated listing. */
    private const AR_NOTE = 'قالب مدمج — المعاينة المباشرة متجر تجريبي. قائمتك وصورك وهويتك ولغاتك تحلّ محلّها فور تطبيقه.';

    /** Arabic listing copy per slug: {tagline, description}. Names stay verbatim. */
    private const TRANSLATIONS = [
        'matcha' => ['tagline' => 'تجربة طلب مشرقة ومرحة بأسطح بيضاء ونعناعية وأزرار سوداء أنيقة.', 'description' => 'قالب مشرق ومرِح للطلب — أسطح بيضاء ونعناعية، ترويسة بموجة خضراء عضوية، بطاقات دائرية الحواف وأزرار سوداء. مصمّم للجوّال أولاً مع دعم كامل للعربية.'],
        'glow' => ['tagline' => 'تجربة طلب زجاجية بلمسات خضراء متوهجة على خلفية داكنة بلون الإسبريسو.', 'description' => 'واجهة زجاجية أنيقة بإضاءة خضراء متوهجة، حلقات تصنيف دائرية، لوحات مثلجة وتتبّع مباشر للطلب. للجوّال أولاً ودعم كامل للعربية.'],
        'aurora' => ['tagline' => 'تجربة طعام رقمية فاخرة بتصميم زجاجي مستقبلي داكن.', 'description' => 'تجربة طعام رقمية من الجيل الجديد — زجاجية مستقبلية داكنة، تدرّجات ناعمة وتفاعلات دقيقة راقية. مصمّمة للجوّال أولاً وسريعة الاستجابة.'],
        'vivid' => ['tagline' => 'منيو تفاعلي نابض بتدرّجات متحركة وحركات مرنة دافئة.', 'description' => 'منيو حيوي بتدرّجات متحركة وأسطح عضوية مشرقة، أوراق سفلية مرنة وتنقّل تصنيفات متحوّل. مصمّم للجوّال أولاً.'],
        'feast' => ['tagline' => 'تجربة تطبيق توصيل طعام احترافية على الويب بأناقة أصيلة.', 'description' => 'تجربة تطبيق توصيل احترافية — شريط عنوان ثابت، صف تصنيفات دائري، بطاقة مطعم بارزة وأوراق سفلية مرنة وشريط سلة عائم. لمسة مرجانية دافئة، للجوّال أولاً.'],
        'velvet' => ['tagline' => 'قالب داكن فاخر بلمسات ذهبية وانتقالات ناعمة وبحث مباشر.', 'description' => 'قالب داكن أنيق وفخم بلمسات ذهبية، انتقالات نوافذ سلسة وبحث فوري مباشر.'],
        'dough' => ['tagline' => 'منيو بيتزا داكن أنيق بلمسات كريمية مرسومة يدويًا.', 'description' => 'منيو بيتزا داكن أنيق — أسطح فحمية بلمسات كريمية مرسومة يدويًا وشريط إجماليات برتقالي دافئ وخط نظيف. للجوّال أولاً ودعم كامل للعربية.'],
        'plate' => ['tagline' => 'منيو تحريري بسيط بعناوين أنيقة وتقييمات نجوم ذهبية.', 'description' => 'منيو تحريري بسيط — خلفية رمادية فاتحة وبطاقات بيضاء، عناوين أنيقة فوق نص رمادي صغير، تقييمات نجوم ذهبية وأزرار سوداء. للجوّال أولاً.'],
        'lime' => ['tagline' => 'منيو وجبات خفيفة جريء بأزرار سوداء ولمسة ليموني نيون.', 'description' => 'منيو تطبيق وجبات جريء — شاشات بيضاء نظيفة، أزرار كبسولة سوداء ولمسة ليموني نيون، عدّادات كمية ضخمة وتتبّع توصيل حيوي. للجوّال أولاً.'],
        'picnic' => ['tagline' => 'تطبيق طعام مبهج ببطاقات برتقالية دافئة ولمسات صفراء مشمسة.', 'description' => 'تطبيق طعام حيوي — بطاقات ميزات برتقالية دافئة ولمسات صفراء وخضراء منعشة، خط مرِح وإحساس ودود يقود المكافآت. للجوّال أولاً.'],
        'orbit' => ['tagline' => 'منيو فائق النظافة بأسلوب iOS بلمسة برتقالية واثقة.', 'description' => 'منيو فائق النظافة بأسلوب iOS — أسطح بيضاء ناصعة، هالة خوخية ناعمة ولمسة برتقالية، تنقّل كبسولي عائم وخط واضح. للجوّال أولاً.'],
        'velora' => ['tagline' => 'منيو متجر بلمسة ليموني نظيفة على أسطح بيج دافئة.', 'description' => 'منيو متجر نظيف بلمسة ليموني — بطاقات بيضاء مشرقة على خلفية بيج دافئة، دوائر أيقونات سوداء وأشرطة إجراء ليموني مع محدّد حجم مرتّب. للجوّال أولاً.'],
        'crumb' => ['tagline' => 'منيو مخبز دافئ بأسطح كريمية وترويسة بنية غنية.', 'description' => 'منيو سوق مخبز دافئ — أسطح كريمية ناعمة تحت ترويسة بنية غنية، بطاقات تصنيف بيج وخيارات بالوزن وتصميم يبرز التقييمات. للجوّال أولاً.'],
        'soiree' => ['tagline' => 'منيو صالة داكن بأسطح أردوازية وبنفسجية راقية.', 'description' => 'منيو صالة داكن — أسطح أردوازية وبرقوقية عميقة، أقراص تبويب مرتفعة وشرائح خصم على الصور وبطاقة دفع أنيقة. للجوّال أولاً.'],
        'scoop' => ['tagline' => 'منيو آيس كريم جريء بالأسود والأصفر وعناوين ضخمة.', 'description' => 'منيو آيس كريم جريء بالأسود والأصفر — عناوين منتجات ضخمة، خيارات لونية واضحة وحالات مخزون جلية في كتالوج داكن واثق. للجوّال أولاً.'],
        'ember' => ['tagline' => 'منيو برجر داكن زجاجي بأزرار برتقالية متوهجة.', 'description' => 'منيو برجر داكن زجاجي — لوحات زجاجية مثلجة فوق خلفية غامضة، أزرار بتدرّج برتقالي متوهج وشرائح حارّة مميّزة. للجوّال أولاً.'],
        'zevoa' => ['tagline' => 'واجهة سوبر-تطبيق بقالة سريعة ببطاقة محفظة وتتبّع توصيل حي.', 'description' => 'واجهة سوبر-تطبيق بقالة سريعة — بطاقة محفظة، شبكة خدمات ومتجر حي ببطاقات منتجات وإضافة بلمسة واحدة، مع سلة وطلب وتتبّع توصيل مباشر. دعم العربية والوضع الداكن.'],
        'lumiere' => ['tagline' => 'متجر عناية بالبشرة ناعم ومشرق مدعوم بالذكاء الاصطناعي.', 'description' => 'متجر جمال وعناية بالبشرة ناعم ومشرق — حلقة روتين يومي، مسح للوجه، بطاقات منتجات على أبيض وتقويم روتين. يستخدم مفردات متجرك (منتجات، لا أطباق). دعم العربية والوضع الداكن.'],
        'maison' => ['tagline' => 'متجر ملابس فاخر تحريري بصور كاملة وخطوط أنيقة.', 'description' => 'متجر ملابس فاخر تحريري — بطاقات صور كاملة، خط عرض أنيق، عيّنات ألوان وأقراص مقاسات وبحث أسلوب بالذكاء الاصطناعي. تصفّح وفلترة وقائمة أمنيات. يعرض «منتجات» لا «أطباق».'],
        'torque' => ['tagline' => 'متجر قطع سيارات داكن باحترافية بتوافق VIN ومخططات مفصّلة.', 'description' => 'متجر قطع غيار داكن احترافي — توافق حسب VIN/الطراز، تصفّح بمخططات مفصّلة، مقياس ثقة التوافق وأوراق مواصفات ومرجع OEM. يستخدم مفردات متجرك، دعم العربية والوضعين.'],
        'freshly' => ['tagline' => 'متجر حجز خدمات هادئ للغسيل والتنظيف الجاف.', 'description' => 'متجر حجز خدمات هادئ للغسيل والتنظيف — بطاقات خدمات، منشئ كميات، جدولة استلام بفترات زمنية وسرعة توصيل وخط زمني لحالة الطلب. يستخدم مفردات متجرك، دعم العربية والوضعين.'],
        'carely' => ['tagline' => 'متجر صيدلية وصحة موثوق للأدوية دون وصفة بتصميم طبي.', 'description' => 'متجر صيدلية وصحة راقٍ للأدوية دون وصفة — تصميم أخضر طبي نظيف، بلاطات تصنيف وبطاقات منتجات بإضافة سريعة، تنبّه للصلاحية وبوابة عمر. دون وصفات طبية. دعم العربية والوضع الداكن.'],
        'aurelia' => ['tagline' => 'واجهة سوبر-تطبيق فندقي فاخر بحجز إقامة وخدمة غرف.', 'description' => 'واجهة سوبر-تطبيق فندقي فاخر — حجز إقامة بنطاق تواريخ، طعام داخل الغرفة، سبا ومرافق وتجارب وإضافة للفاتورة على الغرفة. يستخدم مفردات متجرك، دعم العربية والوضعين.'],
    ];

    /** Blade category label → marketplace theme_industries vocab. */
    private const CATEGORY_MAP = [
        'fast food' => 'fast_food', 'street food' => 'fast_food', 'grill' => 'fast_food',
        'cafe' => 'cafe', 'coffee' => 'cafe', 'tea' => 'cafe',
        'fine dining' => 'fine_dining', 'bistro' => 'fine_dining',
        'bakery' => 'bakery', 'pizza' => 'pizza',
        'retail' => 'general', 'services' => 'general',
    ];

    public function run(): void
    {
        $svc = app(StorefrontThemeService::class);
        $liquidSlugs = Theme::where('engine', 'liquid')->pluck('slug')
            ->merge(['crave', 'starter', 'bazaar'])->map('strtolower')->unique();

        $demoIds = StoreSettings::storeIdsWhere('demo_showcase', fn ($v) => $v === '1');
        $demos = Store::whereIn('id', $demoIds)->orderBy('id')->get(['id', 'slug', 'title', 'color']);

        $catalog = $svc->all();
        $made = 0;

        foreach ($demos as $store) {
            $tpl = (string) StoreSettings::legacy((int) $store->id, 'store_template');

            if ($tpl === '' || str_starts_with($tpl, 'mp:')) {
                continue; // Liquid/marketplace value — not a Blade showcase
            }
            if ($liquidSlugs->contains(strtolower($tpl))) {
                continue; // a Liquid listing already owns this slug (e.g. crave)
            }
            if (! $svc->exists($tpl)) {
                continue; // view gone from disk — never list a broken theme
            }

            $meta = $catalog[$tpl] ?? [
                'name' => Str::headline($tpl),
                'description' => 'A first-party storefront theme for your QR menu.',
                'category' => 'general',
            ];
            $color = $store->color && preg_match('/^#[0-9a-f]{6}$/i', (string) $store->color) ? $store->color : '#3f5efb';

            $theme = Theme::updateOrCreate(['slug' => $tpl], [
                'name' => $meta['name'],
                'engine' => 'blade',
                'template_slug' => $tpl,
                'demo_slug' => $store->slug,
                'status' => 'published',
                'seller_id' => null, // platform-owned
                'price' => 0,        // built-in themes are free
                'category' => $this->mapCategory($meta['category'] ?? 'general'),
                'tagline' => Str::limit(trim((string) ($meta['description'] ?? '')), 140, ''),
                'demo_notes' => 'Built-in theme — the live preview is a demo store. Your menu, photos, branding and languages replace it the moment you apply it.',
                'translations' => isset(self::TRANSLATIONS[$tpl])
                    ? ['ar' => self::TRANSLATIONS[$tpl] + ['notes' => self::AR_NOTE]]
                    : null,
            ]);

            $theme->versions()->updateOrCreate(['semver' => '1.0'], [
                'manifest' => [
                    'name' => $meta['name'],
                    'version' => '1.0',
                    'description' => trim((string) ($meta['description'] ?? '')),
                ],
                'checksum' => hash('sha256', 'blade:'.$tpl),
                'status' => 'published',
                'review_notes' => 'Built-in platform theme',
            ]);

            // Reset any prior generated media. Themes WITH a real storefront capture
            // (the home-gallery webp) render it in an iPhone frame straight from the
            // asset path, so they need no DB media at all; only the rest fall back to
            // a generated SVG hero.
            $rel = "storage/marketplace/theme/{$theme->id}/";
            foreach ($theme->media()->get() as $m) {
                @unlink(public_path($m->disk_path));
                $m->delete();
            }
            if (theme_showcase_shot($tpl) === null) {
                File::ensureDirectoryExists(public_path($rel));
                file_put_contents(public_path($rel.'shot-1.svg'), $this->heroSvg($meta['name'], (string) $store->title, $color));
                $theme->media()->create(['disk_path' => $rel.'shot-1.svg', 'kind' => 'screenshot', 'position' => 1]);
            }

            $made++;
            $this->command?->info("Showcased Blade theme {$tpl} (theme {$theme->id}) → demo /{$store->slug}");
        }

        $this->command?->info("Seeded {$made} Blade showcase listings.");
    }

    private function mapCategory(string $raw): string
    {
        return self::CATEGORY_MAP[strtolower(trim($raw))] ?? 'general';
    }

    private function shade(string $hex, float $f): string
    {
        $c = sscanf($hex, '#%02x%02x%02x') ?: [63, 94, 251];

        return sprintf('#%02x%02x%02x', (int) ($c[0] * (1 - $f)), (int) ($c[1] * (1 - $f)), (int) ($c[2] * (1 - $f)));
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }

    /** A brand-colored storefront-mock hero, 1600x1000, self-contained SVG. */
    private function heroSvg(string $name, string $store, string $hex): string
    {
        $dark = $this->shade($hex, .45);
        $bars = '';
        for ($r = 0; $r < 2; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $x = 980 + $c * 190;
                $y = 300 + $r * 250;
                $bars .= '<rect x="'.$x.'" y="'.$y.'" width="168" height="220" rx="16" fill="#ffffff" opacity=".9"/>'
                    .'<rect x="'.($x + 16).'" y="'.($y + 16).'" width="136" height="104" rx="10" fill="'.$hex.'" opacity=".3"/>'
                    .'<rect x="'.($x + 16).'" y="'.($y + 140).'" width="104" height="12" rx="6" fill="#c9c2b6"/>'
                    .'<rect x="'.($x + 16).'" y="'.($y + 164).'" width="70" height="9" rx="4" fill="#ddd7cc"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$hex.'"/><stop offset="1" stop-color="'.$dark.'"/></linearGradient></defs>'
            .'<rect width="1600" height="1000" fill="url(#g)"/>'
            .'<circle cx="1500" cy="90" r="240" fill="#ffffff" opacity=".07"/>'
            .'<circle cx="120" cy="940" r="180" fill="#ffffff" opacity=".06"/>'
            .'<text x="110" y="200" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="26" font-weight="700" fill="rgba(255,255,255,.85)">'.$this->e($store).' · built-in theme</text>'
            .'<text x="106" y="300" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="72" font-weight="800" fill="#ffffff">'.$this->e($name).'</text>'
            .'<rect x="110" y="360" width="360" height="14" rx="7" fill="rgba(255,255,255,.7)"/>'
            .'<rect x="110" y="410" width="260" height="14" rx="7" fill="rgba(255,255,255,.45)"/>'
            .'<rect x="110" y="470" width="200" height="56" rx="28" fill="#ffffff"/>'
            .'<text x="210" y="507" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="22" font-weight="800" fill="'.$dark.'" text-anchor="middle">View demo</text>'
            .$bars
            .'</svg>';
    }
}
