<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareOAuth;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareTokenExchange;

/**
 * N7-P1 — Square OAuth connect (the marketplace-grade path; raw personal tokens are
 * kept working for BC but flagged "legacy — reconnect via OAuth"). Stores {access_token,
 * refresh_token, expires_at, merchant_id} encrypted; disconnect revokes the token.
 */
class SquareOAuthController extends Controller
{
    use ResolvesCurrentStore;

    private function appCreds(): array
    {
        $c = Credential::for('square', null) ?? [];

        return [
            $c['app_id'] ?? config('services.square.app_id'),
            $c['app_secret'] ?? config('services.square.app_secret'),
        ];
    }

    public function connect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $sandbox = $request->boolean('sandbox');
        [$appId] = $this->appCreds();
        if (empty($appId)) {
            app_alert_error(___('Square app is not configured yet.'));

            return back();
        }
        $state = SquareOAuth::newState();
        session(['square_oauth' => ['state' => $state, 'rid' => $rid, 'sandbox' => $sandbox]]);

        return redirect()->away(SquareOAuth::authorizeUrl($sandbox, (string) $appId, $state));
    }

    public function callback(Request $request)
    {
        $ctx = session('square_oauth');
        if (! $ctx || ! SquareOAuth::stateMatches($ctx['state'] ?? null, $request->query('state'))) {
            abort(403, 'Invalid OAuth state');
        }
        session()->forget('square_oauth');

        $rid = (int) $ctx['rid'];
        abort_unless(in_array($rid, $this->storeIds(), true), 403);
        $sandbox = (bool) $ctx['sandbox'];

        [$appId, $appSecret] = $this->appCreds();
        $token = SquareTokenExchange::exchange($sandbox, (string) $appId, (string) $appSecret, (string) $request->query('code'));
        if (! $token) {
            app_alert_error(___('Square authorization failed.'));

            return redirect()->route('stores.posbridge.index', ['store_id' => $rid]);
        }

        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => 'square', 'provider' => 'square'],
            ['secrets' => [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'expires_at' => $token['expires_at'],
                'merchant_id' => $token['merchant_id'],
                'sandbox' => $sandbox,
                'auth' => 'oauth',
            ], 'active' => true]
        );
        PosBridgeConnection::updateOrCreate(
            ['provider' => 'square', 'store_id' => $rid],
            ['status' => 'active', 'settings' => ['merchant_id' => $token['merchant_id'], 'sandbox' => $sandbox, 'auth' => 'oauth']]
        );

        app_alert_success(___('Square connected via OAuth.'));

        return redirect()->route('stores.posbridge.index', ['store_id' => $rid]);
    }

    public function disconnect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $secrets = Credential::where('store_id', $rid)->where('feature', 'square')->first()->secrets ?? [];
        [$appId, $appSecret] = $this->appCreds();
        if (! empty($secrets['access_token']) && ($secrets['auth'] ?? '') === 'oauth' && $appId) {
            SquareTokenExchange::revoke((bool) ($secrets['sandbox'] ?? false), (string) $appId, (string) $appSecret, (string) $secrets['access_token']);
        }
        PosBridgeConnection::where('store_id', $rid)->where('provider', 'square')->delete();
        Credential::where('store_id', $rid)->where('feature', 'square')->delete();
        app_alert_success(___('Square disconnected.'));

        return back();
    }
}
