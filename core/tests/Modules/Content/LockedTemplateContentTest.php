<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\LockedTemplates\Content;
use PHPUnit\Framework\TestCase;
use Tests\Support\LockedTemplateFixtures;

/**
 * Content::merge is where the superadmin's per-user capabilities are ENFORCED:
 * anything a user's caps don't allow (or that fails validation) is kept as it was
 * and reported, whatever the editor UI sent.
 */
class LockedTemplateContentTest extends TestCase
{
    use LockedTemplateFixtures;

    public function test_allowed_edits_are_stored(): void
    {
        $r = Content::merge(null, [
            'slots' => ['hero.t1' => 'Hello', 'hero.link1' => 'https://example.com', 'header.img1' => '/storage/reseller/1/builder/logo.png'],
            'palette' => ['primary' => '#0E7C66'],
            'settings' => ['theme' => 'dark'],
        ], $this->ltManifest(), $this->ltAllCaps());

        $this->assertSame([], $r['rejected']);
        $this->assertSame('Hello', $r['content']['slots']['hero.t1']);
        $this->assertSame('https://example.com', $r['content']['slots']['hero.link1']);
        $this->assertSame('/storage/reseller/1/builder/logo.png', $r['content']['slots']['header.img1']);
        $this->assertSame('#0e7c66', $r['content']['palette']['primary']);
        $this->assertSame('dark', $r['content']['settings']['theme']);
    }

    public function test_capabilities_are_enforced_per_type(): void
    {
        $caps = $this->ltAllCaps(false);
        $caps['image'] = true;   // this user may only swap images

        $r = Content::merge(['slots' => ['hero.t1' => 'Before']], [
            'slots' => ['hero.t1' => 'After', 'hero.link1' => '#x', 'header.img1' => '/storage/a.png'],
            'palette' => ['primary' => '#000000'],
            'settings' => ['theme' => 'dark'],
        ], $this->ltManifest(), $caps);

        $this->assertSame('Before', $r['content']['slots']['hero.t1']);          // text denied → kept
        $this->assertArrayNotHasKey('hero.link1', $r['content']['slots']);       // link denied
        $this->assertSame('/storage/a.png', $r['content']['slots']['header.img1']); // image allowed
        $this->assertSame([], $r['content']['palette']);
        $this->assertSame([], $r['content']['settings']);
        $this->assertEqualsCanonicalizing(['slots.hero.t1', 'slots.hero.link1', 'palette.primary', 'settings.theme'], $r['rejected']);
    }

    public function test_invalid_and_unknown_values_are_rejected(): void
    {
        $r = Content::merge(null, [
            'slots' => ['hero.link1' => 'javascript:alert(1)', 'header.img1' => 'data:image/svg+xml,x', 'nope.t1' => 'x', 'hero.t1' => ['array']],
            'palette' => ['primary' => 'red', 'ghost' => '#ffffff'],
            'settings' => ['theme' => 'neon'],
        ], $this->ltManifest(), $this->ltAllCaps());

        $this->assertSame([], $r['content']['slots']);
        $this->assertSame([], $r['content']['palette']);
        $this->assertArrayNotHasKey('theme', $r['content']['settings']);
        $this->assertCount(7, $r['rejected']);
    }

    public function test_null_resets_a_slot_to_its_default(): void
    {
        $r = Content::merge(['slots' => ['hero.t1' => 'Custom']], ['slots' => ['hero.t1' => null]], $this->ltManifest(), $this->ltAllCaps());

        $this->assertArrayNotHasKey('hero.t1', $r['content']['slots']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_default_value_clears_the_override(): void
    {
        $r = Content::merge(['slots' => ['hero.t1' => 'Custom']], ['slots' => ['hero.t1' => 'Welcome']], $this->ltManifest(), $this->ltAllCaps());
        $this->assertArrayNotHasKey('hero.t1', $r['content']['slots']);
    }

    public function test_section_toggle_and_reorder_need_their_caps(): void
    {
        $caps = $this->ltAllCaps(false);
        $incoming = ['sections' => [
            ['key' => 'header', 'hidden' => false],
            ['key' => 'features', 'hidden' => true],
            ['key' => 'hero', 'hidden' => false],
            ['key' => 'footer', 'hidden' => false],
        ]];

        $denied = Content::merge(null, $incoming, $this->ltManifest(), $caps);
        $this->assertContains('sections.features.hidden', $denied['rejected']);
        $this->assertContains('sections.order', $denied['rejected']);
        $this->assertSame(['header', 'hero', 'features', 'footer'], array_column($denied['content']['sections'], 'key'));
        $this->assertFalse($denied['content']['sections'][2]['hidden']);

        $caps['sections_toggle'] = $caps['sections_reorder'] = true;
        $allowed = Content::merge(null, $incoming, $this->ltManifest(), $caps);
        $this->assertSame([], $allowed['rejected']);
        $this->assertSame(['header', 'features', 'hero', 'footer'], array_column($allowed['content']['sections'], 'key'));
        $this->assertTrue($allowed['content']['sections'][1]['hidden']);
    }

    public function test_required_sections_cannot_be_hidden(): void
    {
        $r = Content::merge(null, ['sections' => [['key' => 'header', 'hidden' => true]]], $this->ltManifest(), $this->ltAllCaps());
        $this->assertContains('sections.header.hidden', $r['rejected']);
        $this->assertFalse($r['content']['sections'][0]['hidden']);
    }
}
