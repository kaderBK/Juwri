<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Modules\Content\Models\LockedTemplate;
use Juwri\Modules\Content\Models\LockedTemplateVersion;
use Juwri\Modules\Content\Services\LockedTemplates\Permissions;

/**
 * A tiny but complete locked-template manifest (header + hero + footer) covering
 * every slot type, a palette entry, a lead form and a required section.
 *
 * Versions point their assets_base at the shipped sample template's folder
 * (assets/locked-templates/starter/v1): pages only render a version whose files are
 * deployed (LockedTemplateVersion::assetsAvailable).
 */
trait LockedTemplateFixtures
{
    /** @return array<string, mixed> */
    protected function ltManifest(): array
    {
        return [
            'schema' => 1,
            'meta' => ['key' => 'test-tpl', 'version' => 1, 'name' => 'Test template', 'assets_base' => 'assets/locked-templates/starter/v1'],
            'styles' => ['style.css'],
            'scripts' => ['/assets/locked-templates/_runtime/kit/kit.js'],
            'body_class' => 'antialiased',
            'main_class' => 'bg-page',
            'settings' => ['theme' => ['default' => 'light']],
            'palette' => [
                ['key' => 'primary', 'label' => 'Brand colour', 'default' => '#864ffe', 'vars' => ['--color-primary-500' => '{c}', '--color-primary-50' => 'color-mix(in oklab, {c} 6%, white)']],
            ],
            'sections' => [
                [
                    'key' => 'header', 'label' => 'Header', 'required' => true, 'region' => 'before',
                    'html' => '<header data-lt-section="header"><a href="{{slot:header.link1}}" data-lt-a="header.link1"><img src="{{slot:header.img1}}" data-lt-img="header.img1" alt="Logo"></a><span data-lt-t="header.t1">{{slot:header.t1}}</span></header>',
                    'slots' => [
                        ['key' => 'header.img1', 'type' => 'image', 'label' => 'Logo', 'default' => '{{asset}}/media/logo.svg'],
                        ['key' => 'header.link1', 'type' => 'link', 'label' => 'Link: Home', 'default' => '/'],
                        ['key' => 'header.t1', 'type' => 'text', 'label' => 'Text: Brand', 'default' => 'Acme'],
                    ],
                ],
                [
                    'key' => 'hero', 'label' => 'Hero', 'region' => 'main',
                    'html' => '<section id="hero" data-lt-section="hero"><h1 data-lt-t="hero.t1">{{slot:hero.t1}}</h1><form action="{{form:action}}" method="post" data-lt-form>{{form:fields}}<input name="email" placeholder="{{slot:hero.t2}}"><button type="submit">Go</button></form><a href="{{slot:hero.link1}}" data-lt-a="hero.link1"><span data-lt-t="hero.t3">{{slot:hero.t3}}</span></a></section>',
                    'slots' => [
                        ['key' => 'hero.t1', 'type' => 'text', 'label' => 'Heading: Welcome', 'default' => 'Welcome', 'max' => 120],
                        ['key' => 'hero.t2', 'type' => 'text', 'label' => 'Placeholder: Email', 'default' => 'Your email'],
                        ['key' => 'hero.link1', 'type' => 'link', 'label' => 'Link: Start', 'default' => '#cta'],
                        ['key' => 'hero.t3', 'type' => 'text', 'label' => 'Button text: Start', 'default' => 'Start'],
                    ],
                ],
                [
                    'key' => 'features', 'label' => 'Features', 'region' => 'main',
                    'html' => '<section id="features" data-lt-section="features"><div>{{slot:features.icon1}}</div><p data-lt-t="features.t1">{{slot:features.t1}}</p><img src="{{slot:features.img1}}" data-lt-img="features.img1" alt="QR"></section>',
                    'slots' => [
                        ['key' => 'features.t1', 'type' => 'text', 'label' => 'Paragraph: Fast', 'default' => 'Fast and simple'],
                        ['key' => 'features.icon1', 'type' => 'icon', 'label' => 'Icon: Fast', 'default' => '', 'default_html' => '<span class="ns-shape-3 text-[40px]"></span>', 'icon_class' => 'text-[40px]', 'icon_width' => '1em', 'icon_height' => '1em'],
                        ['key' => 'features.img1', 'type' => 'image', 'label' => 'Image: QR code (links to your site)', 'default' => '{{qr:site}}'],
                    ],
                ],
                [
                    'key' => 'footer', 'label' => 'Footer', 'required' => true, 'region' => 'after',
                    'html' => '<footer data-lt-section="footer"><p data-lt-t="footer.t1">{{slot:footer.t1}}</p></footer>',
                    'slots' => [['key' => 'footer.t1', 'type' => 'text', 'label' => 'Paragraph: Copyright', 'default' => '© Acme']],
                ],
            ],
        ];
    }

