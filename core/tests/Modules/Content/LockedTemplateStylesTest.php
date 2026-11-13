<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\LockedTemplates\Content;
use Juwri\Modules\Content\Services\LockedTemplates\Styles;
use PHPUnit\Framework\TestCase;
use Tests\Support\LockedTemplateFixtures;

/**
 * Element styles are typed, range-checked values that end up in a style attribute:
 * nothing free-form may pass, and the superadmin's per-user caps decide who may set
 * which property (enforced in Content::merge, whatever the editor sent).
 */
class LockedTemplateStylesTest extends TestCase
{
    use LockedTemplateFixtures;

    public function test_values_are_typed_and_clamped(): void
    {
        $this->assertSame(200, Styles::cleanProp('text', 'size', 999));
        $this->assertSame(48, Styles::cleanProp('text', 'size', '48'));
        $this->assertSame(700, Styles::cleanProp('text', 'weight', '700'));
        $this->assertNull(Styles::cleanProp('text', 'weight', 450));
        $this->assertSame('#aabbcc', Styles::cleanProp('text', 'color', '#ABC'));
        $this->assertSame('16:9', Styles::cleanProp('image', 'ratio', '16:9'));
        $this->assertSame(1.25, Styles::cleanProp('text', 'line', 1.2468));
        foreach ([['text', 'color', 'red'], ['text', 'color', '#fff;background:url(x)'], ['text', 'align', 'middle'], ['image', 'ratio', '0:1'], ['image', 'ratio', '16/9'], ['text', 'size', 'big'], ['text', 'size', true], ['text', 'nope', 1]] as [$kind, $prop, $bad]) {
            $this->assertNull(Styles::cleanProp($kind, $prop, $bad), "{$kind}.{$prop}");
        }
        // Neutral filter values are "no override".
        $this->assertSame(['grayscale' => 40], Styles::clean('image', ['grayscale' => 40, 'brightness' => 100, 'zoom' => 1, 'bogus' => 3]));
    }

    public function test_vars_and_fluid_size(): void
    {
        $this->assertSame('16px', Styles::fluidSize(16));
        $this->assertSame('clamp(29px, 4.688vw, 48px)', Styles::fluidSize(48));

        $v = Styles::vars('image', ['ratio' => '16:9', 'zoom' => 2, 'fx' => 30, 'fy' => 50, 'grayscale' => 20, 'blur' => 1.5]);
        $this->assertContains('--lt-ar:16 / 9', $v['css']);
        $this->assertContains('--lt-op:30% 50%', $v['css']);
        $this->assertContains('--lt-clip:inset(25% 35% 25% 15%)', $v['css']);
        $this->assertContains('--lt-filter:grayscale(20%) blur(1.5px)', $v['css']);
        $this->assertSame(['crop', 'zoom', 'filter'], $v['markers']);
    }

    public function test_apply_targets_only_hooked_elements(): void
    {
        $html = '<div class="a>b"><h2 class="x" data-lt-t="s.t1 s.t2">Hi</h2><img alt="old" style="color:transparent" src="/a.png" data-lt-img="s.img1"><p data-lt-t="s.t9">keep</p><span class="lt-icon" data-lt-icon="s.icon1"><svg></svg></span></div>';
        $out = Styles::apply($html, [
            'text' => ['s.t2' => ['size' => 32, 'color' => '#112233']],
            'image' => ['s.img1' => ['alt' => 'A "quoted" alt', 'radius' => 12]],
            'icon' => ['s.icon1' => ['size' => 40]],
        ], 's', ['bg' => '#000000']);

        $this->assertStringContainsString('<h2 class="x" data-lt-t="s.t1 s.t2" style="--lt-fs:clamp(19px, 3.125vw, 32px);--lt-c:#112233" data-lt-sty="fs c">', $out);
        $this->assertStringContainsString('alt="A &quot;quoted&quot; alt" style="color:transparent;--lt-rad:12px"', $out);
        $this->assertStringContainsString('data-lt-icon="s.icon1" style="--lt-isz:40px" data-lt-sty="isz"', $out);
        $this->assertStringContainsString('<p data-lt-t="s.t9">keep</p>', $out);     // untouched
        $this->assertStringContainsString('<div class="a>b">', $out);                 // quoted '>' survives
        $this->assertSame($html, Styles::apply($html, []));                          // nothing styled → identical
    }

