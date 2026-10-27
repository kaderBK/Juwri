<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Juwri\Core\Legal\CookieRegistry;
use Juwri\Core\Legal\LegalAcceptances;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Legal\LegalPage;
use Juwri\Core\Legal\SecurityTxt;
use Juwri\Core\Models\User;

/**
 * P1-23: JUWRI LTD's legal documents — the pages (/terms, /privacy, /security, /legal/{dpa,aup,sla,subprocessors}),
 * /.well-known/security.txt + pgp-key.txt, and the owner's acceptance of a changed version (dashboard banner).
 * Everything here 404s unless the instance publishes the legal pages (LEGAL_PAGES) — and on a reseller's white-label
 * host, which never serves JUWRI LTD's documents — except the cookie list.
 */
class LegalController extends Controller
{
    public function show(Request $request, string $document): View
    {
        // Not on a reseller's white-label host either: these are JUWRI LTD's documents, its customers contract with the reseller.
        abort_unless(LegalDocuments::publishedHere() && LegalDocuments::has($document), 404);

        // Admin preview links open a specific text (?lang=en|ar); anything else is ignored.
        $lang = $request->query('lang');
        if (is_string($lang) && in_array($lang, LegalPage::LOCALES, true)) {
            app()->setLocale($lang);
        }

        abort_if(LegalDocuments::hiddenAsUnfilled($document), 404);

        $page = LegalPage::load($document, LegalPage::textLocale($document, app()->getLocale()));
        if ($page->unresolved !== [] && app()->isProduction()) {
            // Never ship a placeholder: the owner fills config/legal.php (.env LEGAL_*) first (legal:check).
            Log::error('legal page has unresolved placeholders', ['document' => $document, 'locale' => $page->locale, 'unresolved' => $page->unresolved]);
            abort(503);
        }

        $version = LegalDocuments::currentVersion($document);

        return view('legal.page', [
            'page' => $page,
            'document' => $document,
            'version' => $version,
            'effective' => LegalDocuments::effectiveDate($document, $version),
            'documents' => LegalDocuments::all(),
        ]);
    }

    /**
     * The cookie list (privacy.md §5, FILL-LIST item 16), printed from the cookie registry the banner and the consent
     * gate read. Shown on every install (Community): it describes this software. JUWRI LTD's company text and the
     * Privacy Policy link only where this instance publishes JUWRI's legal pages and the host is not a reseller's.
     */
    public function cookies(): View
    {
        $published = LegalDocuments::publishedHere();

        return view('legal.cookies', [
            'groups' => CookieRegistry::byCategory(),
            // Same date as the Privacy Policy it is referenced from (privacy.md section 5).
            'updated' => (LegalDocuments::effectiveDate('privacy', LegalDocuments::currentVersion('privacy')) ?? now())
                ->locale(app()->getLocale())->isoFormat('LL'),
            'juwri' => $published,
            'documents' => $published ? LegalDocuments::all() : [],
        ]);
    }

    public function securityTxt(): Response
    {
        abort_unless(LegalDocuments::publishedHere(), 404);

        return response(SecurityTxt::render(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function pgpKey(): Response
    {
        $file = SecurityTxt::pgpKeyFile();
        abort_unless(LegalDocuments::publishedHere() && $file !== null, 404);

        return response((string) file_get_contents($file), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** The dashboard banner's "I accept": records every pending document version (Terms §14.2 / §14.13). */
    public function accept(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $written = LegalAcceptances::acceptPending($user, $request);

        if ($request->expectsJson()) {
            return response()->json(['recorded' => $written]);
        }

        return back()->with('success', __('site.legal.reaccept.done'));
    }
}
