<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Contracts\FiscalInvoices;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\OrderReceiptPdf;

/**
 * Owner redesign (rev/mobile-app): one order on its own screen — status steps,
 * customer, lines and totals — so a push notification or the native app can
 * open an order directly instead of a focused row in the list.
 */
class OwnerOrderController extends Controller
{
    public function show(Order $order): View
    {
        // Same scoping as every owner/staff order action: the order's store must be
        // one this dashboard actor can open. A foreign id is a 404, never a peek.
        abort_unless(
            function_exists('dashboard_can_access_store') && dashboard_can_access_store((int) $order->store_id),
            404
        );

        $order->loadMissing(['items.menu', 'items.variant', 'items.itemExtras.extra', 'voiceNote']);
        $store = Store::findOrFail($order->store_id);

        $fiscal = app(FiscalInvoices::class);
        $postOptions = store_options($store->id);

        return view('owner.orders.show', [
            'order' => $order,
            'store' => $store,
            'money' => $order->computeMoney(),
            'postOptions' => $postOptions,
            // P6-176: KSA stores that e-invoice can issue a standard (B2B) tax invoice for a business buyer
            'b2bInvoice' => strtoupper((string) ($postOptions->fiscal_country ?? '')) === 'SA' && $fiscal->issuesFor((int) $store->id),
        ]);
    }

    /**
     * P6-176: issue the standard (B2B) ZATCA tax invoice of a paid order for a business buyer. The buyer's name, VAT
     * number and national address are entered here and kept on the fiscal document; the order's amounts never change.
     */
    public function taxInvoice(Request $request, int|string $order): RedirectResponse
    {
        $order = Order::findOrFail((int) $order);
        abort_unless(
            function_exists('dashboard_can_access_store') && dashboard_can_access_store((int) $order->store_id),
            404
        );
        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_vat' => ['required', 'string', 'regex:/^3\d{13}3$/'],
            'buyer_street' => ['required', 'string', 'max:150'],
            'buyer_building_number' => ['required', 'string', 'regex:/^\d{4}$/'],
            'buyer_additional_number' => ['required', 'string', 'regex:/^\d{4}$/'], // P6-186: BR-KSA-63 (ZATCA SDK)
            'buyer_district' => ['required', 'string', 'max:100'],
            'buyer_city' => ['required', 'string', 'max:100'],
            'buyer_postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
        ], [
            'buyer_vat.regex' => ___('The VAT number must have 15 digits and start and end with 3.'),
            'buyer_building_number.regex' => ___('The building number must have 4 digits.'),
            'buyer_additional_number.regex' => ___('The additional number must have 4 digits.'),
            'buyer_postal_code.regex' => ___('The postal code must have 5 digits.'),
        ]);
        if (! $order->is_paid) {
            return back()->withInput()->with('error', ___('A tax invoice can be issued once the order is paid.'));
        }
        $store = Store::findOrFail($order->store_id);
        $buyer = ['country' => 'SA'];
        foreach (['name', 'vat', 'street', 'building_number', 'additional_number', 'district', 'city', 'postal_code'] as $f) {
            $buyer[$f] = (string) ($data['buyer_'.$f] ?? '');
        }

        try {
            $doc = app(FiscalInvoices::class)->standardInvoice($store, OrderFiscalContext::for($store, $order), $buyer,
                $order->branch_id ? (int) $order->branch_id : null);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        if ($doc === null) {
            return back()->withInput()->with('error', ___('This store does not issue e-invoices yet.'));
        }

        return back()->with('success', ___('Tax invoice :number issued.', ['number' => (string) $doc->number]));
    }

    /**
     * P6-172: the order receipt / simplified tax invoice as a PDF -- pure PHP (TCPDF), no Chrome or shell, so it works
     * on shared hosting. Same store scoping as show().
     */
    public function receiptPdf(Order $order, OrderReceiptPdf $pdf): Response
    {
        abort_unless(
            function_exists('dashboard_can_access_store') && dashboard_can_access_store((int) $order->store_id),
            404
        );

        $store = Store::findOrFail($order->store_id);

        return response($pdf->render($store, $order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$order->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
