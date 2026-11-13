<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\StaffDocument;
use Juwri\Modules\Hr\Models\StaffOnboardingItem;
use Juwri\Modules\Hr\Models\StaffRoleRate;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN H-P1: employee records, documents, rates, onboarding.
 */
class HrmEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_hrm_suite' => true]);
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function ownerStaff(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Sam', 'email' => 's'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true,
        ]);

        return [$owner, $r, $staff];
    }

    public function test_profile_page_renders_and_seeds_onboarding(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('staff.hrm.show', $staff->id))->assertOk()->assertSeeHtml(___('Onboarding'));
        $this->assertSame(count(StaffOnboardingItem::TEMPLATE), StaffOnboardingItem::where('staff_id', $staff->id)->count());
    }

    public function test_update_profile_and_rate(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('staff.hrm.profile', $staff->id), [
            'national_id' => '1122', 'contract_type' => 'full_time', 'base_wage' => 3000,
        ])->assertRedirect();
        $this->assertSame('1122', $staff->fresh()->national_id);

        $this->actingAs($owner)->post(route('staff.hrm.rate', $staff->id), ['role' => 'waiter', 'hourly_rate' => 25])->assertRedirect();
        $this->assertEqualsWithDelta(25.0, (float) StaffRoleRate::where('staff_id', $staff->id)->where('role', 'waiter')->first()->hourly_rate, 0.01);
    }

    /**
     * SECURITY: documents land on the private 'local' disk (never storage/app/public,
     * which is webroot-symlinked) and only the owning tenant can stream them back.
     */
    public function test_documents_are_private_and_owner_scoped(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        [$other] = $this->ownerStaff();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('staff.hrm.documents.store', $staff->id), [
            'kind' => 'id',
            'file' => UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $doc = StaffDocument::where('staff_id', $staff->id)->firstOrFail();
        $this->assertFileExists(storage_path('app/'.$doc->path));
        $this->assertFileDoesNotExist(storage_path('app/public/'.$doc->path));

        $this->actingAs($owner)->get(route('staff.hrm.documents.download', [$staff->id, $doc->id]))->assertOk();
        // Another tenant cannot reach it.
        $this->actingAs($other)->get(route('staff.hrm.documents.download', [$staff->id, $doc->id]))->assertNotFound();
        // Unauthenticated cannot reach it either.
        auth()->logout();
        $res = $this->get(route('staff.hrm.documents.download', [$staff->id, $doc->id]));
        $this->assertContains($res->getStatusCode(), [302, 401, 403, 404]);

        @unlink(storage_path('app/'.$doc->path));
    }

    public function test_document_upload_and_expiry_command(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$owner, $r, $staff] = $this->ownerStaff();
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('staff.hrm.documents.store', $staff->id), [
            'kind' => 'health_cert', 'expires_at' => now()->addDays(10)->toDateString(),
            'file' => UploadedFile::fake()->create('cert.pdf', 40, 'application/pdf'),
        ])->assertRedirect();
        $this->assertSame(1, StaffDocument::where('staff_id', $staff->id)->count());

        $this->artisan('staff:documents-expiry-alert', ['--days' => 30])->assertExitCode(0);
    }

    public function test_onboarding_toggle_progress(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('staff.hrm.show', $staff->id)); // seeds
        $item = StaffOnboardingItem::where('staff_id', $staff->id)->first();
        $this->actingAs($owner)->post(route('staff.hrm.onboarding.toggle', [$staff->id, $item->id]))->assertRedirect();
        $this->assertTrue($item->fresh()->done);
    }

    public function test_tenant_scope(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        [$other, $otherR, $otherStaff] = $this->ownerStaff();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('staff.hrm.show', $otherStaff->id))->assertNotFound();
    }
}
