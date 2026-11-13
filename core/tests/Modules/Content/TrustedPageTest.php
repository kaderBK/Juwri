<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\Page;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trusted raw-HTML CMS pages: the mode is admin-only, and saving it does NOT run the
 * Builder.js sanitiser (the admin author is fully trusted). A non-admin can never
 * reach these routes.
 */
class TrustedPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);   // web `Installed` middleware guard
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    private function page(array $attrs = []): Page
    {
        return Page::create(array_merge(['title' => 'Trusted', 'content' => 'seed', 'active' => 1, 'type' => '0'], $attrs));
    }

    public function test_enable_trusted_switches_mode_and_keeps_content(): void
    {
        $page = $this->page(['content' => '<p>existing</p>']);

        $this->actingAs($this->admin())
            ->post(route('admin.pages.builder.enableTrusted', $page->id))
            ->assertRedirect(route('admin.pages.builder', $page->id));

        $page->refresh();
        $this->assertTrue($page->isTrustedMode());
        $this->assertSame('<p>existing</p>', $page->content);   // kept as the raw starting point
    }

    public function test_trusted_save_stores_raw_unsanitised(): void
    {
        $page = $this->page(['builder' => 'trusted']);

        $raw = '<h1>Hi</h1><script>alert(1)</script><iframe src="https://evil.example"></iframe>';
        $this->actingAs($this->admin())
            ->postJson(route('admin.pages.builder.trustedSave', $page->id), ['html' => $raw])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $page->refresh();
        // Unlike html mode, the <script> and iframe are NOT stripped.
        $this->assertStringContainsString('<script>alert(1)</script>', $page->content);
        $this->assertStringContainsString('evil.example', $page->content);
    }

    public function test_non_admin_cannot_reach_trusted_save(): void
    {
        $page = $this->page(['builder' => 'trusted']);
        $user = User::factory()->create(['group_id' => '1', 'user_type' => 'user', 'status' => 1, 'email_verified_at' => now()]);

        // AdminMiddleware redirects non-admins away; nothing is written.
        $this->actingAs($user)
            ->post(route('admin.pages.builder.trustedSave', $page->id), ['html' => '<script>x()</script>'])
            ->assertRedirect();

        $page->refresh();
        $this->assertSame('seed', $page->content);
    }

    public function test_guest_cannot_reach_trusted_save(): void
    {
        $page = $this->page(['builder' => 'trusted']);

        $this->post(route('admin.pages.builder.trustedSave', $page->id), ['html' => '<script>x()</script>'])
            ->assertRedirect();

        $page->refresh();
        $this->assertSame('seed', $page->content);
    }
}
