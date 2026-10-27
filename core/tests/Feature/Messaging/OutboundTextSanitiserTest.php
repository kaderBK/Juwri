<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Messaging;

use App\Support\OutboundText;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Listeners\SanitizeOutgoingHeaders;
use Mockery;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** P6-106: customer/owner-typed text in SMS, WhatsApp and email headers. No message is ever sent (queues faked, array mailer). */
class OutboundTextSanitiserTest extends TestCase
{
    private const BIDI = "\u{202E}\u{202A}\u{2066}\u{2069}";

    public function test_name_strips_crlf_bidi_zero_width_and_urls_but_keeps_arabic_and_emoji(): void
    {
        $this->assertSame('Ahmed www evil com', OutboundText::name('Ahmed www.evil.com'));
        $this->assertSame('Ahmed evil com x', OutboundText::name('Ahmed https://evil.com/x'));
        $this->assertSame('Ahmed bit ly abc', OutboundText::name("Ahmed\r\nbit.ly/abc"));
        $this->assertSame('Evil Ahmed', OutboundText::name(self::BIDI."Evil\u{200B}\u{FEFF} Ahmed"));
        $this->assertSame('أحمد 🌹 👨‍👩‍👧', OutboundText::name("أحمد\u{200B} 🌹\n\t 👨‍👩‍👧"));
        $this->assertSame(60, mb_strlen(OutboundText::name(str_repeat('a', 1024))));
        $this->assertSame('Mr Ahmed', OutboundText::name('Mr.Ahmed'));
    }

    public function test_zwj_sequences_with_skin_tones_flags_and_bidi_marks_survive(): void
    {
        $this->assertSame('🧑🏽‍💻 x', OutboundText::name('🧑🏽‍💻 x'));
        $flag = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";
        $this->assertSame($flag, OutboundText::line($flag));
        $this->assertSame("أحمد\u{200F} (Ahmed)\u{200E}\u{061C}", OutboundText::line("أحمد\u{200F} (Ahmed)\u{200E}\u{061C}"));
        $this->assertSame('ab', OutboundText::line("a\u{202E}\u{2066}\u{2069}\u{202C}b"));
    }

    public function test_name_only_neutralises_url_like_things(): void
    {
        $this->assertSame('Ahmed @ home', OutboundText::name('Ahmed @ home'));
        $this->assertSame('@ahmed', OutboundText::name('@ahmed'));
        $this->assertSame('ali at mail com', OutboundText::name('ali@mail.com'));
        $this->assertSame('St Louis', OutboundText::name('St.Louis'));
        $this->assertSame('Ahmed evil co uk a b', OutboundText::name('Ahmed evil.co.uk/a/b'));
        $this->assertSame('Ali (2nd) v1.5', OutboundText::name('Ali (2nd) v1.5'));
        $this->assertSame('Abu-Bakr & Sons', OutboundText::name('Abu-Bakr & Sons'));
    }

    public function test_line_collapses_whitespace_and_caps(): void
    {
        $this->assertSame('a b c', OutboundText::line("a\r\n\t b    c"));
        $this->assertSame(120, mb_strlen(OutboundText::line(str_repeat('x', 1024))));
        $this->assertSame('see https://ok.example/p', OutboundText::line('see https://ok.example/p'));
    }

    public function test_text_keeps_newlines_but_not_control_or_bidi(): void
    {
        $this->assertSame("Hi\n\n there  مرحبا 🌹", OutboundText::text("Hi\r\n\r\n\r\n\u{0007}there\u{202E}  مرحبا 🌹"));
        $this->assertSame("Code:  123456\nhttps://ok.example/a?b=1&c=2", OutboundText::text("Code:  123456\nhttps://ok.example/a?b=1&c=2"));
        $long = str_repeat('x', 5000);
        $this->assertSame($long, OutboundText::text($long));
    }

    public function test_sms_body_is_sanitised_at_the_queue(): void
    {
        Queue::fake();
        app(MessageQueue::class)->sms('+15550001', "Shop: order for Ahmed\u{202E}\u{200B}\x07 done", 1, 'order');
        Queue::assertPushed(SendSmsJob::class, fn ($j) => $j->message === 'Shop: order for Ahmed  done');
    }

    public function test_whatsapp_params_are_single_clean_lines(): void
    {
        Queue::fake();
        $long = str_repeat('N', 1024);
        app(MessageQueue::class)->whatsApp('+15550001', "Body\r\nline", 1, 'order', false, 'tpl', ["Ahmed\r\nLine2\t  x     y\u{202E}", $long, 42, 'أحمد 🌹']);
        Queue::assertPushed(SendWhatsAppJob::class, function ($j) {
            return $j->message === "Body\nline"
                && $j->vars[0] === 'Ahmed Line2 x y'
                && mb_strlen($j->vars[1]) === 400
                && $j->vars[2] === '42'
                && $j->vars[3] === 'أحمد 🌹';
        });
    }

    public function test_long_whatsapp_body_is_logged_not_truncated(): void
    {
        Queue::fake();
        Log::shouldReceive('warning')->once()->with('whatsapp.body_over_limit', Mockery::type('array'));
        $body = str_repeat('y', 4100).' https://ok.example/pay?c=1';
        app(MessageQueue::class)->whatsApp('+15550001', $body, 1, 'order');
        Queue::assertPushed(SendWhatsAppJob::class, fn ($j) => $j->message === $body);
    }

    public function test_listener_tolerates_a_message_with_no_from(): void
    {
        $email = (new Email)->to('a@example.test')->subject("Hi\r\nthere")->text('x');
        (new SanitizeOutgoingHeaders)->handle(
            new MessageSending($email)
        );
        $this->assertSame('Hi there', $email->getSubject());
        $this->assertSame([], $email->getFrom());
    }

    public function test_email_subject_and_from_name_are_sanitised(): void
    {
        Mail::raw('body', function ($m) {
            $m->to('a@example.test')
                ->from('shop@example.test', "Ahmed www.evil.com\r\nBcc: x@y.z".self::BIDI)
                ->replyTo('r@example.test', 'Pay at http://evil.com')
                ->subject("Order from Ahmed\r\nBcc: x@y.z".self::BIDI.' 🌹 أحمد');
        });
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Order from Ahmed Bcc: x@y.z 🌹 أحمد', $sent->getSubject());
        $from = $sent->getFrom()[0]->getName();
        $this->assertStringStartsWith('Ahmed www evil com', $from);
        $this->assertDoesNotMatchRegularExpression('/[\r\n\x{202A}-\x{202E}\x{2066}-\x{2069}]|evil\.com/u', $from);
        $this->assertStringNotContainsString('evil.com', $sent->getReplyTo()[0]->getName());
        $this->assertDoesNotMatchRegularExpression('/\r?\n(?![ \t])/', rtrim($sent->getHeaders()->get('Subject')->getBodyAsString()));
        $this->assertNull($sent->getHeaders()->get('Bcc'));
    }
}
