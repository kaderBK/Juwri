<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\HostedServices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Contracts\Editions\InstallLicenceProof;
use Juwri\Core\Editions\Community\NoInstallLicence;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Juwri\Core\Services\HostedServices\RequestSignature;
use Juwri\Core\Settings\PlatformSettings;
use RuntimeException;
use Tests\TestCase;

/**
 * The installation's side of the juwri-hosted services (owner 2026-10-05): no phone-home — it registers only when the
 * owner connects, keeps its install ID + secret in the platform settings (secret encrypted), signs every call and sends
 * the store context + its own counts, never an edition.
 */
class HostedServiceClientTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://services.juwri.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hosted_services.url' => self::URL.'/']);
    }

    public function test_nothing_is_sent_until_the_owner_connects(): void
    {
        Http::fake();
        $client = app(HostedServiceClient::class);

        $this->assertFalse($client->connected());
        $this->get('/');
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::URL));
        $this->expectException(RuntimeException::class);
        $client->send('POST', '/api/hosted/v1/relay', ['a' => 1]);
    }

    public function test_a_community_installation_connects_and_keeps_its_identity(): void
    {
        $this->app->instance(InstallLicenceProof::class, new NoInstallLicence);
        $secret = str_repeat('ab', 32);
        Http::fake([self::URL.'/api/hosted/v1/installs' => Http::response(['install_id' => 'ci_01j9abcdefghjkmnpqrstvwxyz', 'secret' => $secret, 'edition' => 'community'], 201)]);

        $identity = app(HostedServiceClient::class)->register();

        $this->assertSame(['install_id' => 'ci_01j9abcdefghjkmnpqrstvwxyz', 'edition' => 'community'], $identity);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/api/hosted/v1/installs' && $r->data() === ['kind' => 'community']);
        $this->assertTrue(app(HostedServiceClient::class)->connected());
        $this->assertSame($secret, PlatformSettings::string(HostedServiceClient::INSTALL_SECRET));
        $raw = (string) DB::table('platform_settings')->where('name', HostedServiceClient::INSTALL_SECRET)->value('value');
        $this->assertStringNotContainsString($secret, $raw, 'the secret is encrypted at rest');
    }

    public function test_a_licensed_installation_connects_with_its_licence_proof(): void
    {
        $this->app->instance(InstallLicenceProof::class, new class implements InstallLicenceProof
        {
            public function licenceId(): ?string
            {
                return 'JUWRI-CHAIN-0005';
            }

            public function licenceFileSha256(): ?string
            {
                return str_repeat('c', 64);
            }
        });
        Http::fake(['*' => Http::response(['install_id' => 'JUWRI-CHAIN-0005', 'secret' => str_repeat('cd', 32), 'edition' => 'chain'], 201)]);

        app(HostedServiceClient::class)->register();

        Http::assertSent(fn (Request $r) => $r->data() === ['kind' => 'licence', 'licence_id' => 'JUWRI-CHAIN-0005', 'licence_sha256' => str_repeat('c', 64)]);
        Http::assertSent(fn (Request $r) => ! array_key_exists('edition', $r->data()));
    }

    public function test_a_refused_or_malformed_registration_stores_nothing(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'This licence is not known to juwri services.', 'code' => 'licence_unknown'], 403)
            ->push(['install_id' => 'bad id with spaces', 'secret' => str_repeat('a', 64)], 201)
            ->push(['install_id' => 'ci_ok', 'secret' => 'short'], 201);

        foreach (['This licence is not known', 'unexpected format', 'unexpected format'] as $expected) {
            try {
                app(HostedServiceClient::class)->register();
                $this->fail('registration must fail');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->assertFalse(app(HostedServiceClient::class)->connected());
    }

    public function test_a_service_call_is_signed_and_carries_the_store_context_and_own_counts(): void
    {
        $secret = str_repeat('ef', 32);
        PlatformSettings::set(HostedServiceClient::INSTALL_ID, 'ci_01j9abcdefghjkmnpqrstvwxyz');
        PlatformSettings::set(HostedServiceClient::INSTALL_SECRET, $secret);
        $store = Store::factory()->create(['store_type' => 'retail']);
        Store::factory()->create();
        Http::fake(['*' => Http::response(['served' => true])]);

        $response = app(HostedServiceClient::class)->send('post', 'api/hosted/v1/relay?x=1', ['order' => 5], $store, 77);

        $this->assertTrue($response->json('served'));
        Http::assertSent(function (Request $r) use ($secret, $store): bool {
            $h = fn (string $name): string => (string) ($r->header($name)[0] ?? '');
            $context = [];
            foreach (RequestSignature::CONTEXT as $name) {
                $context[$name] = $h($name);
            }
            $this->assertSame(self::URL.'/api/hosted/v1/relay?x=1', $r->url());
            $this->assertSame([(string) $store->id, 'retail', '77'], [$context[RequestSignature::STORE], $context[RequestSignature::STORE_TYPE], $context[RequestSignature::BRANCH]]);
            $this->assertSame((string) Store::query()->withoutGlobalScopes()->count(), $context[RequestSignature::REPORTED_STORES]);
            $this->assertSame('{"order":5}', $r->body());
            $canonical = RequestSignature::canonical('POST', '/api/hosted/v1/relay?x=1', $h(RequestSignature::TIMESTAMP), $h(RequestSignature::NONCE), $r->body(), $context);

            return RequestSignature::matches($secret, $canonical, $h(RequestSignature::SIGNATURE))
                && RequestSignature::fresh($h(RequestSignature::TIMESTAMP), time())
                && $r->header('X-Juwri-Edition') === [];
        });
    }

    public function test_the_connect_command(): void
    {
        Http::fake(['*' => Http::response(['install_id' => 'ci_01j9abcdefghjkmnpqrstvwxyz', 'secret' => str_repeat('ab', 32), 'edition' => 'community'], 201)]);

        $this->artisan('juwri:services:connect')->expectsOutputToContain('Connected to juwri services as ci_01j9abcdefghjkmnpqrstvwxyz')->assertSuccessful();
        $this->artisan('juwri:services:connect')->expectsOutputToContain('Already connected')->assertSuccessful();
        Http::assertSentCount(1);
        $this->artisan('juwri:services:connect', ['--force' => true])->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_the_connect_command_reports_a_refusal(): void
    {
        Http::fake(['*' => Http::response(['message' => 'juwri services are temporarily unavailable. Try again later.'], 503)]);

        $this->artisan('juwri:services:connect')->expectsOutputToContain('temporarily unavailable')->assertFailed();
        $this->assertFalse(app(HostedServiceClient::class)->connected());
    }
}
