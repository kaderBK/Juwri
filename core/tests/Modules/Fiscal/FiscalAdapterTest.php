<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Contracts\PeppolAccessPoint;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Contracts\FiscalDriver;
use Juwri\Modules\Fiscal\Services\Eta\EtaDriver;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraBuilder;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraDriver;
use Juwri\Modules\Fiscal\Services\PintAe\PintAeAdapter;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDriver;
use Tests\TestCase;

/**
 * P1-08b follow-up (J25-8): FiscalAdapter — one adapter per country from config('fiscal.adapters'), FiscalDriver
 * kept as a deprecated alias, UAE PINT AE stub registered but disabled by default, Fiscal never reads an order.
 */
class FiscalAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): FiscalDriverManager
    {
        $this->app->forgetInstance(FiscalDriverManager::class);

        return $this->app->make(FiscalDriverManager::class);
    }

    public function test_one_adapter_per_country_and_the_old_driver_name_still_matches(): void
    {
        $m = $this->manager();
        $this->assertInstanceOf(ZatcaDriver::class, $m->for('SA'));
        $this->assertInstanceOf(JofotaraDriver::class, $m->for('jo'));
        $this->assertInstanceOf(EtaDriver::class, $m->for('EG'));
        foreach (['SA', 'JO', 'EG'] as $cc) {
            $this->assertInstanceOf(FiscalAdapter::class, $m->for($cc));
            $this->assertInstanceOf(FiscalDriver::class, $m->for($cc), 'deprecated alias interface still satisfied');
            $this->assertSame($cc, $m->for($cc)->country());
        }
        $this->assertSame(['SA', 'JO', 'EG'], $m->countries());
    }

    public function test_uae_pint_adapter_is_configured_but_disabled_by_default(): void
    {
        $this->assertSame(PintAeAdapter::class, config('fiscal.adapters.AE.adapter'));
        $this->assertFalse(config('fiscal.adapters.AE.enabled'));
        $this->assertNull($this->manager()->for('AE'));
        $this->assertFalse($this->manager()->has('OM'), 'Oman: placeholder only');
    }

    public function test_enabled_pint_adapter_without_an_access_point_rejects_with_a_reason(): void
    {
        config(['fiscal.adapters.AE.enabled' => true]);
        $adapter = $this->manager()->for('AE');
        $this->assertInstanceOf(PintAeAdapter::class, $adapter);
        $this->assertSame('report_after_print', $adapter->mode());

        $result = $adapter->submit(new FiscalDocument(['country' => 'AE']));
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('pint_ae_access_point_missing', $result['response']['error']);
    }

    public function test_enabled_pint_adapter_forwards_to_a_bound_access_point(): void
    {
        $this->app->instance(PeppolAccessPoint::class, new class implements PeppolAccessPoint
        {
            public function send(FiscalDocument $document): array
            {
                return ['status' => 'accepted', 'response' => ['asp' => 'fake', 'country' => $document->country]];
            }
        });
        config(['fiscal.adapters.AE.enabled' => true]);

        $result = $this->manager()->for('AE')->submit(new FiscalDocument(['country' => 'AE']));
        $this->assertSame(['status' => 'accepted', 'response' => ['asp' => 'fake', 'country' => 'AE']], $result);
    }

    public function test_a_country_adapter_is_replaced_by_config_and_old_fiscal_driver_implementations_work(): void
    {
        $legacy = new class implements FiscalDriver
        {
            public function country(): string
            {
                return 'JO';
            }

            public function mode(): string
            {
                return 'clear_before_print';
            }

            public function submit(FiscalDocument $document): array
            {
                return ['status' => 'accepted'];
            }
        };
        $this->app->instance('test.jo.connector', $legacy);
        config(['fiscal.adapters.JO.adapter' => 'test.jo.connector']);

        $this->assertSame($legacy, $this->manager()->for('JO'));

        config(['fiscal.adapters.JO.enabled' => false]);
        $this->assertNull($this->manager()->for('JO'));
    }

    public function test_a_non_adapter_class_in_config_fails_loudly(): void
    {
        config(['fiscal.adapters.EG.adapter' => \stdClass::class]);
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->for('EG');
    }

    public function test_the_fiscal_module_never_references_an_order_model(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Fiscal')));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $src = (string) file_get_contents($file->getPathname());
                if (preg_match('/App\\\\Models\\\\Order\b|Modules\\\\Ordering\\\\/', $src)) {
                    $hits[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function test_the_order_relation_is_defined_by_ordering(): void
    {
        $doc = new FiscalDocument(['order_id' => 7]);
        $relation = $doc->order();
        $this->assertSame(Order::class, get_class($relation->getRelated()));
        $this->assertSame('order_id', $relation->getForeignKeyName());
    }

    public function test_the_deprecated_context_name_is_still_accepted_by_builders(): void
    {
        $ctx = new \Juwri\Modules\Fiscal\Services\FiscalReceiptContext('Amman Grill', '12345678', null, 11.6, 1.6, new \DateTimeImmutable('2026-10-02T10:00:00Z'), 'JOD');
        $this->assertInstanceOf(FiscalReceiptContext::class, $ctx);
        $this->assertNull($ctx->orderId);
        $built = new JofotaraBuilder()->build($ctx, ['number' => 'INV-9', 'uuid' => 'u-1']);
        $this->assertStringContainsString('INV-9', $built['ubl']);
    }
}
