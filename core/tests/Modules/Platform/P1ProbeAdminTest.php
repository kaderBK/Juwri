<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogComment;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DISCOVERY probes for the admin / superadmin surfaces (scenario ADM).
 * These are throwaway probes, not a regression suite.
 */
class P1ProbeAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * PROBE 1 — the superadmin blog-comment moderation queue (DataTables ajax
     * branch of Admin\BlogCommentController::index) concatenates guest-supplied
     * `name`, `email` and `comment` straight into the <td> HTML it returns.
     * Public visitors control all three (BlogController::comment).
     */
    public function test_probe_blog_comment_moderation_json_escapes_guest_html(): void
    {
        config(['settings.blog_enable' => 1]);

        $payload = '<img src=x onerror=alert(document.cookie)>';

        $author = $this->admin();

        $blog = Blog::create([
            'author' => $author->id,
            'title' => 'Probe post '.Str::random(5),
            'description' => 'body',
            'status' => 'publish',
        ]);

        BlogComment::create([
            'user_id' => null,
            'blog_id' => $blog->id,
            'name' => $payload,
            'email' => 'guest@example.test',
            'comment' => $payload,
            'active' => 0,          // still PENDING — sits in the moderation queue
        ]);

        $res = $this->actingAs($this->admin())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('admin.blog.comments.index').'?'.http_build_query([
                'draw' => 1,
                'start' => 0,
                'length' => 25,
                'order' => [['column' => 4, 'dir' => 'desc']],
                'search' => ['value' => ''],
            ]));

        $res->assertOk();
        $body = $res->getContent();

        // The public blog view renders the same field with {{ }} (escaped).
        // The admin queue must not hand raw attacker HTML to the panel.
        $this->assertStringNotContainsString(
            '<img src=x onerror=alert(document.cookie)>',
            $body,
            'Admin blog-comment moderation JSON contains RAW guest HTML; DataTables '
            .'injects these cells as innerHTML, so the payload executes in the superadmin panel.'
        );
    }

    /**
     * PROBE 2 — a superadmin resetting a merchant's password
     * (Admin\UserController::updatePassword) writes no activity_logs row,
     * although its sibling admin actions on the same controller/user do
     * (loginAsUser -> 'admin.impersonate', WalletAdminController ->
     * 'wallet.admin_grant').
     */
    public function test_probe_admin_password_reset_is_audited(): void
    {
        $merchant = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.password.update', $merchant->id), [
                'password' => 'newsecret123',
                'password_confirmation' => 'newsecret123',
            ]);

        $this->assertTrue(
            User::find($merchant->id)->password !== $merchant->password,
            'password was not actually changed — probe setup wrong'
        );

        $this->assertNotSame(
            0,
            ActivityLog::where('subject_id', $merchant->id)->count(),
            'Superadmin password reset of a merchant account leaves NO activity_logs row.'
        );
    }
}
