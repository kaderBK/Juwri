<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Money\Money;
use Tests\TestCase;

/**
 * P1-05 slice 9c: GatewayMinorUnits::fromMoney()/majorStringFromMoney()/majorFloatFromMoney() send exactly what
 * toMinor()/toMajorString()/toMajorFloat() send for the same stored amount — every currency in the `currencies`
 * table, boundary and random minor amounts, the gateway currency equal to the amount's or another one (the store's
 * currency moved), and through real saved transactions.
 */
class GatewayMinorUnitsMoneyTest extends TestCase
{
    use RefreshDatabase;

    private const MINORS = [0, 1, 4, 5, 6, 9, 10, 15, 45, 50, 55, 99, 100, 105, 125, 149, 150, 151, 995, 999, 1004, 1005,
        1234, 1235, 1245, 9995, 12345, 99995, 123456789, -1, -5, -15, -1235];

    private const GATEWAY_CURRENCIES = ['USD', 'JPY', 'KWD', 'MGA', 'CLF', 'ISK', 'IQD'];

    private function assertSameAmounts(Money $money, string $gateway, string $label): void
    {
        $major = $money->toFloat(); // what `$transaction->amount` hands the old helpers
        $this->assertSame(GatewayMinorUnits::toMinor($major, $gateway), GatewayMinorUnits::fromMoney($money, $gateway), "minor {$label}");
        $this->assertSame(GatewayMinorUnits::toMajorString($major, $gateway), GatewayMinorUnits::majorStringFromMoney($money, $gateway), "string {$label}");
        $this->assertSame(GatewayMinorUnits::toMajorFloat($major, $gateway), GatewayMinorUnits::majorFloatFromMoney($money, $gateway), "float {$label}");
    }

    public function test_every_currency_matches_the_float_helpers(): void
    {
        mt_srand(905);
        $codes = DB::table('currencies')->orderBy('code')->pluck('code')->all();
        $this->assertGreaterThan(150, count($codes));

        foreach ($codes as $code) {
            $minors = self::MINORS;
            for ($i = 0; $i < 40; $i++) {
                $minors[] = mt_rand(0, 99_999_999);
            }
            foreach ($minors as $minor) {
                $money = Money::ofMinor($minor, $code);
                foreach (array_unique([$code, ...self::GATEWAY_CURRENCIES]) as $gateway) {
                    $this->assertSameAmounts($money, $gateway, "{$code} {$minor} → {$gateway}");
                }
            }
        }
    }

    public function test_default_currency_null_amount_and_case(): void
    {
        $kwd = Money::ofMinor(1235, 'KWD');
        $this->assertSame(1240, GatewayMinorUnits::fromMoney($kwd));
        $this->assertSame('1.235', GatewayMinorUnits::majorStringFromMoney($kwd));
        $this->assertSame(1.235, GatewayMinorUnits::majorFloatFromMoney($kwd));
        $this->assertSame(1240, GatewayMinorUnits::fromMoney($kwd, ' kwd '));

        $this->assertSame(0, GatewayMinorUnits::fromMoney(null, 'USD'));
        $this->assertSame('0.00', GatewayMinorUnits::majorStringFromMoney(null, 'USD'));
        $this->assertSame(0.0, GatewayMinorUnits::majorFloatFromMoney(null, 'JPY'));
        $this->assertSame(GatewayMinorUnits::toMinor(null, 'KWD'), GatewayMinorUnits::fromMoney(null, 'KWD'));
    }

    public function test_saved_transactions_send_the_same_amount(): void
    {
        $user = User::factory()->create();
        $cases = [[12.34, 'USD'], [1250, 'JPY'], [1.235, 'KWD'], [12.345, 'OMR'], [12.5, 'MGA'], [1.2345, 'CLF'],
            [7, 'ISK'], [9.995, 'BHD'], [0.005, 'JOD'], [1234.56, 'NGN']];
        foreach ($cases as [$amount, $currency]) {
            $tx = Transaction::create(['user_id' => $user->id, 'amount' => $amount, 'base_amount' => $amount,
                'currency_code' => $currency, 'transaction_method' => 'wallet_topup', 'product_name' => 'x']);
            $tx = Transaction::find($tx->id);
            foreach (array_unique([$currency, ...self::GATEWAY_CURRENCIES]) as $gateway) {
                $label = "{$currency} {$amount} → {$gateway}";
                $this->assertSame(GatewayMinorUnits::toMinor($tx->amount, $gateway), GatewayMinorUnits::fromMoney($tx->money('amount'), $gateway), $label);
                $this->assertSame(GatewayMinorUnits::toMajorString($tx->amount, $gateway), GatewayMinorUnits::majorStringFromMoney($tx->money('amount'), $gateway), $label);
                $this->assertSame(GatewayMinorUnits::toMajorFloat($tx->amount, $gateway), GatewayMinorUnits::majorFloatFromMoney($tx->money('amount'), $gateway), $label);
            }
        }
    }
}
