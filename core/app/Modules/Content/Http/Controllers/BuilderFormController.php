<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use Juwri\Modules\Content\Models\BuilderFormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Juwri\Core\Http\Controllers\Controller;

/**
 * Receives submissions from curated Builder.js page forms (CMS / reseller / biolink).
 *
 * The route is CSRF-exempt (public, no-auth contact form → pages stay cacheable), so
 * abuse is stopped by three cheap checks before anything is stored: a honeypot field,
 * a minimum render-to-submit time, and a per-IP rate limit. The form's binding (which
 * page, where to notify) is read ONLY from the ENCRYPTED `_mbctx` — never from the
 * visible form — so a spammer cannot redirect notifications or forge the surface.
 *
 * Anti-enumeration: bot/invalid submissions get the SAME success response as good ones.
 */
class BuilderFormController extends Controller
{
    private const MAX_FIELDS = 30;

    private const MAX_VALUE = 2000;

    public function submit(Request $request): Response
    {
        $ctx = null;
        try {
            $ctx = json_decode(Crypt::decryptString((string) $request->input('_mbctx', '')), true);
        } catch (\Throwable) {
            $ctx = null;
        }

        $honeypot = trim((string) $request->input('website', '')) !== '';
        $renderedAt = (int) $request->input('_mbts', 0);
        $tooFast = $renderedAt > 0 && (time() - $renderedAt) < 2;
        $rlKey = 'mbform:'.sha1((string) $request->ip());
        $throttled = RateLimiter::tooManyAttempts($rlKey, 8);

        // Reject silently (identical response) so spammers learn nothing.
        if (! is_array($ctx) || $honeypot || $tooFast || $throttled) {
            return $this->done($request, $ctx);
        }
        RateLimiter::hit($rlKey, 3600);

        $fields = $this->sanitiseFields($request->except(['_mbctx', '_mbts', 'website', '_token', '_method']));

        $submission = BuilderFormSubmission::create([
            'surface' => substr((string) ($ctx['s'] ?? ''), 0, 16),
            'ref_id' => is_numeric($ctx['id'] ?? null) ? (int) $ctx['id'] : null,
            'label' => Str::limit((string) ($ctx['label'] ?? ''), 118, ''),
            'page_url' => Str::limit((string) ($ctx['url'] ?? $request->headers->get('referer', '')), 253, ''),
            'fields' => $fields,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 253, ''),
            'created_at' => now(),
        ]);

        $this->notify($ctx, $submission);

        return $this->done($request, $ctx);
    }

    /**
     * Cap count + length, strip tags/control chars — submissions are stored as plain text.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function sanitiseFields(array $input): array
    {
        $out = [];
        foreach (Arr::dot($input) as $key => $value) {
            if (count($out) >= self::MAX_FIELDS) {
                break;
            }
            if (is_array($value) || $value === null) {
                continue;
            }
            $k = Str::limit(preg_replace('/[^\w.\- ]/u', '', (string) $key) ?? '', 60, '');
            $v = trim(strip_tags((string) $value));
            $v = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $v) ?? '';
            if ($k === '') {
                continue;
            }
            $out[$k] = Str::limit($v, self::MAX_VALUE, '');
        }

        return $out;
    }

    /** @param  array<string, mixed>  $ctx */
    private function notify(array $ctx, BuilderFormSubmission $submission): void
    {
        $to = (string) ($ctx['to'] ?? '');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $lines = ["New submission from: {$submission->label}", 'Page: '.$submission->page_url, ''];
            foreach ($submission->fields as $k => $v) {
                $lines[] = "{$k}: {$v}";
            }
            Mail::raw(implode("\n", $lines), function ($m) use ($to, $submission) {
                $m->to($to)->subject('New form submission — '.($submission->label ?: 'your page'));
            });
        } catch (\Throwable $e) {
            Log::warning('[builder-form] notify failed: '.$e->getMessage());
        }
    }

    /**
     * Uniform success: JSON for the ajax enhancement, else back to the page.
     *
     * @param  array<string, mixed>|null  $ctx
     */
    private function done(Request $request, ?array $ctx): Response
    {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        $back = (string) ($ctx['url'] ?? $request->headers->get('referer', '/'));

        return redirect()->to($this->safeBackUrl($request, $back).'#mbform-sent');
    }

    /** Only ever redirect back to a same-host URL (never an attacker-supplied host). */
    private function safeBackUrl(Request $request, string $url): string
    {
        if ($url === '') {
            return url('/');
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host !== $request->getHost()) {
            return url('/');
        }

        return $url;
    }
}
