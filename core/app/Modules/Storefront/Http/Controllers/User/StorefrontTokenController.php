<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Models\StorefrontToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Security\OneTimeSecrets;

/**
 * GAP-3: owner issuance of per-restaurant storefront tokens. Ownership is enforced
 * against the authenticated user's own restaurants; the raw token is shown once.
 * (Backend issuance — a polished dashboard panel is a deferred follow-up.)
 */
class StorefrontTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => 'required|integer',
            'label' => 'nullable|string|max:80',
            'scopes' => 'nullable|array',
            'scopes.*' => Rule::in(StorefrontToken::SCOPES),
            'allowed_origins' => 'nullable', // array (API) or newline/comma string (form)
            'browser' => 'nullable|boolean',
        ]);

        // Ownership: the restaurant must belong to the authenticated owner.
        $store = Store::where('id', $data['store_id'])->where('user_id', auth()->id())->firstOrFail();

        $origins = $this->parseOrigins($request->input('allowed_origins'));
        $browser = $request->boolean('browser');

        // A browser-embedded token is origin-bound for its write/agent scope, so it
        // must declare at least one origin — reject clearly instead of silently
        // minting an unbound token that would then fail every write.
        if ($browser && empty($origins)) {
            return back()
                ->withErrors(['allowed_origins' => ___('A browser token needs at least one allowed origin, e.g. https://yourstore.com.')])
                ->withInput();
        }
        foreach ($origins as $o) {
            if (! preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $o)) {
                return back()
                    ->withErrors(['allowed_origins' => ___('Each origin must be a scheme and host only, e.g. https://example.com.').' ('.$o.')'])
                    ->withInput();
            }
        }

        $raw = Str::random(48);
        $token = StorefrontToken::create([
            'store_id' => $store->id,
            'token' => hash('sha256', $raw),
            'label' => $data['label'] ?? null,
            'scopes' => $data['scopes'] ?? ['menu:read'],
            'allowed_origins' => $origins ?: null,
            'browser' => $browser,
            'active' => true,
        ]);

        // P4: shown once from the server-side store on the API page (never a session flash).
        app(OneTimeSecrets::class)->put('storefront_token', (int) auth()->id(), (int) $token->id, $raw);

        return redirect()->route('api.index', ['new_storefront_token' => $token->id])
            ->with('success', ___('Storefront token created. Copy it now — it will not be shown again.'));
    }

    /**
     * Normalize the allowed-origins input (an array from an API caller, or a
     * newline/comma-separated string from the dashboard textarea) into a clean,
     * de-duplicated list with trailing slashes stripped (an Origin header carries
     * no path). Each value's format is validated by the caller.
     *
     * @return list<string>
     */
    private function parseOrigins(mixed $input): array
    {
        if (is_array($input)) {
            $list = $input;
        } elseif (is_string($input)) {
            $list = preg_split('/[\r\n,]+/', $input) ?: [];
        } else {
            $list = [];
        }

        $out = [];
        foreach ($list as $o) {
            $o = rtrim(trim((string) $o), '/');
            if ($o !== '') {
                $out[] = $o;
            }
        }

        return array_values(array_unique($out));
    }

    public function destroy(int $id)
    {
        $token = StorefrontToken::findOrFail($id);
        // Ownership via the token's restaurant.
        $owns = Store::where('id', $token->store_id)->where('user_id', auth()->id())->exists();
        abort_unless($owns, 403);

        $token->delete();

        return back()->with('success', ___('Storefront token revoked.'));
    }
}
