<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\BuilderFormSubmission;
use Juwri\Modules\Content\Services\Builder\BuilderForms;
use Juwri\Core\Support\Html;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The curated Builder.js page form: render-time wiring binds a sanitised <form> to
 * the platform submit route with a signed context + honeypot; the endpoint stores
 * a submission only after honeypot + timing + rate-limit checks pass, and reads the
 * page binding ONLY from the encrypted context (never the visible form).
 */
class BuilderFormSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml leaves APP_INSTALLED unset, so the web `Installed` middleware
        // redirects everything to /install. Mark installed for these route tests.
        config(['app.installed' => true]);
    }

    public function test_prepare_wires_action_and_hidden_fields(): void
    {
        // cleanPage marks the form; prepare() points it at the platform route.
        $html = Html::cleanPage('<form><input name="a"></form>');
        $this->assertStringContainsString('data-mb-form', $html);

        $out = BuilderForms::prepare($html, ['surface' => 'cms', 'id' => 3, 'url' => '/page/x']);

        $this->assertStringContainsString('action="'.route('builder.form.submit').'"', $out);
        $this->assertStringContainsString('name="_mbctx"', $out);
        $this->assertStringContainsString('name="_mbts"', $out);
        $this->assertStringContainsString('name="website"', $out);   // honeypot
    }

    public function test_valid_submission_is_stored_and_sanitised(): void
    {
        $ctx = BuilderForms::encodeContext(['surface' => 'cms', 'id' => 7, 'label' => 'Contact', 'url' => url('/page/x')]);

        $res = $this->postJson(route('builder.form.submit'), [
            '_mbctx' => $ctx,
            '_mbts' => time() - 10,
            'name' => 'Jo',
            'message' => 'Hi <b>there</b>',
        ]);

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('builder_form_submissions', 1);
        $row = BuilderFormSubmission::first();
        $this->assertSame('cms', $row->surface);
        $this->assertSame(7, $row->ref_id);
        $this->assertSame('Jo', $row->fields['name']);
        $this->assertSame('Hi there', $row->fields['message']);      // tags stripped
    }

    public function test_honeypot_blocks_storage_but_returns_success(): void
    {
        $ctx = BuilderForms::encodeContext(['surface' => 'cms', 'id' => 1]);

        $res = $this->postJson(route('builder.form.submit'), [
            '_mbctx' => $ctx, '_mbts' => time() - 10, 'website' => 'bot-filled', 'name' => 'x',
        ]);

        $res->assertOk();                                            // silent — spammer learns nothing
        $this->assertDatabaseCount('builder_form_submissions', 0);
    }

    public function test_too_fast_submission_is_blocked(): void
    {
        $ctx = BuilderForms::encodeContext(['surface' => 'cms', 'id' => 1]);

        $res = $this->postJson(route('builder.form.submit'), [
            '_mbctx' => $ctx, '_mbts' => time(), 'name' => 'x',       // <2s since render
        ]);

        $res->assertOk();
        $this->assertDatabaseCount('builder_form_submissions', 0);
    }

    public function test_tampered_context_is_rejected(): void
    {
        $res = $this->postJson(route('builder.form.submit'), [
            '_mbctx' => 'not-a-valid-encrypted-blob', '_mbts' => time() - 10, 'name' => 'x',
        ]);

        $res->assertOk();
        $this->assertDatabaseCount('builder_form_submissions', 0);
    }
}
