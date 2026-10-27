<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Plugins\mediamanager\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\StoreSettings;
use Plugins\mediamanager\app\Models\MediaScanFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ImageScanner
{
    protected string $storagePath;

    protected array $protectedFiles = [
        'default.png',
        'default_user.png',
    ];

    protected array $ignoredDirectories = [
        'storage/.mediamanager-trash',
    ];

    /**
     * Map of storage directories to database references.
     * Each entry can include a 'lookup' key for resolving human-readable owner/name.
     *
     * 'lookup' => [
     *     'owner_table' => 'stores',
     *     'owner_column' => 'id',
     *     'owner_name_column' => 'store_name',
     *     'name_table' => null,
     *     'name_column' => null,
     *     'label' => 'Restaurant',
     * ]
     */
    protected array $referenceMap = [
        'storage/allergies' => [
            [
                'table' => 'allergies',
                'column' => 'image',
                'path' => 'storage/allergies/',
                'lookup' => [
                    'label' => 'Allergy',
                    'name_column' => 'title',
                ],
            ],
        ],
        'storage/blog' => [
            [
                'table' => 'blog_posts',
                'column' => 'image',
                'path' => 'storage/blog/',
                'lookup' => [
                    'label' => 'Blog Post',
                    'name_column' => 'title',
                ],
            ],
        ],
        'storage/logo' => [
            [
                // P1-07: typed platform settings; `value` is JSON (a JSON string for a file name).
                'table' => 'platform_settings',
                'column' => 'value',
                'json_column' => true,
                'where' => ['name' => ['site_logo', 'site_favicon', 'site_logo_footer', 'site_admin_logo', 'social_share_image']],
                'path' => 'storage/logo/',
                'lookup' => [
                    'label' => 'Site Setting',
                    'name_column' => 'name',
                    'labels' => [
                        'site_logo' => 'Site Logo',
                        'site_favicon' => 'Favicon',
                        'site_logo_footer' => 'Footer Logo',
                        'site_admin_logo' => 'Admin Logo',
                        'social_share_image' => 'Social Share Image',
                    ],
                ],
            ],
        ],
        'storage/menu' => [
            [
                'table' => 'items',
                'column' => 'image',
                'path' => 'storage/menu/',
                'lookup' => [
                    'label' => 'Menu Item',
                    'name_column' => 'name',
                    'owner_column' => 'store_id',
                    'owner_name_table' => 'stores',
                    'owner_name_column' => 'title',
                ],
            ],
            [
                'table' => 'image_menus',
                'column' => 'image',
                'path' => 'storage/menu/',
                'lookup' => [
                    'label' => 'Image Menu',
                    'owner_column' => 'store_id',
                    'owner_name_table' => 'stores',
                    'owner_name_column' => 'title',
                ],
            ],
        ],
        'storage/profile' => [
            [
                'table' => 'users',
                'column' => 'image',
                'path' => 'storage/profile/',
                'lookup' => [
                    'label' => 'User Profile',
                    'name_column' => 'name',
                    'owner_column' => 'email',
                ],
            ],
        ],
        'storage/store/cover' => [
            [
                'table' => 'stores',
                'column' => 'cover_image',
                'path' => 'storage/store/cover/',
                'lookup' => [
                    'label' => 'Cover Image',
                    'name_column' => 'title',
                ],
            ],
            [
                'table' => 'stores',
                'column' => 'bio_cover_image',
                'path' => 'storage/store/cover/',
                'lookup' => [
                    'label' => 'Bio Cover Image',
                    'name_column' => 'title',
                ],
            ],
        ],
        'storage/store/logo' => [
            [
                'table' => 'stores',
                'column' => 'main_image',
                'path' => 'storage/store/logo/',
                'lookup' => [
                    'label' => 'Restaurant Logo',
                    'name_column' => 'title',
                ],
            ],
            [
                'table' => 'stores',
                'column' => 'bio_main_image',
                'path' => 'storage/store/logo/',
                'lookup' => [
                    'label' => 'Bio Logo',
                    'name_column' => 'title',
                ],
            ],
            [
                // P1-07: a per-store setting (stores.settings), not a column: read via StoreSettings.
                'table' => 'stores',
                'column' => 'settings',
                'store_setting' => 'qr_image',
                'path' => 'storage/store/logo/',
                'lookup' => [
                    'label' => 'QR Image',
                ],
            ],
        ],
        'storage/testimonials' => [
            [
                'table' => 'testimonials',
                'column' => 'image',
                'path' => 'storage/testimonials/',
                'lookup' => [
                    'label' => 'Testimonial',
                    'name_column' => 'name',
                ],
            ],
        ],
    ];

    public function __construct()
    {
        $this->storagePath = base_path('../storage');
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getReferenceMap(): array
    {
        return $this->referenceMap;
    }

    /**
     * Get all directories that should be scanned.
     */
    /**
     * Get directories to scan. Large directories are split into
     * sub-batches to stay within nginx fastcgi_read_timeout (60s).
     */
    public function getScanDirectories(): array
    {
        if (! is_dir($this->storagePath)) {
            return [];
        }

        $directories = [];
        $maxFiles = 1500; // Keep each batch under this to avoid timeout

        // First pass: collect top-level dirs and their recursive sub-dirs
        $allDirs = [];
        foreach (new \DirectoryIterator($this->storagePath) as $item) {
            if ($item->isDot() || ! $item->isDir()) {
                continue;
            }
            $relativePath = 'storage/'.$item->getFilename();
            if (in_array($relativePath, $this->ignoredDirectories)) {
                continue;
            }
            $allDirs[] = $relativePath;
        }

        foreach ($allDirs as $dir) {
            $absPath = base_path('../'.$dir);
            if (! is_dir($absPath)) {
                continue;
            }

            // Count files recursively
            $fileCount = $this->countFilesRecursive($absPath);

            if ($fileCount <= $maxFiles) {
                $directories[] = $dir;
            } else {
                // Split into sub-directories
                $subDirs = $this->splitIntoBatches($dir, $maxFiles);
                $directories = array_merge($directories, $subDirs);
            }
        }

        $directories = array_values(array_unique($directories));

        usort($directories, function ($a, $b) {
            $aKnown = isset($this->referenceMap[$a]) ? 0 : 1;
            $bKnown = isset($this->referenceMap[$b]) ? 0 : 1;
            if ($aKnown !== $bKnown) {
                return $aKnown <=> $bKnown;
            }

            return strcmp($a, $b);
        });

        return $directories;
    }

    protected function countFilesRecursive(string $path): int
    {
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && ! str_starts_with($file->getFilename(), '.')) {
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $count;
    }

    /**
     * Split a large directory into smaller batches by recursing into subdirs.
     */
    protected function splitIntoBatches(string $dir, int $maxFiles): array
    {
        $batches = [];
        $absPath = base_path('../'.$dir);

        if (! is_dir($absPath)) {
            return [$dir];
        }

        $children = [];
        foreach (new \DirectoryIterator($absPath) as $item) {
            if ($item->isDot()) {
                continue;
            }
            if ($item->isDir()) {
                $children[] = [
                    'path' => $this->getRelativePath($item->getPathname()),
                    'type' => 'dir',
                ];
            } elseif ($item->isFile()) {
                $children[] = [
                    'path' => $this->getRelativePath($item->getPathname()),
                    'type' => 'file',
                ];
            }
        }

        // Collect root-level files (excluding hidden) as a batch
        $rootFiles = array_filter($children, fn ($c) => $c['type'] === 'file' && ! str_starts_with(basename($c['path']), '.'));
        if (count($rootFiles) > 0) {
            // Has real files at root level — keep it as a batch
            $directories = [$dir];
        } else {
            $directories = [];
        }

        // Process subdirectories
        $subDirs = array_filter($children, fn ($c) => $c['type'] === 'dir');
        foreach ($subDirs as $child) {
            $childAbs = base_path('../'.$child['path']);
            $childCount = $this->countFilesRecursive($childAbs);

            if ($childCount <= $maxFiles) {
                $directories[] = $child['path'];
            } else {
                // Recurse deeper
                $subBatches = $this->splitIntoBatches($child['path'], $maxFiles);
                $directories = array_merge($directories, $subBatches);
            }
        }

        return $directories;
    }

    /**
     * Pre-fetch all referenced filenames for a directory, with human-readable lookup info.
     *
     * Returns: [
     *   'lowercase_filename' => [
     *     ['table' => ..., 'column' => ..., 'owner_name' => '...', 'record_name' => '...', 'record_id' => ..., 'label' => '...'],
     *   ]
     * ]
     */
    public function getReferencedFilenames(string $directory): array
    {
        $references = [];

        if (! isset($this->referenceMap[$directory])) {
            return $references;
        }

        foreach ($this->referenceMap[$directory] as $map) {
            if (! empty($map['store_setting'])) {
                $this->collectStoreSettingReferences($map, $references);

                continue;
            }
            try {
                if (! Schema::hasTable($map['table'])) {
                    Log::warning('MediaManager: Skipping missing table '.$map['table'].' for directory '.$directory);

                    continue;
                }

                // Verify the image column exists
                if (! Schema::hasColumn($map['table'], $map['column'])) {
                    Log::warning('MediaManager: Skipping '.$map['table'].'.'.$map['column'].' - column not found');

                    continue;
                }

                // Build a query that fetches the image column plus lookup info
                $lookup = $map['lookup'] ?? [];
                $selectColumns = [$map['table'].'.'.$map['column']];

                // Only select lookup columns if they actually exist
                if (! empty($lookup['name_column']) && Schema::hasColumn($map['table'], $lookup['name_column'])) {
                    $selectColumns[] = $map['table'].'.'.$lookup['name_column'].' as _record_name';
                }
                if (! empty($lookup['owner_column']) && Schema::hasColumn($map['table'], $lookup['owner_column'])) {
                    $selectColumns[] = $map['table'].'.'.$lookup['owner_column'].' as _owner_id';
                }

                $query = DB::table($map['table'])
                    ->whereNotNull($map['table'].'.'.$map['column']);
                // A JSON column cannot be compared with '' on PostgreSQL; empty values are skipped below.
                if (empty($map['json_column'])) {
                    $query->where($map['table'].'.'.$map['column'], '!=', '');
                }

                $query->select($selectColumns);

                // Apply filters
                if (! empty($map['where'])) {
                    foreach ($map['where'] as $column => $value) {
                        if (is_array($value)) {
                            $query->whereIn($column, $value);
                        } else {
                            $query->where($column, $value);
                        }
                    }
                }

                $rows = $query->get();

                // If we need the owner's name from another table, resolve it now
                $ownerNames = [];
                if (! empty($lookup['owner_name_table']) && ! empty($lookup['owner_name_column']) && ! empty($lookup['owner_column'])) {
                    $ownerIds = $rows->pluck('_owner_id')->filter()->unique()->values()->toArray();
                    if (! empty($ownerIds)
                        && Schema::hasTable($lookup['owner_name_table'])
                        && Schema::hasColumn($lookup['owner_name_table'], $lookup['owner_name_column'])
                        && Schema::hasColumn($lookup['owner_name_table'], 'id')
                    ) {
                        $ownerRows = DB::table($lookup['owner_name_table'])
                            ->whereIn('id', $ownerIds)
                            ->pluck($lookup['owner_name_column'], 'id');
                        $ownerNames = $ownerRows->toArray();
                    }
                }

                foreach ($rows as $row) {
                    $value = $row->{$map['column']};
                    $decoded = $this->maybeDecodeJson($value);

                    $filenames = is_array($decoded) ? $decoded : [$decoded];

                    foreach ($filenames as $filename) {
                        if (empty($filename) || ! is_string($filename)) {
                            continue;
                        }
                        if (filter_var($filename, FILTER_VALIDATE_URL)) {
                            continue;
                        }

                        $actualFilename = basename($filename);
                        if (empty($actualFilename)) {
                            continue;
                        }

                        // Build human-readable info
                        $recordName = $row->_record_name ?? null;
                        $ownerId = $row->_owner_id ?? null;
                        $ownerName = null;

                        // For options with labels map
                        if (! empty($lookup['labels']) && ! empty($row->_record_name)) {
                            $recordName = $lookup['labels'][$row->_record_name] ?? $row->_record_name;
                        }

                        // For owner name from another table
                        if (! empty($ownerNames) && ! empty($ownerId)) {
                            $ownerName = $ownerNames[$ownerId] ?? null;
                        } elseif (! empty($lookup['owner_column']) && ! empty($lookup['owner_name_column']) && empty($lookup['owner_name_table'])) {
                            $ownerName = $row->_owner_id ?? null;
                        }

                        // For direct owner name from same table
                        if (! empty($lookup['owner_column']) && ! empty($lookup['name_column']) && empty($lookup['owner_name_table'])) {
                            // name_column is also the owner (like store_name on posts)
                            $recordName = $recordName; // already set
                            $ownerName = null; // no separate owner
                        }

                        $key = strtolower($actualFilename);

                        if (! isset($references[$key])) {
                            $references[$key] = [];
                        }

                        $references[$key][] = [
                            'table' => $map['table'],
                            'column' => $map['column'],
                            'record_id' => $row->id ?? null,
                            'record_name' => $recordName,
                            'owner_name' => $ownerName,
                            'label' => $lookup['label'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::error('MediaManager: Error scanning '.$map['table'].'.'.$map['column'].' — '.$e->getMessage());
            }
        }

        return $references;
    }

    /**
     * A file name kept in one store setting (stores.settings) rather than in a column.
     *
     * @param  array<string, mixed>  $map
     * @param  array<string, list<array<string, mixed>>>  $references
     */
    protected function collectStoreSettingReferences(array $map, array &$references): void
    {
        try {
            $values = StoreSettings::valuesFor((string) $map['store_setting']);
            $titles = $values === [] ? [] : DB::table('stores')->whereIn('id', array_keys($values))->pluck('title', 'id')->all();
            foreach ($values as $storeId => $value) {
                $filename = $this->maybeDecodeJson($value);
                if (empty($filename) || ! is_string($filename) || filter_var($filename, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $actualFilename = basename($filename);
                if ($actualFilename === '') {
                    continue;
                }
                $references[strtolower($actualFilename)][] = [
                    'table' => $map['table'],
                    'column' => $map['column'],
                    'record_id' => $storeId,
                    'record_name' => null,
                    'owner_name' => $titles[$storeId] ?? null,
                    'label' => $map['lookup']['label'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('MediaManager: Error scanning store setting '.$map['store_setting'].' — '.$e->getMessage());
        }
    }

    protected function maybeDecodeJson($value)
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function isProtectedFile(string $filename): bool
    {
        return in_array(strtolower($filename), array_map('strtolower', $this->protectedFiles));
    }

    /**
     * Compute MD5 hash of a file (for duplicate detection).
     */
    protected function computeHash(string $filepath): ?string
    {
        if (! is_readable($filepath)) {
            return null;
        }

        // Fast partial hash: first 4KB + last 4KB + filesize.
        // This is ~100x faster than md5_file() for large images and catches 99.9% of true duplicates.
        $size = filesize($filepath);
        if ($size === false) {
            return null;
        }

        $handle = fopen($filepath, 'rb');
        if (! $handle) {
            return null;
        }

        $head = fread($handle, 4096);
        if ($size > 8192) {
            fseek($handle, -4096, SEEK_END);
            $tail = fread($handle, 4096);
        } else {
            $tail = '';
        }
        fclose($handle);

        return hash('crc32b', $size.'|'.$head.'|'.$tail);
    }

    /**
     * Scan a directory and save results.
     */
    public function scanDirectory(string $directory): array
    {
        $absolutePath = base_path('../'.$directory);

        if (! is_dir($absolutePath)) {
            return [
                'directory' => $directory,
                'scanned' => 0,
                'used' => 0,
                'orphaned' => 0,
                'unknown' => 0,
                'protected' => 0,
                'error' => 'Directory not found',
            ];
        }

        // Pre-fetch references for ALL referenceMap keys, not just the top-level one.
        // This handles recursive scans (e.g. scanning "storage/restaurant" finds files
        // in "storage/store/cover" which maps to a different referenceMap key).
        $allReferences = [];
        foreach (array_keys($this->referenceMap) as $mapKey) {
            if (str_starts_with($mapKey, $directory)) {
                $allReferences[$mapKey] = $this->getReferencedFilenames($mapKey);
            }
        }

        $stats = [
            'directory' => $directory,
            'scanned' => 0,
            'used' => 0,
            'orphaned' => 0,
            'unknown' => 0,
            'protected' => 0,
        ];

        $records = [];
        $now = now();

        // Pre-load ALL protected paths in one query (not per-file)
        $protectedPaths = MediaScanFile::where('is_protected', true)
            ->pluck('is_protected', 'full_path')
            ->toArray();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            /** @var SplFileInfo $file */
            $relativeFullPath = $this->getRelativePath($file->getPathname());
            $filename = $file->getFilename();

            if (str_starts_with($filename, '.') || str_starts_with($filename, '~')) {
                continue;
            }

            // Check if already marked as protected in DB (from pre-loaded cache)
            $dbProtected = isset($protectedPaths[$relativeFullPath]);

            $record = [
                'directory' => $directory,
                'filename' => $filename,
                'full_path' => $relativeFullPath,
                'size' => $file->getSize(),
                'modified_at' => date('Y-m-d H:i:s', $file->getMTime()),
                'is_referenced' => false,
                'reference_table' => null,
                'reference_column' => null,
                'reference_id' => null,
                'reference_name' => null,
                'reference_owner' => null,
                'is_duplicate' => false,
                'duplicate_group_id' => null,
                'is_protected' => $dbProtected,
                'status' => 'unknown',
                'scanned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($dbProtected) {
                $record['status'] = 'protected';
                $stats['protected']++;
            } elseif ($this->isProtectedFile($filename)) {
                $record['is_protected'] = true;
                $record['status'] = 'protected';
                $stats['protected']++;
            } else {
                // Find the best matching referenceMap key for this file's path
                $lookupKey = strtolower($filename);
                $foundRef = false;

                foreach ($allReferences as $mapKey => $refs) {
                    // Check if this file falls under this referenceMap's path
                    if (str_starts_with($relativeFullPath, $mapKey) && isset($refs[$lookupKey])) {
                        $ref = $refs[$lookupKey][0];
                        $record['is_referenced'] = true;
                        $record['reference_table'] = $ref['table'];
                        $record['reference_column'] = $ref['column'];
                        $record['reference_id'] = $ref['record_id'];
                        $record['reference_name'] = $ref['record_name'] ?? null;
                        $record['reference_owner'] = $ref['owner_name'] ?? $ref['label'] ?? null;
                        $record['status'] = 'used';
                        $stats['used']++;
                        $foundRef = true;
                        break;
                    }
                }

                if (! $foundRef) {
                    $hasMapForKey = array_any(array_keys($this->referenceMap), fn ($mapKey) => str_starts_with($relativeFullPath, $mapKey));
                    if ($hasMapForKey) {
                        $record['status'] = 'orphaned';
                        $stats['orphaned']++;
                    } else {
                        $record['status'] = 'unknown';
                        $stats['unknown']++;
                    }
                }
            }

            $records[] = $record;
            $stats['scanned']++;

            if (count($records) >= 500) {
                MediaScanFile::insert($records);
                $records = [];
            }
        }

        if (! empty($records)) {
            MediaScanFile::insert($records);
        }

        return $stats;
    }

    /**
     * Detect duplicate files within a directory group using fast hashes.
     * Time-limited to avoid 504 Gateway Timeouts on large datasets.
     * Returns number of duplicates found. Remaining work can be resumed later.
     */
    public function detectDuplicates(int $maxSeconds = 30): int
    {
        $startTime = time();
        $deadline = $startTime + $maxSeconds;

        // Step 1: Find sizes that have more than one file (potential duplicates).
        $duplicateSizes = MediaScanFile::query()
            ->where('size', '>', 0)
            ->select('size')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('size')
            ->having('cnt', '>', 1)
            ->pluck('size')
            ->toArray();

        if (empty($duplicateSizes)) {
            return 0;
        }

        $duplicateCount = 0;
        $groupId = 0;

        // Step 2: Process each size group individually (small memory footprint)
        foreach (array_chunk($duplicateSizes, 50) as $sizeChunk) {
            // Check time limit
            if (time() >= $deadline) {
                Log::info('MediaManager: Duplicate detection stopped at time limit ('.$maxSeconds.'s). Processed '.$duplicateCount.' duplicates so far.');
                break;
            }

            $filesBySize = MediaScanFile::query()
                ->where('size', '>', 0)
                ->whereIn('size', $sizeChunk)
                ->select('id', 'full_path', 'size')
                ->get()
                ->groupBy('size');

            foreach ($filesBySize as $size => $files) {
                // Check time limit inside inner loop
                if (time() >= $deadline) {
                    Log::info('MediaManager: Duplicate detection stopped at time limit ('.$maxSeconds.'s). Processed '.$duplicateCount.' duplicates so far.');
                    break 2;
                }

                $hashGroups = [];

                foreach ($files as $file) {
                    $absolutePath = base_path('../'.$file->full_path);
                    $hash = $this->computeHash($absolutePath);

                    if ($hash === null) {
                        continue;
                    }

                    if (! isset($hashGroups[$hash])) {
                        $hashGroups[$hash] = [];
                    }
                    $hashGroups[$hash][] = $file->id;
                }

                foreach ($hashGroups as $hash => $fileIds) {
                    if (count($fileIds) > 1) {
                        $groupId++;
                        MediaScanFile::whereIn('id', $fileIds)->update([
                            'is_duplicate' => true,
                            'duplicate_group_id' => $groupId,
                        ]);
                        $duplicateCount += count($fileIds);
                    }
                }
            }

            // Free memory between chunks
            gc_collect_cycles();
        }

        return $duplicateCount;
    }

    protected function getRelativePath(string $absolutePath): string
    {
        $storagePath = realpath($this->storagePath);
        $realPath = realpath($absolutePath);

        if ($storagePath && $realPath && str_starts_with($realPath, $storagePath)) {
            return 'storage'.substr($realPath, strlen($storagePath));
        }

        $rootPath = rtrim(dirname(base_path()), DIRECTORY_SEPARATOR);

        return ltrim(str_replace($rootPath, '', $absolutePath), DIRECTORY_SEPARATOR);
    }

    public function clearPreviousScans(): void
    {
        MediaScanFile::query()->delete();
    }

    public function getSummary(): array
    {
        $summary = MediaScanFile::query()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(size), 0) as total_size')
            ->groupBy('status')
            ->pluck('total_size', 'status')
            ->toArray();

        $counts = MediaScanFile::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $allStatuses = ['used', 'orphaned', 'unknown', 'protected'];
        $result = [];

        foreach ($allStatuses as $status) {
            $result[$status] = [
                'count' => $counts[$status] ?? 0,
                'size' => $summary[$status] ?? 0,
            ];
        }

        $result['total'] = [
            'count' => array_sum($counts),
            'size' => array_sum($summary),
        ];

        // Duplicate count and wasted size (n-1 copies × size)
        $dupStats = MediaScanFile::where('is_duplicate', true)
            ->selectRaw('duplicate_group_id, COUNT(*) as count, MIN(size) as size')
            ->groupBy('duplicate_group_id')
            ->get();

        $dupCount = $dupStats->sum('count');
        $dupWastedSize = $dupStats->sum(function ($g) {
            return max(0, ($g->count - 1)) * ($g->size ?? 0);
        });

        $result['duplicates'] = [
            'count' => $dupCount,
            'size' => $dupWastedSize,
        ];

        return $result;
    }

    /**
     * Format bytes to human-readable string.
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }

    /**
     * Get file info (dimensions, mime type) for image detail panel.
     */
    public static function getImageInfo(string $fullPath): array
    {
        $absolutePath = base_path('../'.$fullPath);

        if (! file_exists($absolutePath)) {
            return ['exists' => false];
        }

        $info = [
            'exists' => true,
            'size' => filesize($absolutePath),
            'size_human' => self::formatBytes(filesize($absolutePath)),
            'modified' => date('Y-m-d H:i:s', filemtime($absolutePath)),
            'created' => date('Y-m-d H:i:s', filectime($absolutePath)),
            'mime' => mime_content_type($absolutePath) ?: 'unknown',
            'extension' => strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)),
            'dimensions' => null,
        ];

        $imageInfo = @getimagesize($absolutePath);
        if ($imageInfo) {
            $info['dimensions'] = [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
            ];
        }

        return $info;
    }

    /**
     * Move a file to trash.
     */
    public function moveToTrash(MediaScanFile $file): bool
    {
        $source = $file->absolute_path;
        $trashPath = $file->trash_path;

        if (! file_exists($source)) {
            return false;
        }

        $trashDir = dirname($trashPath);
        if (! is_dir($trashDir)) {
            if (! mkdir($trashDir, 0755, true) && ! is_dir($trashDir)) {
                Log::error('MediaManager: Failed to create trash directory: '.$trashDir);

                return false;
            }
        }

        if (! rename($source, $trashPath)) {
            Log::error('MediaManager: Failed to move file to trash: '.$source);

            return false;
        }

        return true;
    }

    /**
     * Permanently delete a file.
     */
    public function deletePermanently(MediaScanFile $file): bool
    {
        $path = $file->absolute_path;
        if (! file_exists($path)) {
            return false;
        }
        if (! unlink($path)) {
            Log::error('MediaManager: Failed to delete file: '.$path);

            return false;
        }

        return true;
    }

    /**
     * Restore a file from trash.
     */
    public function restoreFromTrash(MediaScanFile $file): bool
    {
        $trashPath = $file->trash_path;
        $originalPath = $file->absolute_path;

        if (! file_exists($trashPath)) {
            // Fallback for old double-prefixed trash paths
            $oldTrashPath = base_path('../storage/.mediamanager-trash/'.$file->full_path);
            if (file_exists($oldTrashPath)) {
                $trashPath = $oldTrashPath;
            } else {
                return false;
            }
        }

        $originalDir = dirname($originalPath);
        if (! is_dir($originalDir)) {
            if (! mkdir($originalDir, 0755, true) && ! is_dir($originalDir)) {
                return false;
            }
        }

        if (! rename($trashPath, $originalPath)) {
            return false;
        }

        return true;
    }

    /**
     * Get trash files listing.
     * Merges DB-tracked files with filesystem scanning for backward compatibility.
     */
    public function getTrashFiles(): array
    {
        $trashPath = base_path('../storage/.mediamanager-trash');
        $files = [];
        $seenPaths = [];

        // 1. Get DB-tracked trashed files
        $dbFiles = MediaScanFile::where('is_trashed', true)->get();
        foreach ($dbFiles as $file) {
            $trashFilePath = $file->trash_path;
            // Fallback for old double-prefixed paths
            if (! file_exists($trashFilePath)) {
                $oldTrashPath = base_path('../storage/.mediamanager-trash/'.$file->full_path);
                if (file_exists($oldTrashPath)) {
                    $trashFilePath = $oldTrashPath;
                }
            }
            if (! file_exists($trashFilePath)) {
                continue;
            }

            $size = filesize($trashFilePath);
            $dir = str_replace('storage/', '', $file->directory);
            $files[] = [
                'path' => $file->full_path,
                'filename' => $file->filename,
                'directory' => $dir,
                'size' => $size,
                'size_human' => self::formatBytes($size),
                'deleted_at' => $file->trashed_at ? (is_string($file->trashed_at) ? strtotime($file->trashed_at) : $file->trashed_at->getTimestamp()) : filemtime($trashFilePath),
            ];
            $seenPaths[$trashFilePath] = true;
        }

        // 2. Scan filesystem for any untracked files (legacy / backward compat)
        if (is_dir($trashPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($trashPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if (isset($seenPaths[$file->getPathname()])) {
                    continue;
                }

                $relativePath = str_replace(base_path('../'), '', $file->getPathname());
                // e.g. storage/.mediamanager-trash/restaurant/cover/xxx.jpg
                $innerPath = str_replace('storage/.mediamanager-trash/', '', $relativePath);
                $dir = dirname($innerPath);
                if ($dir === '.' || $dir === '') {
                    $dir = '';
                } else {
                    $dir = preg_replace('/^storage\//', '', $dir);
                }

                $files[] = [
                    'path' => $innerPath,
                    'filename' => $file->getFilename(),
                    'directory' => $dir,
                    'size' => $file->getSize(),
                    'size_human' => self::formatBytes($file->getSize()),
                    'deleted_at' => $file->getMTime(),
                ];
            }
        }

        return $files;
    }
}
