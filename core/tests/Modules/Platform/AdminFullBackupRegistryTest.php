<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreTable;
use App\Services\Backup\AdminStoreArchive;
use App\Services\Backup\StoreBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Models\GlJournalLine;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;
use ZipArchive;

/**
 * C3: the registry-driven superadmin backup/restore engine (dark behind
 * admin_backup_registry_engine). Proves the D3 superset guarantee, that a
 * restore no longer orphans store-scoped tables, the always-on refusal for
 * incomplete archives, id preservation, and the dark default.
 */
class AdminFullBackupRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1, 'site_admin_logo' => 'logo.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share(['admin_languages' => collect(), 'notifications' => collect(), 'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function ownerWithStore(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'Burger Barn', 'slug' => 'bb-'.Str::lower(Str::random(6)),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Cheeseburger', 'price' => 20, 'active' => '1']);
        Branch::create(['store_id' => $store->id, 'name' => 'Main Branch']);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'key' => 'abc1234567890']);

        return [$owner, $store];
    }

    private function flagOn(): void
    {
        config(['settings.admin_backup_registry_engine' => '1']);
    }

    /** GET the full export and return a path to a stable copy of the ZIP. */
    private function exportZip(User $admin, User $owner): string
    {
        $res = $this->actingAs($admin)->get(route('admin.users.fullExport', $owner->id));
        $res->assertOk();
        $src = $res->baseResponse->getFile()->getPathname();
        $dst = tempnam(sys_get_temp_dir(), 'c3zip').'.zip';
        copy($src, $dst);

        return $dst;
    }

    private function upload(string $zipPath): UploadedFile
    {
        return new UploadedFile($zipPath, 'backup.zip', 'application/zip', null, true);
    }

    private function manifestFrom(string $zipPath): array
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $json = $zip->getFromName('manifest.json');
        $zip->close();

        return json_decode((string) $json, true);
    }

    public function test_admin_export_covers_every_registry_table(): void
    {
        $this->flagOn();
        [$owner] = $this->ownerWithStore();

        $manifest = $this->manifestFrom($this->exportZip($this->makeAdmin(), $owner));

        $this->assertSame(2, $manifest['schema']);
        $missing = array_diff(array_keys(StoreBackupService::tableRegistry()), array_keys($manifest['stores'][0]));
        $this->assertSame([], $missing, 'the admin export must cover every registry table');
    }

    public function test_restore_does_not_orphan_store_scoped_rows(): void
    {
        $this->flagOn();
        [$owner, $store] = $this->ownerWithStore();
        $before = Branch::where('store_id', $store->id)->count();
        $this->assertGreaterThan(0, $before);
        $zip = $this->exportZip($this->makeAdmin(), $owner);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.users.fullImport', $owner->id), ['backup_file' => $this->upload($zip)])
            ->assertRedirect();

        $this->assertSame(0, Branch::whereNotIn('store_id', Store::query()->select('id'))->count(), 'no branch may be orphaned');
        $this->assertSame($before, Branch::where('store_id', $store->id)->count(), 'branches survive under the preserved store id');
    }

    public function test_restore_preserves_the_store_id_and_table_keys(): void
    {
        $this->flagOn();
        [$owner, $store] = $this->ownerWithStore();
        $originalId = $store->id;
        $originalKey = StoreTable::where('store_id', $store->id)->value('key');
        $zip = $this->exportZip($this->makeAdmin(), $owner);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.users.fullImport', $owner->id), ['backup_file' => $this->upload($zip)])
            ->assertRedirect();

        $this->assertNotNull(Store::find($originalId), 'the store id must be preserved');
        $this->assertSame($originalKey, StoreTable::where('store_id', $originalId)->value('key'), 'the table key must be preserved');
    }

    public function test_restore_maps_the_pre_p104_journal_line_account_column(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $archive = new AdminStoreArchive;
        $gl = JournalEngine::account($store->id, '6200');
        $journal = GlJournal::create(['store_id' => $store->id, 'date' => now()->toDateString(), 'memo' => 'm', 'locked' => false]);
        GlJournalLine::create(['journal_id' => $journal->id, 'gl_account_id' => $gl->id, 'debit' => 5, 'credit' => 0]);
        $backup = $archive->export($store);
        // An archive written before P1-04 carries the GL account under the old column name.
        $backup['gl_journal_lines'] = array_map(function ($row) {
            $row['account_id'] = $row['gl_account_id'];
            unset($row['gl_account_id']);

            return $row;
        }, $backup['gl_journal_lines']);
        $this->assertCount(1, $backup['gl_journal_lines']);

        DB::transaction(function () use ($store, $owner, $archive, $backup) {
            DB::table('accounting_journal_lines')->delete();
            DB::table('accounting_journals')->delete();
            DB::table('accounting_accounts')->delete();
            foreach (StoreBackupService::tableRegistry() as $spec) {
                DB::table(new $spec['model']()->getTable())->delete();
            }
            DB::table('theme_settings')->where('store_id', $store->id)->delete();
            DB::table('stores')->where('id', $store->id)->delete();
            $archive->restore($owner, $backup);
        });

        $this->assertSame($gl->id, (int) GlJournalLine::where('journal_id', $journal->id)->value('gl_account_id'));
    }

    public function test_restore_maps_the_pre_p104_store_column(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $archive = new AdminStoreArchive;
        $journal = GlJournal::create(['store_id' => $store->id, 'date' => now()->toDateString(), 'memo' => 'legacy', 'locked' => false]);
        $backup = $archive->export($store);
        // An archive written before P1-04 slice 3 names the store column the old way.
        $backup['gl_journals'] = array_map(function ($row) {
            $row['restaurant_id'] = $row['store_id']; // legacy archive row
            unset($row['store_id']);

            return $row;
        }, $backup['gl_journals']);

        DB::transaction(function () use ($store, $owner, $archive, $backup) {
            DB::table('accounting_journal_lines')->delete();
            DB::table('accounting_journals')->delete();
            foreach (StoreBackupService::tableRegistry() as $spec) {
                DB::table(new $spec['model']()->getTable())->delete();
            }
            DB::table('theme_settings')->where('store_id', $store->id)->delete();
            DB::table('stores')->where('id', $store->id)->delete();
            $archive->restore($owner, $backup);
        });

        $this->assertSame($store->id, (int) GlJournal::whereKey($journal->id)->value('store_id'));
    }

    public function test_occupied_store_id_is_refused_not_silently_rekeyed(): void
    {
        $this->flagOn();
        [$owner, $store] = $this->ownerWithStore();
        $zip = $this->exportZip($this->makeAdmin(), $owner);

        // A DIFFERENT user now owns a post with the same id (simulated cross-platform archive).
        $other = User::factory()->create(['status' => 1]);
        // Free the id on THIS platform, then hand it to another account.
        Branch::where('store_id', $store->id)->delete();
        $store->forceFill(['user_id' => $other->id])->save();

        $res = $this->actingAs($this->makeAdmin())
            ->post(route('admin.users.fullImport', $owner->id), ['backup_file' => $this->upload($zip)]);
        $res->assertRedirect();

        // The other user's post is untouched.
        $this->assertSame($other->id, Store::find($store->id)->user_id);
    }

    public function test_registry_engine_is_off_by_default(): void
    {
        // No flag set.
        [$owner] = $this->ownerWithStore();
        $manifest = $this->manifestFrom($this->exportZip($this->makeAdmin(), $owner));

        $this->assertSame(1, $manifest['schema']);
        $this->assertArrayNotHasKey('restaurant_staff', $manifest['stores'][0], 'legacy manifest carries only the 10 sections');
    }

    public function test_dry_run_previews_deletions_and_writes_nothing(): void
    {
        $this->flagOn();
        [$owner, $store] = $this->ownerWithStore();
        $zip = $this->exportZip($this->makeAdmin(), $owner);
        $postCountBefore = Store::count();
        $branchesBefore = Branch::where('store_id', $store->id)->count();

        $res = $this->actingAs($this->makeAdmin())
            ->post(route('admin.users.fullImport', $owner->id), ['backup_file' => $this->upload($zip), 'dry_run' => '1']);

        $res->assertOk();
        $res->assertSeeHtml('restaurant_staff');
        $this->assertSame($postCountBefore, Store::count(), 'a dry-run writes nothing');
        $this->assertSame($branchesBefore, Branch::where('store_id', $store->id)->count(), 'a dry-run deletes nothing');
    }

    public function test_legacy_manifest_refuses_the_destructive_clear(): void
    {
        // Export while the flag is OFF -> schema 1.
        [$owner, $store] = $this->ownerWithStore();
        $before = Branch::where('store_id', $store->id)->count();
        $zip = $this->exportZip($this->makeAdmin(), $owner);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.users.fullImport', $owner->id), ['backup_file' => $this->upload($zip)])
            ->assertRedirect();

        // Refused: the post and its branches survive unchanged.
        $this->assertNotNull(Store::find($store->id));
        $this->assertSame($before, Branch::where('store_id', $store->id)->count());
    }

    public function test_partial_restore_override_proceeds_and_raises_a_platform_alert(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $zip = $this->exportZip($this->makeAdmin(), $owner);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.users.fullImport', $owner->id), ['backup_file' => $this->upload($zip), 'allow_partial_restore' => '1'])
            ->assertRedirect();

        $this->assertTrue(PlatformAlert::where('kind', 'backup-restore')->exists(), 'a partial restore must raise a platform alert');
    }
}
