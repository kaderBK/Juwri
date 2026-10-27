<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\LockedTemplates\Content;
use Juwri\Modules\Content\Services\LockedTemplates\Items;
use Juwri\Modules\Content\Services\LockedTemplates\Manifest;
use PHPUnit\Framework\TestCase;
use Tests\Support\LockedTemplateFixtures;

/**
 * Repeated items and section copies change page STRUCTURE, but only by choosing
 * which trusted pieces appear: refs are validated, new items/copies are re-keyed
 * clones of the superadmin's markup, and the `items` / `sections_duplicate` caps
 * are enforced in Content::merge.
 */
class LockedTemplateItemsTest extends TestCase
{
    use LockedTemplateFixtures;

    public function test_items_manifest_is_valid_and_bad_repeaters_are_caught(): void
    {
        $this->assertSame([], Manifest::validate($this->ltItemsManifest()));

        $cases = [
            'unlisted slot' => fn (&$m) => $m['sections'][3]['repeaters'][0]['items'][0]['slots'] = ['list.t1'],
            'undeclared slot' => fn (&$m) => $m['sections'][3]['repeaters'][0]['items'][0]['slots'][] = 'nope.t9',
            'placeholder in tag' => fn (&$m) => $m['sections'][3]['html'] = '<section title="{{repeat:list.r1:0}}"><nav>{{repeat:list.r1:1}}</nav></section>',
            'missing instance markup' => fn (&$m) => array_pop($m['sections'][3]['repeaters'][0]['items'][1]['html']),
            'unknown repeater' => fn (&$m) => $m['sections'][3]['html'] .= '{{repeat:list.r9:0}}',
            'nested repeater' => fn (&$m) => $m['sections'][3]['repeaters'][0]['items'][0]['html'][0] .= '{{repeat:list.r1:0}}',
            'unsafe item markup' => fn (&$m) => $m['sections'][3]['repeaters'][0]['items'][0]['html'][0] = '<a href="{{slot:list.t1}}">{{slot:list.link1}}{{slot:list.p1}}</a>',
        ];
        foreach ($cases as $label => $break) {
            $m = $this->ltItemsManifest();
            $break($m);
            $this->assertNotSame([], Manifest::validate($m), $label);
        }
    }

    public function test_refs_are_validated(): void
    {
        $rep = Items::repeaters($this->ltItemsManifest())['list.r1'];

        $this->assertSame(['0', '1'], Items::refs([], $rep));
        $this->assertSame(['1', 'nab12:0', '0'], Items::cleanRefs(['1', 'nab12:0', '0'], $rep));
        foreach ([[], ['0', '0'], ['2'], ['x'], ['nAB:0'], ['n1:0', 'n1:1'], ['0', '1', 'n1:0', 'n2:0', 'n3:1'], 'nope', ['a' => '0']] as $bad) {
            $this->assertNull(Items::cleanRefs($bad, $rep), json_encode($bad));
        }
    }

    public function test_adding_items_needs_the_cap_and_new_slots_are_editable_in_the_same_save(): void
    {
        $m = $this->ltItemsManifest();
        $incoming = [
            'items' => ['list.r1' => ['0', 'nab12:0', '1']],
            'slots' => ['list.t1~nab12' => 'Three', 'list.link1~nab12' => '/three', 'list.p1~nab12' => 'Third <b>note</b>'],
        ];

        $denied = $this->ltAllCaps();
        $denied['items'] = false;
        $r = Content::merge(null, $incoming, $m, $denied);
        $this->assertArrayNotHasKey('list.r1', $r['content']['items']);
        $this->assertContains('items.list.r1', $r['rejected']);
        $this->assertContains('slots.list.t1~nab12', $r['rejected']);    // no such slot without the item

        $r = Content::merge(null, $incoming, $m, $this->ltAllCaps());
        $this->assertSame([], $r['rejected']);
        $this->assertSame(['0', 'nab12:0', '1'], $r['content']['items']['list.r1']);
        $this->assertSame('/three', $r['content']['slots']['list.link1~nab12']);
        $this->assertSame('Third <strong>note</strong>', $r['content']['slots']['list.p1~nab12']);   // rich like its origin

        // Removing the item drops what it owned; back to template order = no override.
        $gone = Content::merge($r['content'], ['items' => ['list.r1' => ['0', '1']]], $m, $this->ltAllCaps());
        $this->assertArrayNotHasKey('list.r1', $gone['content']['items']);
        $this->assertSame([], array_filter(array_keys($gone['content']['slots']), fn ($k) => str_contains($k, '~nab12')));
    }

