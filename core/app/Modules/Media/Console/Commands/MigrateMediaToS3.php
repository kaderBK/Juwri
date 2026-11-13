<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Models\Credential;

/**
 * MigrateMediaToS3
 *
 * Scans all local media directories and uploads any files not yet present
 * on S3. Supports:
 *   - Per-restaurant mode:  php artisan storage:migrate-to-s3 --restaurant=42
 *   - Platform-wide mode:   php artisan storage:migrate-to-s3
 *   - Dry-run mode:         php artisan storage:migrate-to-s3 --dry-run
 */
class MigrateMediaToS3 extends Command
{
    protected $signature = 'storage:migrate-to-s3
                            {--restaurant= : Migrate only this restaurant ID}
                            {--dry-run     : List files that would be uploaded without actually uploading}';

    protected $description = 'Upload existing local media files to S3-compatible storage (uses BYOK vault credentials)';

    /**
     * Sub-paths (relative to public/) to scan for media files.
     * These match the paths used by image_upload() throughout the app.
     */
    private const MEDIA_DIRS = [
        'storage/menu/',
        'storage/store/cover/',
        'storage/store/logo/',
        'storage/profile/',
        'storage/logo/',
        'storage/blog/',
        'storage/allergies/',
        'storage/testimonials/',
    ];

    public function handle(): int
    {
        $storeId = $this->option('restaurant') ? (int) $this->option('restaurant') : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== juwri – Media to S3 Migration ===');
        $this->newLine();

        if ($dryRun) {
            $this->warn('[DRY RUN] No files will actually be uploaded.');
            $this->newLine();
        }

        // Resolve credentials
        $creds = Credential::for('storage', $storeId);

        if (! $creds || empty($creds['key']) || empty($creds['secret']) || empty($creds['bucket'])) {
            $this->error('No S3 credentials found for '.($storeId ? "restaurant #{$storeId}" : 'platform default').'.');
            $this->line('Configure credentials via Admin → Settings → Integrations or the BYOK merchant dashboard.');

            return self::FAILURE;
        }

        $disk = Storage::build([
            'driver' => 's3',
            'key' => $creds['key'],
            'secret' => $creds['secret'],
            'region' => $creds['region'] ?? 'us-east-1',
            'bucket' => $creds['bucket'],
            'endpoint' => $creds['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($creds['use_path_style_endpoint'] ?? false),
            'throw' => false,
        ]);

        $this->info("Bucket: {$creds['bucket']} | Region: ".($creds['region'] ?? 'us-east-1'));
        $this->newLine();

        $totalUploaded = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach (self::MEDIA_DIRS as $relativeDir) {
            $localDir = public_path($relativeDir);

            if (! is_dir($localDir)) {
                $this->line("<fg=gray>  Skipping {$relativeDir} (directory not found)</>");

                continue;
            }

            $files = array_diff(scandir($localDir), ['.', '..']);
            if (empty($files)) {
                $this->line("<fg=gray>  {$relativeDir} — empty</>");

                continue;
            }

            $this->line("<fg=cyan>  {$relativeDir}</> (".count($files).' files)');

            $s3Prefix = ltrim(preg_replace('#^/?storage/#', '', $relativeDir), '/');

            foreach ($files as $file) {
                $localPath = $localDir.$file;
                if (! is_file($localPath)) {
                    continue;
                }

                $s3Key = $s3Prefix.$file;

                if ($disk->exists($s3Key)) {
                    $this->line("    <fg=gray>SKIP</> {$file} (already on S3)");
                    $totalSkipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("    <fg=yellow>WOULD UPLOAD</> {$file}");
                    $totalUploaded++;

                    continue;
                }

                try {
                    $success = $disk->put($s3Key, file_get_contents($localPath), 'public');
                    if ($success) {
                        $this->line("    <fg=green>OK</> {$file}");
                        $totalUploaded++;
                        Log::info('[MigrateMediaToS3] Uploaded', ['key' => $s3Key]);
                    } else {
                        $this->line("    <fg=red>FAIL</> {$file}");
                        $totalFailed++;
                    }
                } catch (\Throwable $e) {
                    $this->line("    <fg=red>ERROR</> {$file}: {$e->getMessage()}");
                    $totalFailed++;
                    Log::error('[MigrateMediaToS3] Upload failed', ['key' => $s3Key, 'error' => $e->getMessage()]);
                }
            }

            $this->newLine();
        }

        $this->table(
            ['Uploaded', 'Skipped', 'Failed'],
            [[$totalUploaded, $totalSkipped, $totalFailed]]
        );

        if ($totalFailed > 0) {
            $this->warn('Some files failed to upload. Check storage.log for details.');

            return self::FAILURE;
        }

        $this->info('Migration complete!');

        return self::SUCCESS;
    }
}
