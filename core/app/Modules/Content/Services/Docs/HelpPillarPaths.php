<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Docs;

use Juwri\Core\Contracts\Content\HelpPillars;

/**
 * Resolves a help pillar to the directory the Help Center reads. An edition
 * binding wins when it ships that pillar. Otherwise the Community docs tree.
 */
final class HelpPillarPaths
{
    public static function root(string $pillar): string
    {
        $edition = app(HelpPillars::class)->directory($pillar);
        if (is_string($edition) && $edition !== '') {
            $real = realpath($edition);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return resource_path('docs/'.$pillar);
    }
}
