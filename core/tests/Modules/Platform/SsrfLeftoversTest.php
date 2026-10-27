<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Pci\PaymentPageMonitor;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P6-68b: SSRF leftovers — payment-page monitor script fetches, AI vault base_url, accounting sync pinning.
 * (Reseller SMTP pinning is covered in the Enterprise package's own tests.)
 */
class SsrfLeftoversTest extends TestCase
{
    use RefreshDatabase;

    private int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        SsrfGuard::$resolver = function (string $h): array {
            $this->lookups++;

            return match ($h) {
                'internal.example' => ['10.0.0.5'],
                'meta.example' => ['169.254.169.254'],
                // DNS rebinding: public for the check, private for any later lookup
                'rebind.example' => $this->lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'],
                default => ['93.184.216.34'],
            };
        };
    }

    private function fetchScript(string $src, string $page = 'https://shop.example/pay'): mixed
    {
        $m = new ReflectionMethod(PaymentPageMonitor::class, 'fetchScript');

        return $m->invoke(new PaymentPageMonitor, $src, $page);
    }

    // --- (b) PaymentPageMonitor script fetches --------------------------------

    public function test_monitor_refuses_private_and_metadata_script_hosts(): void
    {
        Http::fake();
        $this->assertNull($this->fetchScript('https://internal.example/a.js'));
        $this->assertNull($this->fetchScript('https://169.254.169.254/latest/meta-data/'));
        $this->assertNull($this->fetchScript('https://meta.example/x.js'));
        $this->assertNull($this->fetchScript('http://cdn.example/a.js')); // cross-host must be https
        Http::assertNothingSent();
    }

    public function test_monitor_fetches_public_and_same_origin_scripts(): void
    {
        Http::fake(['*' => Http::response('x', 200)]);
        $this->assertSame(200, $this->fetchScript('https://cdn.example/a.js')?->status());
        $this->assertSame(200, $this->fetchScript('https://shop.example/js/app.js')?->status());
        Http::assertSentCount(2);
    }

    public function test_monitor_pins_the_vetted_ip_so_rebinding_gets_no_second_lookup(): void
    {
        Http::fake(['*' => Http::response('x', 200)]);
        $this->assertNotNull($this->fetchScript('https://rebind.example/a.js'));
        $this->assertSame(1, $this->lookups); // pinned via CURLOPT_RESOLVE; no re-resolution at connect
        // a fresh check after the record flipped private is refused
        $this->assertNull($this->fetchScript('https://rebind.example/b.js'));
    }

    // --- (c) AI vault base_url --------------------------------------------------

    private function store(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function applied(?int $storeId): array
    {
        $m = new ReflectionMethod(AiProviderFactory::class, 'applyByok');

        return $m->invoke(app(AiProviderFactory::class), 'mimo', ['base_url' => 'https://default.example/v1', 'api_key' => 'k'], $storeId);
    }

    private function vault(?int $storeId, string $url): void
    {
        Credential::create([
            'store_id' => $storeId, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'byok', 'base_url' => $url],
        ]);
    }

    public function test_ai_vault_private_or_metadata_base_url_is_dropped(): void
    {
        foreach (['http://169.254.169.254/v1', 'https://internal.example/v1', 'https://127.0.0.1/v1', 'http://api.example/v1'] as $url) {
            Credential::query()->delete();
            $this->vault(null, $url);
            $this->assertSame('https://default.example/v1', $this->applied(null)['base_url'], $url);
        }
    }

    public function test_ai_vault_public_base_url_is_kept(): void
    {
        $s = $this->store();
        $this->vault($s->id, 'https://llm.example/v1');
        $this->assertSame('https://llm.example/v1', $this->applied($s->id)['base_url']);
    }

    public function test_ai_rebinding_base_url_refused_once_it_resolves_private(): void
    {
        $this->vault(null, 'https://rebind.example/v1');
        $this->assertSame('https://rebind.example/v1', $this->applied(null)['base_url']);
        $this->assertSame('https://default.example/v1', $this->applied(null)['base_url']); // re-vetted per call
    }

    public function test_ai_env_allow_lets_only_the_platform_row_use_a_private_host(): void
    {
        config(['ai.allow_private_base_url' => true]);
        $this->vault(null, 'http://10.0.0.9:11434/v1');
        $this->assertSame('http://10.0.0.9:11434/v1', $this->applied(null)['base_url']);

        $s = $this->store(); // merchant BYOK never gets the allowance
        $this->vault($s->id, 'http://10.0.0.9:11434/v1');
        $this->assertSame('https://default.example/v1', $this->applied($s->id)['base_url']);
    }

    // --- accounting sync (merchant base_url) --------------------------------------

    public function test_accounting_sync_refuses_private_base_url_at_send(): void
    {
        Http::fake();
        $s = $this->store();
        $order = Order::create(['store_id' => $s->id, 'status' => 'new', 'type' => 'takeaway']);
        Credential::create([
            'store_id' => $s->id, 'feature' => 'accounting', 'provider' => 'quickbooks', 'active' => true,
            'secrets' => ['provider' => 'quickbooks', 'access_token' => 'tok', 'base_url' => 'https://internal.example/api'],
        ]);

        (new SyncInvoiceJob($order->id))->handle();

        Http::assertNothingSent();
    }
}
