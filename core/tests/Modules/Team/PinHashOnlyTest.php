<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Support\KioskPin;
use Tests\TestCase;

/**
 * P6-88: staff + kiosk PINs are hash-only. Legacy plaintext logs in once and is rehashed (plaintext nulled);
 * compares are constant time; wrong PINs lock PIN sign-in per store; staff:hash-legacy-pins hashes the rest
 * (dry run changes nothing, a second run finds nothing).
 */
class PinHashOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::create(['name' => 'O', 'email' => Str::lower(Str::random(8)).'@test.dev', 'password' => bcrypt('secret')]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, array $attrs): StaffMember
    {
        return StaffMember::create($attrs + [
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'S',
            'email' => Str::lower(Str::random(6)).'@t.dev', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
    }

    public function test_legacy_plaintext_pin_logs_in_once_then_is_hash_only(): void
    {
        $r = $this->store();
        $s = $this->staff($r, ['pin' => '2580']);

        $this->assertSame($s->id, StaffMember::authenticateByPin($r->id, '2580')?->id);
        $s->refresh();
        $this->assertNull($s->pin);
        $this->assertTrue(Hash::check('2580', (string) $s->pin_hash));
        $this->assertNotNull($s->pin_offline_kdf);
        // second login goes through the hash
        $this->assertSame($s->id, StaffMember::authenticateByPin($r->id, '2580')?->id);
    }

    public function test_wrong_pin_fails_and_leaves_plaintext_row_untouched(): void
    {
        $r = $this->store();
        $s = $this->staff($r, ['pin' => '2580']);

        $this->assertNull(StaffMember::authenticateByPin($r->id, '2581'));
        $this->assertNull(StaffMember::authenticateByPin($r->id, '258'));
        $s->refresh();
        $this->assertSame('2580', $s->pin);
        $this->assertNull($s->pin_hash);
    }

    public function test_compares_are_constant_time(): void
    {
        $model = (string) file_get_contents(app_path('Models/StaffMember.php'));
        $this->assertStringContainsString('Hash::check($pin, $staff->pin_hash)', $model);
        $this->assertStringContainsString('hash_equals((string) $staff->pin, $pin)', $model);
        $kiosk = (string) file_get_contents(app_path('Modules/Pos/Support/KioskPin.php'));
        $this->assertStringContainsString('Hash::check($input, $stored)', $kiosk);
        $this->assertStringContainsString('hash_equals($stored, $input)', $kiosk);
        $this->assertDoesNotMatchRegularExpression('/->pin\s*===?|===?\s*\$pin\b/', $model);
    }

    public function test_wrong_pins_lock_pin_login_for_the_store(): void
    {
        $r = $this->store();
        $this->staff($r, ['pin_hash' => Hash::make('4321')]);

        for ($i = 0; $i < StaffMember::PIN_MAX_FAILURES; $i++) {
            $this->assertNull(StaffMember::authenticateByPin($r->id, '000'.$i));
        }
        $this->assertTrue(StaffMember::pinLoginLocked($r->id));
        $this->assertNull(StaffMember::authenticateByPin($r->id, '4321'), 'locked: even the right PIN is refused');

        $other = $this->store();
        $this->staff($other, ['pin_hash' => Hash::make('4321')]);
        $this->assertNotNull(StaffMember::authenticateByPin($other->id, '4321'), 'lock is per store');
    }

    public function test_command_hashes_staff_and_kiosk_pins_and_is_idempotent(): void
    {
        $r = $this->store();
        $legacy = $this->staff($r, ['pin' => '1357']);
        $both = $this->staff($r, ['pin' => '9999', 'pin_hash' => Hash::make('2468')]); // stale plaintext next to a newer hash
        $hashedOnly = $this->staff($r, ['pin_hash' => Hash::make('1111')]);
        $hashBefore = $hashedOnly->pin_hash;
        StoreOption::updateStoreOption($r->id, 'kiosk_exit_pin', '0042');
        StoreOption::updateStoreOption($r->id, 'kiosk_setup_pin', '777777');

        $this->artisan('staff:hash-legacy-pins', ['--dry-run' => true])
            ->expectsOutputToContain('Would hash 2 staff PIN(s) and 2 kiosk PIN(s).')->assertExitCode(0);
        $this->assertSame('1357', $legacy->fresh()->pin);
        $this->assertSame('0042', KioskPin::stored($r->id, 'kiosk_exit_pin'));

        $this->artisan('staff:hash-legacy-pins')->expectsOutputToContain('Hashed 2 staff PIN(s) and 2 kiosk PIN(s).')->assertExitCode(0);
        $legacy->refresh();
        $both->refresh();
        $this->assertNull($legacy->pin);
        $this->assertTrue(Hash::check('1357', (string) $legacy->pin_hash));
        $this->assertNull($both->pin);
        $this->assertTrue(Hash::check('2468', (string) $both->pin_hash), 'an existing hash wins over stale plaintext');
        $this->assertSame($hashBefore, $hashedOnly->fresh()->pin_hash);
        $this->assertTrue(KioskPin::isHashed(KioskPin::stored($r->id, 'kiosk_exit_pin')));
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_exit_pin', '0042'));
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_setup_pin', '777777'));

        $this->artisan('staff:hash-legacy-pins')->expectsOutputToContain('Hashed 0 staff PIN(s) and 0 kiosk PIN(s).')->assertExitCode(0);
        $this->artisan('staff:hash-pins')->assertExitCode(0); // old name still works
    }

    public function test_kiosk_legacy_plaintext_pin_is_rehashed_on_use(): void
    {
        $r = $this->store();
        StoreOption::updateStoreOption($r->id, 'kiosk_exit_pin', '4321');

        $this->assertFalse(KioskPin::verify($r->id, 'kiosk_exit_pin', '1234'));
        $this->assertSame('4321', KioskPin::stored($r->id, 'kiosk_exit_pin'));
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_exit_pin', '4321'));
        $this->assertTrue(KioskPin::isHashed(KioskPin::stored($r->id, 'kiosk_exit_pin')));
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_exit_pin', '4321'));
        $this->assertFalse(KioskPin::verify($r->id, 'kiosk_exit_pin', ''));
        $this->assertFalse(KioskPin::verify($r->id, 'kiosk_setup_pin', '4321'), 'no PIN set: nothing matches');
    }
}
