<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

/**
 * The store's "verify the phone before ordering" switch (the checkout OTP), as the storefront's goods checkout needs it
 * without reaching into Ordering's HTTP layer (P3-08).
 */
interface CheckoutPhoneGate
{
    /** Does this store require a verified phone for orders? */
    public function required(int $storeId): bool;

    /** The phone in the form the verification marker uses ('' when it is not a phone). */
    public function normalize(string $phone): string;

    /** Has this (normalised) phone been verified for a checkout at this store (signed-in customer or a recent code)? */
    public function verified(string $normalizedPhone, int $storeId): bool;
}
