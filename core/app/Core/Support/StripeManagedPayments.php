<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Stripe Managed Payments (merchant of record via Link) for digital Checkout
 * Sessions: plans, add-ons, wallet top-ups, Studio credits, reseller SaaS.
 *
 * Store orders and Connect payouts are not eligible. The flag stays off until
 * Managed Payments is activated in the Dashboard — sending the parameter
 * before that makes Checkout reject the session.
 */
final class StripeManagedPayments
{
    public const API_VERSION = '2025-03-31.basil';

    /** SaaS sold to a business. Eligible for Managed Payments. */
    public const TAX_SAAS_BUSINESS = 'txcd_10103001';

    /** Cloud AI tools sold to a business. Eligible for Managed Payments. */
    public const TAX_AI_BUSINESS = 'txcd_10105002';

    public static function enabled(): bool
    {
        return filter_var(config('settings.stripe_managed_payments'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Per-request Stripe SDK options pinning the API version when Managed Payments is enabled.
     *
     * @return array{stripe_version?: string}
     */
    public static function requestOptions(?bool $enabled = null): array
    {
        return ($enabled ?? self::enabled()) ? ['stripe_version' => self::API_VERSION] : [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function session(array $params, ?bool $enabled = null): array
    {
        if (! ($enabled ?? self::enabled())) {
            return $params;
        }

        unset(
            $params['payment_method_types'],
            $params['payment_method_configuration'],
            $params['excluded_payment_method_types'],
            $params['automatic_tax'],
            $params['tax_id_collection'],
            $params['adaptive_pricing'],
            $params['shipping_address_collection'],
            $params['shipping_options'],
            $params['invoice_creation'],
        );

        $params['managed_payments'] = ['enabled' => true];

        return $params;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public static function product(array $product, string $taxCode, ?bool $enabled = null): array
    {
        if ($enabled ?? self::enabled()) {
            $product['tax_code'] = $taxCode;
        }

        return $product;
    }
}
