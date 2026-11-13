<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\BuildAccountExportJob;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Account\AccountExports;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;
use ZipArchive;

/**
 * P4-07 — the owner's "download all your data" (Terms 7.6, DPA 7.1/10.1): one archive with every store of the account
 * and all its rows (the store-data bundle), never a row of another account; only the account's owner can make or
 * download it (not another owner with the token, not a staff session, not a team member); the purge deletes it.
 * Before: the only owner export was the per-store backup, which leaves out most tables (bookings, staff, team chat,
 * webhooks, AI and studio data, …).
 */
class AccountExportTest extends TestCase
{
    use RefreshDatabase;

    private string $exports;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        // a folder of this test's own: account ids repeat in every parallel process, and the old shared folder (plus a tearDown that
        // deleted every account's files) removed another process's archive in the middle of "the purge deletes the export"
        $this->exports = sys_get_temp_dir().'/account-exports-'.getmypid().'-'.bin2hex(random_bytes(4));
        config(['filesystems.account_exports_path' => $this->exports]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->exports.'/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->exports.'/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->exports);
        parent::tearDown();
    }

    /** @return array{0: User, 1: list<Store>} */
    private function owner(string $tag, int $stores): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'name' => 'Owner '.$tag,
            'email' => $tag.Str::lower(Str::random(6)).'@export.test', 'password' => Hash::make('pw')]);
        $list = [];
        for ($i = 0; $i < $stores; $i++) {
            $store = Store::create(['user_id' => $user->id, 'slug' => $tag.'-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => "Store {$tag}{$i}",
                'sub_title' => 't', 'description' => 'd', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1']);
            Menu::create(['store_id' => $store->id, 'name' => "Tea {$tag}{$i}", 'price' => 5, 'active' => 1]);
            $list[] = $store;
        }

        return [$user->fresh(), $list];
    }

    private function switchUser(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<array<string, mixed>>>} */
    private function read(string $zipPath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $rows = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'data/')) {
                $rows[substr($name, 5, -7)] = array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) $zip->getFromIndex($i)))));
            }
        }
        $zip->close();

        return [$manifest, $rows];
    }

    public function test_the_owner_downloads_every_store_of_the_account_and_nothing_of_another(): void
    {
        [$alice, $aStores] = $this->owner('a', 2);
        [$bob, $bStores] = $this->owner('b', 1);
        DB::table('customers')->insert(['store_id' => $bStores[0]->id, 'account_id' => $bob->account_id, 'name' => 'Bob customer', 'phone' => '0500']);

        // the Backup & export page renders the card (the first build had a Blade error here)
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $aStores[0]->id])->get(route('backup.index'))
            ->assertOk()->assertSee('data-account-export-form', false)->assertDontSee('data-account-export-download', false);

        $this->actingAs($alice)->post(route('backup.account.export'))->assertRedirect();
        $latest = app(AccountExports::class)->latest((int) $alice->account_id);
        $this->assertSame('ready', $latest['status'] ?? null);
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $aStores[0]->id])->get(route('backup.index'))
            ->assertOk()->assertSee(route('backup.account.download', $latest['token']), false);

        $res = $this->actingAs($alice)->get(route('backup.account.download', $latest['token']))->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        [$manifest, $rows] = $this->read($res->getFile()->getPathname());
        $this->assertSame('account', $manifest['scope']['type']);
        $this->assertEqualsCanonicalizing(array_map(fn ($s) => $s->id, $aStores), $manifest['stores']);
        $this->assertEqualsCanonicalizing(['Tea a0', 'Tea a1'], array_column($rows['items'] ?? [], 'name'));
        foreach ($rows as $table => $list) {
            foreach ($list as $row) {
                $this->assertNotContains((int) ($row['store_id'] ?? 0), [$bStores[0]->id], "$table holds Bob's store row");
                if (isset($row['account_id'])) {
                    $this->assertSame((int) $alice->account_id, (int) $row['account_id'], "$table holds another account's row");
                }
            }
        }
        $this->assertArrayNotHasKey('password', $rows['users'][0] ?? [], 'secrets never leave');

        // another owner with Alice's token: not found (looked up in Bob's own account folder)
        $this->switchUser();
        $this->actingAs($bob)->get(route('backup.account.download', $latest['token']))->assertNotFound();
        $this->actingAs($bob)->get(route('backup.account.download', '../'.$alice->account_id.'/'.$latest['token']))->assertNotFound();
    }

    public function test_only_the_account_owner_can_export(): void
    {
        [$alice, $aStores] = $this->owner('a', 1);
        // a staff session (the web guard is the owner, as after a staff sign-in) is refused
        $staff = StaffMember::create(['owner_id' => $alice->id, 'store_id' => $aStores[0]->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1]);
        $res = $this->actingAs($staff, 'staff')->actingAs($alice, 'web')->withSession(['staff_session_id' => $staff->id])->post(route('backup.account.export'));
        $this->assertContains($res->getStatusCode(), [302, 403, 404]);
        $this->assertNull(app(AccountExports::class)->latest((int) $alice->account_id), 'a staff session started an export');

        // a team member of the account (not its owner) is refused
        $this->switchUser();
        $member = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'account_id' => $alice->account_id]);
        $this->actingAs($member)->post(route('backup.account.export'))->assertForbidden();
        $this->actingAs($member)->withSession(['dashboard_store_id' => $aStores[0]->id])->get(route('backup.index'))
            ->assertDontSee('data-account-export-form', false);
    }

    public function test_the_purge_deletes_the_export(): void
    {
        [$alice] = $this->owner('a', 1);
        $this->actingAs($alice)->post(route('backup.account.export'));
        $dir = app(AccountExports::class)->dir((int) $alice->account_id);
        $this->assertNotEmpty(glob($dir.'/*.zip'));

        app(AccountDeletionService::class)->request($alice);
        $this->travel(AccountDeletionService::GRACE_DAYS + 1)->days();
        app(AccountDeletionService::class)->purge(AccountDeletion::sole());
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_a_failed_job_removes_the_part_and_writes_a_failed_marker(): void
    {
        [$alice] = $this->owner('a', 1);
        $exports = app(AccountExports::class);
        $token = Str::lower(Str::random(40));
        $final = $exports->path((int) $alice->account_id, $token);
        @mkdir(dirname($final), 0700, true);
        file_put_contents($final.'.part', 'partial');
        $this->assertFileExists($final.'.part');

        (new BuildAccountExportJob((int) $alice->account_id, $token))->failed(new \RuntimeException('boom'));

        $this->assertFileDoesNotExist($final.'.part');
        $this->assertFileExists($final.'.failed');
    }

    public function test_a_stale_part_is_reported_failed_and_a_fresh_part_processing(): void
    {
        [$alice] = $this->owner('a', 1);
        $exports = app(AccountExports::class);
        $dir = $exports->dir((int) $alice->account_id);
        @mkdir($dir, 0700, true);
        $part = $dir.'/'.Str::lower(Str::random(40)).'.zip.part';

        file_put_contents($part, 'x');
        touch($part, time() - 40 * 60);
        $this->assertSame('failed', $exports->latest((int) $alice->account_id)['status']);

        touch($part, time() - 60);
        $this->assertSame('processing', $exports->latest((int) $alice->account_id)['status']);
    }
}
