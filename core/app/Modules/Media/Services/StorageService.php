<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\RequestEpoch;

/**
 * StorageService – Abstraction layer over local disk and S3-compatible CDN.
 *
 * Workflow:
 *  1. Caller provides the UploadedFile, the relative storage sub-path (e.g. "store/cover/"),
 *     and the restaurant ID (or null for admin-level assets).
 *  2. StorageService resolves credentials via the BYOK Credentials Vault (Credential::for('storage', …)).
 *  3. If S3 credentials are present:
 *       a. Process / resize the image with Intervention.
 *       b. Write it to a temp path.
 *       c. Stream it to S3 using a dynamically-configured disk.
 *       d. Delete the temp file.
 *       e. Return the filename (the S3 object key can be derived from prefix + filename).
 *  4. If no S3 credentials, fall back to the existing local-disk behaviour.
 *
 * URL resolution:
 *  Call StorageService::url($subPath, $filename, $storeId) to get the correct public URL.
 */
class StorageService
{
    /**
     * Per-request memo of the READ-ONLY storage credential lookup, keyed by
     * restaurant id ('platform' for the null/admin scope).
     *
     * H11. The scope is deliberately narrow. Credential::for() is documented as
     * NOT memoised (core/app/Core/Models/Credential.php) because several features are
     * written with query-builder mass updates that fire no model events — e.g.
     * the Firebase clear at User/MobilePushController.php — so a blanket cache
     * would serve a cleared key inside the same request. This memo covers only
     * url(), which never writes, and whose single feature ('storage') is written
     * exclusively through Credential::updateOrCreate
     * (User/SettingsController.php, Admin/SettingsController.php) — both of which
     * DO fire model events, so Credential::booted() invalidates this memo.
     *
     * DO NOT reuse it in upload(), delete(), convertToWebp() or exists(): those
     * run after a write in the same request and must see live credentials.
     * If you ever add a `Credential::where('feature','storage')->delete()`, call
     * StorageService::flushUrlCredentialMemo() next to it — MediaUrlCredentialMemoTest
     * pins that contract.
     *
     * @var array<string, array<string,mixed>|null>
     */
    private static array $urlCredMemo = [];

    /** The application instance $urlCredMemo was filled for (CONVENTIONS: app-scope rebind). */
    private static ?int $memoApp = null;

    /** Drop the read-path memo. Called from Credential::booted() on any save/delete. */
    public static function flushUrlCredentialMemo(): void
    {
        self::$urlCredMemo = [];
    }

    /**
     * The url()-only credential read. Memoised; a null answer (the common
     * no-BYOK case, which costs TWO queries) is memoised too, which is why the
     * hit test is array_key_exists and not isset/??=.
     *
     * @return array<string,mixed>|null
     */
    private static function urlCredentials(?int $storeId): ?array
    {
        $appId = RequestEpoch::id();
        if (self::$memoApp !== $appId) {
            self::$memoApp = $appId;
            self::$urlCredMemo = [];
        }

        $key = $storeId === null ? 'platform' : (string) $storeId;

        if (! array_key_exists($key, self::$urlCredMemo)) {
            self::$urlCredMemo[$key] = Credential::for('storage', $storeId);
        }

        return self::$urlCredMemo[$key];
    }

    /**
     * Upload a file, optionally resizing it, to S3 or local disk.
     *
     * @param  UploadedFile  $file
     * @param  string  $path  Relative path (e.g. "storage/store/cover/")
     * @param  string|null  $size  Resize spec e.g. "500x500", "300", "1000x"
     * @param  string|null  $filename  Override the generated filename (without extension)
     * @param  string|null  $oldFilename  Old filename to delete after upload
     * @param  int|null  $storeId  For BYOK credential lookup
     * @return string The saved filename
     */
    public static function upload(
        $file,
        string $path,
        ?string $size = null,
        ?string $filename = null,
        ?string $oldFilename = null,
        ?int $storeId = null
    ): string {
        // Image processing needs headroom — ensure AT LEAST 256M, but never LOWER
        // an already-higher limit (ini_set is process-global; forcing 256M down was
        // capping long-running processes like the full test suite).
        $current = trim((string) ini_get('memory_limit'));
        if ($current !== '-1') {
            $unit = strtolower(substr($current, -1));
            $bytes = (int) $current * match ($unit) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
            };
            if ($bytes < 256 * 1048576) {
                ini_set('memory_limit', '256M');
            }
        }