    public function test_section_copies_need_their_cap_and_are_rekeyed(): void
    {
        $m = $this->ltItemsManifest();
        $order = [['key' => 'header'], ['key' => 'hero'], ['key' => 'features'], ['key' => 'features~c1x'], ['key' => 'list'], ['key' => 'footer']];

        $r = Content::merge(null, ['sections' => $order], $m, $this->ltAllCaps());   // DEFAULTS-like: sections_duplicate on in ltAllCaps
        $this->assertSame('features~c1x', $r['content']['sections'][3]['key']);
        $this->assertNotContains('sections.order', $r['rejected']);                    // placed after its source: not a reorder

        $caps = $this->ltAllCaps();
        $caps['sections_duplicate'] = false;
        $denied = Content::merge(null, ['sections' => $order], $m, $caps);
        $this->assertNotContains('features~c1x', array_column($denied['content']['sections'], 'key'));
        $this->assertContains('sections.features~c1x', $denied['rejected']);

        // Required sections (header/footer) can't be copied.
        $bad = Content::merge(null, ['sections' => [['key' => 'header'], ['key' => 'header~c2']]], $m, $this->ltAllCaps());
        $this->assertContains('sections.header~c2', $bad['rejected']);

        // The copy's slots exist (re-keyed) and are editable; deleting the copy drops them.
        $edited = Content::merge($r['content'], ['slots' => ['features.t1~c1x' => 'Copy text']], $m, $this->ltAllCaps());
        $this->assertSame('Copy text', $edited['content']['slots']['features.t1~c1x']);
        $deleted = Content::merge($edited['content'], ['sections' => array_values(array_filter($order, fn ($s) => $s['key'] !== 'features~c1x'))], $m, $this->ltAllCaps());
        $this->assertArrayNotHasKey('features.t1~c1x', $deleted['content']['slots']);
        $this->assertNotContains('features~c1x', array_column($deleted['content']['sections'], 'key'));
    }

    public function test_library_sections_are_added_as_copies_under_their_own_cap(): void
    {
        $m = $this->ltItemsManifest() + ['library' => [$this->ltLibraryBlock()]];
        $this->assertSame([], Manifest::validate($m));
        $order = [['key' => 'header'], ['key' => 'hero'], ['key' => 'lib-pricing-1~c7'], ['key' => 'features'], ['key' => 'list'], ['key' => 'footer']];

        $caps = $this->ltAllCaps();
        $caps['sections_duplicate'] = false;                 // adding from the library is its own cap
        $r = Content::merge(null, ['sections' => $order], $m, $caps);
        $this->assertSame('lib-pricing-1~c7', $r['content']['sections'][2]['key']);
        $this->assertSame([], $r['rejected']);

        // Its fields are real (re-keyed) slots; the block itself never sits on a page.
        $edited = Content::merge($r['content'], ['slots' => ['lib-pricing-1.t1~c7' => 'Plans']], $m, $caps);
        $this->assertSame('Plans', $edited['content']['slots']['lib-pricing-1.t1~c7']);
        $plain = Content::merge(null, ['sections' => [['key' => 'lib-pricing-1']]], $m, $caps);
        $this->assertNotContains('lib-pricing-1', array_column($plain['content']['sections'], 'key'));

        $caps['sections_add'] = false;
        $denied = Content::merge(null, ['sections' => $order], $m, $caps);
        $this->assertContains('sections.lib-pricing-1~c7', $denied['rejected']);
        // …and without it, a placed library section can't be removed either.
        $kept = Content::merge($edited['content'], ['sections' => [['key' => 'header'], ['key' => 'hero']]], $m, $caps);
        $this->assertContains('lib-pricing-1~c7', array_column($kept['content']['sections'], 'key'));

        // Without the library attached (manifest only), an unknown block is refused.
        $unknown = Content::merge(null, ['sections' => $order], $this->ltItemsManifest(), $this->ltAllCaps());
        $this->assertContains('sections.lib-pricing-1~c7', $unknown['rejected']);
    }

    public function test_library_sections_follow_every_manifest_rule(): void
    {
        $base = $this->ltItemsManifest();
        $collide = $this->ltLibraryBlock('hero');                       // same key as a page section
        $this->assertNotSame([], Manifest::validate($base + ['library' => [$collide]]));
        $required = ['required' => true] + $this->ltLibraryBlock();
        $this->assertContains("Library section 'lib-pricing-1' must be an optional main-content section.", Manifest::validate($base + ['library' => [$required]]));
        $unsafe = $this->ltLibraryBlock();
        $unsafe['html'] = '<section><script>{{slot:lib-pricing-1.t1}}</script><a href="{{slot:lib-pricing-1.link1}}">x</a></section>';
        $this->assertNotSame([], Manifest::validate($base + ['library' => [$unsafe]]));
    }

    public function test_copy_section_rekeys_everything(): void
    {
        $list = $this->ltItemsManifest()['sections'][3];
        $copy = Items::copySection($list, 'c9');

        $this->assertSame('list~c9', $copy['key']);
        $this->assertStringContainsString('data-lt-section="list~c9"', $copy['html']);
        $this->assertStringContainsString('id="list-c9"', $copy['html']);
        $this->assertStringContainsString('{{repeat:list.r1~c9:0}}', $copy['html']);
        $this->assertSame('list.r1~c9', $copy['repeaters'][0]['key']);
        $this->assertSame(['list.t1~c9', 'list.link1~c9', 'list.p1~c9'], $copy['repeaters'][0]['items'][0]['slots']);
        $this->assertStringContainsString('{{slot:list.t1~c9}}', $copy['repeaters'][0]['items'][0]['html'][0]);
        $this->assertStringContainsString('id="item-one-c9"', $copy['repeaters'][0]['items'][0]['html'][0]);
        $this->assertStringContainsString('aria-controls="item-one-c9"', $copy['repeaters'][0]['items'][0]['html'][1]);
        $this->assertSame('list.t1', $copy['slots'][0]['_origin']);
    }
}
