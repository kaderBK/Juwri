<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\MoneyBugs4;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Models\GlJournalLine;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-208: KWD / BHD / JOD amounts (3 decimals, fils) are never cut to 2 decimals: GL balances and journal totals,
 * staff tips, and no leftover hard-coded round(…, 2) at the listed money sites. Golden numbers
 * end in 5 in the third decimal so a 2-decimal round shows (x.xx5 → x.x(x+1)0).
 */
class ThreeDecimalLeftoverRoundingTest extends TestCase
{
    use RefreshDatabase;

    private const D = 0.0000001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $currency): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    /** @return array<string, array{string}> */
    public static function currencies(): array
    {
        return ['KWD' => ['KWD'], 'BHD' => ['BHD'], 'JOD' => ['JOD']];
    }

    #[DataProvider('currencies')]
    public function test_gl_account_balance_and_journal_totals_keep_three_decimals(string $currency): void
    {
        $store = $this->store($currency);
        $expense = JournalEngine::account($store->id, '6200');
        $journal = GlJournal::create(['store_id' => $store->id, 'date' => now()->toDateString(), 'memo' => 'm', 'locked' => false]);
        GlJournalLine::create(['journal_id' => $journal->id, 'gl_account_id' => $expense->id, 'debit' => 1.235, 'credit' => 0]);
        GlJournalLine::create(['journal_id' => $journal->id, 'gl_account_id' => $expense->id, 'debit' => 2.12, 'credit' => 0]);
        GlJournalLine::create(['journal_id' => $journal->id, 'gl_account_id' => $expense->id, 'debit' => 0, 'credit' => 3.355]);

        $journal->load('lines');
        $this->assertEqualsWithDelta(3.355, $journal->totalDebit(), self::D); // 2 dp gave 3.36
        $this->assertEqualsWithDelta(3.355, $journal->totalCredit(), self::D);

        GlJournalLine::where('journal_id', $journal->id)->where('credit_minor', '>', 0)->delete();
        $this->assertEqualsWithDelta(3.355, $expense->fresh()->balance(), self::D);
    }

    public function test_usd_journal_still_rounds_to_cents(): void
    {
        $store = $this->store('USD');
        $expense = JournalEngine::account($store->id, '6200');
        $journal = GlJournal::create(['store_id' => $store->id, 'date' => now()->toDateString(), 'memo' => 'm', 'locked' => false]);
        GlJournalLine::create(['journal_id' => $journal->id, 'gl_account_id' => $expense->id, 'debit' => 1.25, 'credit' => 0]);
        $this->assertEqualsWithDelta(1.25, $journal->load('lines')->totalDebit(), self::D);
    }

    #[DataProvider('currencies')]
    public function test_staff_tips_summary_keeps_three_decimals(string $currency): void
    {
        $store = $this->store($currency);
        $staff = StaffMember::create([
            'account_id' => $store->fresh()->account_id,
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        foreach ([0.125, 0.25] as $tip) {
            Order::create(['store_id' => $store->id, 'type' => 'dine_in', 'status' => 'completed', 'customer_name' => 'G',
                'served_by_staff_id' => $staff->id, 'tip_amount' => $tip, 'created_at' => now()]);
        }

        $res = $this->actingAs($staff, 'staff')->getJson(route('staffapp.tips'));
        $res->assertOk();
        $this->assertEqualsWithDelta(0.375, (float) $res->json('tips'), self::D); // 2 dp gave 0.38
    }

    /** The listed money sites carry no hard-coded 2-decimal round any more. */
    public function test_no_hardcoded_two_decimal_round_at_the_listed_money_sites(): void
    {
        $files = [
            'app/Modules/Ordering/Http/Controllers/OrderHubController.php',
            'app/Modules/Customers/Http/Controllers/Api/V1/CustomerAppController.php',
            'app/Modules/Accounting/Models/GlAccount.php',
            'app/Modules/Accounting/Models/GlJournal.php',
            'app/Http/Controllers/StaffAppController.php',
            'app/Modules/Ordering/Http/Controllers/User/CateringPublicController.php',
            'app/Modules/Customers/Services/CustomerMergeService.php',
            'app/Modules/Customers/Services/Crm/GuestTimeline.php',
            'app/Modules/Accounting/Http/Controllers/User/GlAccountingController.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression('/(?<![\w>:])round\([^;]*,\s*2\)/', $src, $file.' still rounds money to 2 decimals');
        }
        $refund = (string) file_get_contents(base_path('app/Modules/Ordering/Http/Controllers/User/Concerns/ManagesOrders.php'));
        // SEC-1120: the default refund amount is now the capped refundable base, still rounded to the currency's decimals.
        $this->assertStringContainsString("money_round((float) (\$request->input('amount') ?: \$this->creditRefundCapBase(\$order)), money_decimals(\$currency))", $refund);
    }
}
