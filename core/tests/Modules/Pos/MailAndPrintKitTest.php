<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Tables\Services\TableSessionService;
use Tests\TestCase;

/**
 * WI-16d: every HTML email renders on the shared <x-mail.frame> (table layout,
 * inline colours, dark-mode tolerant) and every printed sheet on <x-ui.print>
 * (kit print.css, @page, toolbar hidden in print). The mail variables and
 * merge data each Mailable passes must still reach the body.
 */
class MailAndPrintKitTest extends TestCase
{
    /** view name => sample data (the keys each Mailable passes) */
    private function mails(): array
    {
        $card = (object) ['initial_value' => 50, 'currency' => 'SAR', 'message' => 'Enjoy!', 'code' => 'GIFT-7788', 'expires_at' => '2026-12-31'];

        return [
            'emails.weekly-digest' => [[
                'name' => 'Nadia', 'totalScans' => 1200, 'uniqueVisitors' => 900, 'totalRevenue' => 345.5,
                'ordersCount' => 42, 'conversionRate' => 3.5, 'currencySymbol' => '$', 'currencyDecimals' => 2,
            ], ['Nadia', '1,200', '$345.50', '3.5%']],
            'emails.campaign' => [[
                'subject' => 'Spring menu', 'body' => '<p>New dishes are in.</p>', 'unsubscribeUrl' => 'https://example.test/u/abc',
            ], ['Spring menu', '<p>New dishes are in.</p>', 'https://example.test/u/abc']],
            'billing::emails.addon-renewal-failed' => [[
                'name' => 'Omar', 'addonLabel' => 'Extra branch', 'amount' => '$9', 'addonsUrl' => 'https://example.test/addons',
            ], ['Omar', '<strong>Extra branch</strong>', 'https://example.test/addons']],
            'emails.dunning' => [[
                'name' => 'Lina', 'graceUntil' => '2026-10-10', 'billingUrl' => 'https://example.test/billing',
            ], ['Lina', '<strong>2026-10-10</strong>', 'https://example.test/billing']],
            'emails.anomaly-alert' => [[
                'name' => 'Sam', 'currentScans' => 12, 'baselineAverageScans' => 40.0, 'dropPercent' => 70.0,
                'restaurantCount' => 2, 'comparisonWindow' => 7,
            ], ['Sam', '70.0', '40.0']],
            'emails.customer-claim-verify' => [[
                'restaurantName' => 'Cafe Zed', 'verifyUrl' => 'https://example.test/verify/xyz', 'customerName' => 'Ali',
            ], ['Cafe Zed', 'https://example.test/verify/xyz', 'Ali']],
            'emails.giftcard' => [[
                'card' => $card, 'restaurantName' => 'Cafe Zed', 'storeUrl' => 'https://example.test/s/zed',
            ], ['GIFT-7788', '50.00 SAR', 'Enjoy!', 'https://example.test/s/zed']],
            'billing::emails.subscription-reminder' => [[
                'name' => 'Rami', 'stage' => 'expiring_3d', 'daysLeft' => 3, 'renewUrl' => 'https://example.test/renew',
            ], ['Rami', 'https://example.test/renew']],
            'reseller::reseller.portal.emails.notification' => [[
                'title' => 'Invoice paid', 'brand' => 'Partner Co', 'platform' => 'juwri', 'recipientName' => 'Olivia',
                'lines' => [['label' => 'Invoice', 'value' => 'INV-42']], 'actionUrl' => 'https://example.test/p',
                'prefsUrl' => 'https://example.test/prefs', 'dir' => 'ltr',
            ], ['Invoice paid', 'Partner Co', 'Olivia', 'INV-42', 'https://example.test/prefs']],
        ];
    }

