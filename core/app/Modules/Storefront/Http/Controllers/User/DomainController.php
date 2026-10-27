<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\DomainVerifier;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Symfony\Component\HttpFoundation\Response;

/**
 * #85: owner-facing custom domain management. SSL automation is handled by infra
 * (Caddy on-demand TLS / Cloudflare for SaaS) once a domain is verified.
 */
class DomainController extends Controller
{
    private function ownedRestaurant(Request $request, $store): Store
    {
        $store = Store::where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->idOrSlug($store))
            ->firstOrFail();

        return $store;
    }

    private function platformHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public function index(Request $request, $store)
    {
        // JSON contract (unchanged) for API/ajax consumers.
        if ($request->expectsJson() || $request->ajax()) {
            $store = $this->ownedRestaurant($request, $store);

            return response()->json([
                'domains' => StoreDomain::where('store_id', $store->id)->get(),
                'target' => $this->platformHost(),
            ]);
        }

        // A2: owner-facing page. The nav entry may pass "0" when no store is in
        // context — that renders the store picker instead of 404ing.
        $storeKey = $store;
        $stores = Store::where('user_id', $request->user()->id)->get(['id', 'title', 'slug']);
        $store = $stores->first(
            fn ($p) => (string) $p->id === (string) $storeKey || (string) $p->slug === (string) $storeKey
        );

        if (! $store && ! in_array((string) $storeKey, ['0', ''], true)) {
            abort(404);
        }
        if (! $store && $stores->count() === 1) {
            $store = $stores->first();
        }

        return view('owner.stores.domains', [
            'stores' => $stores,
            'store' => $store,
            'domains' => $store
                ? StoreDomain::where('store_id', $store->id)->orderBy('id')->get()
                : collect(),
            'target' => $this->platformHost(),
        ]);
    }

    public function store(Request $request, $store)
    {
        $store = $this->ownedRestaurant($request, $store);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
        ]);
        $domain = StoreDomain::normalizeHost($data['domain']);

        // P1-20: one guard for taken hosts, platform hosts (and anything under them) and malformed names.
        $problem = StoreDomains::customHostProblem($domain);
        if ($problem !== null) {
            $message = $problem === 'taken' ? 'That domain is already registered.' : 'That domain cannot be used.';
            if (! ($request->expectsJson() || $request->ajax())) {
                return back()->withInput()->with('error', ___($message));
            }

            return response()->json(['error' => $message], $problem === 'taken' ? 409 : 422);
        }

        $record = StoreDomains::requestCustom($store, $domain);

        if (! ($request->expectsJson() || $request->ajax())) {
            return back()->with('success', ___('Domain added. Create the DNS record shown below, then click Verify.'));
        }

        return response()->json([
            'domain' => $record,
            'instructions' => [
                'cname' => ['host' => $domain, 'points_to' => $this->platformHost()],
                // Review fix 6: the TXT goes on the challenge name, so it can sit next to the CNAME.
                'txt' => ['host' => DomainVerifier::CHALLENGE_LABEL.'.'.$domain, 'value' => $record->verification_token],
            ],
        ], 201);
    }

    /** P1-20 follow-up: claim one platform subdomain ({label}.<storefront host>) — live at once, one per store. */
    public function claimSubdomain(Request $request, int|string $store): RedirectResponse
    {
        $store = $this->ownedRestaurant($request, $store);
        $data = $request->validate(['subdomain' => ['required', 'string', 'max:63']]);
        $label = strtolower(trim($data['subdomain']));

        $error = null;
        if (StoreDomain::where('store_id', $store->id)->where('type', StoreDomain::TYPE_SUBDOMAIN)->exists()) {
            $error = 'Release your current subdomain before claiming another one.';
        } else {
            $error = match (StoreDomains::subdomainProblem($label)) {
                'invalid' => 'Use 3 to 63 letters, numbers or single hyphens, not starting or ending with a hyphen.',
                'reserved' => 'That name is reserved. Choose another one.',
                'taken' => 'That subdomain is already taken.',
                default => null,
            };
        }
        if ($error === null) {
            try {
                StoreDomains::claimSubdomain($store, $label);
            } catch (\InvalidArgumentException) {
                $error = 'That subdomain is already taken.'; // taken between the check and the insert
            }
        }
        if ($error !== null) {
            return back()->withInput()->with('error', ___($error));
        }

        return back()->with('success', ___('Subdomain claimed.'));
    }

    public function verify(Request $request, $store, StoreDomain $domain, DomainVerifier $verifier)
    {
        $store = $this->ownedRestaurant($request, $store);
        abort_unless((int) $domain->store_id === (int) $store->id, 403);

        $html = ! ($request->expectsJson() || $request->ajax());

        if ($domain->verified) {
            return $html
                ? back()->with('success', ___('Verified! Your menu will serve on this domain.'))
                : response()->json(['verified' => true]);
        }

        try {
            $verified = StoreDomains::verify($domain, $verifier);
        } catch (LockTimeoutException) {
            // Another claim on this host holds its lock right now (BL-036 R2): ask to retry, never a 500.
            return $html
                ? back()->with('error', ___('The domain is busy with another change. Please try again in a moment.'))
                : response()->json(['verified' => false, 'message' => ___('The domain is busy with another change. Please try again in a moment.')], 409);
        }
        if ($verified) {
            return $html
                ? back()->with('success', ___('Verified! Your menu will serve on this domain.'))
                : response()->json(['verified' => true]);
        }

        if ($html) {
            return back()->with('error', ___('DNS not detected yet. Add the TXT record shown and try again in a few minutes. A CNAME alone does not verify a domain.'));
        }

        return response()->json([
            'verified' => false,
            'message' => 'DNS not detected yet. Add the TXT record shown and try again in a few minutes. A CNAME alone does not verify a domain.',
        ], 422);
    }

    /**
     * P1-20: choose the store's primary address — a verified domain of this store, or `path` (the
     * platform address). Other addresses 301 to it.
     */
    public function primary(Request $request, int|string $store, string $domain): Response
    {
        $store = $this->ownedRestaurant($request, $store);

        if ($domain === 'path') {
            StoreDomains::usePathAsPrimary($store->id);
        } else {
            $record = StoreDomain::where('store_id', $store->id)->whereKey((int) $domain)->firstOrFail();
            if (! $record->isVerified()) {
                $message = 'Verify the domain before making it the main address.';

                return ($request->expectsJson() || $request->ajax())
                    ? response()->json(['error' => $message], 422)
                    : back()->with('error', ___($message));
            }
            StoreDomains::makePrimary($record);
        }

        return ($request->expectsJson() || $request->ajax())
            ? response()->json(['success' => true, 'url' => store_url($store->fresh())])
            : back()->with('success', ___('Main address updated.'));
    }

    public function destroy(Request $request, $store, StoreDomain $domain)
    {
        $store = $this->ownedRestaurant($request, $store);
        abort_unless((int) $domain->store_id === (int) $store->id, 403);

        StoreDomains::release($domain, 'removed'); // P6-241: frees the host; a removed primary falls back to the path form

        if (! ($request->expectsJson() || $request->ajax())) {
            return redirect()->route('stores.domains.index', $store->id)
                ->with('success', ___('Domain removed.'));
        }

        return response()->json(['success' => true]);
    }
}
