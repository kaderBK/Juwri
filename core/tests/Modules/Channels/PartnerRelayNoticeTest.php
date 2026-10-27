<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Contracts\PartnerTransport;
use Mockery;
use Tests\TestCase;

/**
 * Coordinator review of lane A (owner decision A): the partner-credential connector screens say, in the Community build,
 * that the connector runs directly with the owner's own credentials and through the juwri Cloud relay on Cloud.
 */
class PartnerRelayNoticeTest extends TestCase
{
    private const KEY = 'direct_connection_this_build_reaches_the_partner_with_your_own_partner_credentials_on_juwri_cloud_this_connector_runs_through_the_juwri_cloud_relay';

    private const SCREENS = [
        'hungerstation', 'deliverect', 'aggregators', 'posbridge/foodics', 'posbridge/marn',
    ];

    public function test_the_notice_shows_while_the_direct_transport_is_bound(): void
    {
        app()->setLocale('en');
        $html = view('owner.integrations.partials.relay-notice')->render();

        $this->assertStringContainsString('data-testid="partner-relay-notice"', $html);
        $this->assertStringContainsString('juwri Cloud relay', $html);
    }

    public function test_the_notice_is_hidden_when_a_relay_transport_is_bound(): void
    {
        $this->app->instance(PartnerTransport::class, Mockery::mock(PartnerTransport::class));

        $this->assertStringNotContainsString('partner-relay-notice', view('owner.integrations.partials.relay-notice')->render());
    }

    public function test_every_partner_connector_screen_includes_the_notice(): void
    {
        foreach (self::SCREENS as $screen) {
            $this->assertStringContainsString(
                "@include('owner.integrations.partials.relay-notice')",
                file_get_contents(resource_path("views/owner/integrations/{$screen}.blade.php")),
                "[$screen] is missing the Cloud-relay notice"
            );
        }
    }

    public function test_the_notice_is_translated_in_all_eleven_locales(): void
    {
        $en = (require lang_path('en/lang.php'))[self::KEY] ?? null;
        $this->assertNotNull($en);
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $text = (require lang_path("{$locale}/lang.php"))[self::KEY] ?? null;
            $this->assertNotNull($text, "[$locale] missing");
            $this->assertNotSame($en, $text, "[$locale] is still English");
            $this->assertStringContainsString('juwri Cloud', $text, "[$locale] lost the product name");
        }
    }
}
