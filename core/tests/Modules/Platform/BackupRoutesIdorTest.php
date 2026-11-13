<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Account\AccountExports;
use Juwri\Modules\Integrations\Models\StoreImport;
use Tests\TestCase;

/**
 * P6-177: IDOR proofs for the token routes the P6-59 sweep left "unresolved"
 * (backup.download/destroy/status, backup.account.download,
 * backup.import.confirm/destroy/status). Victim owner A holds a ready backup,
 * a previewed import and an account export. Three attackers aim A's tokens:
 * another owner B, a staff session on A's own store, and A himself with a
 * different store of his in context (the token is pinned to its store).
 * Each gets 403/404 and A's rows/files stay intact. A positive control proves
 * A reaches every route, so the denials are not vacuous.
 */
class BackupRoutesIdorTest extends TestCase
{
    use RefreshDatabase;

    private string $exports;

    private User $owner;

    private Store $store;

    private Store $otherStoreOfOwner;

    private StoreBackup $backup;

    private StoreImport $import;

    private string $exportToken;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // EnsureStaffDashboardPermission stays ON: the staff denial is under test.
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->exports = sys_get_temp_dir().'/idor-exports-'.getmypid().'-'.bin2hex(random_bytes(4));
        config(['filesystems.account_exports_path' => $this->exports]);

        $this->owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()])->fresh();
        $this->store = $this->makeStore($this->owner);
        $this->otherStoreOfOwner = $this->makeStore($this->owner);
        $this->owner = $this->owner->fresh(); // the account is opened with the first store

        $rel = 'backups/idor-'.Str::lower(Str::random(8)).'.zip';
        @mkdir(dirname(storage_path('app/'.$rel)), 0777, true);
        file_put_contents(storage_path('app/'.$rel), 'PK-victim-archive');
        $this->backup = StoreBackup::create([
            'store_id' => $this->store->id, 'user_id' => $this->owner->id, 'token' => StoreBackup::newToken(),
            'status' => 'ready', 'progress' => 100, 'include_pii' => true, 'path' => $rel, 'expires_at' => now()->addDay(),
        ]);
        $this->import = StoreImport::create([
            'store_id' => $this->store->id, 'user_id' => $this->owner->id, 'token' => Str::lower(Str::random(40)),
            'status' => 'previewed', 'plan' => ['menu_categories' => 1], 'manifest' => [],
        ]);

        $this->assertNotNull($this->owner->account_id, 'owner has an account');
        $this->exportToken = Str::lower(Str::random(40));
        $path = app(AccountExports::class)->path((int) $this->owner->account_id, $this->exportToken);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'PK-victim-account-export');
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/'.$this->backup->path));
        foreach (glob($this->exports.'/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->exports.'/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->exports);
        parent::tearDown();
    }

    private function makeStore(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'bk-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    /** @return list<array{0: string, 1: string, 2: string}> [method, route, token] for the 6 store-token routes */
    private function storeTokenRoutes(): array
    {
        return [
            ['get', 'backup.status', $this->backup->token],
            ['get', 'backup.download', $this->backup->token],
            ['delete', 'backup.destroy', $this->backup->token],
            ['post', 'backup.import.confirm', $this->import->token],
            ['get', 'backup.import.status', $this->import->token],
            ['delete', 'backup.import.destroy', $this->import->token],
        ];
    }

    private function assertVictimIntact(): void
    {
        $this->assertNotNull(StoreBackup::find($this->backup->id), 'backup row survives');
        $this->assertFileExists(storage_path('app/'.$this->backup->path));
        $import = StoreImport::find($this->import->id);
        $this->assertNotNull($import, 'import row survives');
        $this->assertSame('previewed', $import->status, 'import not confirmed');
        $this->assertFileExists(app(AccountExports::class)->path((int) $this->owner->account_id, $this->exportToken));
    }

    public function test_other_owner_gets_404_on_every_backup_route(): void
    {
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $intruderStore = $this->makeStore($intruder);

        foreach ($this->storeTokenRoutes() as [$method, $name, $token]) {
            $this->actingAs($intruder)->withSession(['dashboard_store_id' => $intruderStore->id])
                ->{$method.'Json'}(route($name, $token))->assertNotFound();
        }
        $this->actingAs($intruder)->get(route('backup.account.download', $this->exportToken))->assertNotFound();

        $this->assertVictimIntact();
    }

    public function test_other_owner_pointing_the_session_at_the_victims_store_is_refused(): void
    {
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->makeStore($intruder);

        foreach ($this->storeTokenRoutes() as [$method, $name, $token]) {
            $status = $this->actingAs($intruder)->withSession(['dashboard_store_id' => $this->store->id])
                ->{$method.'Json'}(route($name, $token))->getStatusCode();
            $this->assertContains($status, [403, 404], "$name answered $status");
        }

        $this->assertVictimIntact();
    }

    public function test_staff_session_on_the_victims_store_is_403(): void
    {
        $staff = StaffMember::create([
            'owner_id' => $this->owner->id, 'store_id' => $this->store->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
        $routes = $this->storeTokenRoutes();
        $routes[] = ['get', 'backup.account.download', $this->exportToken];

        foreach ($routes as [$method, $name, $token]) {
            $this->actingAs($staff, 'staff')->actingAs($this->owner, 'web')
                ->withSession(['staff_session_id' => $staff->id, 'dashboard_store_id' => $this->store->id])
                ->{$method.'Json'}(route($name, $token))->assertForbidden();
        }

        $this->assertVictimIntact();
    }

    public function test_tokens_are_pinned_to_their_store_even_for_the_same_owner(): void
    {
        foreach ($this->storeTokenRoutes() as [$method, $name, $token]) {
            $this->actingAs($this->owner)->withSession(['dashboard_store_id' => $this->otherStoreOfOwner->id])
                ->{$method.'Json'}(route($name, $token))->assertNotFound();
        }

        $this->assertVictimIntact();
    }

    public function test_account_team_member_cannot_download_the_owners_account_export(): void
    {
        $member = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'account_id' => $this->owner->account_id]);

        $this->actingAs($member)->get(route('backup.account.download', $this->exportToken))->assertNotFound();
    }

    public function test_positive_control_the_owner_reaches_every_route(): void
    {
        $as = fn () => $this->actingAs($this->owner)->withSession(['dashboard_store_id' => $this->store->id]);

        $as()->getJson(route('backup.status', $this->backup->token))->assertOk();
        $as()->get(route('backup.download', $this->backup->token))->assertOk();
        $as()->getJson(route('backup.import.status', $this->import->token))->assertOk();
        $as()->get(route('backup.account.download', $this->exportToken))->assertOk();
        $as()->delete(route('backup.import.destroy', $this->import->token))->assertRedirect();
        $this->assertNull(StoreImport::find($this->import->id));
        $as()->delete(route('backup.destroy', $this->backup->token))->assertRedirect();
        $this->assertNull(StoreBackup::find($this->backup->id));
    }
}
