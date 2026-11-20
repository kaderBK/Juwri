<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * fix-xss L2: storefront themes showed the server's JSON `message` (and aurora the guest order-lookup rows) through
 * innerHTML string concatenation. A future validation message that echoes input would become HTML injection, so
 * the message goes through textContent and the lookup rows through an escaper.
 */
class ThemeMessageSinkXssTest extends TestCase
{
    private const THEMES = [
        'carely/index.blade.php', 'aurelia/index.blade.php', 'shelf/index.blade.php', 'torque/index.blade.php',
        'bookla/index.blade.php', 'lumiere/partials/_script.blade.php', 'aurora/index.blade.php',
    ];

    private function theme(string $file): string
    {
        return File::get(resource_path('views/store_templates/'.$file));
    }

    public function test_server_message_is_never_concatenated_into_innerhtml(): void
    {
        foreach (self::THEMES as $file) {
            $src = $this->theme($file);
            $this->assertStringNotContainsString('+ ((data && data.message)', $src, $file);
            $this->assertStringNotContainsString('+ (data.message ||', $src, $file);
            $this->assertStringContainsString('.textContent = (data && data.message) ||', $src, $file);
        }
    }

    public function test_torque_toast_sets_its_text_with_textcontent(): void
    {
        $src = $this->theme('torque/index.blade.php');

        $this->assertStringNotContainsString("'<span>' + msg + '</span>'", $src);
        $this->assertStringContainsString("el.lastChild.textContent = msg == null ? '' : String(msg);", $src);
    }

    public function test_aurora_order_lookup_rows_are_escaped(): void
    {
        $src = $this->theme('aurora/index.blade.php');

        foreach (["'<a href=\"' + o.track_url", "'#' + o.id", "' · ' + o.date", "'>' + o.status", "'<b>' + o.total"] as $raw) {
            $this->assertStringNotContainsString($raw, $src, "raw lookup value {$raw}");
        }
        foreach (['lkEsc(track)', 'lkEsc(o.id)', 'lkEsc(o.date)', 'lkEsc(o.status)', 'lkEsc(o.total)'] as $escaped) {
            $this->assertStringContainsString($escaped, $src);
        }
    }
}
