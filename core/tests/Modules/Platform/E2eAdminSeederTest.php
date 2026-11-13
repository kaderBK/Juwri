<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\E2eAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * H14: the nightly-e2e superadmin seeder. Runs on sqlite (NOT #[Group('mysql')])
 * — it exercises the two hard guards (no default password, refuses production)
 * that stop this from being a backdoor.
 */
class E2eAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private ?string $origEmail;

    private ?string $origPass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origEmail = getenv('E2E_ADMIN_EMAIL') === false ? null : getenv('E2E_ADMIN_EMAIL');
        $this->origPass = getenv('E2E_ADMIN_PASS') === false ? null : getenv('E2E_ADMIN_PASS');
    }

    protected function tearDown(): void
    {
        foreach (['E2E_ADMIN_EMAIL' => $this->origEmail, 'E2E_ADMIN_PASS' => $this->origPass] as $k => $v) {
            if ($v === null) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
        parent::tearDown();
    }

    private function setEnv(string $email, string $pass): void
    {
        putenv("E2E_ADMIN_EMAIL=$email");
        putenv("E2E_ADMIN_PASS=$pass");
        $_ENV['E2E_ADMIN_EMAIL'] = $email;
        $_ENV['E2E_ADMIN_PASS'] = $pass;
        // env() resolves $_SERVER before $_ENV, and a worktree .env can seed
        // $_SERVER at boot — set it here too so the override actually takes.
        $_SERVER['E2E_ADMIN_EMAIL'] = $email;
        $_SERVER['E2E_ADMIN_PASS'] = $pass;
    }

    private function clearEnv(): void
    {
        putenv('E2E_ADMIN_EMAIL');
        putenv('E2E_ADMIN_PASS');
        unset($_ENV['E2E_ADMIN_EMAIL'], $_ENV['E2E_ADMIN_PASS'], $_SERVER['E2E_ADMIN_EMAIL'], $_SERVER['E2E_ADMIN_PASS']);
    }

    public function test_it_creates_an_admin_from_env(): void
    {
        $this->setEnv('e2e-admin@ci.invalid', 'throwaway-pass');

        (new E2eAdminSeeder)->run();

        $user = User::where('email', 'e2e-admin@ci.invalid')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('throwaway-pass', $user->password));
    }

    public function test_it_refuses_without_credentials(): void
    {
        $this->clearEnv();

        $this->expectException(\RuntimeException::class);
        (new E2eAdminSeeder)->run();
    }

    public function test_it_refuses_in_production(): void
    {
        $this->setEnv('e2e-admin@ci.invalid', 'throwaway-pass');
        $this->app['env'] = 'production';

        try {
            $this->expectException(\RuntimeException::class);
            (new E2eAdminSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
