<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Admin;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Shared set-up for the admin CRUD contract tests (WI-08): a superadmin,
 * a plain account, the admin layout globals, and the two request shapes the
 * admin screens send (DataTables server-side GET and the side-panel form).
 */
trait InteractsWithAdminPanel
{
    protected function bootAdminPanel(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals([], true);
    }

    protected function admin(): User
    {
        return User::factory()->create([
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    protected function plainUser(): User
    {
        return User::factory()->create([
            'user_type' => 'user',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * GET a DataTables server-side page the way admin-ajax.js asks for it.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function dataTable(string $url, int $orderColumn = 0, string $dir = 'asc', string $search = '', array $extra = []): TestResponse
    {
        $query = http_build_query(array_merge([
            'draw' => 3,
            'start' => 0,
            'length' => 25,
            'order' => [['column' => $orderColumn, 'dir' => $dir]],
            'search' => ['value' => $search],
        ], $extra));

        return $this->getJson($url.'?'.$query, ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /** Side-panel forms post through jquery.form with an XHR header and expect JSON. */
    protected function panelPost(string $url, array $data): TestResponse
    {
        return $this->post($url, $data, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
    }

    protected function panelPut(string $url, array $data): TestResponse
    {
        return $this->put($url, $data, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);
    }

    protected function assertPanelOk(TestResponse $response): void
    {
        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('message'));
    }

    /** Validation failures come back as HTTP 200 {success:false, message} (the panel JS contract). */
    protected function assertPanelFails(TestResponse $response, ?string $messagePart = null): void
    {
        $response->assertOk()->assertJson(['success' => false]);
        $this->assertNotEmpty($response->json('message'));
        if ($messagePart !== null) {
            $this->assertStringContainsString($messagePart, (string) $response->json('message'));
        }
    }

    /** The admin group sends guests and non-admins to the sign-in page. */
    protected function assertAdminOnly(string $method, string $url, array $data = []): void
    {
        $this->{$method}($url, $data)->assertRedirect(route('login'));
        $this->actingAs($this->plainUser())->{$method}($url, $data)->assertRedirect(route('login'));
        auth()->logout();
    }
}
