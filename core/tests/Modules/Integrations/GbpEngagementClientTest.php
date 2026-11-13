<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Services\Gbp\FakeGbpEngagementClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientFactory;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane B / B1: the engagement client contract + Fake payload shapes
 * (asserted against the B0-recorded GBP API doc shapes) + dark-default factory.
 */
class GbpEngagementClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeGbpEngagementClient::reset();
    }

    public function test_factory_returns_fake_when_dark(): void
    {
        // No services.gbp.driver, no creds → Fake (zero HTTP).
        $this->assertInstanceOf(FakeGbpEngagementClient::class, GbpEngagementClientFactory::make());
        $this->assertInstanceOf(FakeGbpEngagementClient::class, GbpEngagementClientFactory::make(123));
    }

    public function test_factory_honors_container_binding(): void
    {
        $fake = new FakeGbpEngagementClient();
        app()->instance(GbpEngagementClientInterface::class, $fake);
        $this->assertSame($fake, GbpEngagementClientFactory::make(999));
    }

    public function test_list_reviews_shape_and_average(): void
    {
        FakeGbpEngagementClient::seedReview('LOC-1', [
            'name' => 'accounts/A/locations/LOC-1/reviews/R1', 'reviewId' => 'R1',
            'reviewer' => ['displayName' => 'Sam', 'isAnonymous' => false],
            'starRating' => 'FOUR', 'comment' => 'Great', 'createTime' => '2026-01-02T00:00:00Z',
            'reviewReply' => null,
        ]);
        FakeGbpEngagementClient::seedReview('LOC-1', [
            'name' => 'accounts/A/locations/LOC-1/reviews/R2', 'reviewId' => 'R2',
            'reviewer' => ['displayName' => 'Anon', 'isAnonymous' => true],
            'starRating' => 'TWO', 'comment' => 'Meh', 'createTime' => '2026-01-03T00:00:00Z',
            'reviewReply' => null,
        ]);

        $page = new FakeGbpEngagementClient()->listReviews('LOC-1');

        $this->assertArrayHasKey('reviews', $page);
        $this->assertArrayHasKey('averageRating', $page);
        $this->assertArrayHasKey('totalReviewCount', $page);
        $this->assertArrayHasKey('nextPageToken', $page);
        $this->assertSame(2, $page['totalReviewCount']);
        $this->assertSame(3.0, $page['averageRating']); // (4+2)/2
        $this->assertSame('FOUR', $page['reviews'][0]['starRating']);
    }

    public function test_reply_and_delete_reply_mutate_review(): void
    {
        FakeGbpEngagementClient::seedReview('LOC-1', [
            'name' => 'accounts/A/locations/LOC-1/reviews/R1', 'reviewId' => 'R1',
            'starRating' => 'FIVE', 'reviewReply' => null,
        ]);
        $client = new FakeGbpEngagementClient();

        $reply = $client->replyReview('accounts/A/locations/LOC-1/reviews/R1', 'Thank you!');
        $this->assertSame('Thank you!', $reply['comment']);
        $this->assertArrayHasKey('updateTime', $reply);
        $this->assertSame('Thank you!', $client->listReviews('LOC-1')['reviews'][0]['reviewReply']['comment']);

        $client->deleteReviewReply('accounts/A/locations/LOC-1/reviews/R1');
        $this->assertNull($client->listReviews('LOC-1')['reviews'][0]['reviewReply']);
    }

    public function test_create_and_list_local_posts(): void
    {
        $client = new FakeGbpEngagementClient();
        $created = $client->createLocalPost('LOC-1', [
            'languageCode' => 'en',
            'summary'      => 'Weekend special',
            'callToAction' => ['actionType' => 'ORDER', 'url' => 'https://x.test'],
            'topicType'    => 'STANDARD',
        ]);

        $this->assertArrayHasKey('name', $created);
        $this->assertSame('LIVE', $created['state']);
        $this->assertSame('Weekend special', $created['summary']);

        $list = $client->listLocalPosts('LOC-1');
        $this->assertCount(1, $list['localPosts']);
        $this->assertSame('ORDER', $list['localPosts'][0]['callToAction']['actionType']);
    }

    public function test_performance_report_totals_and_series(): void
    {
        $client = new FakeGbpEngagementClient();
        $report = $client->fetchPerformanceReport('LOC-1', ['WEBSITE_CLICKS', 'CALL_CLICKS'], [
            'start' => ['year' => 2026, 'month' => 1, 'day' => 1],
            'end'   => ['year' => 2026, 'month' => 1, 'day' => 3],
        ]);

        $this->assertArrayHasKey('WEBSITE_CLICKS', $report['metrics']);
        $this->assertArrayHasKey('CALL_CLICKS', $report['metrics']);
        $this->assertCount(3, $report['series']['WEBSITE_CLICKS']); // 3 days inclusive
        $this->assertSame('2026-01-01', $report['series']['WEBSITE_CLICKS'][0]['date']);
        // total == daily * 3
        $daily = $report['series']['WEBSITE_CLICKS'][0]['value'];
        $this->assertSame($daily * 3, $report['metrics']['WEBSITE_CLICKS']);
    }
}
