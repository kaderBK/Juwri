<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Media\Contracts\MediaCopier;
use Juwri\Modules\Media\Models\MediaAsset;

/**
 * Public Media Library — the single choke point that copies an external or
 * shared image INTO an owner's own MediaAsset.
 *
 * Rules (KICKOFF §media, LOCKED):
 *  - NEVER hotlink; always download the bytes and store our own copy.
 *  - NEVER share storage rows across tenants; every copy is a fresh MediaAsset
 *    scoped to the target restaurant.
 *  - Quota is enforced centrally by StorageService::upload (via image_upload).
 *    A rejected upload returns '' → we return null and create no row.
 *
 * All four sources (curated pool, Pexels/Unsplash stock, Google image search,
 * AI-generated) funnel through here so quota + hardening + WebP normalisation
 * happen in exactly one place.
 */
class MediaCopyService implements MediaCopier
{
    /**
     * Copy raw image bytes into the owner's MediaAsset.
     *
     * @param  array  $meta  ['original_name','folder','source','attribution','mime']
     * @return MediaAsset|null null when quota/hardening rejected the upload.
     */
    public function fromBytes(string $bytes, int $storeId, array $meta = []): ?MediaAsset
    {
        if ($bytes === '') {
            return null;
        }

        $folder = $meta['folder'] ?? 'menu';
        $originalNm = $meta['original_name'] ?? ('image-'.time());
        $source = $meta['source'] ?? 'public';
        $attribution = $meta['attribution'] ?? null;
        $mime = $meta['mime'] ?? 'image/jpeg';

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $tmp = tempnam(sys_get_temp_dir(), 'mcs_').'.'.$ext;
        file_put_contents($tmp, $bytes);

        try {
            // test=true lets us wrap an arbitrary local file as an UploadedFile
            // WITHOUT it needing to originate from a real HTTP upload, so the
            // whole StorageService pipeline (guard + quota + WebP) is reused.
            $uploaded = new UploadedFile($tmp, basename($originalNm), $mime, null, true);

            $filename = image_upload($uploaded, 'storage/menu/', '1200', null, null, $storeId);
            if ($filename === '') {
                return null; // quota / hardening rejected
            }

            return MediaAsset::create([
                'store_id' => $storeId,
                'filename' => $filename,
                'original_name' => $originalNm,
                'folder' => $folder,
                'size_bytes' => strlen($bytes),
                'source' => $source,
                'attribution' => $attribution,
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Download a remote image and copy it into the owner's MediaAsset.
     * Returns null on download failure (hotlink-protected hosts, 4xx/5xx,
     * non-image content) or when quota rejects the copy.
     *
     * @throws MediaCopyException with a user-facing reason when the download fails.
     */
    public function fromUrl(string $url, int $storeId, array $meta = []): ?MediaAsset
    {
        // SSRF: the URL is caller-supplied (stock-image copy, "copy from URL"), so it must
        // pass the same guard the webhook/import paths use — https-only, every resolved IP
        // public, redirects re-validated per hop — before the server fetches it. Without
        // this it reached 169.254.169.254 (cloud metadata) / localhost / internal services,
        // returning image/* bodies and leaking HTTP status as a blind port-scan oracle.
        if (! SsrfGuard::isSafePublicUrl($url)) {
            throw new MediaCopyException(___('That URL is not allowed.'));
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; JuwriMedia/1.0)'])
                ->withOptions(SsrfGuard::pinnedOptions($url) ?? throw new \RuntimeException('SSRF: URL is not a safe public https endpoint'))
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('[MediaCopy] download error: '.$e->getMessage(), ['url' => $url]);
            throw new MediaCopyException(___('Could not download this image. The host may block hotlinking — try another image.'));
        }

        if ($response->failed()) {
            throw new MediaCopyException(___('Could not download this image (HTTP :code).', ['code' => $response->status()]));
        }

        $bytes = $response->body();
        $mime = strtolower(explode(';', (string) $response->header('Content-Type'))[0]);

        if ($bytes === '' || ! str_starts_with($mime, 'image/')) {
            throw new MediaCopyException(___('That URL did not return a usable image.'));
        }

        $meta['mime'] = $meta['mime'] ?? $mime;

        return $this->fromBytes($bytes, $storeId, $meta);
    }

    /**
     * Copy a locally-stored file (e.g. a curated public-media asset) into the
     * owner's MediaAsset.
     */
    public function fromLocalFile(string $absolutePath, int $storeId, array $meta = []): ?MediaAsset
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $meta['mime'] = $meta['mime'] ?? (function_exists('mime_content_type') ? (mime_content_type($absolutePath) ?: 'image/jpeg') : 'image/jpeg');

        return $this->fromBytes($bytes, $storeId, $meta);
    }
}
