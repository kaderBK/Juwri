<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Media\Services\StorageQuota;
use Tests\TestCase;

/**
 * Abuse Guard P5 (central wiring): StorageService::upload is the single upload
 * choke point — hardening + per-tenant storage accounting run here for ALL callers.
 */
class StorageServiceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::factory()->create();

        return Store::create([
            'user_id' => $owner->id, 'title' => 'R', 'slug' => 'r-'.$owner->id,
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    public function test_warn_mode_accepts_and_accounts_storage(): void
    {
        config(['settings.abuse_upload_mode' => 'warn']);
        $r = $this->storeFixture();
        $file = UploadedFile::fake()->image('a.jpg', 100, 100);
        $bytes = $file->getSize();

        $name = image_upload($file, 'storage/menu/', null, null, null, $r->id);

        $this->assertNotSame('', $name);
        $this->assertSame($bytes, StorageQuota::used($r->user_id));
    }

    public function test_enforce_rejects_bad_mime_and_saves_nothing(): void
    {
        config(['settings.abuse_upload_mode' => 'enforce']);
        $r = $this->storeFixture();
        $file = UploadedFile::fake()->create('evil.exe', 5, 'application/x-msdownload');

        $name = image_upload($file, 'storage/menu/', null, null, null, $r->id);

        $this->assertSame('', $name);
        $this->assertSame(0, StorageQuota::used($r->user_id));
        $this->assertSame(1, AbuseEvent::where('guard', 'upload')->count());
    }

    public function test_enforce_over_storage_cap_rejects(): void
    {
        config(['settings.abuse_upload_mode' => 'enforce', 'settings.abuse_storage_mode' => 'enforce', 'settings.abuse_storage_tenant_cap_mb' => 0]);
        $r = $this->storeFixture();
        $file = UploadedFile::fake()->image('a.jpg', 50, 50);

        $name = image_upload($file, 'storage/menu/', null, null, null, $r->id);

        $this->assertSame('', $name);
    }
}
