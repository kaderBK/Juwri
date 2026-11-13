<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * P6-147: uploaded SVGs under /storage/ are served by PHP with the sandbox CSP.
 *
 * SVG is active content. Uploads are sanitised (StorageService::sanitizeSvg), and opening one
 * directly must still never run script from our origin (older or seeded files, a sanitizer gap).
 * The .htaccess `<FilesMatch "\.svg$">` header does that on Apache only; OpenLiteSpeed ignores it
 * and served the file with no CSP. So .htaccess rewrites existing /storage/*.svg to index.php
 * (rewrite rules work on both servers) and this middleware answers it before any session, DB or
 * store work. Only real files inside public storage; anything else falls through to the app (404).
 */
class ServePublicSvg
{
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    public function handle(Request $request, Closure $next): Response
    {
        $path = rawurldecode($request->path());
        if (! $request->isMethodSafe() || ! preg_match('#^storage/.+\.svg$#i', $path)) {
            return $next($request);
        }

        $file = self::resolve($path);
        if ($file === null) {
            return $next($request);
        }

        $response = new BinaryFileResponse($file, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000',
        ], true, null, false, true);
        $response->isNotModified($request);

        return $response;
    }

    /** Absolute path of an existing .svg inside public storage, or null. */
    public static function resolve(string $relative): ?string
    {
        if (str_contains($relative, "\0")) {
            return null;
        }
        $root = realpath(public_path('storage'));
        $real = realpath(public_path($relative));
        if ($root === false || $real === false || ! is_file($real)) {
            return null;
        }
        if (! str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'svg') {
            return null;
        }

        return $real;
    }
}
