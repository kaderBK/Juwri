<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every dashboard sidebar icon is a Flaticon UIcons class (`fi-rr-*`, brands,
 * …). If the name is not in the bundled font it renders as a BLANK menu row —
 * which is exactly how "Signage", "WhatsApp settings" and "AI menu actions"
 * shipped icon-less (fi-rr-display / fi-rr-whatsapp / fi-rr-wand-magic-sparkles
 * do not exist in the pack). This guards against re-introducing an icon name the
 * font cannot draw. Pure static check — no auth/feature-gate context needed.
 */
class DashboardNavIconsTest extends TestCase
{
    public function test_every_nav_helper_icon_exists_in_the_bundled_font(): void
    {
        $navSrc = file_get_contents(app_path('Http/Helpers/NavHelper.php'));
        preg_match_all('/fi-(?:rr|sr|br|tr|bs|brands)-[a-z0-9-]+/', $navSrc, $m);
        $used = array_values(array_unique($m[0]));
        $this->assertNotEmpty($used, 'no icons found in NavHelper — the extractor pattern likely broke');

        // The icon font (NexLink Flaticon) left with WI-16h; every shell maps the
        // retired keys to kit/Tabler SVGs in <x-ui.shell.nav-icon>. Each key the
        // nav model uses must be in that map or be a vendored Tabler icon name.
        $iconSrc = file_get_contents(resource_path('views/components/ui/shell/nav-icon.blade.php'));
        preg_match_all("/'([a-z0-9-]+)' => \\['/", $iconSrc, $mm);
        $defined = array_fill_keys($mm[1], true);
        $this->assertNotEmpty($defined, 'no glyphs parsed from the nav-icon map');
        foreach ($used as $ic) {
            $key = (string) preg_replace('/^fi-(rr|sr|br|tr|bs|brands)-/', '', $ic);
            if (is_file(base_path('../assets/vendor/tabler-icons/outline/'.$key.'.svg'))) {
                $defined[$ic] = true;
            }
            if (isset($defined[$key])) {
                $defined[$ic] = true;
            }
        }

        $missing = array_values(array_filter($used, fn ($ic) => ! isset($defined[$ic])));
        $this->assertSame([], $missing, 'Dashboard nav icons with no SVG mapping: '.implode(', ', $missing));
    }
}
