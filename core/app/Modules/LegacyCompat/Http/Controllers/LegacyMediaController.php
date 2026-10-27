<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaIndex;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaPathMap;
use RuntimeException;

/**
 * M6: old public media URLs redirect (301) to the file's target path on the migrated disk. Every action 404s while
 * the module is off or the media config is missing, so a cached route table cannot keep the redirect alive.
 */
class LegacyMediaController extends Controller
{
    /**
     * /storage/{path} → 301 to the target disk's URL for the manifest entry. Only copied/already_present entries
     * redirect; an unknown, unsafe, conflicted or missing entry is a 404. The incoming query string is never kept.
     */
    public function show(string $path): RedirectResponse
    {
        abort_unless(LegacyCompatRoutes::enabled(), 404);

        $toDisk = config('legacy_compat.media.to_disk');
        $manifestPath = config('legacy_compat.media.manifest');
        if (! is_string($toDisk) || $toDisk === '' || ! is_string($manifestPath) || $manifestPath === '') {
            abort(404);
        }

        $normalized = LegacyMediaPathMap::normalize('storage/'.$path);
        if ($normalized === null) {
            abort(404);
        }

        $target = LegacyMediaIndex::lookup($manifestPath, $normalized);
        if ($target === null) {
            abort(404);
        }

        try {
            $url = Storage::disk($toDisk)->url($target);
        } catch (InvalidArgumentException|RuntimeException) {
            abort(404);
        }

        $response = redirect()->to($url, 301);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
