<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Tests\Support\TestPlans;
use Tests\TestCase;
use ZipArchive;

/**
 * BL-005 (part A): P6-320 uploads that live outside any table (StoreUploadFiles:
 * notification sounds, the menu font, folio/pageview PDFs) are carried in the
 * store-data bundle. A store export lists only its OWN uploads under
 * `manifest.uploads`, each mapped to that store id, and adds the files to the zip.
 */
class StoreDataExporterUploadsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    /** @var list<int> */
    private array $storeIds = [];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/store-data-uploads-'.Str::lower(Str::random(8)));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach ($this->storeIds as $id) {
            @rmdir(public_path('storage/notif-sounds/'.$id));
        }
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function owner(string $tag): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, string $tag): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => $tag.'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => 'Store '.$tag, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->storeIds[] = (int) $store->id;

        return $store;
    }

    /** Write one upload outside the media library; returns its relative path. */
    private function upload(string $relative, string $content): string
    {
        $abs = public_path('storage/'.$relative);
        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, $content);
        $this->files[] = $abs;

        return $abs;
    }

    public function test_a_store_export_carries_only_its_own_upload_files(): void
    {
        $owner = $this->owner('a');
        $store = $this->store($owner, 'a-one');
        $other = $this->store($owner, 'a-two');

        $mine = 'notif-sounds/'.$store->id.'/chime-'.Str::lower(Str::random(12)).'.mp3';
        $theirs = 'notif-sounds/'.$other->id.'/chime-'.Str::lower(Str::random(12)).'.mp3';
        $this->upload($mine, 'mine');
        $this->upload($theirs, 'theirs');

        $zipPath = $this->tmp.'/store.zip';
        $manifest = app(StoreDataExporter::class)->export(
            DB::connection(), StoreDataScope::store((int) $store->id), $zipPath
        );

        // Manifest: the store's upload is listed under its entry name, mapped to the store id.
        $this->assertArrayHasKey('uploads', $manifest);
        $this->assertArrayHasKey('uploads/'.$mine, $manifest['uploads']);
        $this->assertSame((int) $store->id, $manifest['uploads']['uploads/'.$mine]);
        // Another store's upload is not in the scope.
        $this->assertArrayNotHasKey('uploads/'.$theirs, $manifest['uploads']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        try {
            $this->assertSame('mine', (string) $zip->getFromName('uploads/'.$mine));
            $this->assertFalse($zip->locateName('uploads/'.$theirs), "another store's upload is in the bundle");

            $written = json_decode((string) $zip->getFromName('manifest.json'), true);
            $this->assertSame((int) $store->id, $written['uploads']['uploads/'.$mine]);
            $this->assertArrayNotHasKey('uploads/'.$theirs, $written['uploads']);
        } finally {
            $zip->close();
        }
    }
}
