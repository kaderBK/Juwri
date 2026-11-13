<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Billing\FeatureGate;
use Juwri\Core\Contracts\Billing\PlanLimits;
use Juwri\Core\Contracts\Billing\PlanQuota;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Ai\Contracts\AiSpend;
use Juwri\Modules\Ai\Contracts\ImageGenerator;
use Juwri\Modules\Ai\Contracts\Translator;
use Juwri\Modules\Ai\Services\AiLimitExceededException;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Ai\Services\Studio\GeminiImageProvider;
use Juwri\Modules\Ai\Services\TranslatorService;
use Juwri\Modules\Auth\Contracts\SocialCredentials;
use Juwri\Modules\Auth\Services\Social\SocialCredentialResolver;
use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Media\Contracts\MediaCopier;
use Juwri\Modules\Media\Contracts\MediaCopyFailed;
use Juwri\Modules\Media\Contracts\StockImages;
use Juwri\Modules\Media\Contracts\StorageQuotas;
use Juwri\Modules\Media\Services\MediaCopyException;
use Juwri\Modules\Media\Services\MediaCopyService;
use Juwri\Modules\Media\Services\StockImageService;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Contracts\StaffPush;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Services\BnplEligibility;
use Juwri\Modules\Payments\Services\WalletService;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Tests\TestCase;

/**
 * P1-08 L1 public surface: every public contract an L2+ module uses resolves, to the class that did the work before
 * (pure delegation), and a container override of that class (test fakes) still applies through the contract.
 */
class L1PublicSurfaceTest extends TestCase
{
    public function test_contracts_resolve_to_the_existing_implementations(): void
    {
        $impl = [
            Ai::class => AiService::class, AiProviderChain::class => AiProviderFactory::class,
            Translator::class => TranslatorService::class, ImageGenerator::class => GeminiImageProvider::class,
            SocialCredentials::class => SocialCredentialResolver::class, MediaCopier::class => MediaCopyService::class,
            StockImages::class => StockImageService::class, Sms::class => SmsService::class,
            SmsOtp::class => SmsOtpService::class, StaffPush::class => StaffPushService::class,
            WhatsApp::class => WhatsAppService::class,
        ];
        foreach ($impl as $contract => $class) {
            $this->assertInstanceOf($class, app($contract), $contract);
        }
        foreach ([Wallet::class, Ledger::class, Gateways::class, StorageQuotas::class, Images::class, AiSpend::class,
            MessageQueue::class, MobilePush::class, PlanQuota::class, PlanLimits::class, FeatureGate::class] as $contract) {
            $this->assertInstanceOf($contract, app($contract), $contract);
        }
    }

    public function test_an_override_of_the_implementation_class_applies_through_the_contract(): void
    {
        $fake = new class extends SmsService
        {
            public function normalizePhone(string $phone): string
            {
                return 'fake:'.$phone;
            }
        };
        $this->app->instance(SmsService::class, $fake);
        $this->assertSame('fake:1', app(Sms::class)->normalizePhone('1'));
    }

    public function test_exceptions_are_catchable_through_the_marker_contracts(): void
    {
        $this->assertInstanceOf(AiLimitExceeded::class, new AiLimitExceededException);
        $this->assertInstanceOf(AiProviderFailure::class, new AiProviderException('x'));
        $this->assertInstanceOf(MediaCopyFailed::class, new MediaCopyException('x'));
    }

    public function test_delegation_returns_the_same_answers(): void
    {
        $this->assertSame(WalletService::currency(), app(Wallet::class)->currency());
        $this->assertSame(BnplEligibility::GATEWAYS, app(Gateways::class)->bnplGateways());
        $this->assertSame(GatewayMinorUnits::decimals('KWD'), app(Gateways::class)->decimals('KWD'));
        $this->assertSame(StockImageService::UNSAFE_PROVIDERS, StockImages::UNSAFE_PROVIDERS);
        $this->assertSame(SocialCredentialResolver::PROVIDERS, ['google', 'facebook', 'apple']);
    }

    public function test_the_message_queue_dispatches_the_same_jobs(): void
    {
        Bus::fake();
        app(MessageQueue::class)->sms('+1', 'hi', 3, 'status', false);
        app(MessageQueue::class)->whatsApp('+1', 'hi', 3, 'status', false, 'tpl', ['a']);
        app(MessageQueue::class)->staffPush(3, 'T', 'B', '/staff-app', 9);

        Bus::assertDispatched(SendSmsJob::class, fn ($j) => [$j->to, $j->message, $j->storeId, $j->feature, $j->marketing] === ['+1', 'hi', 3, 'status', false]);
        Bus::assertDispatched(SendWhatsAppJob::class, fn ($j) => $j->template === 'tpl' && $j->vars === ['a']);
        Bus::assertDispatched(SendPushNotificationJob::class, fn ($j) => $j->storeId === 3 && $j->staffId === 9);
    }

    public function test_whatsapp_after_commit_is_carried_onto_the_job(): void
    {
        Bus::fake();
        app(MessageQueue::class)->whatsApp('+1', 'now', 3, 'low_stock');
        app(MessageQueue::class)->whatsApp('+1', 'later', 3, 'hotel_booking', afterCommit: true);

        Bus::assertDispatched(SendWhatsAppJob::class, fn ($j) => $j->message === 'now' && ! $j->afterCommit);
        Bus::assertDispatched(SendWhatsAppJob::class, fn ($j) => $j->message === 'later' && $j->afterCommit === true);
    }

    public function test_device_registration_through_the_contract_keeps_the_feature_gate(): void
    {
        $this->app->instance(FeatureGate::class, new class implements FeatureGate
        {
            public function storeAllows(?int $storeId, string $feature): bool
            {
                return false;
            }
        });

        $this->expectException(ValidationException::class);
        app(MobilePush::class)->registerDevice(1, 'tok', 'fcm');
    }

    public function test_transcription_is_on_the_ai_contracts(): void
    {
        $this->assertTrue(method_exists(Ai::class, 'transcribe'));
        $this->app->instance(AiProviderFactory::class, new class extends AiProviderFactory
        {
            public function transcribeChain(?int $storeId = null): array
            {
                return ['whisper:'.$storeId];
            }
        });
        $this->assertSame(['whisper:7'], app(AiProviderChain::class)->transcribeChain(7));
    }
}
