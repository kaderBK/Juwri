<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Auth\Services\Social\AppleClientSecret;

/**
 * FEATURES-5 Track A (A2.2) — Superadmin platform-default social-login keys.
 *
 * Manages PLATFORM-level Credential rows (store_id NULL, feature='social',
 * provider='google|facebook|apple'). These are the fallback keys used when a
 * merchant hasn't supplied their own, so social sign-in works with zero owner
 * setup. The `active` flag is the global per-provider on/off switch.
 */
class SocialProvidersController extends Controller
{
    protected function providers(): array
    {
        return [
            'google' => 'Google',
            'facebook' => 'Facebook',
            'apple' => 'Apple',
        ];
    }

    public function index(Request $request)
    {
        $credentials = Credential::whereNull('store_id')
            ->where('feature', 'social')
            ->get()
            ->keyBy('provider');

        $providers = [];
        foreach ($this->providers() as $key => $label) {
            $cred = $credentials->get($key);
            $secrets = is_array($cred?->secrets) ? $cred->secrets : [];
            $providers[$key] = [
                'label' => $label,
                'active' => $cred ? (bool) $cred->active : false,
                'secrets' => $secrets,
                'has_key' => $key === 'apple'
                    ? ! empty($secrets['private_key'])
                    : ! empty($secrets['client_secret']),
            ];
        }

        return view('admin.social-providers.index', [
            'providers' => $providers,
        ]);
    }

    public function update(Request $request)
    {
        $provider = (string) $request->input('provider');
        if (! array_key_exists($provider, $this->providers())) {
            abort(404);
        }

        $incoming = (array) $request->input('secrets', []);
        $active = $request->boolean('active', false);

        $existing = Credential::whereNull('store_id')
            ->where('feature', 'social')
            ->where('provider', $provider)
            ->first();
        $secrets = is_array($existing?->secrets) ? $existing->secrets : [];

        // Merge incoming; masked (••••) means "unchanged", empty means "clear".
        foreach ($incoming as $key => $value) {
            $value = (string) $value;
            if (str_contains($value, '••••')) {
                continue;
            }
            if ($value === '') {
                unset($secrets[$key]);
            } else {
                $secrets[$key] = trim($value);
            }
        }

        Credential::updateOrCreate(
            ['store_id' => null, 'feature' => 'social', 'provider' => $provider],
            ['secrets' => $secrets, 'active' => $active]
        );

        app_alert_success(___('Platform social provider updated successfully'));

        return back();
    }

    public function test(Request $request)
    {
        $provider = (string) $request->input('provider');
        if (! array_key_exists($provider, $this->providers())) {
            return response()->json(['success' => false, 'message' => ___('Unknown provider')], 200);
        }

        $incoming = (array) $request->input('secrets', []);
        $existing = Credential::whereNull('store_id')
            ->where('feature', 'social')
            ->where('provider', $provider)
            ->first();
        $existingSecrets = is_array($existing?->secrets) ? $existing->secrets : [];

        // Merge for validation (masked -> existing).
        $secrets = $existingSecrets;
        foreach ($incoming as $key => $value) {
            $value = (string) $value;
            if (str_contains($value, '••••') || $value === '') {
                continue;
            }
            $secrets[$key] = trim($value);
        }

        if ($provider === 'apple') {
            $ok = AppleClientSecret::generate($secrets) !== null;
            $message = $ok ? ___('Apple keys look valid.') : ___('Apple configuration is incomplete or the private key is invalid.');
        } else {
            $ok = ! empty($secrets['client_id']) && ! empty($secrets['client_secret']);
            $message = $ok ? ___('Configuration looks valid.') : ___('Client ID and client secret are required.');
        }

        return response()->json(['success' => $ok, 'message' => $message], 200);
    }
}
