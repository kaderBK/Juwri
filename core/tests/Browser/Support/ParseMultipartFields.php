<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Browser\Support;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Pest's in-process browser server (pest-plugin-browser 4.3.1) only parses application/x-www-form-urlencoded request bodies;
 * the storefront posts its order as multipart FormData, which would reach the controller empty. This test-only global
 * middleware reads the text fields of a multipart body into the request, and (P6-115b) file parts into temporary
 * UploadedFile objects so a browser test can upload a photo. Registered per test with {@see self::install()}; never part
 * of the application.
 */
final class ParseMultipartFields
{
    public static function install(): void
    {
        app(Kernel::class)->prependMiddleware(self::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $type = (string) $request->headers->get('Content-Type');
        if (! str_starts_with(strtolower($type), 'multipart/form-data') || ! preg_match('/boundary="?([^";]+)"?/i', $type, $m)) {
            return $next($request);
        }

        $pairs = [];
        $files = [];
        foreach (explode('--'.$m[1], (string) $request->getContent()) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }
            [$head, $body] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');
            if (! preg_match('/name="([^"]*)"/i', $head, $n)) {
                continue;
            }
            if (preg_match('/filename="([^"]*)"/i', $head, $f)) {
                if ($f[1] !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'mpf');
                    file_put_contents($tmp, preg_replace('/\r\n$/', '', $body));
                    $mime = preg_match('/Content-Type:\s*([^\r\n]+)/i', $head, $t) ? trim($t[1]) : 'application/octet-stream';
                    // test = true: the file did not arrive through is_uploaded_file(), the browser server is this same process
                    $files[$n[1]] = new UploadedFile($tmp, $f[1], $mime, null, true);
                }

                continue;
            }
            $pairs[] = urlencode($n[1]).'='.urlencode(preg_replace('/\r\n$/', '', $body));
        }
        parse_str(implode('&', $pairs), $fields);
        $request->request->add($fields);
        foreach ($files as $name => $file) {
            $request->files->set($name, $file);
        }

        return $next($request);
    }
}
