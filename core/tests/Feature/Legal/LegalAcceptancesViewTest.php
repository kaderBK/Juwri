<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\LegalAcceptance;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Tests\TestCase;

/**
 * P1-23: the sign-up acceptance records have a UI. Superadmin: a read-only list (user, document, version, source, time,
 * IP as stored) with a user filter, admin routes only. Owner: their OWN acceptances on the Security page, never
 * another user's (IDOR).
 */
class LegalAcceptancesViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['legal.enabled' => true]);
        app()->setLocale('en');
    }

    private function user(string $name, string $type = 'user'): User
    {
        return User::factory()->create([
            'name' => $name, 'email' => strtolower($name).'@example.test', 'user_type' => $type, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function accept(User $user, string $document, string $version, string $ip, string $source = 'signup'): void
    {
        TenantContext::bypass(fn () => LegalAcceptance::query()->create([
            'user_id' => $user->id, 'document' => $document, 'version' => $version, 'source' => $source,
            'accepted_at' => now(), 'ip' => $ip, 'user_agent' => 'phpunit',
        ]));
    }

    public function test_the_admin_list_shows_every_record_and_filters_by_user(): void
    {
        $admin = $this->user('Rootadmin', 'admin');
        $alice = $this->user('Alicia');
        $bob = $this->user('Bobby');
        $this->accept($alice, 'terms', '1.0', '203.0.113.7');
        $this->accept($alice, 'dpa', '1.0', '203.0.113.7');
        $this->accept($bob, 'terms', '1.0', '198.51.100.9', 'banner');

        $all = $this->actingAs($admin)->get(route('admin.legal-acceptances.index'))->assertOk()->getContent();
        foreach (['Alicia', 'Bobby', '203.0.113.7', '198.51.100.9', 'alicia@example.test', 'Change notice', 'At sign-up'] as $needle) {
            $this->assertStringContainsString($needle, $all);
        }

        $byName = $this->actingAs($admin)->get(route('admin.legal-acceptances.index', ['user' => 'alic']))->assertOk()->getContent();
        $this->assertStringContainsString('203.0.113.7', $byName);
        $this->assertStringNotContainsString('198.51.100.9', $byName);

        $byId = $this->actingAs($admin)->get(route('admin.legal-acceptances.index', ['user' => (string) $bob->id]))->assertOk()->getContent();
        $this->assertStringContainsString('198.51.100.9', $byId);
        $this->assertStringNotContainsString('203.0.113.7', $byId);

        $none = $this->actingAs($admin)->get(route('admin.legal-acceptances.index', ['user' => 'nobody-here']))->assertOk()->getContent();
        $this->assertStringContainsString('No records found.', $none);
    }

    public function test_the_list_is_admin_only_and_read_only(): void
    {
        $owner = $this->user('Ownerone');
        $this->accept($owner, 'terms', '1.0', '203.0.113.7');

        $this->get(route('admin.legal-acceptances.index'))->assertRedirect();
        $this->actingAs($owner)->get(route('admin.legal-acceptances.index'))->assertRedirect();
        $this->actingAs($owner)->get(route('admin.legal-acceptances.index'))->assertDontSee('203.0.113.7');

        $admin = $this->user('Rootadmin2', 'admin');
        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->actingAs($admin)->{$verb}(route('admin.legal-acceptances.index'))->assertStatus(405);
        }
        $this->assertSame(1, TenantContext::bypass(fn () => LegalAcceptance::query()->count()));
    }

    public function test_the_owner_sees_only_their_own_acceptances_on_the_security_page(): void
    {
        $mine = $this->user('Minerva');
        $other = $this->user('Otherguy');
        $this->accept($mine, 'terms', '1.0', '203.0.113.7');
        $this->accept($other, 'aup', '1.0', '198.51.100.9');

        $html = $this->actingAs($mine)->get(route('2fa.show'))->assertOk()->getContent();

        $this->assertStringContainsString('Terms you accepted', $html);
        $this->assertStringContainsString('id="legal-acceptances"', $html);
        $this->assertStringNotContainsString('198.51.100.9', $html, "another user's record leaked");
        $this->assertStringNotContainsString('Otherguy', $html);
        $this->assertSame(1, substr_count($html, 'data-label="Accepted at"'));

        $none = $this->actingAs($this->user('Fresh'))->get(route('2fa.show'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="legal-acceptances"', $none);
    }

    public function test_the_new_strings_have_a_real_translation_in_every_locale(): void
    {
        $en = include base_path('lang/en/lang.php');
        $keys = ['legal_acceptances', 'terms_you_accepted', 'accepted_at', 'filter_by_user_name_email_or_id', 'at_sign_up', 'change_notice', 'at_checkout',
            'the_legal_documents_you_accepted_which_version_when_and_where'];
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $table = include base_path("lang/$locale/lang.php");
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $table, "$locale:$key");
                $this->assertNotSame($en[$key], $table[$key], "[$locale] $key is still English");
            }
        }
    }
}
