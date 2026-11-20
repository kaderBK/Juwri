<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Mail\SupportMessage;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Http\Middleware\CacheMarketingResponse;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Content\Models\Testimonial;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09f characterisation of the base-derived parts of HomeController: home
 * redirects and plan totals, pricing, testimonials, CMS pages, the contact and
 * feedback forms, newsletter sign-up and the language switch.
 */
class HomeMarketingContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['app.theme' => 'juwri', 'settings.include_language_code' => 0]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, CacheMarketingResponse::class]);
        TestPlans::purge();
    }

    private function plan(string $name, float $m, float $a, float $l, int $status = 1): object
    {
        return TestPlans::create(['name' => $name, 'description' => 'd', 'monthly_price' => $m, 'annual_price' => $a, 'lifetime_price' => $l, 'settings' => ['restaurant_limit' => 1], 'status' => $status]);
    }

    public function test_home_redirects_to_login_when_the_landing_is_off_and_to_the_dashboard_when_signed_in(): void
    {
        config(['settings.disable_landing_page' => 1]);
        $this->get(route('home'))->assertRedirect(route('login'));

        config(['settings.disable_landing_page' => 0]);
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($user)->get(route('home'))->assertRedirect(route('dashboard'));
    }

    public function test_home_and_pricing_list_active_plans_cheapest_first_with_totals(): void
    {
        $this->pitchOrSkip();
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $this->plan('Big', 30, 300, 900);
        $this->plan('Small', 10, 100, 300);
        $this->plan('Hidden', 1, 1, 1, 0);
        config(['settings.free_membership_plan' => null, 'settings.trial_membership_plan' => null]);

        foreach (['home', 'pricing'] as $route) {
            $this->get(route($route))->assertOk()
                ->assertViewHas('plans', fn ($p) => $p->pluck('name')->all() === ['Small', 'Big'])
                ->assertViewHas('total_monthly', fn ($v) => (float) $v === 40.0)
                ->assertViewHas('total_annual', fn ($v) => (float) $v === 400.0)
                ->assertViewHas('total_lifetime', fn ($v) => (float) $v === 1200.0)
                ->assertViewHas('free_plan', null)
                ->assertViewHas('trial_plan', null);
        }

        $this->get(route('home'))->assertViewHas('testimonials')->assertViewHas('blogArticles')->assertViewHas('faqs');
    }

    public function test_testimonials_page_is_optional_and_paginated_by_21(): void
    {
        $this->pitchOrSkip();
        config(['settings.testimonials_enable' => 0]);
        $this->get(route('testimonials'))->assertNotFound();

        config(['settings.testimonials_enable' => 1]);
        foreach (range(1, 22) as $i) {
            Testimonial::create(['name' => "T$i", 'designation' => 'd', 'content' => 'c', 'image' => 'x.png']);
        }
        $this->get(route('testimonials'))->assertOk()
            ->assertViewHas('testimonials', fn ($p) => $p->perPage() === 21 && $p->total() === Testimonial::count() && $p->total() >= 22);
    }

    public function test_cms_page_by_slug_active_locale_and_members_only(): void
    {
        Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<p>About body</p>', 'active' => 1, 'type' => 0, 'translation_lang' => null]);
        Page::create(['title' => 'Off page', 'slug' => 'off', 'content' => 'c', 'active' => 0, 'type' => 0]);
        Page::create(['title' => 'Turkish only', 'slug' => 'tr-only', 'content' => 'c', 'active' => 1, 'type' => 0, 'translation_lang' => 'tr']);
        Page::create(['title' => 'Members', 'slug' => 'members', 'content' => 'c', 'active' => 1, 'type' => 1]);

        $this->get(route('page', 'about'))->assertOk()->assertViewHas('page', fn ($p) => $p->slug === 'about');
        $this->get(route('page', 'off'))->assertNotFound();
        $this->get(route('page', 'tr-only'))->assertNotFound();
        $this->get(route('page', 'missing'))->assertNotFound();
        $this->get(route('page', 'members'))->assertNotFound();

        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($user)->get(route('page', 'members'))->assertOk();
    }

    public function test_contact_and_feedback_queue_a_support_message(): void
    {
        Mail::fake();
        $form = ['name' => 'Ann', 'email' => 'ann@example.com', 'subject' => 'Hello', 'message' => "Line 1\nLine 2", 'phone' => '+90555'];

        config(['settings.contact_email' => null]);
        $this->from(route('contact'))->post(route('contact.send'), $form)->assertRedirect(route('contact'));
        $this->assertSame(___('Email sending is disabled.'), session('app_alert_message'));
        Mail::assertNothingQueued();

        config([
            'settings.contact_email' => 'support@example.test', 'settings.site_title' => 'Juwri',
            'settings.email_sub_contact' => 'C {NAME}', 'settings.email_message_contact' => 'M {MESSAGE}',
            'settings.email_sub_feedback' => 'F {NAME}', 'settings.email_message_feedback' => 'FB {PHONE}',
        ]);
        $this->from(route('contact'))->post(route('contact.send'), $form)->assertRedirect(route('contact'));
        $this->assertSame(___('Thank you for contacting us.'), session('app_alert_message'));
        Mail::assertQueued(SupportMessage::class, fn (SupportMessage $m) => $m->hasTo('support@example.test')
            && $m->subjectTemplate === 'C {NAME}' && $m->bodyTemplate === 'M {MESSAGE}'
            && $m->replyToEmail === 'ann@example.com' && $m->replyToName === 'Ann'
            && $m->shortCodes === [
                '{SITE_TITLE}' => 'Juwri', '{SITE_URL}' => route('home'), '{NAME}' => 'Ann', '{EMAIL}' => 'ann@example.com',
                '{CONTACT_SUBJECT}' => 'Hello', '{MESSAGE}' => nl2br("Line 1\nLine 2"),
            ]);

        $this->from(route('feedback'))->post(route('feedback.send'), $form)->assertRedirect(route('feedback'));
        $this->assertSame(___('Thank you for your feedback.'), session('app_alert_message'));
        Mail::assertQueued(SupportMessage::class, fn (SupportMessage $m) => $m->subjectTemplate === 'F {NAME}'
            && $m->shortCodes === [
                '{SITE_TITLE}' => 'Juwri', '{SITE_URL}' => route('home'), '{NAME}' => 'Ann', '{EMAIL}' => 'ann@example.com',
                '{PHONE}' => '+90555', '{FEEDBACK_SUBJECT}' => 'Hello', '{MESSAGE}' => nl2br("Line 1\nLine 2"),
            ]);
    }

    /** fix-xss L1: an RFC-valid quoted local part can carry markup; {EMAIL} is escaped like every other visitor tag. */
    public function test_contact_escapes_the_sender_email_tag(): void
    {
        Mail::fake();
        config([
            'settings.contact_email' => 'support@example.test', 'settings.site_title' => 'Juwri',
            'settings.email_sub_contact' => 'C {NAME}', 'settings.email_message_contact' => 'M {EMAIL}',
        ]);
        $email = '"<a href=https://phish.example>x</a>"@example.com';

        $this->from(route('contact'))->post(route('contact.send'), ['name' => 'Ann', 'email' => $email, 'subject' => 'Hi', 'message' => 'Body'])
            ->assertRedirect(route('contact'))->assertSessionHasNoErrors();

        Mail::assertQueued(SupportMessage::class, fn (SupportMessage $m) => $m->shortCodes['{EMAIL}'] === e($email));
    }

    public function test_newsletter_signup_and_language_switch(): void
    {
        $this->postJson(route('newsletter'), ['email' => 'news@example.com'])->assertOk()->assertExactJson(['success' => true]);
        $row = DB::table('newsletter_subscribers')->where('email', 'news@example.com')->first()
            ?? DB::table('subscribers')->where('email', 'news@example.com')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->joined);

        Language::updateOrCreate(['code' => 'tr'], ['name' => 'Turkish', 'direction' => 'ltr', 'active' => 1]);
        $this->from(route('contact'))->get(route('localize', 'tr'))->assertRedirect(route('contact'));
        $this->assertSame('tr', session('locale'));
        $this->get(route('localize', 'zz'))->assertNotFound();
    }

    private function pitchOrSkip(): void
    {
        if (! Route::has('pricing') && ! Route::has('guides.show')) {
            $this->markTestSkipped('pitch routes belong to the edition package');
        }
        config(['settings.platform_marketing' => true]);
    }
}