        // Abuse Guard P5: harden every upload at the single choke point — mime-sniff,
        // size + megapixel caps (checked BEFORE Intervention decodes) and per-tenant
        // storage quota. Raw-bytes callers (e.g. AI image jobs) skip the file checks.
        // Warn-only by default: a would-be reject is logged and the upload proceeds.
        $ownerId = $storeId ? (int) (Store::where('id', $storeId)->value('user_id') ?: 0) : 0;
        $origBytes = (is_object($file) && method_exists($file, 'getSize')) ? (int) $file->getSize() : 0;

        if (! UploadGuard::allow($file, $ownerId ?: null)) {
            return $oldFilename ?? '';
        }

        // Formats Intervention/GD cannot rasterise without destroying the asset:
        //  - SVG  : vector; decoding it to WebP would throw (and lose scalability)
        //  - GIF  : re-encoding to WebP flattens the animation to frame 1
        //  - ICO  : multi-resolution favicon container
        // These are stored byte-for-byte (SVG sanitised first — see sanitizeSvg()).
        $passthrough = self::passthroughExtension($file);

        if ($passthrough !== null) {
            $ext = $passthrough;
            $binary = self::rawBytes($file);
            if ($ext === 'svg') {
                $binary = self::sanitizeSvg($binary);
                if ($binary === '') {
                    // P6-147: unparseable SVG is rejected, not written as an empty file.
                    return $oldFilename ?? '';
                }
            }
        } else {
            // Process image with Intervention
            try {
                $image = Image::make($file);
                // P6-234: apply the EXIF Orientation BEFORE resize/encode - re-encoding drops the tag.
                $image->orientate();
                if ($size) {
                    [$w, $h] = self::parseSize($size);
                    if ($h) {
                        $image->resize($w, $h);
                    } else {
                        $image->resize($w, null, function ($c) {
                            $c->aspectRatio();
                        });
                    }
                }

                // Convert to WebP with 85% quality
                $binary = (string) $image->encode('webp', 85);
                $ext = 'webp';
            } catch (\Throwable $e) {
                // Exotic-but-valid formats the installed GD/Imagick build cannot
                // decode (AVIF on older builds, BMP variants) must not hard-fail
                // the upload — keep the original bytes instead.
                // P6-233: never store the raw bytes under the client's extension.
                // Only a sniffed, allow-listed format with no EXIF-bearing
                // metadata may fall back; everything else is refused.
                $binary = self::rawBytes($file);
                $ext = self::fallbackExtension($binary);
                if ($ext === null) {
                    Log::warning('[StorageService] Image decode failed, upload refused.', [
                        'error' => $e->getMessage(),
                    ]);

                    return $oldFilename ?? '';
                }
                Log::warning('[StorageService] Image decode failed, storing original bytes.', [
                    'error' => $e->getMessage(),
                    'ext' => $ext,
                ]);
            }
        }

        if (! empty($filename)) {
            $filenameOnly = pathinfo($filename, PATHINFO_FILENAME);
            $filename = $filenameOnly.'.'.$ext;
        } else {
            $filename = uniqid().time().'.'.$ext;
        }

        // Determine S3 credentials
        $creds = Credential::for('storage', $storeId);

        if ($creds && self::credentialsAreComplete($creds)) {
            // --- S3 upload path ---
            try {
                // Write processed image to a temp file
                $tmpPath = sys_get_temp_dir().'/'.$filename;
                file_put_contents($tmpPath, $binary);

                // Create a dynamic S3 disk configuration
                $disk = Storage::build([
                    'driver' => 's3',
                    'key' => $creds['key'],
                    'secret' => $creds['secret'],
                    'region' => $creds['region'] ?? 'us-east-1',
                    'bucket' => $creds['bucket'],
                    'endpoint' => $creds['endpoint'] ?? null,
                    'use_path_style_endpoint' => (bool) ($creds['use_path_style_endpoint'] ?? false),
                    'url' => $creds['cdn_url'] ?? null,
                    'visibility' => 'public',
                    'throw' => true,
                ]);

                // S3 object key = normalized subpath + filename
                $s3Key = self::normalizeS3Prefix($path).$filename;
                $disk->put($s3Key, file_get_contents($tmpPath), self::s3PutOptions($filename));

                // Remove old file from S3 if needed — via delete() so a file the
                // edited row shares with others is left alone (ownReferences = 1).
                if ($oldFilename) {
                    self::delete($path, $oldFilename, $storeId, 1);
                }

                @unlink($tmpPath);

                // S3 = the tenant's own BYOK bucket, not our disk — do NOT count it
                // toward the per-tenant storage quota (quota protects local storage only).
                return $filename;
            } catch (\Throwable $e) {
                Log::error('[StorageService] S3 upload failed, falling back to local.', [
                    'error' => $e->getMessage(),
                    'path' => $path,
                ]);
                // Fall through to local on error
            }
        }

