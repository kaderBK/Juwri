<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\Expense;
use Juwri\Modules\Media\Services\StorageQuota;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-005 (part A): the PDF upload paths that sit outside the media library must
 * respect the per-owner StorageQuotas counter — the Page View display PDF, the
 * Folio flip-book PDF and an accounting expense receipt. On refusal nothing is
 * written and no bytes are counted; on success the file lands and the owner's
 * tracked usage grows by exactly the uploaded size.
 */
class PdfStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    /** Files the Folio path moved into the real public storage; unlinked in tearDown. */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        // Bypass auth/CSRF/feature/ratelimit gates; auth() is still set by actingAs().
        $this->withoutMiddleware();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];
        parent::tearDown();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function store(User $owner, ?string $template = null): Store
    {
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($template !== null) {
            StoreOption::updateStoreOption($store->id, 'store_template', $template);
        }

        return $store;
    }

    /** Pin the owner over a 1 MB cap with the counter already full, in enforce mode. */
    private function exhaust(User $owner): void
    {
        config([
            'settings.abuse_storage_mode' => 'enforce',
            'settings.abuse_storage_tenant_cap_mb' => 1,
        ]);
        StorageQuota::put($owner->id, 1024 * 1024);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('menu.pdf', 120, 'application/pdf');
    }

    // ── Page View display PDF (pageviewSource) ─────────────────────────────────

    public function test_pageview_pdf_is_refused_when_the_storage_cap_is_exhausted(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->exhaust($owner);
        $before = StorageQuota::used($owner->id);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/pageview-source", [
            'source' => 'pdf',
            'pdf' => $this->pdf(),
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertEmpty((string) (store_options($store->id)->restaurant_pageview_pdf ?? ''));
        $this->assertSame([], Storage::disk('public')->files('pageview'));
        $this->assertSame($before, StorageQuota::used($owner->id), 'a refused upload is never counted');
    }

    public function test_pageview_pdf_is_stored_and_counted_when_there_is_room(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $store = $this->store($owner);
        $before = StorageQuota::used($owner->id);
        $file = $this->pdf();
        $size = $file->getSize();

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/pageview-source", [
            'source' => 'pdf',
            'pdf' => $file,
        ])->assertOk()->assertJsonPath('ok', true);

        $stored = (string) (store_options($store->id)->restaurant_pageview_pdf ?? '');
        $this->assertNotEmpty($stored);
        Storage::disk('public')->assertExists($stored);
        $this->assertSame($before + $size, StorageQuota::used($owner->id));
    }

    // ── Folio flip-book PDF (folioPdf) ─────────────────────────────────────────

    public function test_folio_pdf_is_refused_when_the_storage_cap_is_exhausted(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');
        $this->exhaust($owner);
        $before = StorageQuota::used($owner->id);

        $this->actingAs($owner)->post("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf',
            'pdf' => $this->pdf(),
        ], ['Accept' => 'text/html'])
            ->assertRedirect()->assertSessionHas('app_alert_type', 'error');

        $this->assertEmpty((string) (store_options($store->id)->restaurant_folio_pdf ?? ''));
        $this->assertSame($before, StorageQuota::used($owner->id), 'a refused upload is never counted');
    }

    public function test_folio_pdf_is_stored_and_counted_when_there_is_room(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');
        $before = StorageQuota::used($owner->id);
        $file = $this->pdf();
        $size = $file->getSize();

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf',
            'pdf' => $file,
        ])->assertOk()->assertJsonPath('success', true);

        $stored = (string) (store_options($store->id)->restaurant_folio_pdf ?? '');
        $this->createdFiles[] = public_path('storage/'.$stored);
        $this->assertStringStartsWith('folio-pdf/', $stored);
        $this->assertFileExists(public_path('storage/'.$stored));
        $this->assertSame($before + $size, StorageQuota::used($owner->id));
    }

    // ── Accounting expense receipt (storeExpense) ──────────────────────────────

    public function test_expense_receipt_is_refused_when_the_storage_cap_is_exhausted(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->exhaust($owner);
        $before = StorageQuota::used($owner->id);

        $this->actingAs($owner)->post(route('stores.books.expenses.store').'?store_id='.$store->id, [
            'amount' => 100,
            'date' => now()->toDateString(),
            'receipt' => $this->pdf(),
        ])->assertRedirect()->assertSessionHasErrors('receipt');

        $this->assertSame(0, Expense::where('store_id', $store->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($before, StorageQuota::used($owner->id), 'a refused upload is never counted');
    }

    public function test_expense_receipt_is_stored_and_counted_when_there_is_room(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $store = $this->store($owner);
        $before = StorageQuota::used($owner->id);
        $file = $this->pdf();
        $size = $file->getSize();

        $this->actingAs($owner)->post(route('stores.books.expenses.store').'?store_id='.$store->id, [
            'amount' => 100,
            'date' => now()->toDateString(),
            'receipt' => $file,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = Expense::where('store_id', $store->id)->first();
        $this->assertNotNull($expense);
        $this->assertNotEmpty($expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->assertSame($before + $size, StorageQuota::used($owner->id));
    }
}