    /**
     * ltManifest() + a "list" section whose items repeat in two instances (a list and
     * a nav, like the desktop + mobile menu), each item a link with text + a note.
     *
     * @return array<string, mixed>
     */
    protected function ltItemsManifest(): array
    {
        $m = $this->ltManifest();
        $item = fn (int $n, string $word) => [
            'slots' => ["list.t{$n}", "list.link{$n}", "list.p{$n}"],
            'html' => [
                "<li id=\"item-{$word}\"><a href=\"{{slot:list.link{$n}}}\" data-lt-a=\"list.link{$n}\"><span data-lt-t=\"list.t{$n}\">{{slot:list.t{$n}}}</span></a><p data-lt-t=\"list.p{$n}\">{{slot:list.p{$n}}}</p></li>",
                "<a href=\"{{slot:list.link{$n}}}\" data-lt-a=\"list.link{$n}\" aria-controls=\"item-{$word}\">{{slot:list.t{$n}}}</a>",
            ],
        ];
        array_splice($m['sections'], 3, 0, [[
            'key' => 'list', 'label' => 'List', 'region' => 'main',
            'html' => '<section id="list" data-lt-section="list"><ul>{{repeat:list.r1:0}}</ul><nav>{{repeat:list.r1:1}}</nav></section>',
            'slots' => [
                ['key' => 'list.t1', 'type' => 'text', 'label' => 'Button text: One', 'default' => 'One'],
                ['key' => 'list.link1', 'type' => 'link', 'label' => 'Link: One', 'default' => '/one'],
                ['key' => 'list.p1', 'type' => 'text', 'label' => 'Paragraph: First', 'default' => 'First note'],
                ['key' => 'list.t2', 'type' => 'text', 'label' => 'Button text: Two', 'default' => 'Two'],
                ['key' => 'list.link2', 'type' => 'link', 'label' => 'Link: Two', 'default' => '/two'],
                ['key' => 'list.p2', 'type' => 'text', 'label' => 'Paragraph: Second', 'default' => 'Second note'],
            ],
            'repeaters' => [['key' => 'list.r1', 'label' => 'Items: One', 'min' => 1, 'max' => 4, 'items' => [$item(1, 'one'), $item(2, 'two')]]],
        ]]);

        return $m;
    }

    protected function ltTemplate(string $status = 'published', ?array $manifest = null): LockedTemplate
    {
        $t = LockedTemplate::create([
            'key' => 'test-tpl-'.substr(md5((string) microtime(true).random_int(0, 99999)), 0, 6),
            'name' => 'Test template',
            'description' => 'A test template',
            'status' => $status,
            'current_version' => 1,
        ]);
        LockedTemplateVersion::create([
            'locked_template_id' => $t->id,
            'version' => 1,
            'manifest' => json_encode($manifest ?? $this->ltManifest()),
            'assets_base' => 'assets/locked-templates/starter/v1',
        ]);

        return $t;
    }

    /**
     * A section-library block (as convert.mjs writes to lib-v<N>.json).
     *
     * @return array<string, mixed>
     */
    protected function ltLibraryBlock(string $key = 'lib-pricing-1'): array
    {
        return [
            'key' => $key, 'label' => 'Simple pricing', 'group' => 'Pricing', 'region' => 'main',
            'html' => '<section data-lt-section="'.$key.'" id="'.$key.'"><h2 data-lt-t="'.$key.'.t1">{{slot:'.$key.'.t1}}</h2><a href="{{slot:'.$key.'.link1}}" data-lt-a="'.$key.'.link1">Go</a></section>',
            'slots' => [
                ['key' => $key.'.t1', 'type' => 'text', 'label' => 'Heading: Simple pricing', 'default' => 'Simple pricing'],
                ['key' => $key.'.link1', 'type' => 'link', 'label' => 'Link: Go', 'default' => '#pricing'],
            ],
        ];
    }

    protected function ltVersion(?array $manifest = null): LockedTemplateVersion
    {
        return new LockedTemplateVersion([
            'locked_template_id' => 1,
            'version' => 1,
            'manifest' => json_encode($manifest ?? $this->ltManifest()),
            'assets_base' => 'assets/locked-templates/starter/v1',
        ]);
    }

    /** @return array<string, bool> */
    protected function ltAllCaps(bool $on = true): array
    {
        return array_fill_keys(array_keys(Permissions::CAPS), $on);
    }
}
