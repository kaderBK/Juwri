<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use Juwri\Core\Http\Controllers\Controller;

/**
 * T7-P3 public migration landing page. GloriaFood was discontinued by Oracle
 * (EOL 2027-04-30); this page pitches switching, lists what we import automatically,
 * and links to signup with ?migrate=gloriafood so the wizard lands on the importer.
 * Registered BEFORE the {slug} restaurant catch-all.
 */
class GloriaFoodLandingController extends Controller
{
    public function show()
    {
        return view('marketing.switch-from-gloriafood', [
            'eolDate'    => 'April 30, 2027',
            'signupUrl'  => url('/register?migrate=gloriafood'),
            'features'   => [
                ['us' => 'QR menus & online ordering', 'gf' => true],
                ['us' => 'Table reservations & waitlist', 'gf' => 'basic'],
                ['us' => 'Promotions, coupons & gift cards', 'gf' => 'basic'],
                ['us' => 'Delivery zones & 3rd-party couriers', 'gf' => 'basic'],
                ['us' => 'Own POS + KDS', 'gf' => false],
                ['us' => 'Loyalty & wallet', 'gf' => false],
                ['us' => 'WhatsApp ordering & AI menu tools', 'gf' => false],
            ],
            'checklist' => [
                __('Export your GloriaFood menu (Settings → Menu → Export) and import it here'),
                __('Import your customer list (no marketing opt-in is carried over)'),
                __('Recreate delivery zones and delivery fees'),
                __('Recreate promotions with our coupon engine'),
                __('Regenerate your table QR codes'),
                __('Point your domain to your new ordering page'),
            ],
        ]);
    }
}
