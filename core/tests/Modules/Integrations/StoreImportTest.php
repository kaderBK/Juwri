<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Services\Backup\StoreBackupService;
use App\Services\Backup\StoreImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Integrations\Jobs\ProcessStoreImportJob;
use Juwri\Modules\Integrations\Models\StoreImport;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;
use ZipArchive;

class StoreImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(User $user, string $slug, bool $seedMenu = false): Store
    {
        $r = Store::create([
            'user_id' => $user->id, 'title' => 'Store '.$slug, 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        if ($seedMenu) {
            $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1']);
            Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Cheeseburger', 'price' => 20, 'active' => '1']);
            Coupon::forceCreate(['user_id' => $user->id, 'code' => 'SAVE10', 'percentage' => 10, 'branch_id' => null]);
        }

        return $r;
    }

    /** Build a backup ZIP for a store and return its absolute path. */
    private function backupZip(Store $store): string
    {
        $backup = StoreBackup::create([
            'store_id' => $store->id, 'user_id' => $store->user_id, 'token' => StoreBackup::newToken(),
            'status' => 'pending', 'datasets' => ['menu', 'coupons'], 'format' => 'json', 'include_pii' => false,
        ]);
        app(StoreBackupService::class)->build($backup->fresh());

        return storage_path('app/'.$backup->fresh()->path);
    }

    /** Stage a ZIP as an uploaded import for a target store. */
    private function stageImport(string $zipAbs, Store $target): StoreImport
    {
        $token = StoreImport::newToken();
        $rel = 'store-imports/'.$target->id.'/'.$token.'.zip';
        @mkdir(dirname(storage_path('app/'.$rel)), 0755, true);
        copy($zipAbs, storage_path('app/'.$rel));

        return StoreImport::create([
            'store_id' => $target->id, 'user_id' => $target->user_id, 'token' => $token,
            'status' => 'uploaded', 'path' => $rel, 'size_bytes' => filesize($zipAbs),
        ]);
    }

    public function test_round_trip_restores_menu_into_empty_store(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'source', true);
        $b = $this->store($owner, 'target');
        $service = app(StoreImportService::class);

        $import = $this->stageImport($this->backupZip($a), $b);

        // Validate + dry-run preview writes nothing.
        [$manifest, $cross] = $service->validate($import, $b);
        $this->assertTrue($cross, 'importing store A backup into store B is cross-store');
        $plan = $service->plan($import, $b);
        $this->assertSame(1, $plan['menu_categories']['rows']);
        $this->assertSame(0, MenuCategory::where('store_id', $b->id)->count(), 'dry-run must write nothing');

        // Apply.
        $summary = $service->apply($import, $b);
        $this->assertSame(1, $summary['menu_categories']['created']);
        $this->assertSame('Mains', MenuCategory::where('store_id', $b->id)->value('name'));
        $this->assertSame('Cheeseburger', Menu::where('store_id', $b->id)->value('name'));
        $this->assertSame(1, Coupon::where('user_id', $owner->id)->where('code', 'SAVE10')->count());

        // Source store untouched (no duplication back into A).
        $this->assertSame(1, MenuCategory::where('store_id', $a->id)->count());
    }

    public function test_http_upload_preview_and_confirm_flow(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'httpsrc', true);
        $b = $this->store($owner, 'httptgt');
        $zip = $this->backupZip($a);
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.zip';
        copy($zip, $tmp);

        $this->actingAs($owner);
        session(['dashboard_store_id' => $b->id]);
        $file = new UploadedFile($tmp, 'backup.zip', 'application/zip', null, true);

        $this->post(route('backup.import'), ['archive' => $file])->assertRedirect();
        $import = StoreImport::where('store_id', $b->id)->firstOrFail();
        $this->assertSame('previewed', $import->status);
        $this->assertArrayHasKey('menu_categories', $import->plan);

        // Nothing imported yet.
        $this->assertSame(0, MenuCategory::where('store_id', $b->id)->count());

        $this->post(route('backup.import.confirm', $import->token))->assertRedirect();
        $import->refresh();
        $this->assertSame('done', $import->status); // sync queue ran the job inline
        $this->assertSame('Mains', MenuCategory::where('store_id', $b->id)->value('name'));
    }

    public function test_apply_is_idempotent_upsert(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->store($owner, 'src2', true);
        $b = $this->store($owner, 'tgt2');
        $service = app(StoreImportService::class);
        $zip = $this->backupZip($a);

        $service->apply($this->stageImport($zip, $b), $b);
        $service->apply($this->stageImport($zip, $b), $b); // second run

        $this->assertSame(1, MenuCategory::where('store_id', $b->id)->count(), 'upsert must not duplicate');
        $this->assertSame(1, Menu::where('store_id', $b->id)->count());
    }

    public function test_import_media_restore_rejects_scripts_and_scopes_per_store(): void
    {
        // Wave V / E1 security: a non-media entry must never be written to the web
        // tree, and a legit image lands under a per-target-store subfolder.
        $owner = User::factory()->create(['status' => 1]);
        $b = $this->store($owner, 'medtgt');
        $rel = 'store-imports/'.$b->id.'/media.zip';
        @mkdir(dirname(storage_path('app/'.$rel)), 0755, true);
        $zip = new ZipArchive;
        $zip->open(storage_path('app/'.$rel), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(['store_id' => $b->id]));
        $zip->addFromString('media/menu/evil.html', '<script>alert(1)</script>');
        $zip->addFromString('media/menu/ok.png', 'PNGOK');
        $zip->close();
        $import = StoreImport::create([
            'store_id' => $b->id, 'user_id' => $owner->id, 'token' => StoreImport::newToken(),
            'status' => 'uploaded', 'path' => $rel,
        ]);

        app(StoreImportService::class)->apply($import, $b);

        $evil = storage_path('app/public/menu/'.$b->id.'/evil.html');
        $sharedEvil = storage_path('app/public/menu/evil.html');
        $ok = storage_path('app/public/menu/'.$b->id.'/ok.png');
        $this->assertFileDoesNotExist($evil, 'script file must be rejected');
        $this->assertFileDoesNotExist($sharedEvil, 'nothing written to the shared root');
        $this->assertFileExists($ok, 'legit image written under per-store folder');
        $this->assertSame('PNGOK', file_get_contents($ok));

        @unlink($ok);
    }

    public function test_archive_without_manifest_is_rejected(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $b = $this->store($owner, 'notarget');
        $rel = 'store-imports/'.$b->id.'/junk.zip';
        @mkdir(dirname(storage_path('app/'.$rel)), 0755, true);
        $zip = new ZipArchive;
        $zip->open(storage_path('app/'.$rel), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('hello.txt', 'not a backup');
        $zip->close();
        $import = StoreImport::create([
            'store_id' => $b->id, 'user_id' => $owner->id, 'token' => StoreImport::newToken(),
            'status' => 'uploaded', 'path' => $rel,
        ]);

        $this->expectException(\RuntimeException::class);
        app(StoreImportService::class)->validate($import, $b);
    }

    public function test_zip_bomb_ratio_is_rejected(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $b = $this->store($owner, 'bombtarget');
        $rel = 'store-imports/'.$b->id.'/bomb.zip';
        @mkdir(dirname(storage_path('app/'.$rel)), 0755, true);
        $zip = new ZipArchive;
        $zip->open(storage_path('app/'.$rel), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(['store_id' => $b->id]));
        $zip->addFromString('big.json', str_repeat('0', 5_000_000)); // highly compressible → huge ratio
        $zip->close();
        $import = StoreImport::create([
            'store_id' => $b->id, 'user_id' => $owner->id, 'token' => StoreImport::newToken(),
            'status' => 'uploaded', 'path' => $rel,
        ]);

        $this->expectException(\RuntimeException::class);
        app(StoreImportService::class)->validate($import, $b);
    }

    public function test_secret_settings_are_not_imported(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $b = $this->store($owner, 'sectarget');
        $rel = 'store-imports/'.$b->id.'/sec.zip';
        @mkdir(dirname(storage_path('app/'.$rel)), 0755, true);
        $zip = new ZipArchive;
        $zip->open(storage_path('app/'.$rel), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(['store_id' => $b->id]));
        $zip->addFromString('settings.json', json_encode([[
            'default_language' => 'en',
            'restaurant_stripe_secret_key' => 'sk_live_LEAK',
            'square_access_token' => 'tok_LEAK',
        ]]));
        $zip->close();
        $import = StoreImport::create([
            'store_id' => $b->id, 'user_id' => $owner->id, 'token' => StoreImport::newToken(),
            'status' => 'uploaded', 'path' => $rel,
        ]);

        app(StoreImportService::class)->apply($import, $b);

        $this->assertSame('en', StoreSettings::legacy((int) $b->id, 'default_language'));
        $this->assertNull(StoreSettings::legacy((int) $b->id, 'restaurant_stripe_secret_key'));
        $this->assertNull(StoreSettings::legacy((int) $b->id, 'square_access_token'));
    }

    /** P6-316: a second confirm (double click, second tab) dispatches nothing. */
    public function test_second_confirm_does_not_dispatch_again(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['status' => 1]);
        $target = $this->store($owner, 'twicetgt');
        $import = $this->stageImport($this->backupZip($this->store($owner, 'twicesrc', true)), $target);
        $import->update(['status' => 'previewed']);

        $this->actingAs($owner);
        session(['dashboard_store_id' => $target->id]);
        $this->post(route('backup.import.confirm', $import->token))->assertRedirect();
        $this->post(route('backup.import.confirm', $import->token));

        Queue::assertPushed(ProcessStoreImportJob::class, 1);
        $this->assertSame('processing', $import->fresh()->status);
    }

    /** P6-316: the running job reads the zip; discarding it then is refused. */
    public function test_discard_is_refused_while_processing(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $target = $this->store($owner, 'busytgt');
        $import = $this->stageImport($this->backupZip($this->store($owner, 'busysrc', true)), $target);
        $import->update(['status' => 'processing']);

        $this->actingAs($owner);
        session(['dashboard_store_id' => $target->id]);
        $this->delete(route('backup.import.destroy', $import->token))->assertRedirect();

        $this->assertNotNull($import->fresh());
        $this->assertFileExists(storage_path('app/'.$import->path));
    }

    /** P6-316: old uploads are pruned; a running import keeps its file. */
    public function test_prune_removes_old_uploads_but_not_running_ones(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $target = $this->store($owner, 'prunetgt');
        $src = $this->store($owner, 'prunesrc', true);
        $old = $this->stageImport($this->backupZip($src), $target);
        $running = $this->stageImport($this->backupZip($src), $target);
        StoreImport::whereKey($old->id)->update(['status' => 'previewed', 'created_at' => now()->subDays(3)]);
        StoreImport::whereKey($running->id)->update(['status' => 'processing', 'created_at' => now()->subDays(3)]);

        $this->artisan('store-imports:prune')->assertSuccessful();

        $this->assertNull($old->fresh()->path);
        $this->assertFileDoesNotExist(storage_path('app/'.$old->path));
        $this->assertSame($running->path, $running->fresh()->path);
        $this->assertFileExists(storage_path('app/'.$running->path));
    }
}
