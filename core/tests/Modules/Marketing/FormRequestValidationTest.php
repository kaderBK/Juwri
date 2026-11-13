<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use Juwri\Modules\Marketing\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R1d: the first FormRequest batch (public contact + newsletter forms) rejects
 * the same payloads with the same errors the inline validate() did.
 */
class FormRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These public forms sit behind the installer gate; mark the app installed.
        config(['app.installed' => true]);
    }

    public function test_contact_form_rejects_missing_fields(): void
    {
        $this->post('/contact', [])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
    }

    public function test_newsletter_rejects_invalid_and_duplicate_email(): void
    {
        $this->post('/newsletter', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Subscriber::create(['email' => 'dup@example.com', 'joined' => now()]);
        $this->post('/newsletter', ['email' => 'dup@example.com'])
            ->assertSessionHasErrors('email');
    }
}
