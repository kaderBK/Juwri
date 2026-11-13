<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Http\Controllers\WhatsAppWebhookController;
use Juwri\Modules\Messaging\Jobs\ProcessWhatsAppWebhookJob;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\MetaProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\TwilioProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * C1: the inbound WhatsApp webhook must resolve the owning tenant from the
 * (untrusted) payload BEFORE authenticating, verify against that tenant's own
 * secret, and fail CLOSED when no secret is resolved. The signature toggle must
 * actually change behaviour. Every case below was observed red on the pre-fix
 * tree (see WI-C1 §5).
 */
class WhatsAppWebhookTenantAuthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://app.test/webhook/whatsapp/twilio';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        WhatsAppProviderFactory::register('twilio', TwilioProvider::class);

        // For the owner-route leg (BYOK save). Same treatment the other
        // owner-route tests use: APP_INSTALLED=false bounces every owner route
        // to /install and the '*' layout composer never registers.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        parent::tearDown();
    }

    /** Invoke the controller directly so the signed url/params are deterministic. */
    private function handle(string $provider, array $params, array $server = []): Response
    {
        $request = Request::create(self::URL, 'POST', $params, [], [], $server);

        return app(WhatsAppWebhookController::class)->handle($request, $provider);
    }

    private function twilioSig(array $params, string $token): array
    {
        ksort($params);
        $data = self::URL;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return ['HTTP_X_TWILIO_SIGNATURE' => base64_encode(hash_hmac('sha1', $data, $token, true))];
    }

    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Auth Test',
            'sub_title' => 't', 'description' => 't',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);

        return [$owner, $store];
    }

    public function test_unsigned_post_is_rejected_when_no_platform_secret_exists(): void
    {
        Bus::fake();

        $response = $this->handle('twilio', [
            'From' => 'whatsapp:+15551234567', 'To' => 'whatsapp:+15550000000',
            'Body' => 'STOP', 'MessageSid' => 'forged1',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        Bus::assertNotDispatched(ProcessWhatsAppWebhookJob::class);
    }

    public function test_byok_tenant_secret_is_used_to_verify(): void
    {
        Bus::fake();
        [, $store] = $this->store();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'AC1', 'auth_token' => 'tenant_token', 'from_number' => '+15550000000'],
            'active' => true,
        ]);
        WhatsAppRoute::create(['provider' => 'twilio', 'sender_id' => '+15550000000', 'store_id' => $store->id]);

        $params = [
            'From' => 'whatsapp:+15551234567', 'To' => 'whatsapp:+15550000000',
            'Body' => 'Table for 2?', 'MessageSid' => 'SMok',
        ];

        $response = $this->handle('twilio', $params, $this->twilioSig($params, 'tenant_token'));

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertDispatched(ProcessWhatsAppWebhookJob::class);
    }

    public function test_payload_signed_with_another_tenants_secret_is_rejected(): void
    {
        Bus::fake();
        [, $a] = $this->store();
        [, $b] = $this->store();
        Credential::create([
            'store_id' => $a->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'ACa', 'auth_token' => 'token_a', 'from_number' => '+15550000000'],
            'active' => true,
        ]);
        Credential::create([
            'store_id' => $b->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'ACb', 'auth_token' => 'token_b', 'from_number' => '+15551111111'],
            'active' => true,
        ]);
        WhatsAppRoute::create(['provider' => 'twilio', 'sender_id' => '+15550000000', 'store_id' => $a->id]);
        WhatsAppRoute::create(['provider' => 'twilio', 'sender_id' => '+15551111111', 'store_id' => $b->id]);

        // Addressed to tenant A's number, but signed with tenant B's token.
        $params = [
            'From' => 'whatsapp:+15559999999', 'To' => 'whatsapp:+15550000000',
            'Body' => 'hi', 'MessageSid' => 'SMforge',
        ];

        $response = $this->handle('twilio', $params, $this->twilioSig($params, 'token_b'));

        $this->assertSame(403, $response->getStatusCode());
        Bus::assertNotDispatched(ProcessWhatsAppWebhookJob::class);
    }

    public function test_toggle_off_accepts_unverified_and_toggle_on_rejects(): void
    {
        Bus::fake();
        $params = [
            'From' => 'whatsapp:+15551234567', 'To' => 'whatsapp:+15550000000',
            'Body' => 'STOP', 'MessageSid' => 'SMtog',
        ];

        // Verification OFF platform-wide → unsigned POST is accepted.
        config(['settings.whatsapp_verify_signatures' => '0']);
        $this->assertSame(200, $this->handle('twilio', $params)->getStatusCode());

        // Verification ON → same unsigned POST is rejected.
        config(['settings.whatsapp_verify_signatures' => '1']);
        $this->assertSame(403, $this->handle('twilio', $params)->getStatusCode());
    }

    /** P6-69: drivers with no signature scheme fail closed (hosted + abstract default). */
    public function test_forged_post_to_hosted_provider_is_403(): void
    {
        Bus::fake();
        $this->assertSame(403, $this->handle('juwri_hosted', ['Body' => 'x', 'From' => '+1'])->getStatusCode());
        Bus::assertNotDispatched(ProcessWhatsAppWebhookJob::class);

        $bare = new class('bare') extends AbstractWhatsAppProvider
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function capabilities(): array
            {
                return [];
            }

            public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
            {
                return new WhatsAppResult('bare', 'failed', false);
            }

            public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult
            {
                return new WhatsAppResult('bare', 'failed', false);
            }
        };
        $this->assertFalse($bare->authenticate([], '{}', self::URL, []));
    }

    public function test_meta_forged_post_is_403_and_valid_signature_is_200(): void
    {
        Bus::fake();
        WhatsAppProviderFactory::register('meta', MetaProvider::class);
        [, $store] = $this->store();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'meta',
            'secrets' => ['access_token' => 't', 'phone_number_id' => 'PN1', 'app_secret' => 'meta_secret'],
            'active' => true,
        ]);
        WhatsAppRoute::create(['provider' => 'meta', 'sender_id' => 'PN1', 'store_id' => $store->id]);
        $body = (string) json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'PN1'], 'messages' => [['from' => '1555', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => 'hi']]]]]]]]]);
        $send = function (array $server) use ($body): Response {
            $request = Request::create(self::URL, 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);

            return app(WhatsAppWebhookController::class)->handle($request, 'meta');
        };

        $this->assertSame(403, $send([])->getStatusCode(), 'unsigned');
        $this->assertSame(403, $send(['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong')])->getStatusCode(), 'wrong key');
        Bus::assertNotDispatched(ProcessWhatsAppWebhookJob::class);

        $this->assertSame(200, $send(['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta_secret')])->getStatusCode());
        Bus::assertDispatched(ProcessWhatsAppWebhookJob::class);
    }

    /** P6-69: the platform toggle cannot switch verification off in production. */
    public function test_toggle_off_in_production_still_rejects_forged_post(): void
    {
        Bus::fake();
        config(['settings.whatsapp_verify_signatures' => '0']);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->assertTrue(whatsapp_verify_signatures());
            $this->assertSame(403, $this->handle('twilio', ['From' => 'whatsapp:+1', 'Body' => 'STOP', 'MessageSid' => 'SMp'])->getStatusCode());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        Bus::assertNotDispatched(ProcessWhatsAppWebhookJob::class);
    }

    public function test_link_provider_post_is_404(): void
    {
        Bus::fake();

        try {
            $this->handle('link', ['Body' => 'x']);
            $this->fail('Expected a 404 for the link provider webhook.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        Bus::assertNotDispatched(ProcessWhatsAppWebhookJob::class);
    }

    public function test_saving_byok_keys_registers_the_sender_route(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)
            ->post(route('stores.whatsappSettings', $store->id), [
                'whatsapp_provider' => 'meta',
                'meta_phone_number_id' => 'PN123',
                'meta_access_token' => 'tok',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('whatsapp_routes', [
            'provider' => 'meta', 'sender_id' => 'PN123', 'store_id' => $store->id,
        ]);
    }
}
