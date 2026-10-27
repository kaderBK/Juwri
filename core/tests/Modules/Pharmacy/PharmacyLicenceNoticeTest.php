<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyLicenceNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-45 (AUP 3.1): the public licence block on a pharmacy storefront. Shown only for a published pharmacy with an
 * APPROVED licence; only licence number, responsible pharmacist and CR number — never the expiry, authority, permit,
 * the pharmacist's registration, the document path or review data.
 */
class PharmacyLicenceNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['pharmacy.enabled_countries' => []]);
    }

    private function store(string $type = 'pharmacy', string $template = 'shelf'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#1f6feb',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    private function licence(Store $store, string $status): PharmacyLicence
    {
        $licence = PharmacyMarket::licence($store, $status);
        $licence->forceFill([
            'licence_number' => 'LIC-PUB-7781',
            'issuing_authority' => 'Secret Authority Zeta',
            'expires_on' => '2031-03-17',
            'responsible_person_name' => 'Dr Amal Pharmacist',
            'responsible_person_registration' => 'REG-HIDDEN-5521',
            'online_sales_permit' => 'PERMIT-HIDDEN-9902',
            'document_path' => 'pharmacy-licences/hidden-doc-3310.pdf',
            'review_note' => 'reviewer-note-hidden-4471',
        ])->save();
        StoreOption::updateStoreOption($store->id, 'fiscal_cr_number', 'CR-1010101010');
        config(['pharmacy.enabled_countries' => ['SA']]);

        return $licence;
    }

    public function test_approved_published_pharmacy_returns_only_the_allow_listed_fields(): void
    {
        $store = $this->store();
        $this->licence($store, PharmacyLicence::APPROVED);

        $block = app(PharmacyLicenceNotice::class)->forStore($store);

        $this->assertSame(['licence_number' => 'LIC-PUB-7781', 'pharmacist' => 'Dr Amal Pharmacist', 'cr_number' => 'CR-1010101010'], $block);
        $this->assertSame([], array_diff(array_keys($block), PharmacyLicenceNotice::FIELDS));
    }

    public function test_optional_rows_are_omitted_when_empty(): void
    {
        $store = $this->store();
        $licence = $this->licence($store, PharmacyLicence::APPROVED);
        $licence->forceFill(['responsible_person_name' => null])->save();
        StoreOption::updateStoreOption($store->id, 'fiscal_cr_number', '');

        $this->assertSame(['licence_number' => 'LIC-PUB-7781'], app(PharmacyLicenceNotice::class)->forStore($store));
    }

    public function test_pending_rejected_expired_missing_and_closed_market_show_nothing(): void
    {
        $notice = app(PharmacyLicenceNotice::class);
        foreach ([PharmacyLicence::PENDING, PharmacyLicence::REJECTED] as $status) {
            $store = $this->store();
            $this->licence($store, $status);
            $this->assertNull($notice->forStore($store), $status);
        }

        $expired = $this->store();
        $this->licence($expired, PharmacyLicence::APPROVED)->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();
        $this->assertNull($notice->forStore($expired), 'expired');

        $none = $this->store();
        config(['pharmacy.enabled_countries' => ['SA']]);
        $this->assertNull($notice->forStore($none), 'no licence');

        $closed = $this->store();
        $this->licence($closed, PharmacyLicence::APPROVED);
        config(['pharmacy.enabled_countries' => []]);
        $this->assertNull($notice->forStore($closed), 'unpublished market');
    }

    public function test_other_store_types_show_nothing(): void
    {
        $store = $this->store('restaurant');
        $this->licence($store, PharmacyLicence::APPROVED);

        $this->assertNull(app(PharmacyLicenceNotice::class)->forStore($store));
    }

    /** @return array<string, array{0: string}> */
    public static function templates(): array
    {
        return ['legacy shelf' => ['shelf'], 'legacy carely' => ['carely'], 'legacy crave' => ['crave'], 'kit table' => ['table']];
    }

    #[DataProvider('templates')]
    public function test_storefront_html_shows_the_block_and_no_disallowed_field(string $template): void
    {
        $store = $this->store('pharmacy', $template);
        $this->licence($store, PharmacyLicence::APPROVED);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-pharmacy-licence', $html, $template);
        foreach (['LIC-PUB-7781', 'Dr Amal Pharmacist', 'CR-1010101010', 'Licensed pharmacy'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        // the expiry date in any usual form; a bare '2031' also occurs by chance in tokens, ids and asset hashes (flaky)
        foreach (['2031-03-17', '17/03/2031', '03/17/2031', 'Mar 2031', 'March 2031', '17 Mar', 'Secret Authority Zeta', 'REG-HIDDEN-5521', 'PERMIT-HIDDEN-9902', 'hidden-doc-3310', 'reviewer-note-hidden-4471'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $template.' leaked '.$hidden);
        }
    }

    #[DataProvider('templates')]
    public function test_storefront_html_hides_the_block_unless_approved(string $template): void
    {
        $store = $this->store('pharmacy', $template);
        $this->licence($store, PharmacyLicence::PENDING);

        $res = $this->get(route('publicView', $store->slug));

        $this->assertStringNotContainsString('data-pharmacy-licence', $res->getContent());
        $this->assertStringNotContainsString('LIC-PUB-7781', $res->getContent());
    }

    public function test_arabic_page_is_rtl_with_arabic_labels(): void
    {
        $store = $this->store('pharmacy', 'shelf');
        $this->licence($store, PharmacyLicence::APPROVED);
        app()->setLocale('ar');

        $html = view('partials.pharmacy-licence', ['store' => $store])->render();

        $this->assertStringContainsString('رقم الترخيص', $html);
        $this->assertStringContainsString('الصيدلي المسؤول', $html);
        $this->assertStringContainsString('dir="auto"', $html);
        $this->assertDoesNotMatchRegularExpression('/(margin|padding|text-align)[^;]*\b(left|right)\b/', $html);
    }
}