        // --- Local disk fallback ---
        // Replacing an image goes through delete() so a file shared with other
        // rows (duplicated menu items, cloned stores) survives the swap.
        // ownReferences = 1: the row being edited still points at the old file.
        if ($oldFilename) {
            self::delete($path, $oldFilename, $storeId, 1);
        }

        // Per-tenant storage quota applies to LOCAL disk only (S3 is the tenant's
        // own bucket). Checked here, after the S3 path has been ruled out.
        if ($ownerId && $origBytes > 0 && ! $oldFilename && ! StorageQuota::allow($ownerId, $origBytes)) {
            return $oldFilename ?? '';
        }

        // Fresh installs / CI checkouts may not have the target directory yet
        // (public/storage subdirs are created lazily, not tracked in git).
        File::ensureDirectoryExists(public_path($path));
        file_put_contents(public_path($path).$filename, $binary);

        if ($ownerId && $origBytes > 0 && ! $oldFilename) {
            StorageQuota::add($ownerId, $origBytes);
        }

        return $filename;
    }

    /**
     * Best-effort thumbnail: encode a small WebP variant of an already-uploaded
     * image and store it under $path with the SAME filename as the full image.
     *
     * Unlike upload(), this does NOT run the UploadGuard or count toward the
     * per-tenant StorageQuota — the parent upload() call already accounted for
     * the source file. A failure here must never fail the item save, so callers
     * wrap it in a guard and this method swallows its own exceptions (returns null).
     *
     * @param  UploadedFile  $file  The original upload
     * @param  string  $path  Relative dir (e.g. "storage/menu/thumb/")
     * @param  string  $filename  Target filename incl. extension (share the full image's name)
     * @param  string  $size  Resize spec, width-only recommended (e.g. "200")
     * @param  int|null  $storeId  BYOK credential lookup
     * @return string|null The thumb filename on success, null on any failure/skip
     */
    public static function thumbnail(
        $file,
        string $path,
        string $filename,
        string $size = '200',
        ?int $storeId = null
    ): ?string {
        try {
            // Vector / animated / icon sources aren't rasterised to a thumb — the
            // full asset already scales (svg) or would lose animation (gif).
            if (self::passthroughExtension($file) !== null) {
                return null;
            }

            $image = Image::make($file);
            [$w, $h] = self::parseSize($size);
            if ($w) {
                if ($h) {
                    $image->resize($w, $h);
                } else {
                    $image->resize($w, null, function ($c) {
                        $c->aspectRatio();
                    });
                }
            }
            $binary = (string) $image->encode('webp', 82);

            // Force a .webp extension on the shared filename.
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.webp';

            $creds = Credential::for('storage', $storeId);
            if ($creds && self::credentialsAreComplete($creds)) {
                $disk = Storage::build([
                    'driver' => 's3',
                    'key' => $creds['key'],
                    'secret' => $creds['secret'],
                    'region' => $creds['region'] ?? 'us-east-1',
                    'bucket' => $creds['bucket'],
                    'endpoint' => $creds['endpoint'] ?? null,
                    'use_path_style_endpoint' => (bool) ($creds['use_path_style_endpoint'] ?? false),
                    'url' => $creds['cdn_url'] ?? null,
                    'visibility' => 'public',
                    'throw' => true,
                ]);
                $disk->put(self::normalizeS3Prefix($path).$filename, $binary, self::s3PutOptions($filename));

                return $filename;
            }

            File::ensureDirectoryExists(public_path($path));
            file_put_contents(public_path($path).$filename, $binary);

            return $filename;
        } catch (\Throwable $e) {
            Log::warning('[StorageService] Thumbnail generation failed, skipping.', [
                'error' => $e->getMessage(),
                'path' => $path,
            ]);

            return null;
        }
    }

    /** Extensions stored byte-for-byte instead of being re-encoded to WebP. */
    /**
     * Object-storage put options. An uploaded SVG is stored with an explicit
     * image/svg+xml type and a sandboxing CSP + nosniff so that opening the
     * object URL directly can never run script from the bucket/CDN origin.
     *
     * @return array<string, string>
     */
    public static function s3PutOptions(string $filename): array
    {
        $options = ['visibility' => 'public'];
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            $options['ContentType'] = 'image/svg+xml';
            $options['CacheControl'] = 'public, max-age=31536000';
            $options['Metadata'] = ['x-content-type-options' => 'nosniff'];
            // S3 cannot set response security headers; the CDN/bucket policy must add
            // nosniff + the CSP below. Forcing a download is the safe default there.
            $options['ContentDisposition'] = 'attachment';
        }

        return $options;
    }

    public const PASSTHROUGH_EXTENSIONS = ['svg', 'gif', 'ico'];

    /**
     * Return the extension this upload must be stored as verbatim, or null when
     * it should go through the normal Intervention → WebP pipeline.
     */
    protected static function passthroughExtension($file): ?string
    {
        $ext = self::originalExtension($file);
        if ($ext === null) {
            return null;
        }

        $mime = (is_object($file) && method_exists($file, 'getMimeType')) ? (string) $file->getMimeType() : '';
        if ($mime === 'image/svg+xml' || $mime === 'image/svg') {
            return 'svg';
        }

        if (! in_array($ext, self::PASSTHROUGH_EXTENSIONS, true)) {
            return null;
        }
        // P6-233: GIF/ICO pass through verbatim only when the bytes really are
        // GIF/ICO; a JPEG (with EXIF/GPS) renamed .gif goes through re-encoding.
        if ($ext === 'gif' || $ext === 'ico') {
            $head = substr(self::rawBytes($file), 0, 6);
            $ok = $ext === 'gif'
                ? str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')
                : str_starts_with($head, "\x00\x00\x01\x00");

            return $ok ? $ext : null;
        }

        return $ext;
    }

    /** P6-233: formats that may be stored undecoded (sniffed, never client-named). */
    public const FALLBACK_MIME_EXTENSIONS = [
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
    ];

    /**
     * Extension to store undecodable bytes under, or null to refuse. Sniffs the
     * real type; refuses anything outside the allow-list and any AVIF carrying
     * an Exif/XMP item (could hold GPS).
     */
    protected static function fallbackExtension(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if ($mime === 'application/octet-stream' && substr($binary, 4, 8) === 'ftypavif') {
            $mime = 'image/avif';
        }
        $ext = self::FALLBACK_MIME_EXTENSIONS[$mime] ?? null;
        if ($ext === 'avif' && (str_contains($binary, 'Exif') || str_contains($binary, '<x:xmpmeta'))) {
            return null;
        }

        return $ext;
    }

    /** Lower-cased original extension of an UploadedFile, or null for raw bytes. */
    protected static function originalExtension($file): ?string
    {
        if (! is_object($file)) {
            return null;
        }

        if (method_exists($file, 'getClientOriginalExtension')) {
            $ext = strtolower((string) $file->getClientOriginalExtension());
        } elseif (method_exists($file, 'extension')) {
            $ext = strtolower((string) $file->extension());
        } else {
            return null;
        }

        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        return $ext !== '' ? $ext : null;
    }

    /** Raw bytes of an UploadedFile (or the string itself for raw-bytes callers). */
    protected static function rawBytes($file): string
    {
        if (is_string($file)) {
            return $file;
        }

        if (is_object($file) && method_exists($file, 'getRealPath')) {
            return (string) @file_get_contents($file->getRealPath());
        }

        return '';
    }

    /**
     * Strip the active content out of an SVG before it is written to a public
     * path. An SVG served from our own origin is a stored-XSS vector otherwise:
     * <script>, event handlers, external references and embedded HTML all
     * execute in the browsing context of whoever opens the image URL.
     */
    public static function sanitizeSvg(string $svg): string
    {
        // S11: delegate to enshrined/svg-sanitize — a DOM-based allowlist
        // sanitizer that removes <script>/<style> vectors, event handlers,
        // external <use xlink:href> references and CDATA/entity-encoded payloads
        // the previous regex chain missed. On a parse failure (returns false) we
        // reject the file entirely rather than write an unverified SVG.
        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true); // strip external <use xlink:href>, url(...) refs
        // P6-37 (svg-sanitize 1.0): pin the DoS bounds explicitly rather than
        // rely on library defaults — no LIBXML_PARSEHUGE (libxml's depth/size
        // limits stay on), <use> reference fan-out and nesting stay capped.
        // 1.0 also strips the DOCTYPE before parsing, so a custom-entity SVG
        // (billion laughs, HTML5 named-ref collision) now fails to parse and
        // is rejected below.
        $sanitizer->setAllowHugeFiles(false);
        $sanitizer->useThreshold(1000);
        $sanitizer->setUseNestingLimit(15);
        $clean = $sanitizer->sanitize($svg);
        if ($clean === false) {
            return ''; // parse failure → reject rather than write unverified SVG
        }

        // The library keeps <style>; drop embedded stylesheets, a CSS
        // url()/@import fetch vector a logo never needs.
        return preg_replace('#<style\b[^>]*>.*?</style>#is', '', $clean) ?? $clean;
    }

    /**
     * Delete a file from S3 or local disk.
     *
     * @param  string  $path  Relative path e.g. "storage/store/cover/"
     * @param  int  $ownReferences  References belonging to the caller itself; see MediaReferences
     */
    public static function delete(string $path, string $filename, ?int $storeId = null, int $ownReferences = 0): bool
    {
        // storage/menu/ is a shared library — duplicated items and cloned stores
        // point several rows at one file. Removing it while anyone else still
        // references it is what leaves menus full of default.png placeholders.
        if (MediaReferences::isSharedPath($path)
            && MediaReferences::isReferenced($filename, $ownReferences)) {
            return false;
        }

        $creds = Credential::for('storage', $storeId);

        if ($creds && self::credentialsAreComplete($creds)) {
            try {
                $removed = self::deleteFromS3($creds, self::normalizeS3Prefix($path).$filename);
                if ($removed) {
                    self::logRemoval($path, $filename, $storeId, 's3');
                }

                return $removed;
            } catch (\Throwable $e) {
                Log::error('[StorageService] S3 delete failed.', ['error' => $e->getMessage()]);
            }
        }

        // Local fallback
        $fullPath = public_path($path.$filename);
        if (file_exists($fullPath) && is_file($fullPath)) {
            // Abuse Guard P5: release the tenant's storage bytes before removing.
            if ($storeId) {
                $ownerId = (int) (Store::where('id', $storeId)->value('user_id') ?: 0);
                if ($ownerId) {
                    StorageQuota::sub($ownerId, (int) @filesize($fullPath));
                }
            }

            $removed = @unlink($fullPath);
            if ($removed) {
                self::logRemoval($path, $filename, $storeId, 'local');
            }

            return $removed;
        }

        return false;
    }

    /**
     * Resolve the public URL for a stored file.
     *
     * @param  string  $path  e.g. "storage/store/cover/"
     */
    public static function url(string $path, string $filename, ?int $storeId = null): string
    {
        if (empty($filename)) {
            return '';
        }

        // H11: memoised read (url() is the only read path; a storefront render
        // calls it 100+ times). Write paths keep the live Credential::for().
        $creds = self::urlCredentials($storeId);

        if ($creds && self::credentialsAreComplete($creds)) {
            $baseUrl = rtrim($creds['cdn_url'] ?? '', '/');

            if (empty($baseUrl)) {
                // Construct from bucket + endpoint
                $endpoint = rtrim($creds['endpoint'] ?? 'https://s3.amazonaws.com', '/');
                $bucket = $creds['bucket'] ?? '';
                $baseUrl = $endpoint.'/'.$bucket;
            }

            $s3Key = self::normalizeS3Prefix($path).$filename;

            return $baseUrl.'/'.ltrim($s3Key, '/');
        }

        // Local fallback: the path already starts with "storage/"
        return asset($path.$filename);
    }

    /**
     * Whether a stored file exists for the local public disk. Remote (S3/CDN) stores are treated as
     * existing so rendering a storefront never makes a network call. Uses live credentials: this is a
     * read path that can run after a write in the same request.
     *
     * @param  string  $path  e.g. "storage/menu/"
     */
    public static function exists(string $path, string $filename, ?int $storeId = null): bool
    {
        if ($filename === '') {
            return false;
        }

        $creds = Credential::for('storage', $storeId);
        if ($creds && self::credentialsAreComplete($creds)) {
            return true;
        }

        return is_file(public_path($path.$filename));
    }

    /**
     * URL for a small cached thumbnail of a stored image, generated on first use.
     *
     * Used for tiny display slots (e.g. the 44px marquee logos) where the source
     * upload can be 100-200 KB. The thumb is a height-capped WebP written once to
     * a `thumbs/` subdir next to the original; later requests just file_exists()
     * and return the URL. Any failure (S3-hosted, unreadable, Intervention error)
     * falls back to the full-size url() so the image never breaks.
     */
    public static function thumbUrl(string $path, string $filename, ?int $storeId = null, int $height = 88): string
    {
        if (empty($filename)) {
            return '';
        }

        // S3-hosted media: skip thumbnailing (no local file to cache), serve original.
        $creds = self::urlCredentials($storeId);
        if ($creds && self::credentialsAreComplete($creds)) {
            return self::url($path, $filename, $storeId);
        }

        try {
            $src = public_path($path.$filename);
            if (! is_file($src)) {
                return self::url($path, $filename, $storeId);
            }

            $thumbName = pathinfo($filename, PATHINFO_FILENAME).'-h'.$height.'.webp';
            $thumbRel = rtrim($path, '/').'/thumbs/';
            $thumbAbs = public_path($thumbRel.$thumbName);

            // Regenerate if missing or stale (source newer than thumb).
            if (! is_file($thumbAbs) || filemtime($thumbAbs) < filemtime($src)) {
                File::ensureDirectoryExists(dirname($thumbAbs));
                $img = Image::make($src);
                if ($img->height() > $height) {
                    $img->resize(null, $height, function ($c) {
                        $c->aspectRatio();
                        $c->upsize();
                    });
                }
                $img->encode('webp', 80)->save($thumbAbs);
            }

            return asset($thumbRel.$thumbName);
        } catch (\Throwable $e) {
            Log::warning('[StorageService] thumb generation failed', [
                'error' => $e->getMessage(),
                'filename' => $filename,
            ]);

            return self::url($path, $filename, $storeId);
        }
    }

    /**
     * P6-308: width-capped WebP variants (e.g. 320w / 640w) of a stored image for card and cart-thumb slots, made on
     * first use beside the original (`<name>-w<width>.webp`, like thumbUrl). Returns ['src' => largest variant URL,
     * 'srcset' => 'url 320w, url 640w'], or null when there is nothing to scale (S3-hosted, missing file, already
     * narrower than the smallest width, any failure) so the caller keeps serving the original.
     *
     * @param  list<int>  $widths  ascending
     * @return array{src: string, srcset: string}|null
     */
    public static function widthVariants(string $path, string $filename, ?int $storeId = null, array $widths = [320, 640]): ?array
    {
        if ($filename === '' || $widths === [] || ! config('media.card_variants', true)) {
            return null;
        }
        $creds = self::urlCredentials($storeId);
        if ($creds && self::credentialsAreComplete($creds)) {
            return null;
        }

        try {
            $src = public_path($path.$filename);
            if (! is_file($src)) {
                return null;
            }
            $size = @getimagesize($src);
            if (! $size || $size[0] <= $widths[0]) {
                return null;
            }

            $rel = rtrim($path, '/').'/thumbs/';
            $parts = [];
            foreach ($widths as $w) {
                if ($w >= $size[0] && $parts !== []) {
                    break; // never upscale: the original serves as the last step
                }
                $name = pathinfo($filename, PATHINFO_FILENAME).'-w'.$w.'.webp';
                $abs = public_path($rel.$name);
                if (! is_file($abs) || filemtime($abs) < filemtime($src)) {
                    File::ensureDirectoryExists(dirname($abs));
                    $img = Image::make($src);
                    $img->resize(min($w, $size[0]), null, function ($c) {
                        $c->aspectRatio();
                        $c->upsize();
                    });
                    $img->encode('webp', 78)->save($abs);
                }
                $parts[] = [asset($rel.$name), $w];
            }

            return [
                'src' => end($parts)[0],
                'srcset' => implode(', ', array_map(fn ($p) => $p[0].' '.$p[1].'w', $parts)),
            ];
        } catch (\Throwable $e) {
            Log::warning('[StorageService] width variants failed', ['error' => $e->getMessage(), 'filename' => $filename]);

            return null;
        }
    }

    /**
     * Convert an existing image to WebP, delete the old file, and return the new filename.
     */
    public static function convertToWebp(string $path, string $filename, ?int $storeId = null): ?string
    {
        if (empty($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'webp') {
            return $filename;
        }

        $creds = Credential::for('storage', $storeId);
        $content = null;
        $isS3 = false;
        $disk = null;
        $s3Key = null;

        if ($creds && self::credentialsAreComplete($creds)) {
            $isS3 = true;
            try {
                $disk = Storage::build([
                    'driver' => 's3',
                    'key' => $creds['key'],
                    'secret' => $creds['secret'],
                    'region' => $creds['region'] ?? 'us-east-1',
                    'bucket' => $creds['bucket'],
                    'endpoint' => $creds['endpoint'] ?? null,
                    'use_path_style_endpoint' => (bool) ($creds['use_path_style_endpoint'] ?? false),
                    'url' => $creds['cdn_url'] ?? null,
                    'throw' => true,
                ]);
                $s3Key = self::normalizeS3Prefix($path).$filename;
                if ($disk->exists($s3Key)) {
                    $content = $disk->get($s3Key);
                }
            } catch (\Throwable $e) {
                Log::error('[StorageService] Failed to read from S3 for WebP conversion', [
                    'error' => $e->getMessage(),
                    'filename' => $filename,
                ]);
            }
        }

        if (! $content) {
            $fullPath = public_path($path.$filename);
            if (file_exists($fullPath) && is_file($fullPath)) {
                $content = file_get_contents($fullPath);
            }
        }

        if (! $content) {
            return null;
        }

        try {
            $image = Image::make($content);
            $image->encode('webp', 85);

            $newFilename = pathinfo($filename, PATHINFO_FILENAME).'.webp';

            if ($isS3 && $disk) {
                $tmpPath = sys_get_temp_dir().'/'.$newFilename;
                $image->save($tmpPath);
                $newS3Key = self::normalizeS3Prefix($path).$newFilename;
                $disk->put($newS3Key, file_get_contents($tmpPath), 'public');
                @unlink($tmpPath);

                // ownReferences = 1: the row being converted still names the source
                // file. Other rows sharing it keep theirs until they convert too.
                if ($newFilename !== $filename && $disk->exists($newS3Key)) {
                    self::delete($path, $filename, $storeId, 1);
                }
            } else {
                $newFullPath = public_path($path.$newFilename);
                File::ensureDirectoryExists(dirname($newFullPath));
                $image->save($newFullPath);
                if ($newFilename !== $filename && file_exists($newFullPath)) {
                    self::delete($path, $filename, $storeId, 1);
                }
            }

            return $newFilename;
        } catch (\Throwable $e) {
            Log::error('[StorageService] WebP conversion failed', [
                'error' => $e->getMessage(),
                'filename' => $filename,
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Record every physical removal in the activity log.
     *
     * When an image goes missing there is otherwise nothing to look at: the row
     * still names the file, and the delete that took it left no trace. This
     * gives the audit an answer to "who removed it, and when".
     */
    private static function logRemoval(string $path, string $filename, ?int $storeId, string $disk): void
    {
        log_activity('media.file_deleted', null, [
            'path' => $path,
            'filename' => $filename,
            'disk' => $disk,
        ], $storeId);
    }

    private static function credentialsAreComplete(array $creds): bool
    {
        return ! empty($creds['key'])
            && ! empty($creds['secret'])
            && ! empty($creds['bucket']);
    }

    private static function normalizeS3Prefix(string $path): string
    {
        // Strip leading "storage/" to avoid nesting under a literal "storage" prefix in the bucket
        $normalized = preg_replace('#^/?storage/#', '', $path);

        return trim($normalized, '/').'/';
    }

    private static function parseSize(string $size): array
    {
        $parts = explode('x', strtolower($size));
        $w = (int) ($parts[0] ?? 0);
        $h = isset($parts[1]) && $parts[1] !== '' ? (int) $parts[1] : null;

        return [$w, $h];
    }

    private static function deleteFromS3(array $creds, string $key): bool
    {
        $disk = Storage::build([
            'driver' => 's3',
            'key' => $creds['key'],
            'secret' => $creds['secret'],
            'region' => $creds['region'] ?? 'us-east-1',
            'bucket' => $creds['bucket'],
            'endpoint' => $creds['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($creds['use_path_style_endpoint'] ?? false),
            'url' => $creds['cdn_url'] ?? null,
            'throw' => false,
        ]);

        return $disk->delete($key);
    }
}