    public function test_merge_enforces_style_caps_per_property(): void
    {
        $caps = $this->ltAllCaps(false);
        $caps['image'] = true;                          // alt text yes, image styling no
        $r = Content::merge(null, [
            'styles' => [
                'hero.t1' => ['size' => 60],
                'header.img1' => ['alt' => 'Our logo', 'grayscale' => 100],
                'nope.t1' => ['size' => 10],
            ],
            'section_styles' => ['hero' => ['bg' => '#ffeecc']],
        ], $this->ltManifest(), $caps);

        $this->assertSame(['header.img1' => ['alt' => 'Our logo']], $r['content']['styles']);
        $this->assertSame([], $r['content']['section_styles']);
        $this->assertEqualsCanonicalizing(['styles.hero.t1.size', 'styles.header.img1.grayscale', 'styles.nope.t1', 'section_styles.hero.bg'], $r['rejected']);

        $all = Content::merge(null, [
            'styles' => ['hero.t1' => ['size' => 60, 'align' => 'center', 'bogus' => 1], 'hero.link1' => ['size' => 20]],
            'section_styles' => ['hero' => ['bg' => '#ffeecc', 'pt' => 5000]],
        ], $this->ltManifest(), $this->ltAllCaps());
        $this->assertSame(['align' => 'center', 'size' => 60], $all['content']['styles']['hero.t1']);
        $this->assertSame(['bg' => '#ffeecc', 'pt' => 400], $all['content']['section_styles']['hero']);
        $this->assertContains('styles.hero.t1.bogus', $all['rejected']);
        $this->assertContains('styles.hero.link1', $all['rejected']);             // links have no element style
    }

    public function test_unchanged_styles_and_text_never_count_as_violations(): void
    {
        $stored = ['slots' => ['hero.t1' => '<strong>Big</strong> news'], 'styles' => ['hero.t1' => ['size' => 60]]];
        $none = $this->ltAllCaps(false);

        $r = Content::merge($stored, ['slots' => ['hero.t1' => '<strong>Big</strong> news'], 'styles' => ['hero.t1' => ['size' => 60]]], $this->ltManifest(), $none);
        $this->assertSame([], $r['rejected']);
        $this->assertSame($stored['styles'], $r['content']['styles']);

        // Removing a style needs the cap too.
        $r = Content::merge($stored, ['styles' => []], $this->ltManifest(), $none);
        $this->assertSame(['styles.hero.t1.size'], $r['rejected']);
        $this->assertSame(['size' => 60], $r['content']['styles']['hero.t1']);
        $r = Content::merge($stored, ['styles' => []], $this->ltManifest(), $this->ltAllCaps());
        $this->assertSame([], $r['content']['styles']);
    }

    public function test_rich_slots_store_sanitised_markup(): void
    {
        $r = Content::merge(null, ['slots' => [
            'hero.t1' => 'Hello <b>world</b><img src=x onerror=alert(1)>',
            'hero.t3' => '<b>Start</b>',                   // inside a link → plain slot
        ]], $this->ltManifest(), $this->ltAllCaps());
        $this->assertSame('Hello <strong>world</strong>', $r['content']['slots']['hero.t1']);
        $this->assertSame('<b>Start</b>', $r['content']['slots']['hero.t3']);   // plain text (escaped at render)

        $caps = $this->ltAllCaps();
        $caps['text_format'] = false;
        $plain = Content::merge(null, ['slots' => ['hero.t1' => 'Hello <b>world</b>']], $this->ltManifest(), $caps);
        $this->assertSame('Hello world', $plain['content']['slots']['hero.t1']);

        // Typing the default back (escaped form) clears the override.
        $back = Content::merge(['slots' => ['hero.t1' => 'x']], ['slots' => ['hero.t1' => 'Welcome']], $this->ltManifest(), $this->ltAllCaps());
        $this->assertArrayNotHasKey('hero.t1', $back['content']['slots']);
    }
}
