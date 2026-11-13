<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\Admin;

use App\Services\PlatformMetrics;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;

/**
 * Platform-level WhatsApp settings: default provider, billing mode + markup,
 * monthly limit, signature verification, and platform provider keys (used when
 * a restaurant hasn't brought its own). (spec §8.2)
 */
class WhatsAppSettingsController extends Controller
{
    private const SETTING_KEYS = [
        'whatsapp_default_provider', 'whatsapp_billing_mode',
        'whatsapp_markup_pct', 'whatsapp_markup_flat', 'whatsapp_monthly_message_limit',
        'whatsapp_verify_signatures',
    ];

    public function index()
    {
        $providers = WhatsAppProviderFactory::available();
        $platform = Credential::whereNull('store_id')
            ->where('feature', 'whatsapp')
            ->pluck('active', 'provider')
            ->toArray();

        // C1: inbound-webhook health so the signature toggle is not a dead control.
        $rejectedToday = PlatformMetrics::todayCount('whatsapp.webhook_rejected');
        $unverifiedToday = PlatformMetrics::todayCount('whatsapp.webhook_unverified');
        $unroutedNumbers = WhatsAppInbound::whereNull('store_id')
            ->where('received_at', '>=', now()->subDay())->count();

        return view('admin.whatsapp.settings', compact(
            'providers', 'platform', 'rejectedToday', 'unverifiedToday', 'unroutedNumbers'
        ));
    }

    public function update(Request $request)
    {
        foreach (self::SETTING_KEYS as $key) {
            PlatformSettings::set($key, (string) $request->get($key, ''));
        }

        // Platform provider keys (store_id null).
        $maps = [
            'twilio' => ['sid' => 'twilio_sid', 'auth_token' => 'twilio_token', 'from_number' => 'twilio_from'],
            'meta' => ['access_token' => 'meta_access_token', 'phone_number_id' => 'meta_phone_number_id',
                'app_secret' => 'meta_app_secret', 'webhook_verify_token' => 'meta_webhook_verify_token'],
        ];
        foreach ($maps as $provider => $fields) {
            $incoming = [];
            foreach ($fields as $secretKey => $formKey) {
                if ($request->filled($formKey)) {
                    $incoming[$secretKey] = trim((string) $request->get($formKey));
                }
            }
            if (! $incoming) {
                continue;
            }
            $cred = Credential::firstOrNew(['store_id' => null, 'feature' => 'whatsapp', 'provider' => $provider]);
            $cred->secrets = array_merge((array) ($cred->secrets ?? []), $incoming);
            $cred->active = true;
            $cred->save();
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => ___('Updated Successfully')]);
        }
        app_alert_success(___('Updated Successfully'));

        return back();
    }
}