    public function test_every_html_email_renders_on_the_mail_frame_with_its_data(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        if (! Route::has('analytics.index')) {
            Route::get('/_t/analytics', fn () => '')->name('analytics.index');
        }

        foreach ($this->mails() as $view => [$data, $needles]) {
            $html = view($view, $data)->render();

            $this->assertStringContainsString('class="jm-card"', $html, "$view is not on <x-mail.frame>");
            $this->assertStringContainsString('name="color-scheme" content="light dark"', $html, $view);
            $this->assertStringContainsString('role="presentation"', $html, $view);
            $this->assertStringNotContainsString('<link', $html, "$view must not depend on external CSS");
            $this->assertStringNotContainsString('<script', $html, $view);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $html, "$view lost `$needle`");
            }
        }
    }

    public function test_admin_email_template_fills_every_merge_tag(): void
    {
        $html = view('emails.default', [
            'body' => '<p>Hi {USER_FULLNAME}, open {LINK} — {USER_FULLNAME}</p>',
            'short_codes' => ['{USER_FULLNAME}' => 'Mona', '{LINK}' => 'https://example.test/x'],
        ])->render();

        $this->assertStringContainsString('<p>Hi Mona, open https://example.test/x — Mona</p>', $html);
        $this->assertStringContainsString('<b>ok</b>', view('emails.default', ['body' => '<b>ok</b>', 'short_codes' => []])->render());
    }

    public function test_mail_frame_follows_the_locale_direction(): void
    {
        App::setLocale('ar');
        try {
            $html = view('emails.dunning', ['name' => 'X', 'graceUntil' => null, 'billingUrl' => 'https://example.test/b'])->render();
        } finally {
            App::setLocale('en');
        }
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('text-align:right', $html);
    }

    public function test_print_sheets_render_on_the_kit_print_page(): void
    {
        $store = (object) ['id' => 1, 'title' => 'Cafe Zed'];
        $tables = collect([(object) ['key' => 'k1', 'table_no' => 4, 'verify_code' => '4321']]);
        $html = view('owner.tables.tent-cards', [
            'store' => $store, 'tables' => $tables, 'mode' => TableSessionService::MODE_TABLE_CODE,
        ])->render();
        $this->assertKitPrint($html);
        $this->assertStringContainsString('ui-print__cell--cut', $html);
        $this->assertStringContainsString('4321', $html);

        $rooms = collect([(object) ['key' => 'r1', 'table_no' => 207, 'label' => 'Sea view', 'floor_label' => 'Floor 2']]);
        $html = view('owner.hotel.rooms-print', ['store' => $store, 'rooms' => $rooms])->render();
        $this->assertKitPrint($html);
        $this->assertStringContainsString('#207', $html);
        $this->assertStringContainsString('Sea view', $html);

        $item = (object) ['staff' => (object) ['name' => 'Huda'], 'worked_hours' => 40, 'leave_hours' => 0, 'overtime_hours' => 2,
            'base_pay' => 800, 'overtime_pay' => 60, 'tips' => 15, 'bonuses' => 0, 'deductions' => 10, 'net' => 865];
        $run = (object) ['period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth()];
        $html = view('owner.team.payroll.payslip', ['item' => $item, 'run' => $run])->render();
        $this->assertKitPrint($html);
        $this->assertStringContainsString('865.00', $html);
        $this->assertStringContainsString('-10.00', $html);
    }

    public function test_pos_prints_use_the_thermal_paper_and_open_the_print_dialog(): void
    {
        foreach (['staff/pos/receipt.blade.php', 'staff/pos/ticket.blade.php'] as $file) {
            $src = file_get_contents(resource_path('views/'.$file));
            $this->assertStringContainsString('<x-ui.print', $src, $file);
            $this->assertStringContainsString('paper="receipt"', $src, $file);
            $this->assertStringContainsString('autoprint', $src, $file);
            $this->assertStringNotContainsString('onload=', $src, "$file: inline handlers are blocked by CSP");
            $this->assertStringNotContainsString('onclick=', $src, $file);
        }
    }

    public function test_no_email_or_print_view_is_left_on_the_bought_theme(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/templates/lamar/emails'));
        $this->assertDirectoryDoesNotExist(resource_path('views/templates/lamar/prints'));
        $this->assertFileDoesNotExist(resource_path('views/templates/lamar/layouts/mail.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/templates/lamar/layouts/print.blade.php'));
        $this->assertFileExists(public_path('assets/owner-ui/print.css'));
    }

    private function assertKitPrint(string $html): void
    {
        $this->assertStringContainsString('class="ui-print ', $html);
        $this->assertStringContainsString('assets/owner-ui/print.css', $html);
        $this->assertStringContainsString('data-ui-print', $html);
        $this->assertStringContainsString('@page', $html);
    }
}
