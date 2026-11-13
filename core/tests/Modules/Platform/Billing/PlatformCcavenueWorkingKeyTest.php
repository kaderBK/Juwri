<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Billing;

use Juwri\Core\Providers\SettingsServiceProvider;
use Juwri\Modules\Payments\Services\Gateways\CcavenueAdapter;
use App\Support\PlatformSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Juwri\Core\Models\PlatformSetting;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * The platform CCAvenue working key is a PlatformSecrets key: encrypted at
 * rest, decrypted into config('settings.*'), legacy plaintext still read.
 */
class PlatformCcavenueWorkingKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_ccavenue_working_key_is_encrypted_at_rest(): void
    {
        PlatformSettings::flush();
        $this->assertTrue(PlatformSecrets::isSecret('CCAVENUE_WORKING_KEY'));

        PlatformSettings::set('CCAVENUE_WORKING_KEY', 'ccav-working-key-0123456789ABCDEF');

        $raw = PlatformSettings::stored('CCAVENUE_WORKING_KEY');
        $this->assertNotSame('ccav-working-key-0123456789ABCDEF', $raw);
        $this->assertSame('ccav-working-key-0123456789ABCDEF', Crypt::decryptString($raw));

        config(['app.installed' => true]);
        new SettingsServiceProvider($this->app)->boot(request());
        $this->assertSame('ccav-working-key-0123456789ABCDEF', config('settings.CCAVENUE_WORKING_KEY'));

        // The platform return still decrypts CCAvenue's payload with it.
        $plain = 'order_id=1&order_status=Aborted';
        $enc = CcavenueAdapter::encrypt($plain, 'ccav-working-key-0123456789ABCDEF');
        $this->assertSame($plain, CcavenueAdapter::decrypt($enc, (string) config('settings.CCAVENUE_WORKING_KEY')));
    }

    public function test_a_plaintext_working_key_saved_before_the_change_still_works(): void
    {
        PlatformSetting::query()->updateOrCreate(['name' => 'CCAVENUE_WORKING_KEY'], ['value' => json_encode('legacy-plain-key')]);
        PlatformSettings::flush();

        $this->assertSame('legacy-plain-key', PlatformSettings::get('CCAVENUE_WORKING_KEY', false));
        $this->assertSame('legacy-plain-key', PlatformSecrets::decrypt('legacy-plain-key'));
    }
}
