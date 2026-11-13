<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * WA-T1: the storefront chat number, resolved once instead of open-coded in 16 themes.
 *
 * Every keeper theme had its own copy of
 * `preg_replace('/\D+/','', @$postOptions->whatsapp_number ?: $store->phone)`,
 * which is exactly why the branch system never reached the chat button.
 */
class StorefrontWhatsAppNumberTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $phone = '+1 (555) 000-1234'): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Number Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => $phone,
        ]);
    }

    public function test_the_branch_number_wins(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => '+44 7700 900201']);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+99 9999 999999');

        // The whole point of the migration: the branch is authoritative.
        $this->assertSame('447700900201', storefront_whatsapp_number($store));
    }

    public function test_it_falls_back_to_the_legacy_option_and_logs_that_read(): void
    {
        Cache::flush();
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+44 7700 900201');

        $this->assertSame('447700900201', storefront_whatsapp_number($store));

        // Lane E is waiting for this counter to stay at zero.
        $this->assertSame(
            1,
            (int) (PlatformSettings::stored('whatsapp_legacy_read_count') ?: 0),
            'a legacy fallback must be recorded'
        );
    }

    public function test_it_falls_back_to_the_store_phone_last(): void
    {
        $store = $this->store('+1 (555) 000-1234');
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);

        $this->assertSame('15550001234', storefront_whatsapp_number($store));
    }

    public function test_using_the_store_phone_does_not_log_a_legacy_read(): void
    {
        Cache::flush();
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);

        storefront_whatsapp_number($store);

        // Only a real legacy-option read counts, or the evidence is worthless.
        $this->assertSame(0, (int) (PlatformSettings::stored('whatsapp_legacy_read_count') ?: 0));
    }

    public function test_a_specific_branch_can_be_asked_for(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => '+11111111111']);
        $second = Branch::create([
            'store_id' => $store->id, 'name' => 'Second', 'whatsapp' => '+22222222222',
            'active' => true, 'position' => 1,
        ]);

        $this->assertSame('22222222222', storefront_whatsapp_number($store, $second));
    }

    public function test_it_returns_empty_when_there_is_nothing_to_chat_to(): void
    {
        $store = $this->store('');
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);

        // Themes render the button only when this is non-empty.
        $this->assertSame('', storefront_whatsapp_number($store));
    }

    public function test_it_strips_every_non_digit(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => '+44 (0) 7700-900 201']);

        // wa.me accepts digits only.
        $this->assertSame('4407700900201', storefront_whatsapp_number($store));
    }

    public function test_a_missing_store_is_not_fatal(): void
    {
        $this->assertSame('', storefront_whatsapp_number(null));
        $this->assertSame('', storefront_whatsapp_number(999999999));
    }

    /**
     * WA-T2: no surviving theme may read the legacy option directly again.
     *
     * This is the regression guard: the reason the branch system never reached the
     * storefront chat button was 16 themes each open-coding their own lookup.
     */
    public function test_no_keeper_theme_reads_the_legacy_option_directly(): void
    {
        $keepers = KeptTemplates::firstParty();

        $offenders = [];
        foreach ($keepers as $theme) {
            $dir = resource_path('views/store_templates/'.$theme);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                if (str_contains(file_get_contents($file->getPathname()), 'postOptions->whatsapp_number')) {
                    $offenders[] = $theme.'/'.$file->getFilename();
                }
            }
        }

        $this->assertSame([], $offenders,
            'these keeper themes still read whatsapp_number directly instead of storefront_whatsapp_number()');
    }

    /**
     * WA-T3: no surviving theme may window.open() the WhatsApp URL again.
     *
     * iOS Safari blocks a popup opened after an async POST — the order was placed
     * and nothing opened. Twelve keeper themes did exactly that. They now call
     * mbWhatsAppHandoff(), which detects the block, shows a real tappable link and
     * navigates the top-level window (which no blocker can stop).
     */
    public function test_no_keeper_theme_popups_the_whatsapp_url(): void
    {
        $keepers = KeptTemplates::MODERN;

        $offenders = [];
        foreach ($keepers as $theme) {
            $dir = base_path('../assets/store_templates/'.$theme);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.js')) {
                    continue;
                }
                foreach (explode("\n", file_get_contents($file->getPathname())) as $line) {
                    if (str_contains($line, 'window.open') && str_contains($line, 'whatsapp_url')) {
                        $offenders[] = $theme.'/'.$file->getFilename();
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            'these keeper themes still window.open() the WhatsApp URL — use mbWhatsAppHandoff()');
    }
}
