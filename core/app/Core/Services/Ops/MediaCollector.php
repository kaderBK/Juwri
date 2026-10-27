<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Ops;

/**
 * Copies a store's real media files into the export ZIP (WI-D1), so the archive
 * is self-contained instead of shipping dead platform URLs.
 *
 * - Files are stream-copied (never read whole into memory).
 * - Deduped by real source path (the shared-filename caveat: one physical file
 *   may back many rows — copy once, point every reference at the same in-zip path).
 * - Bounded by a total byte cap; once hit, further files are counted as omitted
 *   and `truncated` is set, so the manifest can tell the owner what was left out.
 * - Writes `media_index.json` mapping `<category>/<filename>` → in-zip path so the
 *   import side (and a human) can wire rows back to files.
 */
class MediaCollector
{
    private int $bytes = 0;
    private int $copied = 0;
    private int $omitted = 0;
    private bool $truncated = false;
    private array $index = [];
    /** @var array<string,string> realpath => in-zip relative path */
    private array $seen = [];

    public function __construct(private string $work, private int $capBytes)
    {
    }

    /**
     * Resolve a stored filename to a real local file under one of the given base
     * folders (e.g. 'menu', 'restaurant') and copy it in. A filename may already
     * carry a sub-path (e.g. "639/x.jpg"); that is preserved under the category.
     */
    public function add(string $category, ?string $filename, array $bases, bool $privateDisk = false): void
    {
        $filename = trim((string) $filename);
        if ($filename === '' || str_starts_with($filename, 'http')) {
            return; // empty or an absolute URL we can't copy from disk
        }

        $abs = $this->resolve($filename, $bases, $privateDisk);
        if ($abs === null) {
            return; // not present on this disk (e.g. S3-hosted) — skip quietly
        }

        $key = $category . '/' . $filename;
        $real = realpath($abs);
        if ($real !== false && isset($this->seen[$real])) {
            $this->index[$key] = $this->seen[$real]; // dedupe — reuse the copy
            return;
        }

        $size = @filesize($abs) ?: 0;
        if ($this->bytes + $size > $this->capBytes) {
            $this->truncated = true;
            $this->omitted++;

            return;
        }

        $destRel = 'media/' . $category . '/' . ltrim($filename, '/');
        $destAbs = $this->work . '/' . $destRel;
        @mkdir(dirname($destAbs), 0755, true);
        if (! $this->streamCopy($abs, $destAbs)) {
            return;
        }

        $this->bytes += $size;
        $this->copied++;
        if ($real !== false) {
            $this->seen[$real] = $destRel;
        }
        $this->index[$key] = $destRel;
    }

    /** Write media_index.json and return the manifest media block. */
    public function finish(): array
    {
        file_put_contents(
            $this->work . '/media_index.json',
            json_encode($this->index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        return [
            'included'   => true,
            'files'      => $this->copied,
            'bytes'      => $this->bytes,
            'cap_mb'     => (int) round($this->capBytes / 1048576),
            'truncated'  => $this->truncated,
            'omitted'    => $this->omitted,
        ];
    }

    /** First existing physical path for a filename across candidate base folders. */
    private function resolve(string $filename, array $bases, bool $privateDisk = false): ?string
    {
        foreach ($bases as $base) {
            $base = trim($base, '/');
            $candidates = $privateDisk
                ? [storage_path('app/' . $base . '/' . $filename)]
                : [
                    storage_path('app/public/' . $base . '/' . $filename),
                    public_path('storage/' . $base . '/' . $filename),
                    public_path($base . '/' . $filename),
                ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function streamCopy(string $src, string $dest): bool
    {
        $in = @fopen($src, 'rb');
        if (! $in) {
            return false;
        }
        $out = @fopen($dest, 'wb');
        if (! $out) {
            fclose($in);

            return false;
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return true;
    }
}
